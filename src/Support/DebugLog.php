<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Support;

/**
 * Lines for the PHP error log. Never the message text or the key.
 */
final class DebugLog
{
    /** @var array<string, int> */
    private static array $last = [];

    public static function enabled(): bool
    {
        return defined('WP_DEBUG') && constant('WP_DEBUG') === true
            && defined('WP_DEBUG_LOG') && constant('WP_DEBUG_LOG') !== false;
    }

    public static function line(string $text): void
    {
        if (self::enabled()) {
            error_log('[sse-chat] ' . self::oneLine($text));
        }
    }

    /**
     * Write at most once per window per key, whatever the debug setting.
     * Uses a transient so the throttle holds across requests.
     */
    public static function throttled(string $key, string $text, int $window = 60): void
    {
        $now = time();
        if (isset(self::$last[$key]) && $now - self::$last[$key] < $window) {
            return;
        }
        self::$last[$key] = $now;
        $tkey = 'sse_chat_throttle_' . md5($key);
        if (get_transient($tkey) !== false) {
            return;
        }
        set_transient($tkey, 1, $window);
        error_log('[sse-chat] ' . self::oneLine($text));
    }

    /**
     * One log line: control characters (newlines included) become spaces, so
     * text that came from a request cannot forge extra log lines.
     */
    private static function oneLine(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
    }
}
