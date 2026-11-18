<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Unit;

use IndianOpinion\SseChatWidget\Admin\SettingsPage;
use IndianOpinion\SseChatWidget\Limits\Limiter;
use IndianOpinion\SseChatWidget\Plugin;
use IndianOpinion\SseChatWidget\Session\Keys;
use IndianOpinion\SseChatWidget\Settings;
use IndianOpinion\SseChatWidget\Tests\Fakes\ScriptedCaller;
use IndianOpinion\SseChatWidget\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class LimitsTest extends TestCase
{
    private const IP = ['REMOTE_ADDR' => '203.0.113.7'];

    /** T18, case 20. */
    public function testConcurrentStreamsPerIp(): void
    {
        $this->seedPosts();
        $service = $this->service();
        $inner = null;
        $this->caller = new ScriptedCaller([[
            'chunks' => ['one ', 'two'],
            'beforeChunk' => function (int $i) use (&$inner, $service): void {
                if ($i === 0) {
                    [$inner] = $this->ask(['message' => 'second stream'], [], self::IP, $service);
                }
            },
        ], ['chunks' => ['ok']]]);
        [$r] = $this->ask(['message' => 'first stream about the dam'], [], self::IP, $service);
        self::assertNull($r);
        self::assertNotNull($inner);
        self::assertSame([429, 'busy', 5], [$inner->status, $inner->errorCode, $inner->retryAfter]);

        // Slot freed when the stream ended.
        [$r] = $this->ask(['message' => 'third stream'], [], self::IP, $service);
        self::assertNull($r);

        // A worker that died leaves a slot that expires after max duration plus 30 s.
        $this->counters->addSlot('slot:ip:' . Keys::ipHash('203.0.113.7', $this->clock->now()), 99, $this->clock->now() + 120);
        [$r] = $this->ask(['message' => 'blocked by the dead slot'], [], self::IP, $service);
        self::assertSame('busy', $r?->errorCode);
        $this->clock->advance(121);
        [$r] = $this->ask(['message' => 'after expiry'], [], self::IP, $service);
        self::assertNull($r);
    }

    public function testConcurrentStreamsAcrossSite(): void
    {
        $service = $this->service(['concurrent_site' => 2, 'concurrent_ip' => 5]);
        for ($i = 0; $i < 2; $i++) {
            $this->counters->addSlot('slot:site', 1000 + $i, $this->clock->now() + 100);
        }
        [$r] = $this->ask(['message' => 'hello there'], [], self::IP, $service);
        self::assertSame('busy', $r?->errorCode);
    }

    /** T19, case 21. */
    public function testPerIpRollingHour(): void
    {
        $start = $this->clock->now();
        for ($i = 0; $i < 20; $i++) {
            [$r] = $this->ask(['message' => 'message ' . $i]);
            self::assertNull($r, 'message ' . $i);
            $this->clock->advance(60);
        }
        $this->clock->advance(10 * 60);
        [$r] = $this->ask(['message' => 'twenty first']);
        self::assertSame([429, 'rate_ip'], [$r?->status, $r?->errorCode]);
        $oldestLeaves = (intdiv($start, 60) + 60) * 60;
        $expected = (int) (ceil(($oldestLeaves - $this->clock->now()) / 60) * 60);
        self::assertSame($expected, $r?->retryAfter);
        self::assertSame(0, $r->retryAfter % 60);

        // Another IP is unaffected; the same IP passes once the oldest leaves the window.
        [$r] = $this->ask(['message' => 'other ip'], [], ['REMOTE_ADDR' => '203.0.113.99']);
        self::assertNull($r);
        $this->clock->advance((float) $expected);
        [$r] = $this->ask(['message' => 'later']);
        self::assertNull($r);
    }

    /** T20, case 22. */
    public function testPerSessionLimits(): void
    {
        $service = $this->service(['per_ip_hour' => 1000]);
        $token = $this->token($service);
        $send = function (int $i) use ($service, $token) {
            return $this->ask(['session' => $token, 'message' => 'msg ' . $i], [], ['REMOTE_ADDR' => '198.51.100.' . ($i % 250 + 1)], $service)[0];
        };
        for ($i = 1; $i <= 10; $i++) {
            self::assertNull($send($i));
        }
        self::assertSame([429, 'rate_session'], [$send(11)?->status, $send(11)?->errorCode]);
        $sent = 10;
        while ($sent < 30) {
            $this->clock->advance(601);
            for ($j = 0; $j < 10 && $sent < 30; $j++) {
                self::assertNull($send(100 + $sent), 'total ' . ($sent + 1));
                $sent++;
            }
        }
        $this->clock->advance(601);
        self::assertSame('rate_session', $send(999)?->errorCode, 'thirty-first in the session lifetime');
    }

    /** T21, case 23: the day resets at local midnight in the configured zone. */
    public function testDailyCapResetsAtLocalMidnight(): void
    {
        $tz = new \DateTimeZone('Asia/Kolkata');
        $this->clock->t = (float) (new \DateTimeImmutable('2026-11-15 23:58:00', $tz))->getTimestamp();
        $override = ['daily_messages' => 2, 'timezone' => 'Asia/Kolkata', 'strings' => ['daily_cap' => 'Come back tomorrow.']];
        self::assertNull($this->ask(['message' => 'one'], $override, ['REMOTE_ADDR' => '198.51.100.1'])[0]);
        self::assertNull($this->ask(['message' => 'two'], $override, ['REMOTE_ADDR' => '198.51.100.2'])[0]);
        [$r] = $this->ask(['message' => 'three'], $override, ['REMOTE_ADDR' => '198.51.100.3']);
        self::assertSame([429, 'daily_cap', 'Come back tomorrow.'], [$r?->status, $r?->errorCode, $r?->getMessage()]);
        self::assertSame(120, $r->retryAfter);
        $this->clock->advance(121);
        self::assertNull($this->ask(['message' => 'next day'], $override, ['REMOTE_ADDR' => '198.51.100.4'])[0]);
        self::assertSame(20261116, Limiter::dayKey($this->clock->now(), $tz));
    }

    /** T22, case 24. */
    public function testDailyTokenCap(): void
    {
        $this->seedPosts();
        $this->caller = new ScriptedCaller([['chunks' => ['answer'], 'result' => ['input' => 700, 'output' => 400]]]);
        $override = ['daily_tokens' => 1000];
        self::assertNull($this->ask(['message' => 'first question on the dam'], $override)[0]);
        [$r] = $this->ask(['message' => 'second question'], $override, ['REMOTE_ADDR' => '198.51.100.5']);
        self::assertSame([429, 'daily_cap'], [$r?->status, $r?->errorCode]);
    }

    /** T24 (sequential part), case 26: reservation before the call; refusals do not count; failures do. */
    public function testReservationCounting(): void
    {
        $override = ['daily_messages' => 1];
        self::assertNull($this->ask(['message' => 'first'], $override)[0]);
        [$r] = $this->ask(['message' => 'second'], $override);
        self::assertSame('daily_cap', $r?->errorCode);
        $ipBucket = 'ip:' . Keys::ipHash('203.0.113.7', $this->clock->now());
        self::assertSame(1, $this->counters->total($ipBucket), 'the refused request gave its IP count back');
        self::assertSame(1, $this->counters->total('day:messages'));

        // An upstream failure still counts.
        $this->counters->rows = [];
        $this->seedPosts();
        $caller = new ScriptedCaller([['chunks' => [], 'result' => ['error' => 'provider_auth', 'status' => 401]]]);
        $this->caller = $caller;
        self::assertNull($this->ask(['message' => 'the dam question that fails upstream'])[0]);
        self::assertCount(1, $caller->requests);
        self::assertSame(1, $this->counters->total('day:messages'));
    }

    /** T16 (constant part), case 78. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testKillConstantWinsAndCannotBeClearedFromScreen(): void
    {
        define('SSE_CHAT_KILL', true);
        [$r] = $this->ask(['message' => 'hello there']);
        self::assertSame([503, 'paused'], [$r?->status, $r?->errorCode]);
        self::assertSame(['on' => true, 'source' => 'constant'], $this->settings()->killSwitch());
        self::assertNotNull(SettingsPage::applyKill(false));
        self::assertSame(['on' => true, 'source' => 'constant'], Settings::load()->killSwitch());
    }

    /** T16 (CLI and screen part): the stored switch, and the action fires. */
    public function testKillSwitchStoredToggle(): void
    {
        update_option(Settings::OPTION, ['enabled' => true, 'base_url' => 'https://llm.example.net/v1', 'model' => 'm']);
        Plugin::setKill(true, 'cli');
        self::assertTrue(Settings::load()->killSwitch()['on']);
        self::assertSame([[true, 'cli']], did_action_with('sse_chat_kill_switch_changed'));
        [$r] = $this->ask(['message' => 'hello there'], ['kill' => Settings::load()->bool('kill')]);
        self::assertSame('paused', $r?->errorCode);
        self::assertNull(SettingsPage::applyKill(false));
        self::assertFalse(Settings::load()->killSwitch()['on']);
    }
}
