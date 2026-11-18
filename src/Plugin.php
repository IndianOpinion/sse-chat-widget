<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget;

use IndianOpinion\SseChatWidget\Admin\LogPage;
use IndianOpinion\SseChatWidget\Admin\SettingsPage;
use IndianOpinion\SseChatWidget\Chat\ChatService;
use IndianOpinion\SseChatWidget\Cli\Command;
use IndianOpinion\SseChatWidget\Front\Widget;
use IndianOpinion\SseChatWidget\Http\RestController;
use IndianOpinion\SseChatWidget\Limits\WpdbCounterStore;
use IndianOpinion\SseChatWidget\Log\Schema;
use IndianOpinion\SseChatWidget\Log\WpdbLogStore;
use IndianOpinion\SseChatWidget\Model\CallerFactory;
use IndianOpinion\SseChatWidget\State\CacheWorkingState;
use IndianOpinion\SseChatWidget\Support\SystemClock;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin
{
    public const CRON_HOOK = 'sse_chat_hourly';

    private static ?string $file = null;

    public static function boot(string $file): void
    {
        if (self::$file !== null) {
            return;
        }
        self::$file = $file;

        add_action('init', [self::class, 'init']);
        add_action('rest_api_init', [RestController::class, 'register']);
        add_action(self::CRON_HOOK, [self::class, 'hourly']);
        add_action('admin_init', [self::class, 'maybeInstall']);
        add_action('admin_init', [Privacy::class, 'policyText']);
        add_filter('wp_privacy_personal_data_exporters', [Privacy::class, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [Privacy::class, 'registerEraser']);

        Widget::hooks();
        if (is_admin()) {
            SettingsPage::hooks();
            LogPage::hooks();
        }
        if (defined('WP_CLI') && constant('WP_CLI')) {
            Command::register();
        }
    }

    public static function file(): string
    {
        return self::$file ?? (defined('SSE_CHAT_PLUGIN_FILE') ? (string) constant('SSE_CHAT_PLUGIN_FILE') : dirname(__DIR__) . '/sse-chat-widget.php');
    }

    public static function url(string $path): string
    {
        return plugins_url($path, self::file());
    }

    public static function init(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
    }

    public static function activate(): void
    {
        global $wpdb;
        (new Schema($wpdb))->install();
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
    }

    /**
     * Deactivation stops scheduled events only (case 74).
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Tables for must-use installs are created on the first admin page load.
     */
    public static function maybeInstall(): void
    {
        if (Schema::needsInstall()) {
            global $wpdb;
            (new Schema($wpdb))->install();
        }
    }

    /**
     * Hourly: prune expired rate windows, slots and daily totals older than
     * 400 days. Counters only; this never touches the log (case 70).
     */
    public static function hourly(): void
    {
        global $wpdb;
        try {
            (new WpdbCounterStore($wpdb))->pruneExpired(time());
        } catch (Limits\CounterUnavailable $e) {
            // Try again next hour.
        }
    }

    public static function service(): ChatService
    {
        global $wpdb;
        $settings = Settings::load();
        return new ChatService(
            $settings,
            new SystemClock(),
            new WpdbCounterStore($wpdb),
            new CacheWorkingState(),
            new WpdbLogStore($wpdb),
            new CallerFactory($settings)
        );
    }

    /**
     * The capability for a context: settings, log_read or log_delete.
     */
    public static function capability(string $context): string
    {
        $cap = apply_filters('sse_chat_capability', 'manage_options', $context);
        return is_string($cap) && $cap !== '' ? $cap : 'manage_options';
    }

    /**
     * Set the kill switch from the screen or the CLI and record it.
     */
    public static function setKill(bool $on, string $source): void
    {
        global $wpdb;
        $stored = get_option(Settings::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $stored['kill'] = $on;
        update_option(Settings::OPTION, $stored);
        (new WpdbLogStore($wpdb))->audit('kill_' . ($on ? 'on' : 'off'), $source, 0, get_current_user_id());
        do_action('sse_chat_kill_switch_changed', $on, $source);
    }
}
