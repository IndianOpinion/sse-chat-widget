<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Retrieval;

/**
 * One retrieved passage. The marker number is assigned when passages are
 * put into the prompt (1 to n).
 */
final class Passage
{
    public function __construct(
        public readonly int $postId,
        public readonly string $title,
        public readonly string $url,
        public readonly string $date,
        public readonly string $excerpt,
        public readonly float $score = 0.0,
        public readonly int $marker = 0,
    ) {
    }

    public function withMarker(int $marker): self
    {
        return new self($this->postId, $this->title, $this->url, $this->date, $this->excerpt, $this->score, $marker);
    }

    public function withScore(float $score): self
    {
        return new self($this->postId, $this->title, $this->url, $this->date, $this->excerpt, $score, $this->marker);
    }
}
