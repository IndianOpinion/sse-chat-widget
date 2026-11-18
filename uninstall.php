<?php

/**
 * Uninstall (case 73): removes settings, counters, working state and
 * scheduled events. Keeps the conversation log and audit tables unless the
 * administrator ticked "Delete the conversation log when the plugin is
 * uninstalled" or defined SSE_CHAT_DELETE_LOG_ON_UNINSTALL as true.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

\IndianOpinion\SseChatWidget\Uninstaller::run();
