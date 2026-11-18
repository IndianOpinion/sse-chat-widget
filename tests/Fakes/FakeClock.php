<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Fakes;

use IndianOpinion\SseChatWidget\Support\Clock;

final class FakeClock implements Clock
{
    public function __construct(public float $t = 1_789_000_000.0)
    {
    }

    public function now(): int
    {
        return (int) floor($this->t);
    }

    public function micro(): float
    {
        return $this->t;
    }

    public function advance(float $seconds): void
    {
        $this->t += $seconds;
    }
}
