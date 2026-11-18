<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Limits;

/**
 * Counters in the plugin's own table, updated with one atomic upsert each.
 *
 * increment() uses INSERT ... ON DUPLICATE KEY UPDATE with LAST_INSERT_ID(expr)
 * so the statement both adds and returns the new value on the same
 * connection, with no read-then-write race.
 */
final class WpdbCounterStore implements CounterStore
{
    private string $table;

    public function __construct(private readonly \wpdb $db)
    {
        $this->table = $db->prefix . 'sse_chat_counters';
    }

    public function table(): string
    {
        return $this->table;
    }

    public function increment(string $bucket, int $window, int $expires, int $by = 1): int
    {
        $sql = $this->db->prepare(
            "INSERT INTO {$this->table} (bucket, window_start, count, expires)
             VALUES (%s, %d, LAST_INSERT_ID(%d), %d)
             ON DUPLICATE KEY UPDATE count = LAST_INSERT_ID(count + %d), expires = GREATEST(expires, %d)",
            $bucket,
            $window,
            $by,
            $expires,
            $by,
            $expires
        );
        $this->run($sql);
        $value = $this->db->get_var('SELECT LAST_INSERT_ID()');
        if ($value === null || $this->db->last_error !== '') {
            throw new CounterUnavailable('Counter read failed: ' . $this->db->last_error);
        }
        return (int) $value;
    }

    public function decrement(string $bucket, int $window, int $by = 1): void
    {
        $this->run($this->db->prepare(
            "UPDATE {$this->table} SET count = IF(count >= %d, count - %d, 0) WHERE bucket = %s AND window_start = %d",
            $by,
            $by,
            $bucket,
            $window
        ));
    }

    public function get(string $bucket, int $window): int
    {
        $value = $this->db->get_var($this->db->prepare(
            "SELECT count FROM {$this->table} WHERE bucket = %s AND window_start = %d",
            $bucket,
            $window
        ));
        $this->assertOk();
        return $value === null ? 0 : (int) $value;
    }

    public function range(string $bucket, int $from, int $to): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT window_start, count FROM {$this->table} WHERE bucket = %s AND window_start BETWEEN %d AND %d AND count > 0",
            $bucket,
            $from,
            $to
        ), ARRAY_A);
        $this->assertOk();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(int) $row['window_start']] = (int) $row['count'];
        }
        ksort($out);
        return $out;
    }

    public function addSlot(string $bucket, int $slotId, int $expires): void
    {
        $this->run($this->db->prepare(
            "INSERT INTO {$this->table} (bucket, window_start, count, expires) VALUES (%s, %d, 1, %d)",
            $bucket,
            $slotId,
            $expires
        ));
    }

    public function liveSlots(string $bucket, int $now): int
    {
        $value = $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE bucket = %s AND expires > %d",
            $bucket,
            $now
        ));
        $this->assertOk();
        return (int) $value;
    }

    public function removeSlot(string $bucket, int $slotId): void
    {
        $this->run($this->db->prepare(
            "DELETE FROM {$this->table} WHERE bucket = %s AND window_start = %d",
            $bucket,
            $slotId
        ));
    }

    public function pruneExpired(int $now): int
    {
        $result = $this->run($this->db->prepare(
            "DELETE FROM {$this->table} WHERE expires < %d",
            $now
        ));
        return (int) $result;
    }

    private function run(?string $sql): int|bool
    {
        if ($sql === null || $sql === '') {
            throw new CounterUnavailable('Counter query could not be prepared.');
        }
        $result = $this->db->query($sql);
        if ($result === false || $this->db->last_error !== '') {
            throw new CounterUnavailable('Counter write failed: ' . $this->db->last_error);
        }
        return $result;
    }

    private function assertOk(): void
    {
        if ($this->db->last_error !== '') {
            throw new CounterUnavailable('Counter read failed: ' . $this->db->last_error);
        }
    }
}
