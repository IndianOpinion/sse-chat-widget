<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Chat;

/**
 * Writes Server-Sent Events to the response (case 41): ends every output
 * buffer, turns off compression for the request, sends the stream headers
 * and flushes after every event.
 */
final class SseEmitter implements Emitter
{
    /** @var callable(string): void */
    private $header;

    /** @var callable(string): void */
    private $write;

    /** @var callable(): void */
    private $flush;

    /** @var callable(): bool */
    private $connectionAborted;

    /** @var callable(): void */
    private $prepare;

    private float $lastWrite;

    private bool $gone = false;

    public function __construct(
        ?callable $header = null,
        ?callable $write = null,
        ?callable $flush = null,
        ?callable $connectionAborted = null,
        ?callable $prepare = null,
    ) {
        $this->header = $header ?? static function (string $line): void {
            header($line);
        };
        $this->write = $write ?? static function (string $bytes): void {
            echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- SSE frames carry JSON-encoded data.
        };
        $this->flush = $flush ?? static function (): void {
            flush();
        };
        $this->connectionAborted = $connectionAborted ?? static fn (): bool => connection_aborted() === 1;
        $this->prepare = $prepare ?? [self::class, 'prepareOutput'];
        $this->lastWrite = microtime(true);
    }

    /**
     * End every output buffer, keep running after a disconnect so the
     * answer can be logged, and turn off compression for this request.
     */
    public static function prepareOutput(): void
    {
        ignore_user_abort(true);
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
        ini_set('zlib.output_compression', '0');
        ini_set('output_buffering', '0');
        ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            'Content-Type: text/event-stream; charset=utf-8',
            'Cache-Control: no-cache, no-store, private',
            'X-Accel-Buffering: no',
            'X-Robots-Tag: noindex',
            'Content-Encoding: identity',
        ];
    }

    public function start(): void
    {
        ($this->prepare)();
        foreach (self::headers() as $line) {
            ($this->header)($line);
        }
        $this->send(": stream open\n\n");
    }

    public function event(string $name, array $data): void
    {
        $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->send('event: ' . $name . "\ndata: " . $json . "\n\n");
    }

    public function comment(string $text): void
    {
        $this->send(': ' . str_replace(["\r", "\n"], ' ', $text) . "\n\n");
    }

    public function aborted(): bool
    {
        if ($this->gone) {
            return true;
        }
        $this->gone = ($this->connectionAborted)();
        return $this->gone;
    }

    public function idleSeconds(float $now): float
    {
        return $now - $this->lastWrite;
    }

    private function send(string $bytes): void
    {
        if ($this->gone) {
            return;
        }
        ($this->write)($bytes);
        ($this->flush)();
        $this->lastWrite = microtime(true);
        if (($this->connectionAborted)()) {
            $this->gone = true;
        }
    }
}
