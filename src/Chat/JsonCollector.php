<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Chat;

/**
 * Collects the events for a client that asked for one JSON body (case 47).
 */
final class JsonCollector implements Emitter
{
    /** @var array<string, mixed> */
    private array $body = ['meta' => null, 'text' => '', 'citations' => ['sources' => [], 'related' => []], 'error' => null, 'done' => null];

    private float $lastWrite;

    public function __construct()
    {
        $this->lastWrite = microtime(true);
    }

    public function start(): void
    {
    }

    public function event(string $name, array $data): void
    {
        $this->lastWrite = microtime(true);
        switch ($name) {
            case 'delta':
                $this->body['text'] .= is_string($data['text'] ?? null) ? $data['text'] : '';
                break;
            case 'meta':
            case 'citations':
            case 'error':
            case 'done':
                $this->body[$name] = $data;
                break;
        }
    }

    public function comment(string $text): void
    {
    }

    public function aborted(): bool
    {
        return connection_aborted() === 1;
    }

    public function idleSeconds(float $now): float
    {
        return $now - $this->lastWrite;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }
}
