<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model;

/**
 * What a model call produced. Callers return failures here with an error
 * code; they do not throw for provider failures.
 */
final class ChatResult
{
    /**
     * @param string $finish stop, length or aborted
     * @param list<int>|null $cited Passage numbers the caller reports as cited (buffered-json only).
     */
    public function __construct(
        public readonly string $text,
        public readonly string $finish,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $requestId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $providerMessage = null,
        public readonly int $deltas = 0,
        public readonly bool $started = true,
        public readonly ?array $cited = null,
    ) {
    }

    public static function failure(string $code, ?int $status, string $providerMessage, bool $started = true): self
    {
        return new self('', 'stop', null, null, $status, null, $code, $providerMessage, 0, $started);
    }

    /**
     * Retry once only before any delta, on a connection failure, a timeout
     * before the first byte, or a 5xx (case 55).
     */
    public function retryable(): bool
    {
        return $this->errorCode === 'provider_down'
            && $this->deltas === 0
            && ($this->httpStatus === null || $this->httpStatus >= 500);
    }
}
