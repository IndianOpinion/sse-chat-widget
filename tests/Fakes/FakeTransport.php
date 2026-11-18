<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Fakes;

use IndianOpinion\SseChatWidget\Model\Transport\Transport;
use IndianOpinion\SseChatWidget\Model\Transport\TransportResult;

/**
 * Plays a recorded response: a status, body chunks (for a streaming 200)
 * or a whole body, and optional idle polls before the first chunk.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, headers: array<string, string>, body: string, timeout: int}> */
    public array $requests = [];
    public int $chunksRead = 0;

    /**
     * @param list<string> $chunks
     */
    public function __construct(
        private readonly bool $streaming = true,
        private readonly ?int $status = 200,
        private readonly array $chunks = [],
        private readonly string $body = '',
        private readonly ?string $error = null,
        private readonly int $idlePolls = 0,
    ) {
    }

    public function streams(): bool
    {
        return $this->streaming;
    }

    public function post(string $url, array $headers, string $body, callable $onChunk, callable $onIdle, int $timeoutSeconds): TransportResult
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeoutSeconds];
        for ($i = 0; $i < $this->idlePolls; $i++) {
            if ($onIdle()) {
                return new TransportResult($this->status, '', null, true);
            }
        }
        if ($this->status === null) {
            return new TransportResult(null, '', $this->error ?? 'Could not resolve host');
        }
        if ($this->status !== 200 || !$this->streaming) {
            return new TransportResult($this->status, $this->body, null, false, $this->body !== '');
        }
        foreach ($this->chunks as $chunk) {
            $this->chunksRead++;
            if ($onChunk($chunk) === false) {
                return new TransportResult(200, '', null, true, true);
            }
        }
        return new TransportResult(200, '', $this->error, false, $this->chunks !== []);
    }
}
