<?php

/**
 * Plugin Name:       SSE Chat Widget
 * Plugin URI:        https://github.com/IndianOpinion/sse-chat-widget
 * Description:       A chat widget over your own published posts. Answers stream with Server-Sent Events; the prompt, the model and the retrieval stay on the server, with rate limits, a daily cap, a kill switch and a conversation log.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       sse-chat-widget
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SSE_CHAT_VERSION')) {
    define('SSE_CHAT_VERSION', '0.1.0');
}

if (!defined('SSE_CHAT_PLUGIN_FILE')) {
    define('SSE_CHAT_PLUGIN_FILE', __FILE__);
}

require_once __DIR__ . '/autoload.php';

register_activation_hook(__FILE__, [\IndianOpinion\SseChatWidget\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\IndianOpinion\SseChatWidget\Plugin::class, 'deactivate']);

\IndianOpinion\SseChatWidget\Plugin::boot(__FILE__);
