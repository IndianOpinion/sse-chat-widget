<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\State;

/**
 * Working state in the object cache when it is persistent, else in a
 * transient.
 */
final class CacheWorkingState implements WorkingState
{
    public const GROUP = 'sse_chat';
    public const TRANSIENT_PREFIX = 'sse_chat_c_';

    public function get(string $conversationId): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $conversationId) !== 1) {
            return null;
        }
        $value = $this->persistentCache()
            ? wp_cache_get($conversationId, self::GROUP)
            : get_transient(self::TRANSIENT_PREFIX . $conversationId);
        return self::shape($value);
    }

    public function set(string $conversationId, array $state, int $ttl): bool
    {
        if ($this->persistentCache()) {
            return (bool) wp_cache_set($conversationId, $state, self::GROUP, $ttl);
        }
        return (bool) set_transient(self::TRANSIENT_PREFIX . $conversationId, $state, $ttl);
    }

    public function delete(string $conversationId): void
    {
        if ($this->persistentCache()) {
            wp_cache_delete($conversationId, self::GROUP);
            return;
        }
        delete_transient(self::TRANSIENT_PREFIX . $conversationId);
    }

    private function persistentCache(): bool
    {
        return function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    }

    /**
     * @return array{session: string, turns: int, started: int, history: list<array{role: string, content: string}>}|null
     */
    public static function shape(mixed $value): ?array
    {
        if (!is_array($value) || !isset($value['session']) || !is_string($value['session'])) {
            return null;
        }
        $history = [];
        foreach (is_array($value['history'] ?? null) ? $value['history'] : [] as $m) {
            if (
                is_array($m) && isset($m['role'], $m['content']) && is_string($m['role']) && is_string($m['content'])
                && in_array($m['role'], ['user', 'assistant'], true)
            ) {
                $history[] = ['role' => $m['role'], 'content' => $m['content']];
            }
        }
        return [
            'session' => $value['session'],
            'turns' => is_int($value['turns'] ?? null) ? $value['turns'] : 0,
            'started' => is_int($value['started'] ?? null) ? $value['started'] : 0,
            'history' => $history,
        ];
    }
}
