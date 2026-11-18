<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model;

/**
 * Mutable state while one upstream stream is read.
 *
 * @internal
 */
final class StreamState
{
    public string $text = '';
    public int $deltas = 0;
    public bool $done = false;
    public ?string $finish = null;
    public ?int $inputTokens = null;
    public ?int $outputTokens = null;
    public ?string $requestId = null;
    public ?string $errorCode = null;
    public ?string $providerMessage = null;

    /**
     * @param array<mixed> $json
     */
    public function readUsage(array $json): void
    {
        $usage = $json['usage'] ?? null;
        if (!is_array($usage)) {
            return;
        }
        if (is_numeric($usage['prompt_tokens'] ?? null)) {
            $this->inputTokens = (int) $usage['prompt_tokens'];
        }
        if (is_numeric($usage['completion_tokens'] ?? null)) {
            $this->outputTokens = (int) $usage['completion_tokens'];
        }
    }

    public function result(?int $status, string $finish, ?string $errorCode = null, ?string $providerMessage = null): ChatResult
    {
        return new ChatResult(
            $this->text,
            $finish,
            $this->inputTokens,
            $this->outputTokens,
            $status,
            $this->requestId,
            $errorCode,
            $providerMessage,
            $this->deltas,
            true
        );
    }
}
