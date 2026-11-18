<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Retrieval;

/**
 * Builds a passage from a post (case 38): manual excerpt if set, else the
 * content without shortcodes and tags, whitespace collapsed, cut to the
 * passage length at a word boundary.
 */
final class PassageBuilder
{
    public function __construct(private readonly int $words)
    {
    }

    public function build(\WP_Post $post, float $score = 0.0): Passage
    {
        $passage = new Passage(
            (int) $post->ID,
            html_entity_decode(wp_strip_all_tags(get_the_title($post)), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            (string) get_permalink($post),
            (string) get_the_date('', $post),
            $this->excerpt($post),
            $score
        );
        $filtered = apply_filters('sse_chat_passage', $passage, $post);
        return $filtered instanceof Passage ? $filtered : $passage;
    }

    public function excerpt(\WP_Post $post): string
    {
        $manual = trim((string) $post->post_excerpt);
        $source = $manual !== '' ? $manual : (string) $post->post_content;
        $text = wp_strip_all_tags(strip_shortcodes($source));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return self::cut($text, $this->words);
    }

    /**
     * Collapse whitespace and cut to $words words at a word boundary.
     */
    public static function cut(string $text, int $words): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }
        $parts = explode(' ', $text);
        if (count($parts) <= $words) {
            return $text;
        }
        return implode(' ', array_slice($parts, 0, $words)) . ' ...';
    }
}
