<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model;

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Exception\AuthenticationException;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedAttemptException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Exception\HttpException;
use IndianOpinion\LlmJson\Exception\RateLimitedException;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;

/**
 * JsonAsker over indianopinion/llm-json-php. Loaded only when the library
 * is installed; maps the library's exceptions to the codes of case 54.
 */
final class LlmJsonAsker implements JsonAsker
{
    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    public static function available(): bool
    {
        return class_exists(Client::class);
    }

    public function ask(array $messages, array $schema, int $maxOutputTokens, float $temperature, int $deadlineSeconds): array
    {
        try {
            $local = in_array(strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST)), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
            $provider = Provider::generic('sse-chat', $this->baseUrl, $this->apiKey, false, $local);
            $client = Client::create(Chain::of(Route::to($provider, $this->model)));
            $request = Request::for('sse-chat', Schema::fromArray('chat_answer', $schema))
                ->withMessages($messages)
                ->withTokenCap($maxOutputTokens)
                ->withTemperature($temperature)
                ->withDeadline((float) $deadlineSeconds);
            $result = $client->complete($request);
        } catch (\Throwable $e) {
            throw self::map($e);
        }
        $usage = $result->usage();
        return [
            'data' => $result->data(),
            'input_tokens' => $usage->promptTokens,
            'output_tokens' => $usage->completionTokens,
        ];
    }

    public static function map(\Throwable $e): CallerFailure
    {
        if ($e instanceof CreditExhaustedException || $e instanceof CreditExhaustedAttemptException) {
            return new CallerFailure('provider_credit', $e->getMessage(), 402);
        }
        if ($e instanceof ChainExhaustedException && $e->lastError() !== null) {
            $inner = self::map($e->lastError());
            return new CallerFailure($inner->errorCode, $e->getMessage(), $inner->httpStatus);
        }
        if ($e instanceof AuthenticationException) {
            return new CallerFailure('provider_auth', $e->getMessage(), 401);
        }
        if ($e instanceof RateLimitedException) {
            return new CallerFailure('provider_busy', $e->getMessage(), 429);
        }
        if ($e instanceof HttpException) {
            $status = $e->getCode() > 0 ? $e->getCode() : null;
            return new CallerFailure(ErrorMapper::fromStatus($status, $e->getMessage()), $e->getMessage(), $status);
        }
        return new CallerFailure('provider_down', $e->getMessage());
    }
}
