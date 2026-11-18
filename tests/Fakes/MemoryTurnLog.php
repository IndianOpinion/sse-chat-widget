<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Fakes;

use IndianOpinion\SseChatWidget\Log\LogWriteFailed;
use IndianOpinion\SseChatWidget\Log\TurnLog;
use IndianOpinion\SseChatWidget\Log\TurnRecord;

final class MemoryTurnLog implements TurnLog
{
    /** @var list<array{0: TurnRecord, 1: string}> */
    public array $turns = [];
    /** @var array<string, string> conversation => session hash */
    public array $owners = [];
    /** @var array<string, bool> */
    public array $hidden = [];
    public bool $broken = false;

    public function record(TurnRecord $turn, string $mode): void
    {
        if ($this->broken) {
            throw new LogWriteFailed('disk full');
        }
        $this->turns[] = [$turn, $mode];
        $this->owners[$turn->conversationId] = $turn->sessionHash;
    }

    public function hide(string $conversationId, string $sessionHash, int $now): bool
    {
        if (($this->owners[$conversationId] ?? null) !== $sessionHash) {
            return false;
        }
        $this->hidden[$conversationId] = true;
        return true;
    }

    public function last(): TurnRecord
    {
        return $this->turns[count($this->turns) - 1][0];
    }
}
