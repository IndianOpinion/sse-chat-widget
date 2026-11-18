<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Limits;

use IndianOpinion\SseChatWidget\Settings;
use IndianOpinion\SseChatWidget\Support\Clock;

/**
 * Daily counters (case 79): messages, refusals by code, tokens, aborted and
 * uncited answers, log write failures. Counts, not log rows; kept 400 days.
 */
final class Stats
{
    public const RETENTION_DAYS = 400;

    public function __construct(
        private readonly CounterStore $store,
        private readonly Settings $settings,
        private readonly Clock $clock,
    ) {
    }

    public function bump(string $name, int $by = 1): void
    {
        if ($by <= 0) {
            return;
        }
        $now = $this->clock->now();
        try {
            $this->store->increment('day:' . $name, $this->today(), $now + self::RETENTION_DAYS * 86400, $by);
        } catch (CounterUnavailable $e) {
            // Statistics never block an answer.
        }
    }

    public function today(): int
    {
        return Limiter::dayKey($this->clock->now(), $this->settings->timezone());
    }

    public function get(string $name, ?int $day = null): int
    {
        try {
            return $this->store->get('day:' . $name, $day ?? $this->today());
        } catch (CounterUnavailable $e) {
            return 0;
        }
    }

    /**
     * Today's figures for the status box and the CLI.
     *
     * @param list<string> $refusalCodes
     * @return array<string, int>
     */
    public function snapshot(array $refusalCodes): array
    {
        $out = [];
        foreach (['messages', 'tokens', 'aborted', 'uncited', 'no_context', 'log_write_failed', 'admin_test'] as $name) {
            $out[$name] = $this->get($name);
        }
        foreach ($refusalCodes as $code) {
            $v = $this->get('refused:' . $code);
            if ($v > 0) {
                $out['refused:' . $code] = $v;
            }
        }
        return $out;
    }
}
