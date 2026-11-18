<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Front;

use IndianOpinion\SseChatWidget\Http\RestController;
use IndianOpinion\SseChatWidget\Plugin;
use IndianOpinion\SseChatWidget\Settings;

/**
 * Shortcode, block and floating launcher (section 5.3). The markup is
 * static: no nonce, no session, no user data and no prompt text, so pages
 * stay cacheable (case 1).
 */
final class Widget
{
    public const HANDLE = 'sse-chat-widget';

    public static function hooks(): void
    {
        add_action('init', [self::class, 'register']);
        add_action('wp_enqueue_scripts', [self::class, 'maybeEnqueueLauncher']);
        add_action('wp_footer', [self::class, 'launcher']);
    }

    public static function register(): void
    {
        $version = defined('SSE_CHAT_VERSION') ? (string) constant('SSE_CHAT_VERSION') : '0.1.0';
        wp_register_script(self::HANDLE, Plugin::url('assets/sse-chat-widget.js'), [], $version, ['in_footer' => true, 'strategy' => 'defer']);
        wp_register_style(self::HANDLE, Plugin::url('assets/sse-chat-widget.css'), [], $version);
        add_shortcode('sse_chat', [self::class, 'shortcode']);
        if (function_exists('register_block_type')) {
            register_block_type(dirname(Plugin::file()) . '/blocks/widget', [
                'render_callback' => [self::class, 'block'],
            ]);
        }
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public static function shortcode(array|string $atts = []): string
    {
        $atts = shortcode_atts(['title' => '', 'greeting' => '', 'context' => 'auto'], is_array($atts) ? $atts : [], 'sse_chat');
        return self::render((string) $atts['title'], (string) $atts['greeting'], (string) $atts['context'], false);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function block(array $attributes = []): string
    {
        return self::render(
            is_string($attributes['title'] ?? null) ? $attributes['title'] : '',
            is_string($attributes['greeting'] ?? null) ? $attributes['greeting'] : '',
            is_string($attributes['context'] ?? null) ? $attributes['context'] : 'auto',
            false
        );
    }

    public static function render(string $title, string $greeting, string $context, bool $launcher): string
    {
        $settings = Settings::load();
        if (!$settings->bool('enabled')) {
            return '';
        }
        wp_enqueue_script(self::HANDLE);
        wp_enqueue_style(self::HANDLE);

        $contextId = 0;
        if ($context !== 'none' && is_singular()) {
            $contextId = (int) get_queried_object_id();
        }
        $attrs = [
            'class' => 'sse-chat' . ($launcher ? ' sse-chat--launcher' : ''),
            'data-sse-chat' => '',
            'data-endpoint' => rest_url(RestController::NAMESPACE . '/'),
            'data-context' => $contextId > 0 ? (string) $contextId : '',
            'data-launcher' => $launcher ? '1' : '0',
        ];
        if ($title !== '') {
            $attrs['data-title'] = mb_substr($title, 0, 200);
        }
        if ($greeting !== '') {
            $attrs['data-greeting'] = mb_substr($greeting, 0, 500);
        }
        $html = '<div';
        foreach ($attrs as $k => $v) {
            $html .= ' ' . $k . '="' . esc_attr($v) . '"';
        }
        $html .= '><noscript>' . esc_html__('The chat needs JavaScript.', 'sse-chat-widget') . '</noscript></div>';
        return $html;
    }

    public static function launcherWanted(): bool
    {
        $settings = Settings::load();
        if (!$settings->bool('enabled') || !$settings->bool('launcher') || is_admin()) {
            return false;
        }
        if (is_singular() && in_array((string) get_post_type(), $settings->list('launcher_exclude_types'), true)) {
            return false;
        }
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? (string) parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '/';
        foreach ($settings->list('launcher_exclude_paths') as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return false;
            }
        }
        return true;
    }

    public static function maybeEnqueueLauncher(): void
    {
        if (self::launcherWanted()) {
            wp_enqueue_script(self::HANDLE);
            wp_enqueue_style(self::HANDLE);
        }
    }

    public static function launcher(): void
    {
        if (self::launcherWanted()) {
            echo self::render('', '', 'auto', true); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_attr above.
        }
    }
}
