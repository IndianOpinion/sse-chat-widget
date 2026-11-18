<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Db;

use IndianOpinion\SseChatWidget\Log\Schema;
use IndianOpinion\SseChatWidget\Tests\TestCase;

/**
 * Database tests run against a real MySQL or MariaDB. Point them at a
 * throwaway server with SSE_CHAT_TEST_DB_SOCKET, or with
 * SSE_CHAT_TEST_DB_HOST, _USER, _PASS and _NAME. Without one they skip.
 */
abstract class DbTestCase extends TestCase
{
    protected \wpdb $db;

    public static function connect(): ?\mysqli
    {
        $socket = getenv('SSE_CHAT_TEST_DB_SOCKET') ?: null;
        $host = getenv('SSE_CHAT_TEST_DB_HOST') ?: null;
        if ($socket === null && $host === null) {
            return null;
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = new \mysqli(
            $socket !== null ? 'localhost' : (string) $host,
            getenv('SSE_CHAT_TEST_DB_USER') ?: 'root',
            getenv('SSE_CHAT_TEST_DB_PASS') ?: '',
            getenv('SSE_CHAT_TEST_DB_NAME') ?: 'sse_chat_test',
            (int) (getenv('SSE_CHAT_TEST_DB_PORT') ?: 3306),
            $socket
        );
        if ($conn->connect_errno !== 0) {
            return null;
        }
        $conn->set_charset('utf8mb4');
        return $conn;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $conn = self::connect();
        if ($conn === null) {
            self::markTestSkipped('No test database configured (SSE_CHAT_TEST_DB_SOCKET or SSE_CHAT_TEST_DB_HOST).');
        }
        $this->db = new \wpdb($conn, 'wptest_');
        $GLOBALS['wpdb'] = $this->db;
        foreach (array_keys(Schema::COLUMNS) as $table) {
            $this->db->query("DROP TABLE IF EXISTS wptest_{$table}");
        }
        $missing = (new Schema($this->db))->install();
        self::assertSame([], $missing);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new \wpdb(null);
        parent::tearDown();
    }

    /**
     * @return array<string, int>
     */
    protected function tableCounts(): array
    {
        $out = [];
        foreach (['sse_chat_conversations', 'sse_chat_messages', 'sse_chat_audit'] as $t) {
            $out[$t] = (int) $this->db->get_var("SELECT COUNT(*) FROM wptest_{$t}");
        }
        return $out;
    }
}
