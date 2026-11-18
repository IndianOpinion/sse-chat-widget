<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model;

/**
 * Asks a model for one JSON object. The buffered-json caller uses it; the
 * shipped implementation wraps indianopinion/llm-json-php.
 */
interface JsonAsker
{
    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $schema JSON schema of the expected object.
     * @return array{data: array<string, mixed>, input_tokens: ?int, output_tokens: ?int}
     * @throws CallerFailure
     */
    public function ask(array $messages, array $schema, int $maxOutputTokens, float $temperature, int $deadlineSeconds): array;
}
