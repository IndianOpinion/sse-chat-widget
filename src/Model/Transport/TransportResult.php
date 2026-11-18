<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model\Transport;

final class TransportResult
{
    /**
     * @param int|null $status HTTP status, null when no response arrived.
     * @param string $body The body of a non-200 response, or the whole body from a non-streaming transport.
     * @param string|null $error Transport failure text (connection, DNS, TLS, timeout).
     * @param bool $aborted The callback asked to stop.
     * @param bool $firstByte At least one body byte arrived.
     */
    public function __construct(
        public readonly ?int $status,
        public readonly string $body = '',
        public readonly ?string $error = null,
        public readonly bool $aborted = false,
        public readonly bool $firstByte = false,
    ) {
    }
}
