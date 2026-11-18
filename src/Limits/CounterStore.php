<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Limits;

/**
 * Storage for rate windows, concurrency slots and daily totals. Every method
 * throws CounterUnavailable when the store fails.
 *
 * A bucket is a string key; a window is an integer (a minute number, a day
 * as Ymd, or a slot id). Each (bucket, window) row has a count and an
 * expiry time after which pruning may remove it.
 */
interface CounterStore
{
    /**
     * Atomically add $by to the row and return the count after the add. When
     * several requests add at once, each sees a distinct result.
     */
    public function increment(string $bucket, int $window, int $expires, int $by = 1): int;

    /** Atomically subtract $by, never below zero. */
    public function decrement(string $bucket, int $window, int $by = 1): void;

    public function get(string $bucket, int $window): int;

    /**
     * @return array<int, int> window => count, for windows in [$from, $to] with a count above zero.
     */
    public function range(string $bucket, int $from, int $to): array;

    /** Add a live slot row. */
    public function addSlot(string $bucket, int $slotId, int $expires): void;

    /** Count slot rows of a bucket that expire after $now. */
    public function liveSlots(string $bucket, int $now): int;

    public function removeSlot(string $bucket, int $slotId): void;

    /** Remove rows whose expiry has passed. Returns rows removed. */
    public function pruneExpired(int $now): int;
}
