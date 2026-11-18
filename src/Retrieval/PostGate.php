<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Retrieval;

/**
 * Case 34: only published posts of an allowed type, without a password, and
 * not excluded by the filter. Checked at retrieval, at passage building and
 * again at citation.
 */
final class PostGate
{
    /**
     * @param list<string> $postTypes
     */
    public function __construct(private readonly array $postTypes)
    {
    }

    public function allows(int|\WP_Post|null $post): bool
    {
        if (is_int($post)) {
            $post = $post > 0 ? get_post($post) : null;
        }
        if (!$post instanceof \WP_Post) {
            return false;
        }
        if ($post->post_status !== 'publish') {
            return false;
        }
        if (!in_array($post->post_type, $this->postTypes, true)) {
            return false;
        }
        if ((string) $post->post_password !== '') {
            return false;
        }
        return (bool) apply_filters('sse_chat_post_allowed', true, $post);
    }

    /**
     * @return list<string>
     */
    public function postTypes(): array
    {
        return $this->postTypes;
    }
}
