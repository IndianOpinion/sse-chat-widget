<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Spend;

/**
 * Optional integration with spend-meter (cases 25, 60, 83), contract 1 as
 * published in spend-meter's README and PRD (sections 5.2 and 5.3). Active
 * only when SPEND_METER_API is defined as 1.
 *
 * Calls made, all through WordPress hooks:
 *  - filter spend_meter_pre_spend(null, array $intent): ?array. Null means
 *    no meter answered (treated as absent: the plugin's own caps apply).
 *    Any other answer whose "allowed" is not exactly true refuses.
 *  - action spend_meter_record(array $call), once per attempt that reached
 *    the provider, with the reservation_id from the decision.
 *  - action spend_meter_release(string $reservation_id), when an allowed reservation is
 *    given up because no call was made.
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
     * Ask before spending.
     *
     * @return array{present: bool, allowed: bool, reservation: ?string, reason: string}
     *         present is false when no meter answered (the filter returned null).
     */
    public function preSpend(string $provider, string $model, int $estimatedInputTokens, int $outputCap): array
    {
        $answer = apply_filters('spend_meter_pre_spend', null, [
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => self::providerSlug($provider),
            'model' => mb_substr($model, 0, 128),
            'est_input_tokens' => max(0, $estimatedInputTokens),
            'max_output_tokens' => max(0, $outputCap),
        ]);
        if ($answer === null) {
            return ['present' => false, 'allowed' => true, 'reservation' => null, 'reason' => 'absent'];
        }
        if (!is_array($answer) || ($answer['allowed'] ?? null) !== true) {
            $reason = is_array($answer) && is_string($answer['reason'] ?? null) ? $answer['reason'] : 'invalid_answer';
            return ['present' => true, 'allowed' => false, 'reservation' => null, 'reason' => $reason];
        }
        $reservation = $answer['reservation_id'] ?? null;
        return [
            'present' => true,
            'allowed' => true,
            'reservation' => is_string($reservation) && $reservation !== '' ? $reservation : null,
            'reason' => 'ok',
        ];
    }

    /**
     * Record one attempt that reached (or tried to reach) the provider.
     *
     * @param string $status ok, error_billed or error_unbilled
     */
    public function record(?string $reservation, string $status, ?int $inputTokens, ?int $outputTokens, ?int $httpStatus, string $provider, string $model, int $attempt, ?string $errorCode, ?int $latencyMs): void
    {
        $call = [
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => self::providerSlug($provider),
            'model' => mb_substr($model, 0, 128),
            'status' => $status,
            'input_tokens' => max(0, (int) $inputTokens),
            'output_tokens' => max(0, (int) $outputTokens),
            'attempt' => $attempt,
        ];
        if ($reservation !== null) {
            $call['reservation_id'] = $reservation;
        }
        if ($httpStatus !== null) {
            $call['http_status'] = $httpStatus;
        }
        if ($errorCode !== null) {
            $call['error_code'] = mb_substr($errorCode, 0, 64);
        }
        if ($latencyMs !== null) {
            $call['latency_ms'] = max(0, $latencyMs);
        }
        do_action('spend_meter_record', $call);
    }

    /**
     * Give up a reservation when no call was made. A null reservation holds
     * nothing, so nothing is sent.
     */
    public function release(?string $reservation): void
    {
        if ($reservation === null) {
            return;
        }
        do_action('spend_meter_release', $reservation);
    }

    /**
     * spend-meter wants a lower-case provider slug. The provider is the
     * configured base URL's host.
     */
    public static function providerSlug(string $provider): string
    {
        $slug = (string) preg_replace('/[^a-z0-9_.-]+/', '-', strtolower($provider));
        $slug = trim($slug, '-');
        return $slug !== '' ? mb_substr($slug, 0, 64) : 'unknown';
    }
}
