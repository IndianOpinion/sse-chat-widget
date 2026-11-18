<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model;

/**
 * Case 54: map upstream failures to a few reader-facing codes.
 */
final class ErrorMapper
{
    private const CREDIT = '/(?<![a-z])(credits?|quota|billing|insufficient[_ ](funds|balance)|payment[_ ]required)(?![a-z])/i';

    public static function fromStatus(?int $status, string $body): string
    {
        if ($status === 402 || preg_match(self::CREDIT, $body) === 1) {
            return 'provider_credit';
        }
        if ($status === 401 || $status === 403) {
            return 'provider_auth';
        }
        if ($status === 429) {
            return 'provider_busy';
        }
        return 'provider_down';
    }

    /**
     * An error object inside a 200 stream (case 56).
     *
     * @param array<mixed> $error
     */
    public static function fromErrorFrame(array $error): string
    {
        $code = $error['code'] ?? ($error['status'] ?? null);
        $status = is_numeric($code) ? (int) $code : null;
        $text = json_encode($error) ?: '';
        return self::fromStatus($status, $text);
    }
}
