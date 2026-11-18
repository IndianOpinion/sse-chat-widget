<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\State;

/**
 * Working conversation state (case 29). Not the log: it exists even when
 * logging is off, and its expiry is not log deletion.
 *
 * State shape: {session: string, turns: int, started: int, history: list<{role: string, content: string}>}
 */
interface WorkingState
{
    /**
     * @return array{session: string, turns: int, started: int, history: list<array{role: string, content: string}>}|null
     */
    public function get(string $conversationId): ?array;

    /**
     * @param array{session: string, turns: int, started: int, history: list<array{role: string, content: string}>} $state
     * @return bool False when the store could not keep it.
     */
    public function set(string $conversationId, array $state, int $ttl): bool;

    public function delete(string $conversationId): void;
}
