<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model\Transport;

/**
 * Streaming transport on a direct curl handle with a write callback. The
 * write callback hands each piece of a 200 body to the caller as it
 * arrives; the progress callback polls for a stop. Returning a short count
 * from the write callback, or non-zero from the progress callback, makes
 * curl close the connection, so the provider stops generating.
 */
final class CurlTransport implements Transport
{
    private const ERROR_BODY_CAP = 65536;

    public static function available(): bool
    {
        return function_exists('curl_init') && function_exists('curl_setopt_array');
    }

    public function streams(): bool
    {
        return true;
    }

    public function post(string $url, array $headers, string $body, callable $onChunk, callable $onIdle, int $timeoutSeconds): TransportResult
    {
        if ($url === '') {
            return new TransportResult(null, '', 'no endpoint URL');
        }
        $handle = curl_init();
        if ($handle === false) {
            return new TransportResult(null, '', 'curl_init failed');
        }

        $status = null;
        $errorBody = '';
        $aborted = false;
        $firstByte = false;

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$status): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                    $status = (int) $m[1];
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$status, &$errorBody, &$aborted, &$firstByte, $onChunk): int {
                $firstByte = true;
                if ($status !== 200) {
                    if (strlen($errorBody) < self::ERROR_BODY_CAP) {
                        $errorBody .= substr($data, 0, self::ERROR_BODY_CAP - strlen($errorBody));
                    }
                    return strlen($data);
                }
                if ($onChunk($data) === false) {
                    $aborted = true;
                    return 0;
                }
                return strlen($data);
            },
            CURLOPT_XFERINFOFUNCTION => static function () use (&$aborted, $onIdle): int {
                if ($onIdle()) {
                    $aborted = true;
                    return 1;
                }
                return 0;
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https,http';
        }
        curl_setopt_array($handle, $options);

        $ok = curl_exec($handle);
        $error = null;
        if ($ok === false && !$aborted) {
            $error = curl_error($handle) !== '' ? curl_error($handle) : 'curl error ' . curl_errno($handle);
        }
        curl_close($handle);

        return new TransportResult($status, $errorBody, $error, $aborted, $firstByte);
    }
}
