<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model;

use IndianOpinion\SseChatWidget\Model\Transport\Transport;

/**
 * The built-in caller (cases 52 to 57): an OpenAI-compatible chat
 * completions request with "stream": true. Parses data lines, stops at
 * [DONE], reads usage from the final chunk. A non-200 body is read as an
 * error document, never as SSE. On a transport that cannot stream it asks
 * for a whole answer and replays it in pieces.
 */
final class StreamingCaller implements ModelCaller, ReportsBuffering
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $baseUrl,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 90,
    ) {
    }

    public function isBuffered(): bool
    {
        return !$this->transport->streams();
    }

    public function endpoint(): string
    {
        return rtrim($this->baseUrl, '/') . '/chat/completions';
    }

    public function stream(ChatRequest $request, callable $onDelta, callable $shouldStop): ChatResult
    {
        $streaming = $this->transport->streams();
        $payload = [
            'model' => $request->model,
            'messages' => $request->messages,
            'max_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
            'stream' => $streaming,
        ];
        if ($streaming) {
            $payload['stream_options'] = ['include_usage' => true];
        }
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => $streaming ? 'text/event-stream' : 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];

        $state = new StreamState();
        $parser = new SseLineParser();

        $onChunk = function (string $bytes) use ($parser, $state, $onDelta, $shouldStop): bool {
            foreach ($parser->feed($bytes) as $data) {
                $this->handleData($data, $state, $onDelta);
                if ($state->done || $state->errorCode !== null) {
                    return false;
                }
            }
            return !$shouldStop();
        };

        $timeout = $request->timeoutSeconds !== null ? max(1, min($this->timeoutSeconds, $request->timeoutSeconds)) : $this->timeoutSeconds;
        $result = $this->transport->post($this->endpoint(), $headers, $body, $onChunk, $shouldStop, $timeout);

        if ($streaming && $result->status === 200 && !$state->done && $state->errorCode === null && !$result->aborted) {
            foreach ($parser->finish() as $data) {
                $this->handleData($data, $state, $onDelta);
            }
        }

        if ($state->errorCode !== null) {
            return $state->result(200, 'stop', $state->errorCode, $state->providerMessage);
        }
        if ($result->aborted && !$state->done) {
            return $state->result($result->status, 'aborted');
        }
        if ($result->status === null) {
            return $state->result(null, 'stop', 'provider_down', $result->error ?? 'no response');
        }
        if ($result->status !== 200) {
            $text = $this->scrubbed($result->body);
            return $state->result($result->status, 'stop', ErrorMapper::fromStatus($result->status, $result->body), $text);
        }
        if ($result->error !== null && !$state->done) {
            // The connection dropped part way through a 200 stream.
            // Before any delta it counts as a connection failure and may be retried.
            return $state->result($state->deltas > 0 ? 200 : null, $state->finish ?? 'stop', 'provider_down', $result->error);
        }

        if (!$streaming) {
            return $this->replay($result->body, $state, $onDelta, $shouldStop);
        }
        return $state->result(200, $state->finish ?? 'stop');
    }

    private function handleData(string $data, StreamState $state, callable $onDelta): void
    {
        $data = trim($data);
        if ($data === '[DONE]') {
            $state->done = true;
            return;
        }
        $json = json_decode($data, true);
        if (!is_array($json)) {
            return;
        }
        if (isset($json['error']) && is_array($json['error'])) {
            $state->errorCode = ErrorMapper::fromErrorFrame($json['error']);
            $state->providerMessage = $this->scrubbed((string) json_encode($json['error']));
            return;
        }
        if (isset($json['id']) && is_string($json['id']) && $state->requestId === null) {
            $state->requestId = $json['id'];
        }
        $state->readUsage($json);
        $choice = $json['choices'][0] ?? null;
        if (!is_array($choice)) {
            return;
        }
        $text = $choice['delta']['content'] ?? null;
        if (is_string($text) && $text !== '') {
            $state->text .= $text;
            $state->deltas++;
            $onDelta($text);
        }
        $finish = $choice['finish_reason'] ?? null;
        if (is_string($finish) && $finish !== '') {
            $state->finish = $finish === 'length' ? 'length' : 'stop';
        }
    }

    private function replay(string $body, StreamState $state, callable $onDelta, callable $shouldStop): ChatResult
    {
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return $state->result(200, 'stop', 'provider_down', 'unreadable response body');
        }
        if (isset($json['error']) && is_array($json['error'])) {
            return $state->result(200, 'stop', ErrorMapper::fromErrorFrame($json['error']), $this->scrubbed((string) json_encode($json['error'])));
        }
        if (isset($json['id']) && is_string($json['id'])) {
            $state->requestId = $json['id'];
        }
        $state->readUsage($json);
        $choice = $json['choices'][0] ?? null;
        $text = is_array($choice) && is_string($choice['message']['content'] ?? null) ? $choice['message']['content'] : '';
        $finish = is_array($choice) && ($choice['finish_reason'] ?? null) === 'length' ? 'length' : 'stop';
        foreach (Replay::pieces($text) as $piece) {
            if ($shouldStop()) {
                return $state->result(200, 'aborted');
            }
            $state->text .= $piece;
            $state->deltas++;
            $onDelta($piece);
        }
        return $state->result(200, $finish);
    }

    /**
     * Provider error text with the key removed. Some providers echo part of
     * the key or the Authorization header in an error body.
     */
    private function scrubbed(string $body): string
    {
        $text = self::providerText($body);
        if (strlen($this->apiKey) >= 8) {
            $text = str_replace($this->apiKey, '[key]', $text);
        }
        return (string) preg_replace('/\b(Bearer)\s+\S+/i', '$1 [key]', $text);
    }

    /**
     * Provider error text for the log only, trimmed and without control characters.
     */
    public static function providerText(string $body): string
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $msg = $json['error']['message'] ?? ($json['message'] ?? null);
            if (is_string($msg)) {
                $body = $msg;
            }
        }
        $body = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $body);
        return mb_substr(trim($body), 0, 300);
    }
}
