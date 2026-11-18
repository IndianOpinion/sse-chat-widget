<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Fakes;

use IndianOpinion\SseChatWidget\Model\ChatRequest;
use IndianOpinion\SseChatWidget\Model\ChatResult;
use IndianOpinion\SseChatWidget\Model\ModelCaller;

/**
 * A caller that plays scripted attempts. Each attempt is a list of chunks
 * and a final ChatResult template (error code, finish, tokens).
 */
final class ScriptedCaller implements ModelCaller
{
    /** @var list<ChatRequest> */
    public array $requests = [];

    /** @var list<array{chunks: list<string>, result?: array<string, mixed>, beforeChunk?: callable}> */
    public array $attempts;

    /**
     * @param list<array{chunks: list<string>, result?: array<string, mixed>, beforeChunk?: callable}> $attempts
     */
    public function __construct(array $attempts)
    {
        $this->attempts = $attempts;
    }

    public static function answer(string ...$chunks): self
    {
        return new self([['chunks' => $chunks, 'result' => ['input' => 100, 'output' => 20]]]);
    }

    public function stream(ChatRequest $request, callable $onDelta, callable $shouldStop): ChatResult
    {
        $this->requests[] = $request;
        $attempt = $this->attempts[count($this->requests) - 1] ?? $this->attempts[count($this->attempts) - 1];
        $r = $attempt['result'] ?? [];
        $text = '';
        $deltas = 0;
        foreach ($attempt['chunks'] as $i => $chunk) {
            if (isset($attempt['beforeChunk'])) {
                ($attempt['beforeChunk'])($i);
            }
            if ($shouldStop()) {
                return new ChatResult($text, 'aborted', $r['input'] ?? null, $r['output'] ?? null, 200, null, null, null, $deltas);
            }
            $text .= $chunk;
            $deltas++;
            $onDelta($chunk);
        }
        return new ChatResult(
            $text,
            $r['finish'] ?? 'stop',
            $r['input'] ?? null,
            $r['output'] ?? null,
            array_key_exists('status', $r) ? $r['status'] : 200,
            'req-1',
            $r['error'] ?? null,
            $r['provider'] ?? null,
            $deltas,
            $r['started'] ?? true,
            $r['cited'] ?? null
        );
    }
}
