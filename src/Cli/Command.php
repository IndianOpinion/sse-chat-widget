<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Cli;

use IndianOpinion\SseChatWidget\Admin\LogPage;
use IndianOpinion\SseChatWidget\Admin\SettingsPage;
use IndianOpinion\SseChatWidget\Log\WpdbLogStore;
use IndianOpinion\SseChatWidget\Plugin;
use IndianOpinion\SseChatWidget\Settings;

/**
 * WP-CLI: wp sse-chat status|kill|ask|index|logs (section 5.6).
 */
final class Command
{
    public static function register(): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::add_command('sse-chat', self::class);
        }
    }

    /**
     * Show whether the chat is on, today's counts and caps.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        foreach (SettingsPage::status(Settings::load()) as $label => $value) {
            \WP_CLI::line(sprintf('%-26s %s', $label . ':', $value));
        }
    }

    /**
     * Turn the kill switch on or off.
     *
     * ## OPTIONS
     *
     * <state>
     * : on or off
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function kill(array $args, array $assoc): void
    {
        $state = $args[0] ?? '';
        if ($state !== 'on' && $state !== 'off') {
            \WP_CLI::error('Use: wp sse-chat kill on|off');
        }
        if ($state === 'off' && defined('SSE_CHAT_KILL') && constant('SSE_CHAT_KILL') === true) {
            \WP_CLI::error('SSE_CHAT_KILL is defined as true in wp-config.php; remove it there to turn the chat back on.');
        }
        Plugin::setKill($state === 'on', 'cli');
        \WP_CLI::success('Kill switch ' . $state . '.');
    }

    /**
     * Ask a question through the full path. Counts against the caps; logged as admin_test.
     *
     * ## OPTIONS
     *
     * <question>
     * : The question.
     *
     * [--context=<post>]
     * : Post id of a context page.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function ask(array $args, array $assoc): void
    {
        $question = $args[0] ?? '';
        $out = Plugin::service()->adminTest($question, (int) ($assoc['context'] ?? 0), get_current_user_id());
        if ($out['refusal'] !== null) {
            \WP_CLI::error('Refused: ' . $out['refusal']->errorCode . ' (HTTP ' . $out['refusal']->status . ')');
        }
        $text = '';
        $sources = [];
        foreach ($out['events'] as [$name, $data]) {
            if ($name === 'delta') {
                $text .= (string) ($data['text'] ?? '');
            } elseif ($name === 'citations') {
                $sources = is_array($data['sources'] ?? null) ? $data['sources'] : [];
            } elseif ($name === 'error') {
                \WP_CLI::warning('Error: ' . (string) ($data['code'] ?? ''));
            }
        }
        \WP_CLI::line($text);
        foreach ($sources as $s) {
            if (is_array($s)) {
                \WP_CLI::line(sprintf('[%s] %s %s', (string) ($s['n'] ?? ''), (string) ($s['title'] ?? ''), (string) ($s['url'] ?? '')));
            }
        }
    }

    /**
     * Vector index commands. The vector retriever is on the roadmap.
     *
     * ## OPTIONS
     *
     * <action>
     * : rebuild or status
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function index(array $args, array $assoc): void
    {
        $action = $args[0] ?? 'status';
        if ($action !== 'status' && $action !== 'rebuild') {
            \WP_CLI::error('Use: wp sse-chat index rebuild|status');
        }
        \WP_CLI::line('The vector retriever is not shipped in 0.1.0. The keyword retriever is in use; there is no index to ' . ($action === 'rebuild' ? 'rebuild.' : 'report on.'));
    }

    /**
     * Export or purge the conversation log.
     *
     * ## OPTIONS
     *
     * <action>
     * : export or purge
     *
     * [--from=<date>]
     * : Export start, YYYY-MM-DD.
     *
     * [--to=<date>]
     * : Export end, YYYY-MM-DD.
     *
     * [--format=<format>]
     * : csv or json. Default csv.
     *
     * [--older-than=<days>]
     * : Purge conversations older than this many days. Defaults to the retention setting.
     *
     * [--yes]
     * : Purge without asking. Without it, purge asks, and does nothing when not run in a terminal.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function logs(array $args, array $assoc): void
    {
        global $wpdb;
        $store = new WpdbLogStore($wpdb);
        $action = $args[0] ?? '';
        if ($action === 'export') {
            $format = (string) ($assoc['format'] ?? 'csv');
            $from = (string) ($assoc['from'] ?? '');
            $to = (string) ($assoc['to'] ?? '');
            if ($format === 'json') {
                $store->export($from, $to, static function (array $rows): void {
                    foreach ($rows as $row) {
                        \WP_CLI::line((string) wp_json_encode($row));
                    }
                });
                return;
            }
            $out = fopen('php://stdout', 'wb');
            if ($out !== false) {
                LogPage::writeCsv($out, $store, $from, $to);
            }
            return;
        }
        if ($action !== 'purge') {
            \WP_CLI::error('Use: wp sse-chat logs export|purge');
        }
        $result = self::purge($store, Settings::load(), $assoc, self::interactive(), time(), get_current_user_id());
        if ($result['error'] !== null) {
            \WP_CLI::error($result['error']);
        }
        \WP_CLI::success(sprintf('%d conversations deleted.', $result['deleted']));
    }

    /**
     * The purge decision, separated for testing. Prints the count first;
     * deletes only with --yes or an interactive confirmation.
     *
     * @param array<string, string|bool> $assoc
     * @return array{deleted: int, error: ?string}
     */
    public static function purge(WpdbLogStore $store, Settings $settings, array $assoc, bool $interactive, int $now, int $userId): array
    {
        $days = isset($assoc['older-than']) ? (int) $assoc['older-than'] : $settings->int('retention_days');
        if ($days <= 0) {
            return ['deleted' => 0, 'error' => 'Retention is 0 (keep forever). Pass --older-than=<days> to purge.'];
        }
        $count = $store->eligibleCount($days, $now);
        self::say(sprintf('%d conversations are older than %d days.', $count, $days));
        if ($count === 0) {
            return ['deleted' => 0, 'error' => null];
        }
        if (empty($assoc['yes'])) {
            if (!$interactive) {
                return ['deleted' => 0, 'error' => 'Not a terminal and --yes not given; nothing deleted.'];
            }
            \WP_CLI::confirm(sprintf('Delete %d conversations?', $count));
        }
        return ['deleted' => $store->deleteOlderThan($days, $now, $userId, 'cli'), 'error' => null];
    }

    private static function interactive(): bool
    {
        return function_exists('stream_isatty') && defined('STDIN') && stream_isatty(STDIN);
    }

    private static function say(string $line): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::line($line);
        }
    }
}
