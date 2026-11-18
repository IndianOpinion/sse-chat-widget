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
     * An IPv6 address is hashed by its /64 prefix: one subscriber usually holds
     * a whole /64, and hashing full addresses would let them step around every
     * per-IP limit by changing the low bits.
     */
    public static function ipHash(string $ip, int $timestamp): string
    {
        return substr(hash_hmac('sha256', self::bucket($ip), self::ipKey($timestamp)), 0, 16);
    }

    /**
     * The address a limit counts against: IPv4 as is, IPv6 as its /64.
     */
    public static function bucket(string $ip): string
    {
        if (!str_contains($ip, ':')) {
            return $ip;
        }
        $bin = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? inet_pton($ip) : false;
        if ($bin === false || strlen($bin) !== 16) {
            return $ip;
        }
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            // IPv4-mapped: count it as the IPv4 address it carries.
            $v4 = inet_ntop(substr($bin, 12));
            return $v4 === false ? $ip : $v4;
        }
        $prefix = inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8));
        return $prefix === false ? $ip : $prefix . '/64';
    }

    /**
     * Stored form of a session id: never the id itself.
     */
    public static function sessionHash(string $sessionId): string
    {
        return substr(hash_hmac('sha256', 'sid|' . $sessionId, self::session()), 0, 32);
    }
}
