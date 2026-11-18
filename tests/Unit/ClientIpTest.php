<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Unit;

use IndianOpinion\SseChatWidget\Http\ClientIp;
use IndianOpinion\SseChatWidget\Session\Keys;
use IndianOpinion\SseChatWidget\Tests\TestCase;

/** T25, case 27. */
final class ClientIpTest extends TestCase
{
    public function testForwardedHeaderIgnoredByDefault(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'];
        self::assertSame('10.0.0.5', ClientIp::resolve($server, []));
    }

    public function testHonouredOnlyFromTrustedProxyRightMostUntrusted(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.9, 10.0.0.7'];
        self::assertSame('198.51.100.9', ClientIp::resolve($server, ['10.0.0.0/8']));
        // Peer not trusted: header ignored.
        self::assertSame('192.0.2.1', ClientIp::resolve(['REMOTE_ADDR' => '192.0.2.1'] + $server, ['10.0.0.0/8']));
        // All hops trusted: stay with the peer.
        self::assertSame('10.0.0.5', ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '10.1.1.1'], ['10.0.0.0/8']));
        // IPv6 range.
        self::assertSame('2001:db8::1', ClientIp::resolve(['REMOTE_ADDR' => 'fd00::1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::1'], ['fd00::/8']));
        // A garbage hop ends the walk as unknown.
        self::assertSame('unknown', ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'garbage'], ['10.0.0.5']));
    }

    public function testEmptyIpIsTheUnknownBucket(): void
    {
        self::assertSame('unknown', ClientIp::resolve([], []));
        self::assertSame('unknown', ClientIp::resolve(['REMOTE_ADDR' => 'nonsense'], []));
        // The unknown bucket is rate limited like any IP.
        for ($i = 0; $i < 20; $i++) {
            self::assertNull($this->ask(['message' => 'q ' . $i], [], [])[0]);
        }
        self::assertSame('rate_ip', $this->ask(['message' => 'over'], [], [])[0]?->errorCode);
    }

    public function testFilterCanReplaceButNotInject(): void
    {
        add_filter('sse_chat_client_ip', static fn (string $ip, array $server): string => '192.0.2.44', 10, 2);
        self::assertSame('192.0.2.44', ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.1'], []));
        \WpStub::reset();
        add_filter('sse_chat_client_ip', static fn (): string => '<script>');
        self::assertSame('unknown', ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.1'], []));
    }

    public function testRanges(): void
    {
        self::assertTrue(ClientIp::inRange('192.168.1.77', '192.168.1.0/25'));
        self::assertFalse(ClientIp::inRange('192.168.1.200', '192.168.1.0/25'));
        self::assertTrue(ClientIp::validRange('10.0.0.0/8'));
        self::assertFalse(ClientIp::validRange('10.0.0.0/33'));
        self::assertFalse(ClientIp::validRange('example.org'));
    }

    /** Case 27 and 69: one IPv6 /64 is one bucket; IPv4-mapped addresses count as IPv4. */
    public function testIpv6IsBucketedByPrefix(): void
    {
        $t = 1_700_000_000;
        self::assertSame(Keys::ipHash('2001:db8:1:2::1', $t), Keys::ipHash('2001:db8:1:2:ffff:eeee:dddd:1', $t));
        self::assertNotSame(Keys::ipHash('2001:db8:1:2::1', $t), Keys::ipHash('2001:db8:1:3::1', $t));
        self::assertSame(Keys::ipHash('203.0.113.7', $t), Keys::ipHash('::ffff:203.0.113.7', $t));
        self::assertNotSame(Keys::ipHash('203.0.113.7', $t), Keys::ipHash('203.0.113.8', $t));
        self::assertSame(16, strlen(Keys::ipHash('2001:db8::1', $t)));
    }
}
