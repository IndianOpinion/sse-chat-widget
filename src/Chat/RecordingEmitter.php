<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Chat;

use IndianOpinion\SseChatWidget\Support\Clock;

/**
 * Keeps events in memory. Used by the administrator's test question, the
 * WP-CLI ask command and the tests.
 */
final class RecordingEmitter implements Emitter
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $events = [];

    /** @var list<string> */
    public array $comments = [];

    public bool $started = false;

    /** @var (callable(): bool)|null */
    public $abortWhen = null;

    private float $lastWrite;

    public function __construct(private readonly Clock $clock)
    {
        $this->lastWrite = $clock->micro();
    }

    public function start(): void
    {
        $this->started = true;
    }

    public function event(string $name, array $data): void
    {
        $this->events[] = [$name, $data];
        $this->lastWrite = $this->clock->micro();
    }

    public function comment(string $text): void
    {
        $this->comments[] = $text;
        $this->events[] = ['comment', ['text' => $text]];
        $this->lastWrite = $this->clock->micro();
    }

    public function aborted(): bool
    {
        return $this->abortWhen !== null && ($this->abortWhen)();
    }

    public function idleSeconds(float $now): float
    {
        return $now - $this->lastWrite;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (array $e): string => $e[0], $this->events);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function of(string $name): array
    {
        $out = [];
        foreach ($this->events as [$n, $data]) {
            if ($n === $name) {
                $out[] = $data;
            }
        }
        return $out;
    }

    public function text(): string
    {
        return implode('', array_map(static fn (array $d): string => (string) $d['text'], $this->of('delta')));
    }
}
