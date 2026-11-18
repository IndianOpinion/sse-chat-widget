<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Retrieval;

final class RetrievalQuery
{
    /**
     * @param list<string> $postTypes
     */
    public function __construct(
        public readonly string $text,
        public readonly ?int $contextPostId,
        public readonly int $limit,
        public readonly array $postTypes,
        public readonly int $passageWords = 120,
    ) {
    }
}
