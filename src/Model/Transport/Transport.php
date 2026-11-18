<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model\Transport;

/**
 * HTTP POST for the built-in caller.
 */
interface Transport
{
    /** True when the body arrives in chunks as the server sends it. */
    public function streams(): bool;

    /**
     * @param array<string, string> $headers
     * @param callable(string): bool $onChunk Receives body bytes of a 200 response as they arrive (streaming
     *                                        transports only). Return false to abort.
     * @param callable(): bool $onIdle Polled about once a second. Return true to abort.
     */
    public function post(string $url, array $headers, string $body, callable $onChunk, callable $onIdle, int $timeoutSeconds): TransportResult;
}
