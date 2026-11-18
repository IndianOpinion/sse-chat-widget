<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model;

/**
 * A JsonAsker failure already mapped to a case 54 code.
 */
final class CallerFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $providerMessage,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($providerMessage);
    }
}
