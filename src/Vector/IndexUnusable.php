<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Vector;

/**
 * The packed index failed a header or size check (case 64). The reason is
 * a short code for the log: bad_tag, bad_version, short_header,
 * dimension_too_small, dimension_mismatch, partial_record.
 */
final class IndexUnusable extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Vector index unusable: ' . $reason);
    }
}
