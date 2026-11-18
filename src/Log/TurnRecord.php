<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Log;

/**
 * One reader message and its answer, as the log stores it (case 68).
 */
final class TurnRecord
{
    /**
     * @param list<int> $retrieved
     * @param list<int> $cited
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly string $sessionHash,
        public readonly int $userId,
        public readonly string $ipHash,
        public readonly int $contextPost,
        public readonly int $turn,
        public readonly string $message,
        public readonly string $answer,
        public readonly array $retrieved,
        public readonly array $cited,
        public readonly string $outcome,
        public readonly string $model,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly ?int $firstTokenMs,
        public readonly int $totalMs,
        public readonly int $createdAt,
        public readonly array $notes = [],
    ) {
    }
}
