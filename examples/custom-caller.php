<?php

/**
 * Plugin a site's own model caller through the sse_chat_model_caller filter.
 * Drop into a small plugin or mu-plugin. The plugin then never loads the
 * built-in caller. This one answers with a fixed string, which is handy for
 * a staging site that must never spend.
 */

declare(strict_types=1);

use IndianOpinion\SseChatWidget\Model\ChatRequest;
use IndianOpinion\SseChatWidget\Model\ChatResult;
use IndianOpinion\SseChatWidget\Model\ModelCaller;

add_filter('sse_chat_model_caller', static function ($caller, array $settings) {
    return new class implements ModelCaller {
        public function stream(ChatRequest $request, callable $onDelta, callable $shouldStop): ChatResult
        {
            $text = 'This is a staging answer. See [1].';
            foreach (explode(' ', $text) as $i => $word) {
                if ($shouldStop()) {
                    return new ChatResult('', 'aborted');
                }
                $onDelta(($i > 0 ? ' ' : '') . $word);
            }
            return new ChatResult($text, 'stop', 0, 0, 200);
        }
    };
}, 10, 2);
