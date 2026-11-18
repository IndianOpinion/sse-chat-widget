<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Http;

/**
 * Origin check for the public routes (case 12). Cached pages cannot carry a
 * nonce, so the Origin (or Referer) header takes its place.
 */
final class OriginCheck
{
    /** @var list<string> */
    private array $allowed;

    /**
     * @param list<string> $allowedOrigins Normalised origins, the site origin first.
     */
    public function __construct(array $allowedOrigins)
    {
        $this->allowed = [];
        foreach ($allowedOrigins as $o) {
            $n = self::normalise($o);
            if ($n !== null) {
                $this->allowed[] = $n;
            }
        }
    }

    /**
     * @param array<mixed> $extra
     */
    public static function forSite(string $homeUrl, array $extra): self
    {
        $list = [$homeUrl];
        foreach ($extra as $e) {
            if (is_string($e)) {
                $list[] = $e;
            }
        }
        return new self($list);
    }

    /**
     * True when the request may proceed.
     */
    public function passes(?string $origin, ?string $referer): bool
    {
        if ($origin !== null && $origin !== '') {
            $n = self::normalise($origin);
            return $n !== null && in_array($n, $this->allowed, true);
        }
        if ($referer !== null && $referer !== '') {
            $host = parse_url($referer, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                return false;
            }
            $host = strtolower($host);
            foreach ($this->allowed as $a) {
                if (strtolower((string) parse_url($a, PHP_URL_HOST)) === $host) {
                    return true;
                }
            }
            return false;
        }
        return false;
    }

    /**
     * scheme://host[:port], lower case, default ports dropped. Null when the
     * value is not an http or https origin.
     */
    public static function normalise(string $value): ?string
    {
        $parts = parse_url(trim($value));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if ($port === null || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            return $scheme . '://' . $host;
        }
        return $scheme . '://' . $host . ':' . $port;
    }
}
