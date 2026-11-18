<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Retrieval;

/**
 * The built-in keyword retriever (case 36). One core search query over the
 * allowed post types, relevance then newest first, limited to four times
 * the passage count, reranked by query terms in the title (weight 3) and the
 * excerpt (weight 1). Spends nothing.
 *
 * Core search requires every term to match, which drops most natural
 * questions. For this query only, the search clause matches any term; the
 * rerank then puts the posts that match most terms first.
 */
final class KeywordRetriever implements Retriever
{
    private const STOPWORDS = [
        'a', 'about', 'above', 'after', 'again', 'all', 'also', 'am', 'an', 'and', 'any', 'are', 'as', 'at',
        'be', 'been', 'before', 'being', 'both', 'but', 'by', 'can', 'could', 'did', 'do', 'does', 'doing',
        'during', 'each', 'few', 'for', 'from', 'further', 'had', 'has', 'have', 'having', 'he', 'her', 'here',
        'hers', 'him', 'his', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'just', 'me', 'more', 'most',
        'my', 'no', 'nor', 'not', 'now', 'of', 'off', 'on', 'once', 'only', 'or', 'other', 'our', 'out', 'over',
        'own', 'same', 'she', 'should', 'so', 'some', 'such', 'than', 'that', 'the', 'their', 'them', 'then',
        'there', 'these', 'they', 'this', 'those', 'through', 'to', 'too', 'under', 'until', 'up', 'very', 'was',
        'we', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'whom', 'why', 'will', 'with', 'would',
        'you', 'your', 'tell', 'please', 'know', 'say', 'said', 'says', 'site', 'article', 'articles', 'write',
        'wrote', 'written', 'anything', 'something', 'thing', 'things', 'get', 'got',
    ];

    private const MAX_TERMS = 8;

    public function __construct(private readonly PostGate $gate)
    {
    }

    public function retrieve(RetrievalQuery $query): array
    {
        $terms = self::terms($query->text);
        if ($terms === [] || $query->limit < 1) {
            return [];
        }

        $searchFilter = static function (string $search, \WP_Query $wpQuery) use ($terms): string {
            if (!$wpQuery->get('sse_chat_any_term')) {
                return $search;
            }
            global $wpdb;
            $clauses = [];
            foreach ($terms as $term) {
                $like = '%' . $wpdb->esc_like($term) . '%';
                $clauses[] = (string) $wpdb->prepare(
                    "({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR {$wpdb->posts}.post_content LIKE %s)",
                    $like,
                    $like,
                    $like
                );
            }
            return ' AND (' . implode(' OR ', $clauses) . ') ';
        };

        add_filter('posts_search', $searchFilter, 10, 2);
        try {
            $wpQuery = new \WP_Query([
                's' => implode(' ', $terms),
                'post_type' => $query->postTypes,
                'post_status' => 'publish',
                'has_password' => false,
                'posts_per_page' => 4 * $query->limit,
                'orderby' => ['relevance' => 'DESC', 'date' => 'DESC'],
                'ignore_sticky_posts' => true,
                'no_found_rows' => true,
                'update_post_term_cache' => false,
                'update_post_meta_cache' => false,
                'sse_chat_any_term' => true,
            ]);
            $posts = $wpQuery->posts;
        } finally {
            remove_filter('posts_search', $searchFilter, 10);
        }

        $builder = new PassageBuilder($query->passageWords);
        $scored = [];
        $seen = [];
        $rank = 0;
        foreach (is_array($posts) ? $posts : [] as $post) {
            if (!$post instanceof \WP_Post || isset($seen[$post->ID]) || !$this->gate->allows($post)) {
                continue;
            }
            $seen[$post->ID] = true;
            $passage = $builder->build($post);
            $score = 3 * self::hits($terms, $passage->title) + self::hits($terms, $passage->excerpt);
            $scored[] = [$score, $rank++, $passage->withScore((float) $score)];
        }

        usort($scored, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        $out = [];
        foreach (array_slice($scored, 0, $query->limit) as $row) {
            $out[] = $row[2];
        }
        return $out;
    }

    /**
     * Distinct lower-case search terms, stopwords and one- or two-letter
     * words removed, at most eight, longest kept.
     *
     * @return list<string>
     */
    public static function terms(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $terms = [];
        foreach ($words as $w) {
            if ($w === '' || mb_strlen($w) < 3 || in_array($w, self::STOPWORDS, true)) {
                continue;
            }
            $terms[$w] = true;
        }
        $terms = array_map('strval', array_keys($terms));
        if (count($terms) > self::MAX_TERMS) {
            $order = array_flip($terms);
            usort($terms, static fn (string $a, string $b): int => [mb_strlen($b), $order[$a]] <=> [mb_strlen($a), $order[$b]]);
            $terms = array_slice($terms, 0, self::MAX_TERMS);
        }
        return array_values($terms);
    }

    /**
     * @param list<string> $terms
     */
    public static function hits(array $terms, string $text): int
    {
        $text = mb_strtolower($text);
        $n = 0;
        foreach ($terms as $t) {
            if (str_contains($text, $t)) {
                $n++;
            }
        }
        return $n;
    }
}
