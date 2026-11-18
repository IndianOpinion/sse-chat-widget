<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model\Transport;

/**
 * Non-streaming fallback through the WordPress HTTP API, for hosts without
 * curl. The caller then asks for a whole answer and replays it (case 53).
 */
final class WpHttpTransport implements Transport
{
    public function streams(): bool
    {
        return false;
    }

    public function post(string $url, array $headers, string $body, callable $onChunk, callable $onIdle, int $timeoutSeconds): TransportResult
    {
        if ($onIdle()) {
            return new TransportResult(null, '', null, true);
        }
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => $body,
            'timeout' => max(1, $timeoutSeconds),
            'redirection' => 0,
            'reject_unsafe_urls' => !self::isLocal($url),
        ]);
        if (is_wp_error($response)) {
            return new TransportResult(null, '', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $text = (string) wp_remote_retrieve_body($response);
        return new TransportResult($status === 0 ? null : $status, $text, null, false, $text !== '');
    }

    private static function isLocal(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }
}
