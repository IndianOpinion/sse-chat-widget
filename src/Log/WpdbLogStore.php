<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Log;

/**
 * The conversation log in the plugin's own tables.
 *
 * The plugin never deletes log rows on its own (case 70). The only methods
 * here that delete are called from an administrator's explicit action
 * (case 71), and each writes one audit entry.
 */
final class WpdbLogStore implements TurnLog
{
    public const BATCH = 500;

    private string $conversations;
    private string $messages;
    private string $audit;

    public function __construct(private readonly \wpdb $db)
    {
        $this->conversations = $db->prefix . 'sse_chat_conversations';
        $this->messages = $db->prefix . 'sse_chat_messages';
        $this->audit = $db->prefix . 'sse_chat_audit';
    }

    public function record(TurnRecord $turn, string $mode): void
    {
        $at = gmdate('Y-m-d H:i:s', $turn->createdAt);
        $keepText = $mode === 'full';
        $uncited = $turn->cited === [] && $turn->answer !== '' ? 1 : 0;

        $sql = $this->db->prepare(
            "INSERT INTO {$this->conversations}
               (id, session_hash, user_id, ip_hash, context_post, started_at, last_activity, turns, outcome, uncited_answers, hidden_by_reader)
             VALUES (%s, %s, %d, %s, %d, %s, %s, %d, %s, %d, 0)
             ON DUPLICATE KEY UPDATE last_activity = %s, turns = GREATEST(turns, %d), outcome = %s,
               uncited_answers = uncited_answers + %d",
            $turn->conversationId,
            $turn->sessionHash,
            $turn->userId,
            $turn->ipHash,
            $turn->contextPost,
            $at,
            $at,
            $turn->turn,
            $turn->outcome,
            $uncited,
            $at,
            $turn->turn,
            $turn->outcome,
            $uncited
        );
        $this->write($sql);

        $common = [
            'conversation_id' => $turn->conversationId,
            'turn' => $turn->turn,
            'retrieved' => self::ids($turn->retrieved),
            'cited' => self::ids($turn->cited),
            'outcome' => $turn->outcome,
            'model' => mb_substr($turn->model, 0, 128),
            'notes' => mb_substr(implode(',', $turn->notes), 0, 255),
            'created_at' => $at,
        ];
        $rows = [
            $common + ['role' => 'user', 'text' => $keepText ? $turn->message : null],
            $common + [
                'role' => 'assistant',
                'text' => $keepText ? $turn->answer : null,
                'input_tokens' => $turn->inputTokens,
                'output_tokens' => $turn->outputTokens,
                'first_token_ms' => $turn->firstTokenMs,
                'total_ms' => $turn->totalMs,
            ],
        ];
        foreach ($rows as $row) {
            $filtered = apply_filters('sse_chat_log_row', $row);
            if (is_array($filtered)) {
                $row = array_intersect_key($filtered, $row);
            }
            if ($this->db->insert($this->messages, $row) === false) {
                throw new LogWriteFailed('Message insert failed: ' . $this->db->last_error);
            }
        }
    }

    public function hide(string $conversationId, string $sessionHash, int $now): bool
    {
        $found = $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->conversations} WHERE id = %s AND session_hash = %s",
            $conversationId,
            $sessionHash
        ));
        if ((int) $found === 0) {
            return false;
        }
        $this->write($this->db->prepare(
            "UPDATE {$this->conversations} SET hidden_by_reader = 1, hidden_at = COALESCE(hidden_at, %s) WHERE id = %s AND session_hash = %s",
            gmdate('Y-m-d H:i:s', $now),
            $conversationId,
            $sessionHash
        ));
        return true;
    }

    /**
     * Conversations past retention (case 70). Zero retention means keep
     * forever, so nothing is eligible.
     */
    public function eligibleCount(int $retentionDays, int $now): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }
        return (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->conversations} WHERE last_activity < %s",
            self::cutoff($retentionDays, $now)
        ));
    }

    /**
     * Delete conversations (and their messages) whose last activity is older
     * than $days. Only ever called from an administrator's action.
     *
     * @return int Conversations deleted.
     */
    public function deleteOlderThan(int $days, int $now, int $userId, string $route): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = self::cutoff($days, $now);
        $total = 0;
        do {
            $ids = $this->db->get_col($this->db->prepare(
                "SELECT id FROM {$this->conversations} WHERE last_activity < %s ORDER BY last_activity ASC LIMIT %d",
                $cutoff,
                self::BATCH
            ));
            $ids = is_array($ids) ? array_map('strval', $ids) : [];
            if ($ids !== []) {
                $total += $this->deleteIds($ids);
            }
        } while (count($ids) === self::BATCH);
        $this->auditDeletion($total, $route, $userId, 'older_than=' . $days);
        return $total;
    }

    /**
     * Delete one conversation from the log screen.
     */
    public function deleteConversation(string $conversationId, int $userId, string $route): int
    {
        $n = $this->deleteIds([$conversationId]);
        $this->auditDeletion($n, $route, $userId, 'conversation=' . $conversationId);
        return $n;
    }

    /**
     * Personal data eraser for a logged-in user's rows (section 8).
     */
    public function deleteForUser(int $wpUserId, int $actingUserId, string $route): int
    {
        $ids = $this->db->get_col($this->db->prepare(
            "SELECT id FROM {$this->conversations} WHERE user_id = %d",
            $wpUserId
        ));
        $ids = is_array($ids) ? array_map('strval', $ids) : [];
        $n = $ids === [] ? 0 : $this->deleteIds($ids);
        $this->auditDeletion($n, $route, $actingUserId, 'user=' . $wpUserId);
        return $n;
    }

    /**
     * @param list<string> $ids
     */
    private function deleteIds(array $ids): int
    {
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));
        $this->write($this->db->prepare("DELETE FROM {$this->messages} WHERE conversation_id IN ($placeholders)", ...$ids));
        $deleted = $this->write($this->db->prepare("DELETE FROM {$this->conversations} WHERE id IN ($placeholders)", ...$ids));
        return is_int($deleted) ? $deleted : 0;
    }

    private function auditDeletion(int $count, string $route, int $userId, string $detail): void
    {
        $this->audit('delete', $route, $count, $userId, $detail);
        do_action('sse_chat_log_deleted', $count, $route, $userId);
    }

    /**
     * Append one audit entry. The plugin never deletes audit entries except
     * when the administrator opted in to deleting the log at uninstall.
     */
    public function audit(string $action, string $route, int $count, int $userId, string $detail = ''): void
    {
        $this->db->insert($this->audit, [
            'created_at' => gmdate('Y-m-d H:i:s'),
            'user_id' => $userId,
            'route' => mb_substr($route, 0, 32),
            'action' => mb_substr($action, 0, 32),
            'count' => $count,
            'detail' => mb_substr($detail, 0, 255),
        ]);
    }

    /**
     * Conversations newest first with filters (case 72).
     *
     * @param array{from?: string, to?: string, outcome?: string, cited?: string, ip_hash?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function conversations(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $args] = $this->where($filters);
        $args[] = max(1, min(500, $limit));
        $args[] = max(0, $offset);
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->conversations} c WHERE $where ORDER BY c.last_activity DESC, c.id ASC LIMIT %d OFFSET %d",
            ...$args
        ), ARRAY_A);
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param array{from?: string, to?: string, outcome?: string, cited?: string, ip_hash?: string} $filters
     */
    public function countConversations(array $filters): int
    {
        [$where, $args] = $this->where($filters);
        $sql = "SELECT COUNT(*) FROM {$this->conversations} c WHERE $where";
        return (int) $this->db->get_var($args === [] ? $sql : $this->db->prepare($sql, ...$args));
    }

    /**
     * @param array{from?: string, to?: string, outcome?: string, cited?: string, ip_hash?: string} $filters
     * @return array{0: string, 1: list<string|int>}
     */
    private function where(array $filters): array
    {
        $where = ['1=1'];
        $args = [];
        if (isset($filters['from']) && self::isDate($filters['from'])) {
            $where[] = 'c.last_activity >= %s';
            $args[] = $filters['from'] . ' 00:00:00';
        }
        if (isset($filters['to']) && self::isDate($filters['to'])) {
            $where[] = 'c.last_activity <= %s';
            $args[] = $filters['to'] . ' 23:59:59';
        }
        if (isset($filters['outcome']) && preg_match('/^[a-z_]{1,32}$/', $filters['outcome']) === 1) {
            $where[] = 'c.outcome = %s';
            $args[] = $filters['outcome'];
        }
        if (($filters['cited'] ?? '') === 'uncited') {
            $where[] = 'c.uncited_answers > 0';
        } elseif (($filters['cited'] ?? '') === 'cited') {
            $where[] = 'c.uncited_answers = 0';
        }
        if (isset($filters['ip_hash']) && preg_match('/^[0-9a-f]{16}$/', $filters['ip_hash']) === 1) {
            $where[] = 'c.ip_hash = %s';
            $args[] = $filters['ip_hash'];
        }
        return [implode(' AND ', $where), $args];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function conversation(string $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->conversations} WHERE id = %s", $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function turns(string $conversationId): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->messages} WHERE conversation_id = %s ORDER BY id ASC",
            $conversationId
        ), ARRAY_A);
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Stream message rows for a date range in batches (case 72). Calls
     * $emit once per batch.
     *
     * @param callable(list<array<string, mixed>>): void $emit
     * @return int Rows emitted.
     */
    public function export(string $from, string $to, callable $emit, int $batch = self::BATCH): int
    {
        $last = 0;
        $total = 0;
        $from = self::isDate($from) ? $from . ' 00:00:00' : '1970-01-01 00:00:00';
        $to = self::isDate($to) ? $to . ' 23:59:59' : '9999-12-31 23:59:59';
        do {
            $rows = $this->db->get_results($this->db->prepare(
                "SELECT m.id, m.conversation_id, m.turn, m.role, m.text, m.retrieved, m.cited, m.outcome, m.model,
                        m.input_tokens, m.output_tokens, m.first_token_ms, m.total_ms, m.notes, m.created_at,
                        c.ip_hash, c.user_id, c.hidden_by_reader
                   FROM {$this->messages} m
                   LEFT JOIN {$this->conversations} c ON c.id = m.conversation_id
                  WHERE m.id > %d AND m.created_at BETWEEN %s AND %s
                  ORDER BY m.id ASC LIMIT %d",
                $last,
                $from,
                $to,
                $batch
            ), ARRAY_A);
            $rows = is_array($rows) ? array_values($rows) : [];
            if ($rows !== []) {
                $emit($rows);
                $total += count($rows);
                $last = (int) $rows[count($rows) - 1]['id'];
            }
        } while (count($rows) === $batch);
        return $total;
    }

    /**
     * Rows of one logged-in user, for the personal data exporter.
     *
     * @return list<array<string, mixed>>
     */
    public function rowsForUser(int $userId, int $limit, int $offset): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT m.id, m.conversation_id, m.role, m.text, m.created_at FROM {$this->messages} m
               JOIN {$this->conversations} c ON c.id = m.conversation_id
              WHERE c.user_id = %d ORDER BY m.id ASC LIMIT %d OFFSET %d",
            $userId,
            $limit,
            $offset
        ), ARRAY_A);
        return is_array($rows) ? array_values($rows) : [];
    }

    public function count(string $table): int
    {
        $map = ['conversations' => $this->conversations, 'messages' => $this->messages, 'audit' => $this->audit];
        if (!isset($map[$table])) {
            throw new \InvalidArgumentException('Unknown table.');
        }
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$map[$table]}");
    }

    private static function cutoff(int $days, int $now): string
    {
        return gmdate('Y-m-d H:i:s', $now - $days * 86400);
    }

    private static function isDate(string $s): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1;
    }

    /**
     * @param list<int> $ids
     */
    private static function ids(array $ids): string
    {
        return mb_substr(implode(',', array_map('intval', $ids)), 0, 255);
    }

    private function write(?string $sql): int|bool
    {
        if ($sql === null || $sql === '') {
            throw new LogWriteFailed('Log query could not be prepared.');
        }
        $result = $this->db->query($sql);
        if ($result === false) {
            throw new LogWriteFailed('Log write failed: ' . $this->db->last_error);
        }
        return $result;
    }
}
