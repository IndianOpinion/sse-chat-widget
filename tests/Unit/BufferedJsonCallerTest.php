<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Unit;

use IndianOpinion\LlmJson\Exception\AuthenticationException;
use IndianOpinion\LlmJson\Exception\RateLimitedException;
use IndianOpinion\SseChatWidget\Model\BufferedJsonCaller;
use IndianOpinion\SseChatWidget\Model\CallerFactory;
use IndianOpinion\SseChatWidget\Model\CallerFailure;
use IndianOpinion\SseChatWidget\Model\JsonAsker;
use IndianOpinion\SseChatWidget\Model\LlmJsonAsker;
use IndianOpinion\SseChatWidget\Model\StreamingCaller;
use IndianOpinion\SseChatWidget\Tests\Fakes\FakeTransport;
use IndianOpinion\SseChatWidget\Tests\TestCase;

/** T48, case 58. */
final class BufferedJsonCallerTest extends TestCase
{
    private function asker(array $data, ?CallerFailure $fail = null): JsonAsker
    {
        return new class ($data, $fail) implements JsonAsker {
            /** @var list<array<string, mixed>> */
            public array $calls = [];
            public function __construct(private array $data, private ?CallerFailure $fail)
            {
            }
            public function ask(array $messages, array $schema, int $maxOutputTokens, float $temperature, int $deadlineSeconds): array
            {
                $this->calls[] = compact('messages', 'schema', 'maxOutputTokens');
                if ($this->fail !== null) {
                    throw $this->fail;
                }
                return ['data' => $this->data, 'input_tokens' => 50, 'output_tokens' => 9];
            }
        };
    }

    public function testReplaysAnswerAndIntersectsCitedWithMarkersPresent(): void
    {
        $this->seedPosts();
        $asker = $this->asker(['answer' => 'The dam opened [1]. Council noted it [2].', 'cited' => [1, 3]]);
        $this->caller = new BufferedJsonCaller($asker);
        [, $e] = $this->ask(['message' => 'river dam council budget']);
        self::assertTrue($e->of('meta')[0]['buffered']);
        self::assertSame('The dam opened [1]. Council noted it [2].', $e->text());
        // [2] appears in the text but is not in cited; 3 is cited but not in the text.
        self::assertSame([1], array_map(static fn ($s) => $s['n'], $e->of('citations')[0]['sources']));
        self::assertSame(BufferedJsonCaller::SCHEMA, $asker->calls[0]['schema']);
        self::assertSame(600, $asker->calls[0]['maxOutputTokens']);
        self::assertSame(50, $this->log->last()->inputTokens);
    }

    public function testLibraryFailuresMapToCodes(): void
    {
        $this->seedPosts();
        $this->caller = new BufferedJsonCaller($this->asker([], new CallerFailure('provider_credit', 'out of credit', 402)));
        [, $e] = $this->ask(['message' => 'river dam']);
        self::assertSame('provider_credit', $e->of('error')[0]['code']);

        self::assertSame('provider_auth', LlmJsonAsker::map(new AuthenticationException('bad key', 401))->errorCode);
        self::assertSame('provider_busy', LlmJsonAsker::map(new RateLimitedException('slow down', 429))->errorCode);
        self::assertSame('provider_down', LlmJsonAsker::map(new \RuntimeException('boom'))->errorCode);
    }

    public function testWithoutLibraryTheOptionFallsBackToStreaming(): void
    {
        \WpStub::$filters['sse_chat_model_caller'] = [];
        $factory = new CallerFactory($this->settings(['caller' => 'buffered-json']), new FakeTransport(), static fn (): bool => false);
        self::assertInstanceOf(StreamingCaller::class, $factory->make());
        self::assertNotNull($factory->notice());

        $factory = new CallerFactory($this->settings(['caller' => 'buffered-json']), new FakeTransport(), static fn (): bool => true);
        self::assertInstanceOf(BufferedJsonCaller::class, $factory->make());
        self::assertSame('buffered-json', $factory->name());
        self::assertTrue(LlmJsonAsker::available(), 'the dev install has the library');
    }
}
