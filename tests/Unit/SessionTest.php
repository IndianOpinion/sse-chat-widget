<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Unit;

use IndianOpinion\SseChatWidget\Session\Keys;
use IndianOpinion\SseChatWidget\Session\SessionTokens;
use IndianOpinion\SseChatWidget\Settings;
use IndianOpinion\SseChatWidget\Tests\TestCase;

final class SessionTest extends TestCase
{
    /** T2: the token verifies, expires at the set lifetime, and the body carries display strings only. */
    public function testSessionEndpointIssuesVerifiableTokenWithDisplayStringsOnly(): void
    {
        $service = $this->service(['session_hours' => 5]);
        $out = $service->session($this->request('POST', '{}'));
        self::assertSame(200, $out['status']);
        $body = $out['body'];
        self::assertTrue($body['configured']);
        self::assertSame($this->clock->now() + 5 * 3600, $body['expires']);
        $verified = $service->tokens()->verify($body['session'], $this->clock->now());
        self::assertNotNull($verified);
        self::assertSame($this->clock->now() + 5 * 3600, $verified['expires']);
        self::assertSame(['message_chars' => 1000], $body['limits']);

        $json = (string) json_encode($body);
        self::assertStringNotContainsString('test-model-1', $json);
        self::assertStringNotContainsString('llm.example.net', $json);
        self::assertStringNotContainsString('{passages}', $json);
        self::assertStringNotContainsString('sk-test', $json);
        self::assertSame(['session', 'expires', 'configured', 'strings', 'limits'], array_keys($body));
    }

    /** T3 (server side), case 14: tampered, expired and malformed tokens are refused. */
    public function testTamperedExpiredAndMalformedTokensAreRefused(): void
    {
        $tokens = new SessionTokens(Keys::session(), 3600);
        $issued = $tokens->issue(1000);
        self::assertNotNull($tokens->verify($issued['token'], 1000));

        $tampered = substr($issued['token'], 0, -1) . (str_ends_with($issued['token'], 'a') ? 'b' : 'a');
        self::assertNull($tokens->verify($tampered, 1000));
        $otherId = preg_replace('/^v1\.[0-9a-f]{32}/', 'v1.' . str_repeat('0', 32), $issued['token']);
        self::assertNull($tokens->verify($otherId, 1000));
        self::assertNull($tokens->verify($issued['token'], 1000 + 3600), 'expired at the lifetime');
        self::assertNull($tokens->verify('not-a-token', 1000));
        self::assertNull($tokens->verify(['array'], 1000));
        self::assertNull($tokens->verify(null, 1000));
        self::assertNull((new SessionTokens('another key', 3600))->verify($issued['token'], 1000));

        foreach (['', 'v1.abc', 'garbage', $tampered] as $bad) {
            [$refusal] = $this->ask(['session' => $bad, 'message' => 'hello there']);
            self::assertNotNull($refusal);
            self::assertSame(401, $refusal->status);
            self::assertSame('session_invalid', $refusal->errorCode);
        }
        $this->clock->advance(25 * 3600);
        $old = $this->token();
        $this->clock->advance(25 * 3600);
        [$refusal] = $this->ask(['session' => $old, 'message' => 'hello there']);
        self::assertSame('session_invalid', $refusal?->errorCode);
    }

    /** T68 (session part), case 84: a missing key reports configured false and issues no token. */
    public function testMissingKeyReportsNotConfigured(): void
    {
        delete_option(Settings::KEY_OPTION);
        $out = $this->service()->session($this->request('POST', '{}'));
        self::assertSame(200, $out['status']);
        self::assertFalse($out['body']['configured']);
        self::assertArrayNotHasKey('session', $out['body']);
    }

    public function testSessionRouteChecksOriginAndMethod(): void
    {
        $out = $this->service()->session($this->request('POST', '{}', ['origin' => 'https://evil.example']));
        self::assertSame(403, $out['status']);
        $out = $this->service()->session($this->request('GET', ''));
        self::assertSame(405, $out['status']);
    }

    public function testIpHashIsKeyedTruncatedAndRotatesYearly(): void
    {
        $a = Keys::ipHash('203.0.113.7', strtotime('2026-03-01 UTC'));
        $b = Keys::ipHash('203.0.113.7', strtotime('2026-11-01 UTC'));
        $c = Keys::ipHash('203.0.113.7', strtotime('2027-01-01 UTC'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $a);
        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
    }
}
