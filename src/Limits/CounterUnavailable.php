<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Limits;

/**
 * The counter store could not be read or written. Limits fail closed (case 81).
 */
final class CounterUnavailable extends \RuntimeException
{
}
