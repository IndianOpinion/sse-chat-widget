<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Log;

/**
 * What the chat path needs from the log.
 */
interface TurnLog
{
    /**
     * Write one turn. $mode is full or metadata; metadata stores no text.
     *
     * @throws LogWriteFailed
     */
    public function record(TurnRecord $turn, string $mode): void;

    /**
     * Mark a conversation hidden by the reader (case 86). Changes nothing
     * else and deletes nothing.
     *
     * @return bool True when a row owned by that session was found.
     */
    public function hide(string $conversationId, string $sessionHash, int $now): bool;
}
