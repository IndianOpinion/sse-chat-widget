<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Spend;

/**
 * Optional integration with a spend-meter plugin (cases 25, 60, 83). Active
 * only when SPEND_METER_API is defined with contract 1. Fails closed: any
 * answer other than {"allowed": true} refuses the request.
 *
 * Calls made, all through WordPress hooks:
 *  - filter spend_meter_pre_spend(null, array $args): array{allowed: bool, reservation?: string}
 *  - action spend_meter_record(array $record), once per attempt
 *  - action spend_meter_release(array $release), when the call never starts
 */
final class SpendMeterBridge
{
    public const CALLER = 'sse-chat-widget';
    public const PURPOSE = 'chat';

    public static function active(): bool
    {
        return defined('SPEND_METER_API') && (int) constant('SPEND_METER_API') === 1;
    }

    /**
     * @return array{allowed: bool, reservation: ?string}
     */
    public function preSpend(string $provider, string $model, int $estimatedInputTokens, int $outputCap): array
    {
        $answer = apply_filters('spend_meter_pre_spend', null, [
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $estimatedInputTokens,
            'output_tokens' => $outputCap,
        ]);
        if (!is_array($answer) || ($answer['allowed'] ?? null) !== true) {
            return ['allowed' => false, 'reservation' => null];
        }
        $reservation = $answer['reservation'] ?? null;
        return ['allowed' => true, 'reservation' => is_scalar($reservation) ? (string) $reservation : null];
    }

    public function record(?string $reservation, string $status, ?int $inputTokens, ?int $outputTokens, ?int $httpStatus, string $provider, string $model): void
    {
        do_action('spend_meter_record', [
            'reservation' => $reservation,
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => $provider,
            'model' => $model,
            'status' => $status,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'http_status' => $httpStatus,
        ]);
    }

    public function release(?string $reservation): void
    {
        do_action('spend_meter_release', [
            'reservation' => $reservation,
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
        ]);
    }
}
