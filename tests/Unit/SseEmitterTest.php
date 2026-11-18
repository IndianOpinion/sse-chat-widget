<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Unit;

use IndianOpinion\SseChatWidget\Chat\SseEmitter;
use PHPUnit\Framework\TestCase;

/** T36, case 41. */
final class SseEmitterTest extends TestCase
{
    public function testHeadersBuffersAndFlushAfterEveryEvent(): void
    {
        $headers = [];
        $out = [];
        $flushes = 0;
        $prepared = 0;
        $emitter = new SseEmitter(
            static function (string $h) use (&$headers): void {
                $headers[] = $h;
            },
            static function (string $b) use (&$out): void {
                $out[] = $b;
            },
            static function () use (&$flushes): void {
                $flushes++;
            },
            static fn (): bool => false,
            static function () use (&$prepared): void {
                $prepared++;
            }
        );
        $emitter->start();
        $emitter->event('meta', ['conversation' => 'abc', 'message' => 1]);
        $emitter->event('delta', ['text' => "line\nnext </script>"]);
        $emitter->comment('ping');

        self::assertSame(1, $prepared);
        self::assertContains('Content-Type: text/event-stream; charset=utf-8', $headers);
        self::assertContains('Cache-Control: no-cache, no-store, private', $headers);
        self::assertContains('X-Accel-Buffering: no', $headers);
        self::assertContains('X-Robots-Tag: noindex', $headers);
        self::assertSame(count($out), $flushes, 'one flush per write');
        self::assertSame("event: meta\ndata: {\"conversation\":\"abc\",\"message\":1}\n\n", $out[1]);
        self::assertSame("event: delta\ndata: {\"text\":\"line\\nnext </script>\"}\n\n", $out[2], 'newlines stay inside the JSON');
        self::assertSame(": ping\n\n", $out[3]);
    }

    public function testRealPrepareEndsEveryBuffer(): void
    {
        $level = ob_get_level();
        ob_start();
        ob_start();
        // Run the real routine in a nested buffer we can restore afterwards.
        SseEmitter::prepareOutput();
        self::assertSame(0, ob_get_level());
        self::assertSame('0', ini_get('zlib.output_compression'));
        while (ob_get_level() < $level) {
            ob_start();
        }
        ignore_user_abort(false);
    }

    public function testStopsWritingOnceTheReaderHasGone(): void
    {
        $writes = 0;
        $gone = false;
        $emitter = new SseEmitter(static function (): void {
        }, static function () use (&$writes): void {
            $writes++;
        }, static function (): void {
        }, static function () use (&$gone): bool {
            return $gone;
        }, static function (): void {
        });
        $emitter->start();
        $gone = true;
        $emitter->event('delta', ['text' => 'a']);
        self::assertTrue($emitter->aborted());
        $emitter->event('delta', ['text' => 'b']);
        self::assertSame(2, $writes);
    }
}
