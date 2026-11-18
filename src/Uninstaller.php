<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget;

use IndianOpinion\SseChatWidget\Log\Schema;
use IndianOpinion\SseChatWidget\State\CacheWorkingState;

final class Uninstaller
{
    /**
     * @return array{log_deleted: bool}
     */
    public static function run(): array
    {
        global $wpdb;
        $settings = get_option(Settings::OPTION, []);
        $deleteLog = (is_array($settings) && !empty($settings['delete_log_on_uninstall']))
            || (defined('SSE_CHAT_DELETE_LOG_ON_UNINSTALL') && constant('SSE_CHAT_DELETE_LOG_ON_UNINSTALL') === true);

        wp_clear_scheduled_hook(Plugin::CRON_HOOK);

        foreach ([Settings::OPTION, Settings::KEY_OPTION, Schema::VERSION_OPTION, Schema::STATUS_OPTION, 'sse_chat_last_provider_error'] as $option) {
            delete_option($option);
        }

        // Working state and throttles kept as transients.
        $like1 = $wpdb->esc_like('_transient_' . CacheWorkingState::TRANSIENT_PREFIX) . '%';
        $like2 = $wpdb->esc_like('_transient_timeout_' . CacheWorkingState::TRANSIENT_PREFIX) . '%';
        $like3 = $wpdb->esc_like('_transient_sse_chat_') . '%';
        $like4 = $wpdb->esc_like('_transient_timeout_sse_chat_') . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $like1,
            $like2,
            $like3,
            $like4
        ));
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(CacheWorkingState::GROUP);
        }

        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sse_chat_counters");

        if ($deleteLog) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sse_chat_messages");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sse_chat_conversations");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sse_chat_audit");
        }

        // The vector index is not built in 0.1.0; a file left by a later
        // version at the documented path is removed.
        if (defined('SSE_CHAT_INDEX_DIR') && is_string(constant('SSE_CHAT_INDEX_DIR'))) {
            $file = rtrim((string) constant('SSE_CHAT_INDEX_DIR'), '/') . '/sse-chat-vectors.idx';
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }
        return ['log_deleted' => $deleteLog];
    }
}
