<?php

/**
 * Must-use loader for sse-chat-widget.
 *
 * Copy this file into wp-content/mu-plugins/ and the plugin folder to
 * wp-content/mu-plugins/sse-chat-widget/. Must-use plugins have no
 * activation hook, so the tables are created on the first admin page load.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$sse_chat_main = __DIR__ . '/sse-chat-widget/sse-chat-widget.php';
if (is_file($sse_chat_main)) {
    require_once $sse_chat_main;
}
unset($sse_chat_main);
