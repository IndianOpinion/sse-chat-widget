<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Chat;

use IndianOpinion\SseChatWidget\Http\ClientIp;
use IndianOpinion\SseChatWidget\Http\IncomingRequest;
use IndianOpinion\SseChatWidget\Http\OriginCheck;
use IndianOpinion\SseChatWidget\Http\Refusal;
use IndianOpinion\SseChatWidget\Limits\CounterStore;
use IndianOpinion\SseChatWidget\Limits\CounterUnavailable;
use IndianOpinion\SseChatWidget\Limits\Limiter;
use IndianOpinion\SseChatWidget\Limits\Reservation;
use IndianOpinion\SseChatWidget\Limits\Stats;
use IndianOpinion\SseChatWidget\Log\LogWriteFailed;
use IndianOpinion\SseChatWidget\Log\Schema;
use IndianOpinion\SseChatWidget\Log\TurnLog;
use IndianOpinion\SseChatWidget\Log\TurnRecord;
use IndianOpinion\SseChatWidget\Model\CallerFactory;
use IndianOpinion\SseChatWidget\Model\ChatRequest;
use IndianOpinion\SseChatWidget\Model\ChatResult;
use IndianOpinion\SseChatWidget\Model\Replay;
use IndianOpinion\SseChatWidget\Model\ReportsBuffering;
use IndianOpinion\SseChatWidget\Retrieval\KeywordRetriever;
use IndianOpinion\SseChatWidget\Retrieval\Passage;
use IndianOpinion\SseChatWidget\Retrieval\PassageBuilder;
use IndianOpinion\SseChatWidget\Retrieval\PostGate;
use IndianOpinion\SseChatWidget\Retrieval\QueryBuilder;
use IndianOpinion\SseChatWidget\Retrieval\RetrievalQuery;
use IndianOpinion\SseChatWidget\Retrieval\RetrievalRunner;
use IndianOpinion\SseChatWidget\Retrieval\Retriever;
use IndianOpinion\SseChatWidget\Session\Keys;
use IndianOpinion\SseChatWidget\Session\SessionTokens;
use IndianOpinion\SseChatWidget\Settings;
use IndianOpinion\SseChatWidget\Spend\SpendMeterBridge;
use IndianOpinion\SseChatWidget\State\WorkingState;
use IndianOpinion\SseChatWidget\Support\Clock;
use IndianOpinion\SseChatWidget\Support\DebugLog;

/**
 * The public chat path: the request checks of section 4.2, conversation
 * state, retrieval, the model call with its single retry, citations, the
 * stream of events and the log.
 */
final class ChatService
{
    public const MAX_BODY_BYTES = 16384;
    public const PING_SECONDS = 10;
    public const KNOWN_KEYS = ['session', 'conversation', 'message', 'context_post'];

    private Stats $stats;
    private Limiter $limiter;
    private SpendMeterBridge $spend;
    private PostGate $gate;

    /** @var list<array{role: string, content: string}> */
    private array $lastPrompt = [];

    /** @var list<int> */
    private array $lastCited = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly CounterStore $counters,
        private readonly WorkingState $state,
        private readonly TurnLog $log,
        private readonly CallerFactory $callers,
        private readonly ?Retriever $keyword = null,
        ?SpendMeterBridge $spend = null,
        private readonly ?\Closure $logHealthy = null,
    ) {
        $this->stats = new Stats($counters, $settings, $clock);
        $this->limiter = new Limiter($counters, $settings, $clock);
        $this->spend = $spend ?? new SpendMeterBridge();
        $this->gate = new PostGate($settings->list('post_types'));
    }

    public function tokens(): SessionTokens
    {
        return new SessionTokens(Keys::session(), $this->settings->int('session_hours') * 3600);
    }

    /**
     * POST /session (case 2). No model call, no retrieval.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function session(IncomingRequest $request): array
    {
        try {
            $this->checkOrigin($request);
            $this->checkMethod($request);
        } catch (Refusal $r) {
            return ['status' => $r->status, 'body' => $r->body()];
        }
        $public = [
            'strings' => $this->publicStrings(),
            'limits' => ['message_chars' => $this->settings->int('message_chars')],
        ];
        if (!$this->settings->bool('enabled') || !$this->settings->isConfigured()) {
            return ['status' => 200, 'body' => ['configured' => false] + $public];
        }
        $issued = $this->tokens()->issue($this->clock->now());
        return ['status' => 200, 'body' => ['session' => $issued['token'], 'expires' => $issued['expires'], 'configured' => true] + $public];
    }

    /**
     * POST /forget (case 86): hide a conversation from the widget. Never
     * deletes a log row, never lowers a counter.
     *
     * @return array{status: int, body: array<string, mixed>, retry_after?: int}
     */
    public function forget(IncomingRequest $request): array
    {
        $ip = ClientIp::resolve($request->server, $this->settings->list('trusted_proxies'));
        $ipHash = Keys::ipHash($ip, $this->clock->now());
        try {
            $this->checkOrigin($request);
            $this->checkMethod($request);
            $body = $this->parseBody($request);
            $session = $this->checkSession($body);
            $reservation = new Reservation($this->counters);
            try {
                $this->limiter->limitForget($reservation, $ipHash);
            } catch (CounterUnavailable $e) {
                throw new Refusal(503, 'limits_unavailable', $this->settings->displayString('error_generic'));
            }
            $conversation = is_string($body['conversation'] ?? null) ? $body['conversation'] : '';
            if (preg_match('/^[0-9a-f]{32}$/', $conversation) !== 1) {
                throw new Refusal(404, 'conversation_unknown', $this->settings->displayString('conversation_expired'));
            }
            $sessionHash = Keys::sessionHash($session['id']);
            $state = $this->state->get($conversation);
            $ownsState = $state !== null && hash_equals($state['session'], $sessionHash);
            if ($state !== null && !$ownsState) {
                throw new Refusal(404, 'conversation_unknown', $this->settings->displayString('conversation_expired'));
            }
            $hidden = false;
            try {
                $hidden = $this->log->hide($conversation, $sessionHash, $this->clock->now());
            } catch (LogWriteFailed $e) {
                DebugLog::throttled('log_write', 'Hiding a conversation failed: ' . $e->getMessage());
            }
            if (!$ownsState && !$hidden) {
                throw new Refusal(404, 'conversation_unknown', $this->settings->displayString('conversation_expired'));
            }
            $this->state->delete($conversation);
            return ['status' => 200, 'body' => ['forgotten' => true, 'message' => $this->settings->displayString('forgotten')]];
        } catch (Refusal $r) {
            $this->refused($r, $ipHash);
            $out = ['status' => $r->status, 'body' => $r->body()];
            if ($r->retryAfter !== null) {
                $out['retry_after'] = $r->retryAfter;
            }
            return $out;
        }
    }

    /**
     * POST /stream. Returns a Refusal when a check fails before anything is
     * sent; otherwise streams to the emitter and returns null.
     */
    public function stream(IncomingRequest $request, Emitter $emitter): ?Refusal
    {
        $ip = ClientIp::resolve($request->server, $this->settings->list('trusted_proxies'));
        $ipHash = Keys::ipHash($ip, $this->clock->now());
        $reservation = new Reservation($this->counters);
        try {
            $checked = $this->check($request, $ipHash, $reservation);
        } catch (Refusal $r) {
            $this->refused($r, $ipHash);
            return $r;
        }
        try {
            $this->run($checked, $emitter, $request->adminTest);
        } finally {
            $reservation->freeSlots();
        }
        return null;
    }

    /**
     * Run the full path for an administrator (case 77). Skips the origin
     * and session checks; counts against the caps; logged as admin_test.
     *
     * @return array{refusal: ?Refusal, events: list<array{0: string, 1: array<string, mixed>}>, prompt: list<array{role: string, content: string}>}
     */
    public function adminTest(string $question, int $contextPost, int $userId): array
    {
        $issued = $this->tokens()->issue($this->clock->now());
        $request = new IncomingRequest(
            'POST',
            ['origin' => (string) home_url()],
            (string) json_encode(['session' => $issued['token'], 'message' => $question, 'context_post' => $contextPost]),
            ['REMOTE_ADDR' => '127.0.0.1'],
            $userId,
            true
        );
        $emitter = new RecordingEmitter($this->clock);
        $this->lastPrompt = [];
        $refusal = $this->stream($request, $emitter);
        return ['refusal' => $refusal, 'events' => $emitter->events, 'prompt' => $this->lastPrompt];
    }

    /**
     * Section 4.2, in order. Throws the first refusal.
     *
     * @throws Refusal
     */
    private function check(IncomingRequest $request, string $ipHash, Reservation $reservation): CheckedRequest
    {
        if (!$this->settings->bool('enabled')) {
            throw new Refusal(503, 'disabled', $this->settings->displayString('paused'));
        }
        if (!$request->adminTest) {
            $this->checkOrigin($request);
        }
        $this->checkMethod($request);
        $body = $this->parseBody($request);
        if (!$this->settings->isConfigured()) {
            throw new Refusal(503, 'not_configured', $this->settings->displayString('paused'));
        }
        $session = $this->checkSession($body);
        $message = $this->checkMessage($body);
        $sessionHash = Keys::sessionHash($session['id']);

        $conversationId = $body['conversation'] ?? '';
        $state = null;
        if ($conversationId !== '') {
            if (!is_string($conversationId) || preg_match('/^[0-9a-f]{32}$/', $conversationId) !== 1) {
                throw new Refusal(404, 'conversation_unknown', $this->settings->displayString('conversation_expired'));
            }
            $state = $this->state->get($conversationId);
            if ($state === null || !hash_equals($state['session'], $sessionHash)) {
                throw new Refusal(404, 'conversation_unknown', $this->settings->displayString('conversation_expired'));
            }
            if ($state['turns'] >= $this->settings->int('turns_per_conversation')) {
                throw new Refusal(429, 'conversation_full', $this->settings->displayString('conversation_full'));
            }
        } else {
            $conversationId = bin2hex(random_bytes(16));
        }

        $kill = $this->settings->killSwitch();
        if ($kill['on']) {
            throw new Refusal(503, 'paused', $this->settings->displayString('paused'));
        }

        $verdict = apply_filters('sse_chat_verify_request', true, [
            'ip_hash' => $ipHash,
            'session' => $sessionHash,
            'conversation' => $conversationId,
            'message' => $message,
            'user_id' => $request->userId,
            'headers' => $request->headers,
        ]);
        if ($verdict instanceof \WP_Error) {
            $data = $verdict->get_error_data();
            $status = is_array($data) && is_int($data['status'] ?? null) ? $data['status'] : 403;
            $code = (string) $verdict->get_error_code();
            throw new Refusal($status, $code !== '' ? $code : 'refused', $verdict->get_error_message() !== '' ? $verdict->get_error_message() : $this->settings->displayString('error_generic'));
        }

        try {
            $this->limiter->acquireSlots($reservation, $ipHash);
            $this->limiter->reserve($reservation, $ipHash, $session['id'], $session['expires']);
        } catch (CounterUnavailable $e) {
            $reservation->release();
            DebugLog::throttled('counters', 'Counter store unavailable: ' . $e->getMessage());
            throw new Refusal(503, 'limits_unavailable', $this->settings->displayString('paused'));
        }

        $spendReservation = null;
        if (SpendMeterBridge::active()) {
            $estimate = (int) ceil((strlen($this->settings->string('prompt_template')) + strlen($message)) / 4)
                + $this->settings->int('passages') * $this->settings->int('passage_words') * 2
                + $this->settings->int('history_window') * 200;
            $answer = $this->spend->preSpend($this->provider(), $this->settings->string('model'), $estimate, $this->settings->int('max_tokens'));
            if (!$answer['allowed']) {
                $reservation->release();
                throw new Refusal(503, 'spend_denied', $this->settings->displayString('paused'));
            }
            $spendReservation = $answer['reservation'];
        }

        $unknown = array_diff(array_keys($body), self::KNOWN_KEYS);
        $notes = [];
        if ($unknown !== []) {
            DebugLog::line('Ignored unknown body keys: ' . implode(',', array_map('strval', array_slice($unknown, 0, 10))));
        }

        $context = $body['context_post'] ?? null;
        $contextPost = null;
        $contextId = is_int($context) || (is_string($context) && ctype_digit($context)) ? (int) $context : 0;
        if ($contextId > 0) {
            $post = get_post($contextId);
            if ($post instanceof \WP_Post && $this->gate->allows($post)) {
                $contextPost = (new PassageBuilder($this->settings->int('passage_words')))->build($post);
            } else {
                $notes[] = 'context_ignored';
            }
        } elseif ($context !== null && $context !== '' && $context !== 0) {
            $notes[] = 'context_ignored';
        }

        return new CheckedRequest(
            $conversationId,
            $state,
            $session['id'],
            $sessionHash,
            $session['expires'],
            $message,
            $contextPost,
            $contextId,
            $ipHash,
            $request->userId,
            $spendReservation,
            $notes
        );
    }

    /**
     * @throws Refusal
     */
    private function checkOrigin(IncomingRequest $request): void
    {
        $origin = OriginCheck::forSite((string) home_url(), $this->settings->list('allowed_origins'));
        if (!$origin->passes($request->header('origin'), $request->header('referer'))) {
            throw new Refusal(403, 'origin_refused', $this->settings->displayString('error_generic'));
        }
    }

    /**
     * @throws Refusal
     */
    private function checkMethod(IncomingRequest $request): void
    {
        if (strtoupper($request->method) !== 'POST') {
            throw new Refusal(405, 'bad_request', $this->settings->displayString('error_generic'));
        }
    }

    /**
     * @return array<string, mixed>
     * @throws Refusal
     */
    private function parseBody(IncomingRequest $request): array
    {
        if (strlen($request->body) > self::MAX_BODY_BYTES) {
            throw new Refusal(413, 'too_large', $this->settings->displayString('message_too_long'));
        }
        if (!mb_check_encoding($request->body, 'UTF-8')) {
            throw new Refusal(400, 'bad_encoding', $this->settings->displayString('error_generic'));
        }
        $body = json_decode($request->body, true);
        if (!is_array($body) || ($body !== [] && array_is_list($body)) || !str_starts_with(ltrim($request->body), '{')) {
            throw new Refusal(400, 'bad_request', $this->settings->displayString('error_generic'));
        }
        return $body;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{id: string, issued: int, expires: int}
     * @throws Refusal
     */
    private function checkSession(array $body): array
    {
        $session = $this->tokens()->verify($body['session'] ?? null, $this->clock->now());
        if ($session === null) {
            throw new Refusal(401, 'session_invalid', $this->settings->displayString('error_generic'));
        }
        return $session;
    }

    /**
     * Case 15.
     *
     * @param array<string, mixed> $body
     * @throws Refusal
     */
    private function checkMessage(array $body): string
    {
        $raw = $body['message'] ?? null;
        if (!is_string($raw)) {
            throw new Refusal(400, 'empty_message', $this->settings->displayString('error_generic'));
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw new Refusal(400, 'bad_encoding', $this->settings->displayString('error_generic'));
        }
        $clean = str_replace("\r\n", "\n", $raw);
        $clean = (string) preg_replace('/[^\P{Cc}\t\n]/u', '', $clean);
        $clean = trim($clean);
        if ($clean === '') {
            throw new Refusal(400, 'empty_message', $this->settings->displayString('error_generic'));
        }
        if (mb_strlen($clean) > $this->settings->int('message_chars')) {
            throw new Refusal(413, 'message_too_long', $this->settings->displayString('message_too_long'));
        }
        return $clean;
    }

    private function refused(Refusal $r, string $ipHash): void
    {
        $this->stats->bump('refused:' . $r->errorCode);
        do_action('sse_chat_request_refused', $r->errorCode, $ipHash);
    }

    /**
     * Everything after the checks: the stream itself.
     */
    private function run(CheckedRequest $req, Emitter $emitter, bool $adminTest): void
    {
        $startedAt = $this->clock->micro();
        $deadline = $startedAt + $this->settings->int('max_stream_seconds');
        $notes = $req->notes;

        $caller = $this->callers->make();
        $buffered = $caller instanceof ReportsBuffering && $caller->isBuffered();
        if ($buffered) {
            $notes[] = 'buffered';
        }

        // Reserve the turn in working state before anything else.
        $state = $req->state ?? ['session' => $req->sessionHash, 'turns' => 0, 'started' => $this->clock->now(), 'history' => []];
        $previousHistory = $state['history'];
        $turn = $state['turns'] + 1;
        $state['turns'] = $turn;
        $ttl = max(60, $req->sessionExpires - $this->clock->now());
        $history = $this->state->set($req->conversationId, $state, $ttl);
        if (!$history) {
            $previousHistory = [];
            $notes[] = 'history_off';
        }

        $emitter->start();
        $emitter->event('meta', [
            'conversation' => $req->conversationId,
            'message' => $turn,
            'buffered' => $buffered,
            'history' => $history,
        ]);
        $emitter->event('status', ['text' => $this->settings->displayString('status_searching')]);

        // Retrieval (cases 33 to 40).
        $previousReader = null;
        for ($i = count($previousHistory) - 1; $i >= 0; $i--) {
            if ($previousHistory[$i]['role'] === 'user') {
                $previousReader = $previousHistory[$i]['content'];
                break;
            }
        }
        $queryText = QueryBuilder::build($req->message, $req->contextPost?->title, $previousReader);
        $custom = apply_filters('sse_chat_retriever', null, $this->settings->all());
        $runner = new RetrievalRunner(
            $this->gate,
            $this->keyword ?? new KeywordRetriever($this->gate),
            $custom instanceof Retriever ? $custom : null
        );
        $passages = $runner->run(new RetrievalQuery(
            $queryText,
            $req->contextPost?->postId,
            min(10, max(1, $this->settings->int('passages'))),
            $this->gate->postTypes(),
            $this->settings->int('passage_words')
        ));
        $notes = array_merge($notes, $runner->notes());

        $tracker = new CitationTracker();
        $text = '';
        $firstTokenMs = null;
        $onDelta = function (string $piece) use ($emitter, $tracker, &$text, &$firstTokenMs, $startedAt): void {
            if ($firstTokenMs === null) {
                $firstTokenMs = (int) round(($this->clock->micro() - $startedAt) * 1000);
            }
            $text .= $piece;
            $tracker->feed($piece);
            $emitter->event('delta', ['text' => $piece]);
        };

        $result = null;
        $stopReason = null;

        if ($passages === [] && $this->settings->string('no_context') === 'decline') {
            // Case 39 (and 80): no passages, no model call.
            foreach (Replay::pieces($this->settings->displayString('no_context')) as $piece) {
                $onDelta($piece);
            }
            $outcome = 'no_context';
            $this->stats->bump('no_context');
            if (SpendMeterBridge::active()) {
                $this->spend->release($req->spendReservation);
            }
            $emitter->event('citations', ['sources' => [], 'related' => []]);
            $emitter->event('done', ['finish' => 'stop']);
        } else {
            $builder = new PromptBuilder($this->settings->string('prompt_template'), [
                'site_name' => (string) get_bloginfo('name'),
                'site_description' => (string) get_bloginfo('description'),
                'date' => (string) wp_date((string) get_option('date_format', 'j F Y'), $this->clock->now()),
            ]);
            $window = $this->settings->int('history_window');
            $historyForModel = $window > 0 ? array_slice($previousHistory, -$window) : [];
            $messages = $builder->messages($passages, $req->contextPost, $historyForModel, $req->message);
            $this->lastPrompt = $messages;
            $chat = new ChatRequest($messages, $this->settings->string('model'), $this->settings->int('max_tokens'), $this->settings->float('temperature'));

            $shouldStop = function () use ($emitter, $deadline, &$stopReason): bool {
                if ($stopReason !== null) {
                    return true;
                }
                if ($emitter->aborted()) {
                    $stopReason = 'aborted';
                    return true;
                }
                $now = $this->clock->micro();
                if ($now >= $deadline) {
                    $stopReason = 'timeout';
                    return true;
                }
                if ($emitter->idleSeconds($now) >= self::PING_SECONDS) {
                    $emitter->comment('ping');
                }
                return false;
            };

            $attempt = 0;
            do {
                $attempt++;
                $result = $this->callSafely($caller, $chat, $onDelta, $shouldStop);
                $this->recordSpend($req, $result);
            } while ($attempt < 2 && $result->retryable() && $stopReason === null && $text === '');

            $outcome = $this->finishStream($emitter, $result, $stopReason, $passages, $tracker, $text, $notes);
        }

        if ($adminTest) {
            $notes[] = 'admin_test';
            $this->stats->bump('admin_test');
            if ($outcome === 'answered') {
                $outcome = 'admin_test';
            }
        }

        // Working history (case 29).
        if ($history) {
            $state['history'] = array_slice(array_merge($previousHistory, [
                ['role' => 'user', 'content' => $req->message],
                ['role' => 'assistant', 'content' => $text],
            ]), -40);
            $this->state->set($req->conversationId, $state, $ttl);
        }

        $inputTokens = $result?->inputTokens;
        $outputTokens = $result?->outputTokens;
        $this->stats->bump('tokens', (int) $inputTokens + (int) $outputTokens);
        if ($outcome === 'aborted') {
            $this->stats->bump('aborted');
        }
        $retrieved = array_map(static fn (Passage $p): int => $p->postId, $passages);
        $citedIds = $this->lastCited;
        if ($citedIds === [] && $text !== '' && $outcome !== 'no_context') {
            $notes[] = 'uncited';
            $this->stats->bump('uncited');
        }
        if ($result !== null && $result->errorCode !== null) {
            update_option('sse_chat_last_provider_error', ['code' => $result->errorCode, 'time' => $this->clock->now()], false);
        }

        $totalMs = (int) round(($this->clock->micro() - $startedAt) * 1000);
        $this->writeLog(new TurnRecord(
            $req->conversationId,
            $req->sessionHash,
            $req->userId,
            $req->ipHash,
            $req->contextPost !== null ? $req->contextPost->postId : 0,
            $turn,
            $req->message,
            $text,
            $retrieved,
            $citedIds,
            $outcome,
            $this->settings->string('model'),
            $inputTokens,
            $outputTokens,
            $firstTokenMs,
            $totalMs,
            $this->clock->now(),
            array_values(array_unique($notes))
        ));

        do_action('sse_chat_answer_completed', $req->conversationId, $turn, $outcome, $citedIds, [
            'input' => $inputTokens,
            'output' => $outputTokens,
        ]);

        DebugLog::line(sprintf(
            'conversation=%s outcome=%s retriever=%s passages=%d cited=%d first_token_ms=%s total_ms=%d tokens=%s/%s',
            $req->conversationId,
            $outcome,
            $runner->used(),
            count($passages),
            count($citedIds),
            $firstTokenMs === null ? '-' : (string) $firstTokenMs,
            $totalMs,
            $inputTokens === null ? '-' : (string) $inputTokens,
            $outputTokens === null ? '-' : (string) $outputTokens
        ));
    }

    /**
     * Send citations, error and done for a finished call.
     *
     * @param list<Passage> $passages
     * @param list<string> $notes
     * @return string The outcome for the log.
     */
    private function finishStream(Emitter $emitter, ChatResult $result, ?string $stopReason, array $passages, CitationTracker $tracker, string $text, array &$notes): string
    {
        $this->lastCited = [];
        if ($stopReason === 'aborted' || ($stopReason === null && $result->finish === 'aborted')) {
            $emitter->event('done', ['finish' => 'aborted']);
            return 'aborted';
        }
        if ($stopReason === 'timeout') {
            $this->sendCitations($emitter, $passages, $tracker, $result, $text);
            $emitter->event('error', ['code' => 'timeout', 'message' => $this->settings->displayString('timeout')]);
            $emitter->event('done', ['finish' => 'length']);
            return 'timeout';
        }
        if ($result->errorCode !== null) {
            if ($result->providerMessage !== null && $result->providerMessage !== '') {
                DebugLog::line('Provider error ' . $result->errorCode . ' (HTTP ' . ($result->httpStatus ?? '-') . '): ' . $result->providerMessage);
                $notes[] = 'provider:' . mb_substr($result->providerMessage, 0, 120);
            }
            if ($text !== '') {
                $this->sendCitations($emitter, $passages, $tracker, $result, $text);
            }
            $emitter->event('error', ['code' => $result->errorCode, 'message' => $this->settings->displayString($result->errorCode)]);
            $emitter->event('done', ['finish' => 'stop']);
            return $result->errorCode;
        }
        $this->sendCitations($emitter, $passages, $tracker, $result, $text);
        $finish = $result->finish === 'length' ? 'length' : 'stop';
        $emitter->event('done', ['finish' => $finish]);
        return 'answered';
    }

    /**
     * Case 46: sources are the passages whose markers appeared, in order of
     * first appearance, each rechecked against the post gate.
     *
     * @param list<Passage> $passages
     */
    private function sendCitations(Emitter $emitter, array $passages, CitationTracker $tracker, ChatResult $result, string $text): void
    {
        $byMarker = [];
        foreach ($passages as $p) {
            $byMarker[$p->marker] = $p;
        }
        $markers = $tracker->markers();
        if ($result->cited !== null) {
            $markers = array_values(array_intersect($markers, $result->cited));
        }
        $sources = [];
        foreach ($markers as $n) {
            $p = $byMarker[$n] ?? null;
            if ($p === null || !$this->gate->allows($p->postId)) {
                continue;
            }
            $sources[] = $this->publicPassage($p);
            $this->lastCited[] = $p->postId;
        }
        $related = [];
        if ($sources === [] && $text !== '' && $this->settings->bool('show_related')) {
            foreach ($passages as $p) {
                if ($this->gate->allows($p->postId)) {
                    $related[] = $this->publicPassage($p);
                }
            }
        }
        $emitter->event('citations', ['sources' => $sources, 'related' => $related]);
    }

    /**
     * Only titles, URLs and dates reach the browser, never excerpts.
     *
     * @return array{n: int, post: int, title: string, url: string, date: string}
     */
    private function publicPassage(Passage $p): array
    {
        return ['n' => $p->marker, 'post' => $p->postId, 'title' => $p->title, 'url' => $p->url, 'date' => $p->date];
    }

    private function callSafely(\IndianOpinion\SseChatWidget\Model\ModelCaller $caller, ChatRequest $chat, callable $onDelta, callable $shouldStop): ChatResult
    {
        try {
            return $caller->stream($chat, $onDelta, $shouldStop);
        } catch (\Throwable $e) {
            // Callers should not throw; treat it as an unreachable provider.
            return ChatResult::failure('provider_down', null, get_class($e) . ': ' . $e->getMessage(), false);
        }
    }

    private function recordSpend(CheckedRequest $req, ChatResult $result): void
    {
        if (!SpendMeterBridge::active()) {
            return;
        }
        if (!$result->started) {
            $this->spend->release($req->spendReservation);
            return;
        }
        if ($result->errorCode === null) {
            $status = 'ok';
        } elseif ($result->deltas > 0 || (int) $result->outputTokens > 0 || $result->httpStatus === 200) {
            $status = 'error_billed';
        } else {
            $status = 'error_unbilled';
        }
        $this->spend->record(
            $req->spendReservation,
            $status,
            $result->inputTokens,
            $result->outputTokens,
            $result->httpStatus,
            $this->provider(),
            $this->settings->string('model')
        );
    }

    private function writeLog(TurnRecord $turn): void
    {
        $mode = $this->settings->string('log_mode');
        if ($mode === 'off') {
            return;
        }
        try {
            $healthy = $this->logHealthy !== null ? (bool) ($this->logHealthy)() : Schema::healthy();
            if (!$healthy) {
                throw new LogWriteFailed('Log tables failed the column check.');
            }
            $this->log->record($turn, $mode === 'metadata' ? 'metadata' : 'full');
        } catch (LogWriteFailed $e) {
            // Case 82: the reader is not punished for the log.
            $this->stats->bump('log_write_failed');
            DebugLog::throttled('log_write', 'Log write failed: ' . $e->getMessage(), 60);
        }
    }

    private function provider(): string
    {
        $host = parse_url($this->settings->string('base_url'), PHP_URL_HOST);
        return is_string($host) ? $host : 'unknown';
    }

    /**
     * @return array<string, string>
     */
    private function publicStrings(): array
    {
        $keys = ['title', 'greeting', 'placeholder', 'disclaimer', 'send', 'stop', 'new_conversation', 'forget', 'forgotten',
            'status_searching', 'sources_label', 'related_label', 'cut_short', 'conversation_expired', 'conversation_full',
            'message_too_long', 'error_generic', 'busy', 'rate_limited', 'paused', 'daily_cap'];
        $all = $this->settings->strings();
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $all[$k] ?? '';
        }
        return $out;
    }
}
