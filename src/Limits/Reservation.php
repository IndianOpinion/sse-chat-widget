<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Limits;

/**
 * Counter increments taken before the model call (case 26). release() gives
 * them back when a later check refuses the request.
 */
final class Reservation
{
    /** @var list<array{0: string, 1: int}> */
    private array $counts = [];

    /** @var list<array{0: string, 1: int}> */
    private array $slots = [];

    private bool $released = false;

    public function __construct(private readonly CounterStore $store)
    {
    }

    public function addCount(string $bucket, int $window): void
    {
        $this->counts[] = [$bucket, $window];
    }

    public function addSlot(string $bucket, int $slotId): void
    {
        $this->slots[] = [$bucket, $slotId];
    }

    /**
     * Undo the counts and free the slots. Used when the request is refused
     * after the reservation was taken.
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        foreach ($this->counts as [$bucket, $window]) {
            try {
                $this->store->decrement($bucket, $window);
            } catch (CounterUnavailable $e) {
                // Over-counting is the safe direction; nothing more to do.
            }
        }
        $this->counts = [];
        $this->freeSlots();
    }

    /**
     * Free the concurrency slots only. The message counts stay, because the
     * provider may have billed the call (case 26).
     */
    public function freeSlots(): void
    {
        foreach ($this->slots as [$bucket, $slotId]) {
            try {
                $this->store->removeSlot($bucket, $slotId);
            } catch (CounterUnavailable $e) {
                // The slot expires on its own after the stream maximum plus 30 s.
            }
        }
        $this->slots = [];
    }
}
