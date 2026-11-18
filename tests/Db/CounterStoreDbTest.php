<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Db;

use IndianOpinion\SseChatWidget\Http\Refusal;
use IndianOpinion\SseChatWidget\Limits\CounterUnavailable;
use IndianOpinion\SseChatWidget\Limits\Limiter;
use IndianOpinion\SseChatWidget\Limits\Reservation;
use IndianOpinion\SseChatWidget\Limits\WpdbCounterStore;
use IndianOpinion\SseChatWidget\Plugin;
use IndianOpinion\SseChatWidget\Support\SystemClock;

final class CounterStoreDbTest extends DbTestCase
{
    public function testIncrementIsAtomicUpsertReturningPosition(): void
    {
        $a = new WpdbCounterStore($this->db);
        $b = new WpdbCounterStore(new \wpdb(self::connect(), 'wptest_'));
        self::assertSame(1, $a->increment('ip:x', 10, 2000));
        self::assertSame(2, $b->increment('ip:x', 10, 2000));
        self::assertSame(3, $a->increment('ip:x', 10, 1000), 'expiry only grows');
        self::assertSame(8, $a->increment('ip:x', 10, 2000, 5));
        $a->decrement('ip:x', 10);
        self::assertSame(7, $b->get('ip:x', 10));
        $a->decrement('ip:x', 10, 100);
        self::assertSame(0, $a->get('ip:x', 10), 'never below zero');
        $a->increment('ip:x', 11, 2000);
        $a->increment('ip:x', 12, 2000);
        self::assertSame([11 => 1, 12 => 1], $a->range('ip:x', 10, 12));

        $a->addSlot('slot:site', 5, 1500);
        $a->addSlot('slot:site', 6, 900);
        self::assertSame(1, $a->liveSlots('slot:site', 1000));
        $a->removeSlot('slot:site', 5);
        self::assertSame(0, $a->liveSlots('slot:site', 1000));
    }

    /** T24, case 26: two requests at the limit minus one, truly in parallel: exactly one passes. */
    public function testParallelRequestsAtTheLimitExactlyOnePasses(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is not available.');
        }
        $limit = 5;
        $settings = $this->settings(['per_ip_hour' => $limit, 'per_session_total' => 1000, 'per_session_10min' => 1000, 'daily_messages' => 100000]);
        $store = new WpdbCounterStore($this->db);
        $now = time();
        $minute = intdiv($now, 60);
        // limit - 1 already counted in earlier minutes of the window.
        $store->increment('ip:shared', $minute - 3, $now + 7200, $limit - 1);

        $workers = 6;
        $start = microtime(true) + 0.5;
        $pids = [];
        $dir = sys_get_temp_dir() . '/sse-chat-par-' . getmypid();
        @mkdir($dir);
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $conn = self::connect();
                $db = new \wpdb($conn, 'wptest_');
                $limiter = new Limiter(new WpdbCounterStore($db), $settings, new SystemClock());
                while (microtime(true) < $start) {
                    usleep(200);
                }
                $result = 'pass';
                try {
                    $limiter->reserve(new Reservation(new WpdbCounterStore($db)), 'shared', bin2hex(random_bytes(16)), time() + 3600);
                } catch (Refusal $r) {
                    $result = $r->errorCode;
                }
                file_put_contents($dir . '/' . $i, $result);
                // Leave without destructors or shutdown handlers: they would
                // close the parent's inherited connection and print reports.
                if (function_exists('posix_kill')) {
                    posix_kill(getmypid(), SIGKILL);
                }
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $results = [];
        for ($i = 0; $i < $workers; $i++) {
            $results[] = (string) file_get_contents($dir . '/' . $i);
            unlink($dir . '/' . $i);
        }
        rmdir($dir);
        sort($results);
        $store = new WpdbCounterStore(new \wpdb(self::connect(), 'wptest_'));
        self::assertSame(['pass', 'rate_ip', 'rate_ip', 'rate_ip', 'rate_ip', 'rate_ip'], $results);
        // Refused requests gave their counts back.
        self::assertSame($limit, array_sum($store->range('ip:shared', $minute - 59, $minute + 1)));
    }

    /** T65, case 79: daily totals are pruned after 400 days; log rows are untouched. */
    public function testPruningRemovesOnlyExpiredCounters(): void
    {
        $store = new WpdbCounterStore($this->db);
        $now = time();
        $store->increment('day:messages', 20250101, $now - 10);
        $store->increment('day:messages', 20261115, $now + 400 * 86400);
        $this->db->query("INSERT INTO wptest_sse_chat_conversations (id, started_at, last_activity) VALUES ('" . str_repeat('a', 32) . "', '2020-01-01 00:00:00', '2020-01-01 00:00:00')");
        Plugin::hourly();
        self::assertSame(0, $store->get('day:messages', 20250101));
        self::assertSame(1, $store->get('day:messages', 20261115));
        self::assertSame(1, (int) $this->db->get_var('SELECT COUNT(*) FROM wptest_sse_chat_conversations'));
    }

    /** T66 (store side), case 81. */
    public function testDeadDatabaseThrowsCounterUnavailable(): void
    {
        $this->expectException(CounterUnavailable::class);
        (new WpdbCounterStore(new \wpdb(null, 'wptest_')))->increment('ip:x', 1, 2);
    }
}
