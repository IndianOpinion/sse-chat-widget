<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Support;

interface Clock
{
    /** Unix time in seconds. */
    public function now(): int;

    /** Monotonic-enough time in seconds with fractions, for durations. */
    public function micro(): float;
}
