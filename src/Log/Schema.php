<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Log;

/**
 * Tables, created through dbDelta and verified afterwards by reading the
 * columns back. dbDelta can skip a column silently, so a missing column
 * blocks logging and shows a notice rather than writing partial rows.
 */
final class Schema
{
    public const VERSION = '1';
    public const VERSION_OPTION = 'sse_chat_db_version';
    public const STATUS_OPTION = 'sse_chat_schema_status';

    public const COLUMNS = [
        'sse_chat_conversations' => ['id', 'session_hash', 'user_id', 'ip_hash', 'context_post', 'started_at', 'last_activity', 'turns', 'outcome', 'uncited_answers', 'hidden_by_reader', 'hidden_at'],
        'sse_chat_messages' => ['id', 'conversation_id', 'turn', 'role', 'text', 'retrieved', 'cited', 'outcome', 'model', 'input_tokens', 'output_tokens', 'first_token_ms', 'total_ms', 'notes', 'created_at'],
        'sse_chat_audit' => ['id', 'created_at', 'user_id', 'route', 'action', 'count', 'detail'],
        'sse_chat_counters' => ['bucket', 'window_start', 'count', 'expires'],
    ];

    public function __construct(private readonly \wpdb $db)
    {
    }

    /**
     * @return array<string, string> table suffix => CREATE TABLE statement
     */
    public function statements(): array
    {
        $c = $this->db->get_charset_collate();
        $p = $this->db->prefix;
        return [
            'sse_chat_conversations' => "CREATE TABLE {$p}sse_chat_conversations (
  id char(32) NOT NULL,
  session_hash char(32) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ip_hash char(16) NOT NULL DEFAULT '',
  context_post bigint(20) unsigned NOT NULL DEFAULT 0,
  started_at datetime NOT NULL,
  last_activity datetime NOT NULL,
  turns int(10) unsigned NOT NULL DEFAULT 0,
  outcome varchar(32) NOT NULL DEFAULT '',
  uncited_answers int(10) unsigned NOT NULL DEFAULT 0,
  hidden_by_reader tinyint(1) NOT NULL DEFAULT 0,
  hidden_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY last_activity (last_activity),
  KEY ip_hash (ip_hash),
  KEY user_id (user_id)
) $c;",
            'sse_chat_messages' => "CREATE TABLE {$p}sse_chat_messages (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id char(32) NOT NULL,
  turn int(10) unsigned NOT NULL DEFAULT 0,
  role varchar(16) NOT NULL,
  text longtext NULL,
  retrieved varchar(255) NOT NULL DEFAULT '',
  cited varchar(255) NOT NULL DEFAULT '',
  outcome varchar(32) NOT NULL DEFAULT '',
  model varchar(128) NOT NULL DEFAULT '',
  input_tokens int(10) unsigned NULL DEFAULT NULL,
  output_tokens int(10) unsigned NULL DEFAULT NULL,
  first_token_ms int(10) unsigned NULL DEFAULT NULL,
  total_ms int(10) unsigned NULL DEFAULT NULL,
  notes varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY conversation_id (conversation_id),
  KEY created_at (created_at)
) $c;",
            'sse_chat_audit' => "CREATE TABLE {$p}sse_chat_audit (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  route varchar(32) NOT NULL DEFAULT '',
  action varchar(32) NOT NULL DEFAULT '',
  count int(10) unsigned NOT NULL DEFAULT 0,
  detail varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) $c;",
            'sse_chat_counters' => "CREATE TABLE {$p}sse_chat_counters (
  bucket varchar(191) NOT NULL,
  window_start bigint(20) NOT NULL,
  count bigint(20) NOT NULL DEFAULT 0,
  expires bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (bucket,window_start),
  KEY expires (expires)
) $c;",
        ];
    }

    /**
     * Create or update the tables, then verify every column.
     *
     * @return list<string> Missing columns as table.column; empty when all is well.
     */
    public function install(): array
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        foreach ($this->statements() as $sql) {
            dbDelta($sql);
        }
        $missing = $this->verify();
        update_option(self::VERSION_OPTION, self::VERSION);
        update_option(self::STATUS_OPTION, $missing === [] ? 'ok' : implode(',', $missing), false);
        return $missing;
    }

    /**
     * @return list<string>
     */
    public function verify(): array
    {
        $missing = [];
        foreach (self::COLUMNS as $table => $columns) {
            $rows = $this->db->get_col("SHOW COLUMNS FROM {$this->db->prefix}{$table}", 0);
            $have = is_array($rows) ? array_map('strval', $rows) : [];
            foreach ($columns as $col) {
                if (!in_array($col, $have, true)) {
                    $missing[] = $table . '.' . $col;
                }
            }
        }
        return $missing;
    }

    public static function healthy(): bool
    {
        return get_option(self::STATUS_OPTION, '') === 'ok';
    }

    public static function needsInstall(): bool
    {
        return get_option(self::VERSION_OPTION, '') !== self::VERSION;
    }
}
