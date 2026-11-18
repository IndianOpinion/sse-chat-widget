<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model;

/**
 * Incremental parser for an upstream text/event-stream body. Feed it bytes
 * as they arrive; it returns the data payloads of complete events. Lines
 * split across chunks are held until complete.
 */
final class SseLineParser
{
    private string $buffer = '';

    /** @var list<string> */
    private array $data = [];

    /**
     * @return list<string> Data payloads, one per complete event.
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $out = [];
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);
            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }
            if ($line === '') {
                if ($this->data !== []) {
                    $out[] = implode("\n", $this->data);
                    $this->data = [];
                }
                continue;
            }
            if ($line[0] === ':') {
                continue;
            }
            if (str_starts_with($line, 'data:')) {
                $value = substr($line, 5);
                if (str_starts_with($value, ' ')) {
                    $value = substr($value, 1);
                }
                $this->data[] = $value;
            }
        }
        return $out;
    }

    /**
     * Flush a final event that was not followed by a blank line.
     *
     * @return list<string>
     */
    public function finish(): array
    {
        $out = $this->feed("\n\n");
        $this->buffer = '';
        return $out;
    }
}
