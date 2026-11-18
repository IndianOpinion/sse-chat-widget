<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Http;

/**
 * A framework-free view of one HTTP request to a public route.
 */
final class IncomingRequest
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param array<string, string> $headers Header names in any case.
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        array $headers,
        public readonly string $body,
        public readonly array $server = [],
        public readonly int $userId = 0,
        public readonly bool $adminTest = false,
    ) {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower(str_replace('_', '-', (string) $k))] = (string) $v;
        }
        $this->headers = $h;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * True when the client asked for one JSON body instead of a stream (case 47).
     */
    public function wantsJson(): bool
    {
        $accept = strtolower($this->header('accept') ?? '');
        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/event-stream');
    }
}
