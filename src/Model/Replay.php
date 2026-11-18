<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model;

/**
 * Splits a whole answer into pieces of about 40 characters for replay as
 * delta events (case 53). Breaks after a space where one is near.
 */
final class Replay
{
    /**
     * @return list<string>
     */
    public static function pieces(string $text, int $size = 40): array
    {
        $out = [];
        $len = mb_strlen($text);
        $i = 0;
        while ($i < $len) {
            $piece = mb_substr($text, $i, $size);
            if ($i + $size < $len) {
                $space = mb_strrpos($piece, ' ');
                if ($space !== false && $space >= intdiv($size, 2)) {
                    $piece = mb_substr($piece, 0, $space + 1);
                }
            }
            $out[] = $piece;
            $i += mb_strlen($piece);
        }
        return $out;
    }
}
