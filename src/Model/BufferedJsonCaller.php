<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model;

/**
 * The buffered-json caller (case 58). Asks for {"answer": string, "cited":
 * int[]}, then replays the answer as deltas. The cited list is returned for
 * the chat service to intersect with the markers present in the text.
 */
final class BufferedJsonCaller implements ModelCaller, ReportsBuffering
{
    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'answer' => ['type' => 'string'],
            'cited' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ],
        'required' => ['answer', 'cited'],
        'additionalProperties' => false,
    ];

    private const FORMAT_NOTE = 'Reply with a JSON object: "answer" holds your answer text with the bracketed passage numbers in it, and "cited" lists the passage numbers you used.';

    public function __construct(
        private readonly JsonAsker $asker,
        private readonly int $deadlineSeconds = 90,
    ) {
    }

    public function isBuffered(): bool
    {
        return true;
    }

    public function stream(ChatRequest $request, callable $onDelta, callable $shouldStop): ChatResult
    {
        if ($shouldStop()) {
            return new ChatResult('', 'aborted', started: false);
        }
        $messages = $request->messages;
        $messages[] = ['role' => 'system', 'content' => self::FORMAT_NOTE];
        try {
            $deadline = $request->timeoutSeconds !== null ? max(1, min($this->deadlineSeconds, $request->timeoutSeconds)) : $this->deadlineSeconds;
            $reply = $this->asker->ask($messages, self::SCHEMA, $request->maxOutputTokens, $request->temperature, $deadline);
        } catch (CallerFailure $e) {
            return ChatResult::failure($e->errorCode, $e->httpStatus, $e->getMessage());
        }

        $answer = is_string($reply['data']['answer'] ?? null) ? $reply['data']['answer'] : '';
        $cited = [];
        foreach (is_array($reply['data']['cited'] ?? null) ? $reply['data']['cited'] : [] as $n) {
            if (is_int($n)) {
                $cited[] = $n;
            }
        }

        $text = '';
        $deltas = 0;
        foreach (Replay::pieces($answer) as $piece) {
            // @phpstan-ignore if.alwaysFalse (the callback is impure: the reader can leave mid-replay)
            if ($shouldStop()) {
                return new ChatResult($text, 'aborted', $reply['input_tokens'], $reply['output_tokens'], 200, null, null, null, $deltas, true, $cited);
            }
            $text .= $piece;
            $deltas++;
            $onDelta($piece);
        }
        return new ChatResult($text, 'stop', $reply['input_tokens'], $reply['output_tokens'], 200, null, null, null, $deltas, true, $cited);
    }
}
