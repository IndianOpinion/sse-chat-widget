<?php

/**
 * Refuse chat requests from anonymous readers with sse_chat_verify_request.
 * Returning a WP_Error refuses before any spend; its status defaults to 403.
 */

declare(strict_types=1);

add_filter('sse_chat_verify_request', static function ($ok, array $request) {
    if ((int) $request['user_id'] === 0) {
        return new WP_Error('login_required', 'Please log in to use the chat.', ['status' => 401]);
    }
    return $ok;
}, 10, 2);
