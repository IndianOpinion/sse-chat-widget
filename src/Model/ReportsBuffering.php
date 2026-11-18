<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model;

/**
 * Optional: a caller that knows it will replay a whole answer rather than
 * stream it says so, and the meta event carries "buffered": true (case 53).
 */
interface ReportsBuffering
{
    public function isBuffered(): bool;
}
