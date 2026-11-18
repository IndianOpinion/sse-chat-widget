<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Unit;

use IndianOpinion\SseChatWidget\Model\ChatRequest;
use IndianOpinion\SseChatWidget\Model\ErrorMapper;
use IndianOpinion\SseChatWidget\Model\Replay;
use IndianOpinion\SseChatWidget\Model\SseLineParser;
use IndianOpinion\SseChatWidget\Model\StreamingCaller;
use IndianOpinion\SseChatWidget\Model\Transport\WpHttpTransport;
use IndianOpinion\SseChatWidget\Tests\Fakes\FakeTransport;
use IndianOpinion\SseChatWidget\Tests\Fakes\ScriptedCaller;
use IndianOpinion\SseChatWidget\Tests\TestCase;

final class StreamingCallerTest extends TestCase
{
    private function chatRequest(): ChatRequest
    {
        return new ChatRequest([['role' => 'system', 'content' => 's'], ['role' => 'user', 'content' => 'q']], 'm-1', 600, 0.3);
    }

    /**
     * @return list<string> The recorded stream cut into awkward pieces.
     */
    private static function recorded(int $size): array
    {
        $raw = (string) file_get_contents(dirname(__DIR__) . '/fixtures/chat-completions-stream.txt');
        return str_split(str_replace("\n", "\r\n", $raw), $size);
    }

    /** T42, case 52. */
    public function testParsesRecordedStreamWithUsageAndDone(): void
    {
        foreach ([7, 64, 4096] as $size) {
            $transport = new FakeTransport(true, 200, self::recorded($size));
            $caller = new StreamingCaller($transport, 'https://llm.example.net/v1/', 'sk-secret', 90);
            $deltas = [];
            $result = $caller->stream($this->chatRequest(), static function (string $t) use (&$deltas): void {
                $deltas[] = $t;
            }, static fn (): bool => false);
            self::assertSame(['The dam ', 'opened in 2019 [', '1].'], $deltas, 'chunk size ' . $size);
            self::assertSame('The dam opened in 2019 [1].', $result->text);
            self::assertSame('stop', $result->finish);
            self::assertSame([812, 14], [$result->inputTokens, $result->outputTokens]);
            self::assertSame('chatcmpl-1', $result->requestId);
            self::assertNull($result->errorCode);
        }
        $sent = $transport->requests[0];
        self::assertSame('https://llm.example.net/v1/chat/completions', $sent['url']);
        self::assertSame('Bearer sk-secret', $sent['headers']['Authorization']);
        $body = json_decode($sent['body'], true);
        self::assertTrue($body['stream']);
        self::assertSame(['include_usage' => true], $body['stream_options']);
        self::assertSame(600, $body['max_tokens']);
        self::assertSame('m-1', $body['model']);
    }

    /** T43, case 53: a host without a streaming transport gets a buffered replay. */
    public function testBufferedReplayWithoutStreamingTransport(): void
    {
        $whole = (string) json_encode([
            'id' => 'x',
            'choices' => [['message' => ['content' => str_repeat('word ', 30) . '[1]'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 31],
        ]);
        $transport = new FakeTransport(false, 200, [], $whole);
        $caller = new StreamingCaller($transport, 'https://llm.example.net/v1', 'k', 90);
        self::assertTrue($caller->isBuffered());
        $pieces = [];
        $result = $caller->stream($this->chatRequest(), static function (string $t) use (&$pieces): void {
            $pieces[] = $t;
        }, static fn (): bool => false);
        self::assertGreaterThan(3, count($pieces));
        foreach ($pieces as $p) {
            self::assertLessThanOrEqual(40, mb_strlen($p));
        }
        self::assertSame(str_repeat('word ', 30) . '[1]', implode('', $pieces));
        self::assertFalse(json_decode($transport->requests[0]['body'], true)['stream']);
        self::assertSame(31, $result->outputTokens);

        // Through the service, meta says buffered.
        $this->seedPosts();
        $this->caller = $caller;
        [, $e] = $this->ask(['message' => 'river dam']);
        self::assertTrue($e->of('meta')[0]['buffered']);
        self::assertFalse((new WpHttpTransport())->streams());
    }

    /** T44, case 54. */
    public function testUpstreamFailuresMapToCodes(): void
    {
        $cases = [
            [401, '{"error":{"message":"Invalid API key"}}', 'provider_auth'],
            [403, 'forbidden', 'provider_auth'],
            [402, '{"error":{"message":"Payment required"}}', 'provider_credit'],
            [400, '{"error":{"message":"You exceeded your current quota"}}', 'provider_credit'],
            [429, '{"error":{"message":"Rate limit reached"}}', 'provider_busy'],
            [500, 'Internal error', 'provider_down'],
            [null, '', 'provider_down'],
        ];
        foreach ($cases as [$status, $body, $code]) {
            $caller = new StreamingCaller(new FakeTransport(true, $status, [], $body), 'https://llm.example.net/v1', 'k', 90);
            $deltas = 0;
            $result = $caller->stream($this->chatRequest(), static function () use (&$deltas): void {
                $deltas++;
            }, static fn (): bool => false);
            self::assertSame($code, $result->errorCode, (string) $status);
            self::assertSame(0, $deltas, 'an error body is never parsed as SSE');
        }
        self::assertSame('provider_credit', ErrorMapper::fromStatus(429, 'insufficient_quota'));

        // Provider text never reaches the browser.
        $this->seedPosts();
        $this->caller = new StreamingCaller(new FakeTransport(true, 401, [], '{"error":{"message":"Invalid API key sk-abc"}}'), 'https://llm.example.net/v1', 'k', 90);
        [, $e] = $this->ask(['message' => 'river dam']);
        $json = (string) json_encode($e->events);
        self::assertStringNotContainsString('Invalid API key', $json);
        self::assertSame('provider_auth', $e->of('error')[0]['code']);
        self::assertContains('provider:Invalid API key sk-abc', $this->log->last()->notes, 'it goes to the log');
    }

    /** T45 (caller side): connection failure and 5xx are retryable before a delta; 4xx are not. */
    public function testRetryabilityOfResults(): void
    {
        foreach ([[null, true], [500, true], [503, true], [400, false], [401, false], [402, false], [429, false]] as [$status, $retry]) {
            $result = (new StreamingCaller(new FakeTransport(true, $status, [], 'x'), 'https://a.example/v1', 'k', 90))
                ->stream($this->chatRequest(), static function (): void {
                }, static fn (): bool => false);
            self::assertSame($retry, $result->retryable(), (string) $status);
        }
    }

    /** T46, case 56. */
    public function testErrorFrameInside200EndsAnswer(): void
    {
        $chunks = [
            "data: {\"choices\":[{\"delta\":{\"content\":\"Partial \"}}]}\n\n",
            "data: {\"error\":{\"code\":402,\"message\":\"Insufficient credits\"}}\n\n",
            "data: {\"choices\":[{\"delta\":{\"content\":\"never\"}}]}\n\n",
        ];
        $result = (new StreamingCaller(new FakeTransport(true, 200, $chunks), 'https://a.example/v1', 'k', 90))
            ->stream($this->chatRequest(), static function (): void {
            }, static fn (): bool => false);
        self::assertSame('provider_credit', $result->errorCode);
        self::assertSame('Partial ', $result->text);
        self::assertFalse($result->retryable());
    }

    public function testStopIsPolledWhileIdleAndBetweenChunks(): void
    {
        $polls = 0;
        $transport = new FakeTransport(true, 200, self::recorded(30), '', null, 3);
        $result = (new StreamingCaller($transport, 'https://a.example/v1', 'k', 90))
            ->stream($this->chatRequest(), static function (): void {
            }, static function () use (&$polls): bool {
                return ++$polls > 5;
            });
        self::assertSame('aborted', $result->finish);
        self::assertLessThan(count(self::recorded(30)), $transport->chunksRead, 'stopped reading early');
    }

    public function testParserAndReplayHelpers(): void
    {
        $p = new SseLineParser();
        self::assertSame([], $p->feed("data: a\r\n"));
        self::assertSame(["a\nb"], $p->feed("data: b\r\n\r\n"));
        self::assertSame(['tail'], (new SseLineParser())->finish() + (function () {
            $q = new SseLineParser();
            $q->feed('data: tail');
            return $q->finish();
        })());
        self::assertSame(['abc'], Replay::pieces('abc'));
        self::assertSame('', implode('', Replay::pieces('')));
        self::assertSame('ä' . str_repeat('ö', 50), implode('', Replay::pieces('ä' . str_repeat('ö', 50))));
        self::assertInstanceOf(ScriptedCaller::class, ScriptedCaller::answer('x'));
    }

    /** Case 49: the upstream timeout never runs past the time left before the stream deadline. */
    public function testTransportTimeoutIsCappedByTheRequest(): void
    {
        $transport = new FakeTransport(true, 200, ["data: [DONE]\n\n"]);
        $caller = new StreamingCaller($transport, 'https://llm.example.net/v1', 'k', 95);
        $caller->stream(new ChatRequest([['role' => 'user', 'content' => 'q']], 'm', 100, 0.3, 12), static function (): void {
        }, static fn (): bool => false);
        $caller->stream(new ChatRequest([['role' => 'user', 'content' => 'q']], 'm', 100, 0.3), static function (): void {
        }, static fn (): bool => false);
        self::assertSame([12, 95], array_column($transport->requests, 'timeout'));
    }

    /** Secrets in logs: a provider error body that echoes the key is scrubbed before it is logged. */
    public function testProviderTextNeverCarriesTheKey(): void
    {
        $key = 'sk-live-0123456789abcdef';
        $body = '{"error":{"message":"Bad key ' . $key . ' (Authorization: Bearer ' . $key . ')"}}';
        $caller = new StreamingCaller(new FakeTransport(true, 401, [], $body), 'https://llm.example.net/v1', $key, 90);
        $result = $caller->stream($this->chatRequest(), static function (): void {
        }, static fn (): bool => false);
        self::assertSame('provider_auth', $result->errorCode);
        self::assertStringNotContainsString($key, (string) $result->providerMessage);
        self::assertStringContainsString('[key]', (string) $result->providerMessage);
    }
}
