<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Session;

/**
 * HMAC keys derived from the WordPress salts with plugin-specific labels.
 * Rotating the salts invalidates sessions and changes future IP hashes.
 */
final class Keys
{
    public static function session(): string
    {
        return hash_hmac('sha256', 'sse-chat-widget|session|v1', wp_salt('auth'));
    }

    /**
     * The IP hash key rotates every calendar year (UTC), so a stored hash
     * groups abuse within a year and cannot be joined across years.
     */
    public static function ipKey(int $timestamp): string
    {
        return hash_hmac('sha256', 'sse-chat-widget|ip|' . gmdate('Y', $timestamp), wp_salt('nonce'));
    }

    /**
     * Keyed hash of an IP, 16 hex characters (case 69). Raw IPs are never stored.
     */
    public static function ipHash(string $ip, int $timestamp): string
    {
        return substr(hash_hmac('sha256', $ip, self::ipKey($timestamp)), 0, 16);
    }

    /**
     * Stored form of a session id: never the id itself.
     */
    public static function sessionHash(string $sessionId): string
    {
        return substr(hash_hmac('sha256', 'sid|' . $sessionId, self::session()), 0, 32);
    }
}
