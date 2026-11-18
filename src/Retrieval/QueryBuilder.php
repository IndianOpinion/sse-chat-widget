<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Retrieval;

/**
 * The retrieval query (case 33): the new message, plus the context post
 * title when valid, plus the previous reader message when the new one is
 * under six words.
 */
final class QueryBuilder
{
    public static function build(string $message, ?string $contextTitle, ?string $previousMessage): string
    {
        $parts = [$message];
        if ($contextTitle !== null && $contextTitle !== '') {
            $parts[] = $contextTitle;
        }
        if ($previousMessage !== null && $previousMessage !== '' && self::wordCount($message) < 6) {
            $parts[] = $previousMessage;
        }
        return implode("\n", $parts);
    }

    public static function wordCount(string $text): int
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        return count(array_filter($words, static fn (string $w): bool => $w !== ''));
    }
}
