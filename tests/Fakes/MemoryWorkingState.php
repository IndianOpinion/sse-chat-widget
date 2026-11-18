<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Fakes;

use IndianOpinion\SseChatWidget\State\WorkingState;

final class MemoryWorkingState implements WorkingState
{
    /** @var array<string, array{session: string, turns: int, started: int, history: list<array{role: string, content: string}>}> */
    public array $items = [];
    public bool $broken = false;

    public function get(string $conversationId): ?array
    {
        return $this->broken ? null : ($this->items[$conversationId] ?? null);
    }

    public function set(string $conversationId, array $state, int $ttl): bool
    {
        if ($this->broken) {
            return false;
        }
        $this->items[$conversationId] = $state;
        return true;
    }

    public function delete(string $conversationId): void
    {
        unset($this->items[$conversationId]);
    }
}
