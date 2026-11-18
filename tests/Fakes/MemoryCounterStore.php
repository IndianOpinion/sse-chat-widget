<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Fakes;

use IndianOpinion\SseChatWidget\Limits\CounterStore;
use IndianOpinion\SseChatWidget\Limits\CounterUnavailable;

final class MemoryCounterStore implements CounterStore
{
    /** @var array<string, array<int, array{count: int, expires: int}>> */
    public array $rows = [];
    public bool $broken = false;

    private function guard(): void
    {
        if ($this->broken) {
            throw new CounterUnavailable('store down');
        }
    }

    public function increment(string $bucket, int $window, int $expires, int $by = 1): int
    {
        $this->guard();
        $row = $this->rows[$bucket][$window] ?? ['count' => 0, 'expires' => 0];
        $row['count'] += $by;
        $row['expires'] = max($row['expires'], $expires);
        $this->rows[$bucket][$window] = $row;
        return $row['count'];
    }

    public function decrement(string $bucket, int $window, int $by = 1): void
    {
        $this->guard();
        if (isset($this->rows[$bucket][$window])) {
            $this->rows[$bucket][$window]['count'] = max(0, $this->rows[$bucket][$window]['count'] - $by);
        }
    }

    public function get(string $bucket, int $window): int
    {
        $this->guard();
        return $this->rows[$bucket][$window]['count'] ?? 0;
    }

    public function range(string $bucket, int $from, int $to): array
    {
        $this->guard();
        $out = [];
        foreach ($this->rows[$bucket] ?? [] as $w => $row) {
            if ($w >= $from && $w <= $to && $row['count'] > 0) {
                $out[$w] = $row['count'];
            }
        }
        ksort($out);
        return $out;
    }

    public function addSlot(string $bucket, int $slotId, int $expires): void
    {
        $this->guard();
        $this->rows[$bucket][$slotId] = ['count' => 1, 'expires' => $expires];
    }

    public function liveSlots(string $bucket, int $now): int
    {
        $this->guard();
        $n = 0;
        foreach ($this->rows[$bucket] ?? [] as $row) {
            if ($row['expires'] > $now) {
                $n++;
            }
        }
        return $n;
    }

    public function removeSlot(string $bucket, int $slotId): void
    {
        $this->guard();
        unset($this->rows[$bucket][$slotId]);
    }

    public function pruneExpired(int $now): int
    {
        $this->guard();
        $n = 0;
        foreach ($this->rows as $bucket => $windows) {
            foreach ($windows as $w => $row) {
                if ($row['expires'] < $now) {
                    unset($this->rows[$bucket][$w]);
                    $n++;
                }
            }
        }
        return $n;
    }

    public function total(string $prefix): int
    {
        $n = 0;
        foreach ($this->rows as $bucket => $windows) {
            if (str_starts_with($bucket, $prefix)) {
                foreach ($windows as $row) {
                    $n += $row['count'];
                }
            }
        }
        return $n;
    }
}
