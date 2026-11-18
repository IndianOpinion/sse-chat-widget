<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Http;

/**
 * A request refused before anything is streamed (section 4.2). Carries the
 * HTTP status, the public code and the reader-facing message.
 */
final class Refusal extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $displayMessage,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($displayMessage, $status);
    }

    /**
     * @return array{code: string, message: string}
     */
    public function body(): array
    {
        return ['code' => $this->errorCode, 'message' => $this->getMessage()];
    }
}
