<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Chat;

use IndianOpinion\SseChatWidget\Retrieval\Passage;

/**
 * A stream request that passed every check.
 *
 * @internal
 */
final class CheckedRequest
{
    /**
     * @param array{session: string, turns: int, started: int, history: list<array{role: string, content: string}>}|null $state
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly ?array $state,
        public readonly string $sessionId,
        public readonly string $sessionHash,
        public readonly int $sessionExpires,
        public readonly string $message,
        public readonly ?Passage $contextPost,
        public readonly int $contextPostRequested,
        public readonly string $ipHash,
        public readonly int $userId,
        public readonly ?string $spendReservation,
        public readonly array $notes,
    ) {
    }
}
