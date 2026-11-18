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
            return ['allowed' => false, 'reason' => 'cap_site_day', 'reservation_id' => null];
        }, 10, 2);
        $caller = ScriptedCaller::answer('x');
        $this->caller = $caller;
        [$r] = $this->ask(['message' => 'river dam']);
        self::assertSame([503, 'spend_denied'], [$r?->status, $r?->errorCode]);
        self::assertSame([], $caller->requests);
        self::assertSame('chat', $asked[0]['purpose']);
        self::assertSame('sse-chat-widget', $asked[0]['caller']);
        self::assertSame('llm.example.net', $asked[0]['provider']);
        self::assertSame(600, $asked[0]['max_output_tokens']);
        self::assertIsInt($asked[0]['est_input_tokens']);
        self::assertArrayNotHasKey('input_tokens', $asked[0]);
        self::assertSame(0, $this->counters->total('day:messages'), 'reservation released');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMalformedAnswerFailsClosed(): void
    {
        define('SPEND_METER_API', 1);
        add_filter('spend_meter_pre_spend', static fn () => 'yes');
        [$r] = $this->ask(['message' => 'river dam']);
        self::assertSame('spend_denied', $r?->errorCode);
        self::assertSame([], $this->caller instanceof ScriptedCaller ? $this->caller->requests : []);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNullAnswerMeansNoMeterAndOwnCapsApply(): void
    {
        define('SPEND_METER_API', 1);
        $this->seedPosts();
        [$r, $e] = $this->ask(['message' => 'river dam']);
        self::assertNull($r);
        self::assertSame('The dam opened in 2019 [1].', $e->text());
        self::assertSame([], did_action_with('spend_meter_record'), 'no meter answered, so nothing is recorded with it');
        self::assertSame([], did_action_with('spend_meter_release'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNoReleaseAfterARecordedAttempt(): void
    {
        define('SPEND_METER_API', 1);
        $this->seedPosts();
        add_filter('spend_meter_pre_spend', static fn () => ['allowed' => true, 'reason' => 'ok', 'reservation_id' => 'r-2']);
        // First attempt reaches the provider and fails; the retry never starts.
        $this->caller = new ScriptedCaller([
            ['chunks' => [], 'result' => ['error' => 'provider_down', 'status' => 502]],
            ['chunks' => [], 'result' => ['error' => 'provider_down', 'status' => null, 'started' => false]],
        ]);
        $this->ask(['message' => 'river dam']);
        self::assertCount(1, did_action_with('spend_meter_record'));
        self::assertSame([], did_action_with('spend_meter_release'), 'exactly one of record or release per reservation');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAllowingRecordsOncePerAttemptAndReleasesWhenNoCall(): void
    {
        define('SPEND_METER_API', 1);
        $this->seedPosts();
        add_filter('spend_meter_pre_spend', static fn () => ['allowed' => true, 'reason' => 'ok', 'reservation_id' => 'r-1']);
        $this->caller = new ScriptedCaller([
            ['chunks' => [], 'result' => ['error' => 'provider_down', 'status' => 503]],
            ['chunks' => ['ok [1]'], 'result' => ['input' => 10, 'output' => 3]],
        ]);
        $this->ask(['message' => 'river dam']);
        $records = did_action_with('spend_meter_record');
        self::assertCount(2, $records);
        self::assertSame(['r-1', 'error_unbilled', 1, 'provider_down'], [$records[0][0]['reservation_id'], $records[0][0]['status'], $records[0][0]['attempt'], $records[0][0]['error_code']]);
        self::assertSame(['r-1', 'ok', 10, 3, 2], [$records[1][0]['reservation_id'], $records[1][0]['status'], $records[1][0]['input_tokens'], $records[1][0]['output_tokens'], $records[1][0]['attempt']]);
        self::assertSame('llm.example.net', $records[0][0]['provider']);
        self::assertSame([], did_action_with('spend_meter_release'));

        // No passages: the call never starts, one release.
        $this->ask(['message' => 'quantum chromodynamics']);
        $releases = did_action_with('spend_meter_release');
        self::assertCount(1, $releases);
        self::assertSame('r-1', $releases[0][0], 'the release action takes the reservation id as a string');
        self::assertCount(2, did_action_with('spend_meter_record'));
    }
}
