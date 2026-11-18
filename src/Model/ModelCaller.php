<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model;

/**
 * A model caller. $onDelta(string $text): void receives text as it
 * arrives. $shouldStop(): bool is polled between chunks (and while idle);
 * when it returns true the caller stops reading at once and returns with
 * finish reason "aborted".
 */
interface ModelCaller
{
    public function stream(ChatRequest $request, callable $onDelta, callable $shouldStop): ChatResult;
}
