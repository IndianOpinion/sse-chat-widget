<?php

/**
 * Supply a retriever through sse_chat_retriever. The plugin still checks
 * every returned post (published, allowed type, no password) and falls back
 * to the keyword retriever if this one throws.
 */

declare(strict_types=1);

use IndianOpinion\SseChatWidget\Retrieval\Passage;
use IndianOpinion\SseChatWidget\Retrieval\RetrievalQuery;
use IndianOpinion\SseChatWidget\Retrieval\Retriever;

add_filter('sse_chat_retriever', static function () {
    return new class implements Retriever {
        public function retrieve(RetrievalQuery $query): array
        {
            // Newest posts in the allowed types, as a deliberately simple example.
            $posts = get_posts(['post_type' => $query->postTypes, 'numberposts' => $query->limit, 'post_status' => 'publish']);
            $out = [];
            foreach ($posts as $post) {
                $out[] = new Passage(
                    $post->ID,
                    get_the_title($post),
                    (string) get_permalink($post),
                    (string) get_the_date('', $post),
                    wp_trim_words(wp_strip_all_tags($post->post_content), $query->passageWords)
                );
            }
            return $out;
        }
    };
});
