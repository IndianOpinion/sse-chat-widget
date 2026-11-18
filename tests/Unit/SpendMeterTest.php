<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Unit;

use IndianOpinion\SseChatWidget\Tests\Fakes\ScriptedCaller;
use IndianOpinion\SseChatWidget\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** T23, cases 25, 60 and 83. Separate processes because SPEND_METER_API is a constant. */
final class SpendMeterTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDenyingRefusesWithoutUpstreamCall(): void
    {
        define('SPEND_METER_API', 1);
        $this->seedPosts();
        $asked = [];
        add_filter('spend_meter_pre_spend', static function ($v, array $args) use (&$asked) {
            $asked[] = $args;
            return ['allowed' => false];
        }, 10, 2);
        $caller = ScriptedCaller::answer('x');
        $this->caller = $caller;
        [$r] = $this->ask(['message' => 'river dam']);
        self::assertSame([503, 'spend_denied'], [$r?->status, $r?->errorCode]);
        self::assertSame([], $caller->requests);
        self::assertSame('chat', $asked[0]['purpose']);
        self::assertSame('sse-chat-widget', $asked[0]['caller']);
        self::assertSame('llm.example.net', $asked[0]['provider']);
        self::assertSame(600, $asked[0]['output_tokens']);
        self::assertSame(0, $this->counters->total('day:messages'), 'reservation released');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnavailableMeterFailsClosed(): void
    {
        define('SPEND_METER_API', 1);
        [$r] = $this->ask(['message' => 'river dam']);
        self::assertSame('spend_denied', $r?->errorCode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAllowingRecordsOncePerAttemptAndReleasesWhenNoCall(): void
    {
        define('SPEND_METER_API', 1);
        $this->seedPosts();
        add_filter('spend_meter_pre_spend', static fn () => ['allowed' => true, 'reservation' => 'r-1']);
        $this->caller = new ScriptedCaller([
            ['chunks' => [], 'result' => ['error' => 'provider_down', 'status' => 503]],
            ['chunks' => ['ok [1]'], 'result' => ['input' => 10, 'output' => 3]],
        ]);
        $this->ask(['message' => 'river dam']);
        $records = did_action_with('spend_meter_record');
        self::assertCount(2, $records);
        self::assertSame(['r-1', 'error_unbilled'], [$records[0][0]['reservation'], $records[0][0]['status']]);
        self::assertSame(['r-1', 'ok', 10, 3], [$records[1][0]['reservation'], $records[1][0]['status'], $records[1][0]['input_tokens'], $records[1][0]['output_tokens']]);
        self::assertSame([], did_action_with('spend_meter_release'));

        // No passages: the call never starts, one release.
        $this->ask(['message' => 'quantum chromodynamics']);
        self::assertCount(1, did_action_with('spend_meter_release'));
        self::assertCount(2, did_action_with('spend_meter_record'));
    }
}
