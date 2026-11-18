<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Http;

/**
 * Client IP resolution (case 27). REMOTE_ADDR unless the peer is a trusted
 * proxy; then the right-most untrusted hop of X-Forwarded-For. No forwarded
 * header is trusted by default.
 */
final class ClientIp
{
    public const UNKNOWN = 'unknown';

    /**
     * @param array<string, mixed> $server
     * @param list<string> $trustedProxies IPs and CIDR ranges.
     */
    public static function resolve(array $server, array $trustedProxies): string
    {
        $remote = isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR']) ? trim($server['REMOTE_ADDR']) : '';
        $ip = self::valid($remote) ? $remote : self::UNKNOWN;

        if ($ip !== self::UNKNOWN && $trustedProxies !== [] && self::inAny($ip, $trustedProxies)) {
            $xff = isset($server['HTTP_X_FORWARDED_FOR']) && is_string($server['HTTP_X_FORWARDED_FOR'])
                ? $server['HTTP_X_FORWARDED_FOR'] : '';
            $hops = array_reverse(array_map('trim', explode(',', $xff)));
            $found = null;
            foreach ($hops as $hop) {
                if ($hop === '') {
                    continue;
                }
                if (!self::valid($hop)) {
                    // An unparsable hop ends the walk: everything left of it is untrustworthy.
                    $found = self::UNKNOWN;
                    break;
                }
                if (!self::inAny($hop, $trustedProxies)) {
                    $found = $hop;
                    break;
                }
            }
            if ($found !== null) {
                $ip = $found;
            }
        }

        $filtered = apply_filters('sse_chat_client_ip', $ip, $server);
        if (!is_string($filtered) || ($filtered !== self::UNKNOWN && !self::valid($filtered))) {
            return self::UNKNOWN;
        }
        return $filtered;
    }

    public static function valid(string $ip): bool
    {
        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    public static function validRange(string $range): bool
    {
        if (self::valid($range)) {
            return true;
        }
        $parts = explode('/', $range);
        if (count($parts) !== 2 || !self::valid($parts[0]) || preg_match('/^\d{1,3}$/', $parts[1]) !== 1) {
            return false;
        }
        $max = str_contains($parts[0], ':') ? 128 : 32;
        return (int) $parts[1] <= $max;
    }

    /**
     * @param list<string> $ranges
     */
    public static function inAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $r) {
            if (self::inRange($ip, $r)) {
                return true;
            }
        }
        return false;
    }

    public static function inRange(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            $a = inet_pton($ip);
            $b = self::valid($range) ? inet_pton($range) : false;
            return $a !== false && $b !== false && $a === $b;
        }
        [$net, $bits] = explode('/', $range, 2);
        $ipBin = self::valid($ip) ? inet_pton($ip) : false;
        $netBin = self::valid($net) ? inet_pton($net) : false;
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($ipBin, $netBin, $bytes) !== 0) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
