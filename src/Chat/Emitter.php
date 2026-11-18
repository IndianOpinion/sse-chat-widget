<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Chat;

/**
 * Where events go once the checks have passed.
 */
interface Emitter
{
    /** Send headers and prepare output. Called once, before the first event. */
    public function start(): void;

    /**
     * @param array<string, mixed> $data
     */
    public function event(string $name, array $data): void;

    /** An SSE comment line, such as a keep-alive ping. */
    public function comment(string $text): void;

    /** True when the reader has gone. */
    public function aborted(): bool;

    /** Seconds since the last write, for keep-alive pings. */
    public function idleSeconds(float $now): float;
}
