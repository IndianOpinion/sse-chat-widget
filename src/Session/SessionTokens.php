<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Session;

/**
 * Stateless session tokens (case 3): a random session id, the issue time and
 * an HMAC over both. Not bound to the IP.
 *
 * Format: v1.<32 hex id>.<issued unix time>.<64 hex hmac>
 */
final class SessionTokens
{
    public function __construct(
        private readonly string $key,
        private readonly int $lifetimeSeconds,
    ) {
    }

    /**
     * @return array{token: string, id: string, issued: int, expires: int}
     */
    public function issue(int $now): array
    {
        $id = bin2hex(random_bytes(16));
        return [
            'token' => $this->sign($id, $now),
            'id' => $id,
            'issued' => $now,
            'expires' => $now + $this->lifetimeSeconds,
        ];
    }

    /**
     * @return array{id: string, issued: int, expires: int}|null Null when missing, malformed, forged or expired.
     */
    public function verify(mixed $token, int $now): ?array
    {
        if (!is_string($token) || strlen($token) > 200) {
            return null;
        }
        if (preg_match('/^v1\.([0-9a-f]{32})\.(\d{1,12})\.([0-9a-f]{64})$/', $token, $m) !== 1) {
            return null;
        }
        $id = $m[1];
        $issued = (int) $m[2];
        if (!hash_equals($this->sign($id, $issued), $token)) {
            return null;
        }
        $expires = $issued + $this->lifetimeSeconds;
        if ($issued > $now + 60 || $now >= $expires) {
            return null;
        }
        return ['id' => $id, 'issued' => $issued, 'expires' => $expires];
    }

    private function sign(string $id, int $issued): string
    {
        $payload = 'v1.' . $id . '.' . $issued;
        return $payload . '.' . hash_hmac('sha256', $payload, $this->key);
    }
}
