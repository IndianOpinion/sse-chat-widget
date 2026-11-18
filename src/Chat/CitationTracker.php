<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Chat;

/**
 * Finds citation markers like [2] in streamed text, across chunk
 * boundaries (case 45). Keeps the order of first appearance.
 */
final class CitationTracker
{
    private string $tail = '';

    /** @var list<int> */
    private array $markers = [];

    public function feed(string $text): void
    {
        $buffer = $this->tail . $text;
        $offset = 0;
        if (preg_match_all('/\[(\d{1,3})(?:\s*,\s*(\d{1,3}))*\]/', $buffer, $all, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($all[0] as $match) {
                if (preg_match_all('/\d{1,3}/', $match[0], $nums) > 0) {
                    foreach ($nums[0] as $n) {
                        $this->add((int) $n);
                    }
                }
                $offset = $match[1] + strlen($match[0]);
            }
        }
        $rest = substr($buffer, $offset);
        $open = strrpos($rest, '[');
        // Keep an unfinished marker (up to 16 bytes) for the next chunk.
        $this->tail = ($open !== false && strlen($rest) - $open <= 16 && !str_contains(substr($rest, $open), ']'))
            ? substr($rest, $open) : '';
    }

    /**
     * @return list<int> Markers in order of first appearance.
     */
    public function markers(): array
    {
        return $this->markers;
    }

    private function add(int $n): void
    {
        if ($n > 0 && !in_array($n, $this->markers, true)) {
            $this->markers[] = $n;
        }
    }
}
