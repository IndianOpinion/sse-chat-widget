<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model;

final class ChatRequest
{
    /**
     * @param list<array{role: string, content: string}> $messages
     */
    public function __construct(
        public readonly array $messages,
        public readonly string $model,
        public readonly int $maxOutputTokens,
        public readonly float $temperature,
    ) {
    }
}
