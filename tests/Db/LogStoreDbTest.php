<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Db;

use IndianOpinion\SseChatWidget\Admin\LogPage;
use IndianOpinion\SseChatWidget\Admin\SettingsPage;
use IndianOpinion\SseChatWidget\Chat\ChatService;
use IndianOpinion\SseChatWidget\Cli\Command;
use IndianOpinion\SseChatWidget\Limits\WpdbCounterStore;
use IndianOpinion\SseChatWidget\Log\Schema;
use IndianOpinion\SseChatWidget\Log\TurnRecord;
use IndianOpinion\SseChatWidget\Log\WpdbLogStore;
use IndianOpinion\SseChatWidget\Model\CallerFactory;
use IndianOpinion\SseChatWidget\Plugin;
use IndianOpinion\SseChatWidget\Privacy;
use IndianOpinion\SseChatWidget\Settings;
use IndianOpinion\SseChatWidget\Tests\Fakes\ScriptedCaller;
use IndianOpinion\SseChatWidget\Uninstaller;

final class LogStoreDbTest extends DbTestCase
{
    private function dbService(array $override = []): ChatService
    {
        $settings = $this->settings($override);
        return new ChatService($settings, $this->clock, new WpdbCounterStore($this->db), $this->state, new WpdbLogStore($this->db), new CallerFactory($settings), null, null, static fn (): bool => true);
    }

    private function turn(string $id, int $daysAgo, int $userId = 0, string $outcome = 'answered', array $cited = [1]): TurnRecord
    {
        return new TurnRecord($id, str_repeat('5', 32), $userId, 'abcdef0123456789', 0, 1, 'question ' . $id, 'answer ' . $id, [1, 2], $cited, $outcome, 'm', 10, 5, 100, 300, time() - $daysAgo * 86400);
    }

    /** T55, case 67. */
    public function testLogModes(): void
    {
        $this->seedPosts();
        $this->caller = ScriptedCaller::answer('Opened [1].');
        $this->ask(['message' => 'river dam full'], [], ['REMOTE_ADDR' => '203.0.113.7'], $this->dbService(['log_mode' => 'full']));
        $this->ask(['message' => 'river dam meta'], [], ['REMOTE_ADDR' => '203.0.113.7'], $this->dbService(['log_mode' => 'metadata']));
        $before = $this->tableCounts();
        $this->ask(['message' => 'river dam off'], [], ['REMOTE_ADDR' => '203.0.113.7'], $this->dbService(['log_mode' => 'off']));
        self::assertSame($before, $this->tableCounts(), 'off writes no rows');

        $texts = $this->db->get_col('SELECT text FROM wptest_sse_chat_messages ORDER BY id');
        self::assertSame(['river dam full', 'Opened [1].', '', ''], $texts, 'metadata stores no text');
        self::assertSame(2, (int) $this->db->get_var('SELECT COUNT(*) FROM wptest_sse_chat_messages WHERE text IS NULL'));
        self::assertSame('1', $this->db->get_var("SELECT cited FROM wptest_sse_chat_messages WHERE role = 'assistant' LIMIT 1"));
        self::assertSame(4, $before['sse_chat_messages'], 'switching mode deleted nothing');
    }

    /** T57, case 69. */
    public function testNoRawIpAnywhere(): void
    {
        $this->seedPosts();
        $this->ask(['message' => 'river dam'], [], ['REMOTE_ADDR' => '203.0.113.77'], $this->dbService());
        $this->ask(['message' => 'river dam'], [], ['REMOTE_ADDR' => '2001:db8::77'], $this->dbService());
        $dump = '';
        foreach (array_keys(Schema::COLUMNS) as $t) {
            $dump .= json_encode($this->db->get_results("SELECT * FROM wptest_{$t}", ARRAY_A));
        }
        self::assertStringNotContainsString('203.0.113.77', $dump);
        self::assertStringNotContainsString('2001:db8::77', $dump);
        self::assertStringNotContainsString('2001:0db8', $dump);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $this->db->get_var('SELECT ip_hash FROM wptest_sse_chat_conversations LIMIT 1'));
    }

    /** T58, cases 70 and 74. */
    public function testEligibleRowsSurviveEveryAutomaticRoutine(): void
    {
        $store = new WpdbLogStore($this->db);
        $store->record($this->turn(str_repeat('a', 32), 200), 'full');
        $store->record($this->turn(str_repeat('b', 32), 100), 'full');
        $store->record($this->turn(str_repeat('c', 32), 10), 'full');
        update_option(Settings::OPTION, ['retention_days' => 90]);
        self::assertSame(2, $store->eligibleCount(90, time()));
        self::assertSame(0, $store->eligibleCount(0, time()), '0 keeps forever');
        $before = $this->tableCounts();

        Plugin::hourly();
        Plugin::activate();
        Plugin::deactivate();
        delete_option(Schema::VERSION_OPTION);
        Plugin::maybeInstall();
        SettingsPage::applySave(['retention_days' => '1', 'log_mode' => 'off'], null);
        foreach (\WpStub::$filters as $hook => $_) {
            if (str_starts_with($hook, 'sse_chat') || str_contains($hook, 'cron')) {
                do_action($hook);
            }
        }
        self::assertSame($before, $this->tableCounts());
    }

    /** T59, case 71. */
    public function testPurgeNeedsNonceCapabilityAndDeletesOnlyEligibleWithOneAuditEntry(): void
    {
        $store = new WpdbLogStore($this->db);
        $store->record($this->turn(str_repeat('a', 32), 200), 'full');
        $store->record($this->turn(str_repeat('b', 32), 10), 'full');
        $settings = new Settings(['retention_days' => 90]);
        \WpStub::$userId = 3;

        self::assertSame('capability', LogPage::purgeEligible($store, $settings, time(), wp_create_nonce('sse_chat_purge'), '1'));
        \WpStub::$caps['manage_options'] = true;
        self::assertSame('nonce', LogPage::purgeEligible($store, $settings, time(), null, '1'));
        self::assertSame('nonce', LogPage::purgeEligible($store, $settings, time(), wp_create_nonce('other_action'), '1'));
        self::assertSame('confirmation', LogPage::purgeEligible($store, $settings, time(), wp_create_nonce('sse_chat_purge'), '7'));
        self::assertSame(2, $store->count('conversations'));
        self::assertSame(0, $store->count('audit'));

        self::assertSame(1, LogPage::purgeEligible($store, $settings, time(), wp_create_nonce('sse_chat_purge'), '1'));
        self::assertNotNull($store->conversation(str_repeat('b', 32)));
        self::assertNull($store->conversation(str_repeat('a', 32)));
        self::assertSame(2, $store->count('messages'));
        $audit = $this->db->get_results('SELECT * FROM wptest_sse_chat_audit', ARRAY_A);
        self::assertCount(1, $audit);
        self::assertSame(['delete', 'log_screen', '1', '3'], [$audit[0]['action'], $audit[0]['route'], $audit[0]['count'], $audit[0]['user_id']]);
        self::assertSame([[1, 'log_screen', 3]], did_action_with('sse_chat_log_deleted'));

        // CLI without --yes, not interactive: nothing deleted.
        $store->record($this->turn(str_repeat('d', 32), 300), 'full');
        $out = Command::purge($store, $settings, [], false, time(), 3);
        self::assertSame(0, $out['deleted']);
        self::assertNotNull($out['error']);
        self::assertNotNull($store->conversation(str_repeat('d', 32)));
        $out = Command::purge($store, $settings, ['yes' => true, 'older-than' => '250'], false, time(), 3);
        self::assertSame(['deleted' => 1, 'error' => null], $out);
        self::assertSame(2, $store->count('audit'));
    }

    /** T60, case 72. */
    public function testFiltersAndCsvExportInBatches(): void
    {
        $store = new WpdbLogStore($this->db);
        $store->record($this->turn(str_repeat('a', 32), 5, 0, 'answered', [1]), 'full');
        $store->record($this->turn(str_repeat('b', 32), 3, 0, 'no_context', []), 'full');
        $store->record(new TurnRecord(str_repeat('c', 32), 's', 0, '1111222233334444', 0, 1, '=HYPERLINK("x")', 'a', [], [], 'answered', 'm', null, null, null, 1, time() - 86400), 'full');

        $ids = static fn (array $rows): array => array_map(static fn ($r) => substr((string) $r['id'], 0, 1), $rows);
        self::assertSame(['c', 'b', 'a'], $ids($store->conversations([])), 'newest first');
        self::assertSame(['b'], $ids($store->conversations(['outcome' => 'no_context'])));
        self::assertSame(['c', 'b'], $ids($store->conversations(['cited' => 'uncited'])));
        self::assertSame(['a'], $ids($store->conversations(['cited' => 'cited'])));
        self::assertSame(['c'], $ids($store->conversations(['ip_hash' => '1111222233334444'])));
        $from = gmdate('Y-m-d', time() - 4 * 86400);
        self::assertSame(['c', 'b'], $ids($store->conversations(['from' => $from])));
        self::assertSame(2, $store->countConversations(['from' => $from]));
        self::assertSame(['a'], $ids($store->conversations(['to' => gmdate('Y-m-d', time() - 5 * 86400)])));
        self::assertCount(3, $store->conversations(['ip_hash' => "' OR 1=1 --"]), 'a malformed filter value is ignored, never put in SQL');

        $out = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        $batches = 0;
        $rows = $store->export('', '', static function () use (&$batches): void {
            $batches++;
        }, 2);
        self::assertSame(6, $rows);
        self::assertSame(3, $batches, 'six rows in batches of two');
        self::assertSame(6, LogPage::writeCsv($out, $store, '', '', 4));
        rewind($out);
        $csv = (string) stream_get_contents($out);
        self::assertStringStartsWith('id,conversation_id,turn,role,text', $csv);
        self::assertStringContainsString("'=HYPERLINK", $csv, 'formula cells are neutralised');
        self::assertSame(7, substr_count(trim($csv), "\n") + 1);
    }

    /** T61, case 73. */
    public function testUninstallKeepsLogByDefaultAndRemovesItWhenOptedIn(): void
    {
        $store = new WpdbLogStore($this->db);
        $store->record($this->turn(str_repeat('a', 32), 1), 'full');
        (new WpdbCounterStore($this->db))->increment('day:messages', 1, time() + 100);
        update_option(Settings::OPTION, ['enabled' => true]);
        update_option(Settings::KEY_OPTION, 'k');
        \WpStub::$scheduled[Plugin::CRON_HOOK] = 1;

        $result = Uninstaller::run();
        self::assertFalse($result['log_deleted']);
        self::assertFalse(get_option(Settings::OPTION));
        self::assertFalse(get_option(Settings::KEY_OPTION));
        self::assertArrayNotHasKey(Plugin::CRON_HOOK, \WpStub::$scheduled);
        $tables = $this->db->get_col("SHOW TABLES LIKE 'wptest_sse_chat_%'");
        sort($tables);
        self::assertSame(['wptest_sse_chat_audit', 'wptest_sse_chat_conversations', 'wptest_sse_chat_messages'], $tables);

        update_option(Settings::OPTION, ['delete_log_on_uninstall' => true]);
        self::assertTrue(Uninstaller::run()['log_deleted']);
        self::assertSame([], $this->db->get_col("SHOW TABLES LIKE 'wptest_sse_chat_%'"));
    }

    /** T71, case 86 (tables): forget changes no row count and no text. */
    public function testForgetLeavesEveryRowAndMarksHidden(): void
    {
        $this->seedPosts();
        $service = $this->dbService();
        $token = $this->token($service);
        [, $e] = $this->ask(['session' => $token, 'message' => 'river dam'], [], ['REMOTE_ADDR' => '203.0.113.7'], $service);
        $conversation = (string) $e->of('meta')[0]['conversation'];
        $before = $this->tableCounts();
        $textsBefore = $this->db->get_col('SELECT text FROM wptest_sse_chat_messages ORDER BY id');
        $countersBefore = $this->db->get_results('SELECT bucket, window_start, count FROM wptest_sse_chat_counters WHERE bucket NOT LIKE "forget:%" ORDER BY bucket, window_start', ARRAY_A);

        $out = $service->forget($this->request('POST', (string) json_encode(['session' => $token, 'conversation' => $conversation])));
        self::assertSame(200, $out['status']);
        self::assertSame($before, $this->tableCounts());
        self::assertSame($textsBefore, $this->db->get_col('SELECT text FROM wptest_sse_chat_messages ORDER BY id'));
        self::assertSame($countersBefore, $this->db->get_results('SELECT bucket, window_start, count FROM wptest_sse_chat_counters WHERE bucket NOT LIKE "forget:%" ORDER BY bucket, window_start', ARRAY_A));
        $row = (new WpdbLogStore($this->db))->conversation($conversation);
        self::assertSame('1', (string) $row['hidden_by_reader']);
        self::assertNotNull($row['hidden_at']);

        // With working state gone, the log row still proves ownership; another session cannot hide it.
        $this->state->items = [];
        $other = $service->forget($this->request('POST', (string) json_encode(['session' => $this->token($service), 'conversation' => $conversation])));
        self::assertSame(404, $other['status']);
    }

    public function testPrivacyExportAndEraser(): void
    {
        $store = new WpdbLogStore($this->db);
        $store->record($this->turn(str_repeat('a', 32), 1, 9), 'full');
        $store->record($this->turn(str_repeat('b', 32), 1, 0), 'full');
        update_option('__stub_user_email_reader@example.org', 9);
        $export = Privacy::export('reader@example.org');
        self::assertCount(2, $export['data']);
        self::assertTrue($export['done']);
        self::assertSame(['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true], Privacy::erase('reader@example.org'), 'needs an administrator');
        \WpStub::$caps['erase_others_personal_data'] = true;
        self::assertTrue(Privacy::erase('reader@example.org')['items_removed']);
        self::assertSame(1, $store->count('conversations'));
        self::assertSame('privacy_eraser', $this->db->get_var('SELECT route FROM wptest_sse_chat_audit'));
    }

    public function testSchemaVerificationFindsMissingColumns(): void
    {
        $schema = new Schema($this->db);
        self::assertSame([], $schema->verify());
        $this->db->query('ALTER TABLE wptest_sse_chat_messages DROP COLUMN notes');
        self::assertSame(['sse_chat_messages.notes'], $schema->verify());
    }
}
