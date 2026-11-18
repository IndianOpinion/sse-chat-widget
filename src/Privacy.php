<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget;

use IndianOpinion\SseChatWidget\Log\WpdbLogStore;

/**
 * Privacy policy suggestion, personal data exporter and eraser for
 * logged-in readers (section 8). Anonymous rows are keyed only by session
 * and IP hash, so they cannot be found by an email address.
 */
final class Privacy
{
    public const PAGE = 100;

    public static function policyText(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        $text = '<p>When you use the chat on this site, we keep what you type and the answers it gives, '
            . 'with a keyed hash of your IP address (not the address itself), so we can review answers and stop abuse. '
            . 'If you are logged in, we also keep your user id with the conversation. '
            . 'The question is sent to our model provider to produce the answer. '
            . 'Choosing "Forget this conversation" hides it from the chat; the site keeps its own record for the period set out here. '
            . 'An administrator reviews and deletes old records; set the period that applies on this site here.</p>';
        wp_add_privacy_policy_content('SSE Chat Widget', wp_kses_post($text));
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public static function registerExporter(array $exporters): array
    {
        $exporters['sse-chat-widget'] = [
            'exporter_friendly_name' => 'Chat conversations',
            'callback' => [self::class, 'export'],
        ];
        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public static function registerEraser(array $erasers): array
    {
        $erasers['sse-chat-widget'] = [
            'eraser_friendly_name' => 'Chat conversations',
            'callback' => [self::class, 'erase'],
        ];
        return $erasers;
    }

    /**
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public static function export(string $email, int $page = 1): array
    {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user instanceof \WP_User) {
            return ['data' => [], 'done' => true];
        }
        $rows = (new WpdbLogStore($wpdb))->rowsForUser((int) $user->ID, self::PAGE, ($page - 1) * self::PAGE);
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'group_id' => 'sse-chat',
                'group_label' => 'Chat conversations',
                'item_id' => 'sse-chat-' . $row['id'],
                'data' => [
                    ['name' => 'Conversation', 'value' => (string) $row['conversation_id']],
                    ['name' => 'Role', 'value' => (string) $row['role']],
                    ['name' => 'Text', 'value' => (string) ($row['text'] ?? '')],
                    ['name' => 'Time (UTC)', 'value' => (string) $row['created_at']],
                ],
            ];
        }
        return ['data' => $data, 'done' => count($rows) < self::PAGE];
    }

    /**
     * Runs only when an administrator processes an erasure request.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public static function erase(string $email, int $page = 1): array
    {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user instanceof \WP_User || !current_user_can('erase_others_personal_data')) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        $n = (new WpdbLogStore($wpdb))->deleteForUser((int) $user->ID, get_current_user_id(), 'privacy_eraser');
        return ['items_removed' => $n > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
    }
}
