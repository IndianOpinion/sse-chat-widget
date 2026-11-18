<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Admin;

use IndianOpinion\SseChatWidget\Log\WpdbLogStore;
use IndianOpinion\SseChatWidget\Plugin;
use IndianOpinion\SseChatWidget\Settings;

/**
 * The log screen (cases 71 and 72): conversations newest first with
 * filters, one conversation's turns, CSV export, and the administrator's
 * delete actions. Nothing here deletes without a capability, a nonce and
 * an explicit click.
 */
final class LogPage
{
    public const SLUG = 'sse-chat-log';

    public static function hooks(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_sse_chat_purge', [self::class, 'handlePurge']);
        add_action('admin_post_sse_chat_delete_conversation', [self::class, 'handleDeleteConversation']);
        add_action('admin_post_sse_chat_export', [self::class, 'handleExport']);
    }

    public static function menu(): void
    {
        add_management_page('Chat log', 'Chat log', Plugin::capability('log_read'), self::SLUG, [self::class, 'render']);
    }

    private static function store(): WpdbLogStore
    {
        global $wpdb;
        return new WpdbLogStore($wpdb);
    }

    /**
     * "Delete eligible rows" (case 71). Needs the delete capability, a valid
     * nonce, and the confirmed count to match what is eligible now.
     *
     * @return int|string Conversations deleted, or a refusal reason.
     */
    public static function purgeEligible(WpdbLogStore $store, Settings $settings, int $now, ?string $nonce, string $confirmedCount): int|string
    {
        if (!current_user_can(Plugin::capability('log_delete'))) {
            return 'capability';
        }
        if ($nonce === null || $nonce === '' || wp_verify_nonce($nonce, 'sse_chat_purge') === false) {
            return 'nonce';
        }
        $retention = $settings->int('retention_days');
        $eligible = $store->eligibleCount($retention, $now);
        if ($eligible === 0) {
            return 0;
        }
        if ($confirmedCount !== (string) $eligible) {
            return 'confirmation';
        }
        return $store->deleteOlderThan($retention, $now, get_current_user_id(), 'log_screen');
    }

    public static function handlePurge(): void
    {
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : null;
        $confirm = isset($_POST['confirm_count']) && is_string($_POST['confirm_count']) ? sanitize_text_field(wp_unslash($_POST['confirm_count'])) : '';
        $result = self::purgeEligible(self::store(), Settings::load(), time(), $nonce, $confirm);
        if (is_string($result) && $result !== 'confirmation') {
            wp_die('You are not allowed to do that.', 403);
        }
        self::back(['purged' => is_int($result) ? (string) $result : 'mismatch']);
    }

    public static function handleDeleteConversation(): void
    {
        if (!current_user_can(Plugin::capability('log_delete'))) {
            wp_die('You are not allowed to do that.', 403);
        }
        $id = isset($_POST['conversation']) && is_string($_POST['conversation']) ? sanitize_text_field(wp_unslash($_POST['conversation'])) : '';
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (preg_match('/^[0-9a-f]{32}$/', $id) !== 1 || wp_verify_nonce($nonce, 'sse_chat_delete_' . $id) === false) {
            wp_die('You are not allowed to do that.', 403);
        }
        $n = self::store()->deleteConversation($id, get_current_user_id(), 'log_screen_one');
        self::back(['deleted' => (string) $n]);
    }

    public static function handleExport(): void
    {
        if (!current_user_can(Plugin::capability('log_read'))) {
            wp_die('You are not allowed to do that.', 403);
        }
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (wp_verify_nonce($nonce, 'sse_chat_export') === false) {
            wp_die('You are not allowed to do that.', 403);
        }
        $from = isset($_POST['from']) && is_string($_POST['from']) ? sanitize_text_field(wp_unslash($_POST['from'])) : '';
        $to = isset($_POST['to']) && is_string($_POST['to']) ? sanitize_text_field(wp_unslash($_POST['to'])) : '';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chat-log-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            exit;
        }
        self::writeCsv($out, self::store(), $from, $to);
        fclose($out);
        exit;
    }

    public const CSV_COLUMNS = ['id', 'conversation_id', 'turn', 'role', 'text', 'retrieved', 'cited', 'outcome', 'model',
        'input_tokens', 'output_tokens', 'first_token_ms', 'total_ms', 'notes', 'created_at', 'ip_hash', 'user_id', 'hidden_by_reader'];

    /**
     * Write CSV in batches (case 72). Cells that a spreadsheet would read as
     * a formula are prefixed with a quote.
     *
     * @param resource $out
     */
    public static function writeCsv($out, WpdbLogStore $store, string $from, string $to, int $batch = WpdbLogStore::BATCH): int
    {
        fputcsv($out, self::CSV_COLUMNS, ',', '"', '\\');
        return $store->export($from, $to, static function (array $rows) use ($out): void {
            foreach ($rows as $row) {
                $line = [];
                foreach (self::CSV_COLUMNS as $col) {
                    $v = isset($row[$col]) ? (string) $row[$col] : '';
                    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                        $v = "'" . $v;
                    }
                    $line[] = $v;
                }
                fputcsv($out, $line, ',', '"', '\\');
            }
            flush();
        }, $batch);
    }

    /**
     * @param array<string, string> $args
     */
    private static function back(array $args): void
    {
        wp_safe_redirect(add_query_arg(['page' => self::SLUG] + $args, admin_url('tools.php')));
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can(Plugin::capability('log_read'))) {
            wp_die('You are not allowed to see this page.', 403);
        }
        $store = self::store();
        $settings = Settings::load();
        $canDelete = current_user_can(Plugin::capability('log_delete'));
        $get = static fn (string $k): string => isset($_GET[$k]) && is_string($_GET[$k]) ? sanitize_text_field(wp_unslash($_GET[$k])) : '';

        echo '<div class="wrap"><h1>Chat log</h1>';
        if ($get('purged') !== '') {
            echo '<div class="notice notice-info"><p>' . esc_html($get('purged') === 'mismatch'
                ? 'Nothing was deleted: the number of eligible conversations changed. Check the count and try again.'
                : sprintf('%d conversations deleted.', (int) $get('purged'))) . '</p></div>';
        }

        $view = $get('conversation');
        if (preg_match('/^[0-9a-f]{32}$/', $view) === 1) {
            self::renderConversation($store, $view, $canDelete);
            echo '</div>';
            return;
        }

        $retention = $settings->int('retention_days');
        $eligible = $store->eligibleCount($retention, time());
        echo '<p>Retention: ' . esc_html($retention === 0 ? 'keep forever' : $retention . ' days') . '. ';
        echo esc_html(sprintf('%d conversations are eligible for deletion.', $eligible)) . ' The plugin never deletes them on its own.</p>';
        if ($canDelete && $eligible > 0) {
            $msg = sprintf('Delete %d conversations older than %d days? This cannot be undone.', $eligible, $retention);
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(' . esc_attr((string) wp_json_encode($msg)) . ');">';
            wp_nonce_field('sse_chat_purge');
            echo '<input type="hidden" name="action" value="sse_chat_purge"><input type="hidden" name="confirm_count" value="' . esc_attr((string) $eligible) . '">';
            submit_button(sprintf('Delete eligible rows (%d)', $eligible), 'delete', 'submit', false);
            echo '</form>';
        }

        $filters = array_filter([
            'from' => $get('from'),
            'to' => $get('to'),
            'outcome' => $get('outcome'),
            'cited' => $get('cited'),
            'ip_hash' => $get('ip_hash'),
        ], static fn (string $v): bool => $v !== '');
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
        foreach (['from' => 'From (YYYY-MM-DD)', 'to' => 'To (YYYY-MM-DD)', 'outcome' => 'Outcome', 'ip_hash' => 'IP hash'] as $k => $label) {
            echo '<label>' . esc_html($label) . ' <input type="text" name="' . esc_attr($k) . '" value="' . esc_attr($filters[$k] ?? '') . '" size="12"></label> ';
        }
        echo '<select name="cited"><option value="">Cited or not</option><option value="cited"' . selected($filters['cited'] ?? '', 'cited', false) . '>Cited only</option><option value="uncited"' . selected($filters['cited'] ?? '', 'uncited', false) . '>With uncited answers</option></select> ';
        submit_button('Filter', 'secondary', 'filter', false);
        echo '</form>';

        $page = max(1, (int) $get('paged'));
        $rows = $store->conversations($filters, 50, ($page - 1) * 50);
        $total = $store->countConversations($filters);
        echo '<table class="widefat striped"><thead><tr><th>Last activity (UTC)</th><th>Conversation</th><th>Turns</th><th>Outcome</th><th>Uncited</th><th>IP hash</th><th>User</th><th>Reader</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $link = add_query_arg(['page' => self::SLUG, 'conversation' => (string) $r['id']], admin_url('tools.php'));
            echo '<tr><td>' . esc_html((string) $r['last_activity']) . '</td><td><a href="' . esc_url($link) . '">' . esc_html(substr((string) $r['id'], 0, 12)) . '</a></td>';
            echo '<td>' . esc_html((string) $r['turns']) . '</td><td>' . esc_html((string) $r['outcome']) . '</td><td>' . esc_html((string) $r['uncited_answers']) . '</td>';
            echo '<td>' . esc_html((string) $r['ip_hash']) . '</td><td>' . esc_html((string) $r['user_id']) . '</td>';
            echo '<td>' . ((int) $r['hidden_by_reader'] === 1 ? esc_html('hidden by reader') : '') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p>' . esc_html(sprintf('%d conversations. Page %d.', $total, $page)) . ' ';
        if ($page > 1) {
            echo '<a href="' . esc_url(add_query_arg(['page' => self::SLUG, 'paged' => $page - 1] + $filters, admin_url('tools.php'))) . '">Newer</a> ';
        }
        if ($page * 50 < $total) {
            echo '<a href="' . esc_url(add_query_arg(['page' => self::SLUG, 'paged' => $page + 1] + $filters, admin_url('tools.php'))) . '">Older</a>';
        }
        echo '</p>';

        echo '<h2>Export</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('sse_chat_export');
        echo '<input type="hidden" name="action" value="sse_chat_export">';
        echo '<label>From <input type="date" name="from"></label> <label>To <input type="date" name="to"></label> ';
        submit_button('Export CSV', 'secondary', 'submit', false);
        echo '</form></div>';
    }

    private static function renderConversation(WpdbLogStore $store, string $id, bool $canDelete): void
    {
        $conv = $store->conversation($id);
        if ($conv === null) {
            echo '<p>Conversation not found.</p>';
            return;
        }
        echo '<h2>Conversation ' . esc_html($id) . '</h2>';
        if ((int) $conv['hidden_by_reader'] === 1) {
            echo '<p><strong>Hidden by reader</strong> at ' . esc_html((string) $conv['hidden_at']) . ' UTC. The rows are kept until you delete them.</p>';
        }
        echo '<table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Role</th><th>Text</th><th>Cited</th><th>Outcome</th><th>Tokens</th></tr></thead><tbody>';
        foreach ($store->turns($id) as $t) {
            echo '<tr><td>' . esc_html((string) $t['created_at']) . '</td><td>' . esc_html((string) $t['role']) . '</td>';
            echo '<td style="white-space:pre-wrap">' . esc_html((string) ($t['text'] ?? '(not stored)')) . '</td>';
            echo '<td>' . esc_html((string) $t['cited']) . '</td><td>' . esc_html((string) $t['outcome']) . '</td>';
            echo '<td>' . esc_html((string) ($t['input_tokens'] ?? '') . '/' . (string) ($t['output_tokens'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ($canDelete) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Delete this conversation? This cannot be undone.\');">';
            wp_nonce_field('sse_chat_delete_' . $id);
            echo '<input type="hidden" name="action" value="sse_chat_delete_conversation"><input type="hidden" name="conversation" value="' . esc_attr($id) . '">';
            submit_button('Delete this conversation', 'delete', 'submit', false);
            echo '</form>';
        }
    }
}
