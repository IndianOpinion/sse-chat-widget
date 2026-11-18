<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Limits;

use IndianOpinion\SseChatWidget\Http\Refusal;
use IndianOpinion\SseChatWidget\Settings;
use IndianOpinion\SseChatWidget\Support\Clock;

/**
 * Concurrency slots, rolling rate windows and the daily caps (cases 20 to 24).
 */
final class Limiter
{
    public function __construct(
        private readonly CounterStore $store,
        private readonly Settings $settings,
        private readonly Clock $clock,
    ) {
    }

    public static function dayKey(int $timestamp, \DateTimeZone $tz): int
    {
        return (int) (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz)->format('Ymd');
    }

    /**
     * Take concurrency slots for one stream (case 20). Simultaneous arrivals
     * at the edge may both be refused; neither can both pass.
     *
     * @throws Refusal
     * @throws CounterUnavailable
     */
    public function acquireSlots(Reservation $reservation, string $ipHash): void
    {
        $now = $this->clock->now();
        $expires = $now + $this->settings->int('max_stream_seconds') + 30;
        $slotId = random_int(1, PHP_INT_MAX);
        $groups = [
            ['slot:ip:' . $ipHash, $this->settings->int('concurrent_ip')],
            ['slot:site', $this->settings->int('concurrent_site')],
        ];
        foreach ($groups as [$bucket, $limit]) {
            $this->store->addSlot($bucket, $slotId, $expires);
            $reservation->addSlot($bucket, $slotId);
            if ($this->store->liveSlots($bucket, $now) > $limit) {
                $reservation->release();
                throw new Refusal(429, 'busy', $this->settings->displayString('busy'), 5);
            }
        }
    }

    /**
     * Count the message against the per-IP, per-session and daily limits
     * before any spend (cases 21 to 24, 26).
     *
     * @throws Refusal
     * @throws CounterUnavailable
     */
    public function reserve(Reservation $reservation, string $ipHash, string $sessionId, int $sessionExpires): void
    {
        $now = $this->clock->now();
        $minute = intdiv($now, 60);

        $this->rolling($reservation, 'ip:' . $ipHash, $minute, 60, $this->settings->int('per_ip_hour'), 'rate_ip', 'rate_limited');

        $total = 'sess:' . $sessionId;
        $position = $this->store->increment($total, 0, $sessionExpires + 60);
        $reservation->addCount($total, 0);
        if ($position > $this->settings->int('per_session_total')) {
            $reservation->release();
            throw new Refusal(429, 'rate_session', $this->settings->displayString('rate_limited'));
        }

        $this->rolling($reservation, 'sess10:' . $sessionId, $minute, 10, $this->settings->int('per_session_10min'), 'rate_session', 'rate_limited');

        $day = self::dayKey($now, $this->settings->timezone());
        $position = $this->store->increment('day:messages', $day, $now + 400 * 86400);
        $reservation->addCount('day:messages', $day);
        if ($position > $this->settings->int('daily_messages')) {
            $reservation->release();
            throw new Refusal(429, 'daily_cap', $this->settings->displayString('daily_cap'), $this->secondsToMidnight($now));
        }

        $tokenCap = $this->settings->int('daily_tokens');
        if ($tokenCap > 0 && $this->store->get('day:tokens', $day) >= $tokenCap) {
            $reservation->release();
            throw new Refusal(429, 'daily_cap', $this->settings->displayString('daily_cap'), $this->secondsToMidnight($now));
        }
    }

    /**
     * The forget route is subject to the per-IP limit (case 86), counted in
     * its own bucket so it never spends or refunds a message.
     *
     * @throws Refusal
     * @throws CounterUnavailable
     */
    public function limitForget(Reservation $reservation, string $ipHash): void
    {
        $minute = intdiv($this->clock->now(), 60);
        $this->rolling($reservation, 'forget:' . $ipHash, $minute, 60, $this->settings->int('per_ip_hour'), 'rate_ip', 'rate_limited');
    }

    /**
     * A rolling window built from one-minute buckets. The increment returns
     * this request's position within its minute, so with the earlier minutes
     * (which no longer change) each concurrent request sees a distinct total.
     *
     * @throws Refusal
     */
    private function rolling(Reservation $reservation, string $bucket, int $minute, int $minutes, int $limit, string $code, string $stringKey): void
    {
        $position = $this->store->increment($bucket, $minute, ($minute + $minutes + 1) * 60);
        $reservation->addCount($bucket, $minute);
        $earlier = array_sum($this->store->range($bucket, $minute - $minutes + 1, $minute - 1));
        if ($earlier + $position <= $limit) {
            return;
        }
        $windows = $this->store->range($bucket, $minute - $minutes + 1, $minute);
        $reservation->release();
        $oldest = $windows === [] ? $minute : (int) array_key_first($windows);
        $seconds = ($oldest + $minutes) * 60 - $this->clock->now();
        $retry = max(60, (int) (ceil($seconds / 60) * 60));
        throw new Refusal(429, $code, $this->settings->displayString($stringKey), $retry);
    }

    private function secondsToMidnight(int $now): int
    {
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone($this->settings->timezone());
        $midnight = $local->setTime(0, 0)->modify('+1 day');
        return max(60, $midnight->getTimestamp() - $now);
    }
}
