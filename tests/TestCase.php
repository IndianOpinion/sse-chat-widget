<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests;

use IndianOpinion\SseChatWidget\Chat\ChatService;
use IndianOpinion\SseChatWidget\Chat\RecordingEmitter;
use IndianOpinion\SseChatWidget\Http\IncomingRequest;
use IndianOpinion\SseChatWidget\Model\CallerFactory;
use IndianOpinion\SseChatWidget\Model\ModelCaller;
use IndianOpinion\SseChatWidget\Settings;
use IndianOpinion\SseChatWidget\Tests\Fakes\FakeClock;
use IndianOpinion\SseChatWidget\Tests\Fakes\MemoryCounterStore;
use IndianOpinion\SseChatWidget\Tests\Fakes\MemoryTurnLog;
use IndianOpinion\SseChatWidget\Tests\Fakes\MemoryWorkingState;
use IndianOpinion\SseChatWidget\Tests\Fakes\ScriptedCaller;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected FakeClock $clock;
    protected MemoryCounterStore $counters;
    protected MemoryWorkingState $state;
    protected MemoryTurnLog $log;
    protected ?ModelCaller $caller = null;
    /** @var array<string, mixed> */
    protected array $settingsOverride = [];

    protected function setUp(): void
    {
        \WpStub::reset();
        update_option(Settings::KEY_OPTION, 'sk-test-key-0000');
        $this->clock = new FakeClock();
        $this->counters = new MemoryCounterStore();
        $this->state = new MemoryWorkingState();
        $this->log = new MemoryTurnLog();
        $this->caller = ScriptedCaller::answer('The dam opened in 2019 [1].');
        $this->settingsOverride = [];
        add_filter('sse_chat_model_caller', fn () => $this->caller);
    }

    /**
     * @param array<string, mixed> $override
     */
    protected function settings(array $override = []): Settings
    {
        return new Settings(array_replace([
            'enabled' => true,
            'base_url' => 'https://llm.example.net/v1',
            'model' => 'test-model-1',
        ], $this->settingsOverride, $override));
    }

    /**
     * @param array<string, mixed> $override
     */
    protected function service(array $override = [], bool $logHealthy = true): ChatService
    {
        $settings = $this->settings($override);
        return new ChatService(
            $settings,
            $this->clock,
            $this->counters,
            $this->state,
            $this->log,
            new CallerFactory($settings),
            null,
            null,
            static fn (): bool => $logHealthy
        );
    }

    protected function token(?ChatService $service = null): string
    {
        $out = ($service ?? $this->service())->session($this->request('POST', '{}'));
        return (string) $out['body']['session'];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $server
     */
    protected function request(string $method, string $body, array $headers = ['origin' => 'https://example.org'], array $server = ['REMOTE_ADDR' => '203.0.113.7'], int $userId = 0): IncomingRequest
    {
        return new IncomingRequest($method, $headers, $body, $server, $userId);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function streamBody(array $body): string
    {
        return (string) json_encode($body);
    }

    /**
     * Run one stream request; returns [refusal or null, emitter].
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $override
     * @param array<string, mixed> $server
     * @return array{0: ?\IndianOpinion\SseChatWidget\Http\Refusal, 1: RecordingEmitter}
     */
    protected function ask(array $body, array $override = [], array $server = ['REMOTE_ADDR' => '203.0.113.7'], ?ChatService $service = null, int $userId = 0): array
    {
        $service ??= $this->service($override);
        if (!array_key_exists('session', $body)) {
            $body['session'] = $this->token($service);
        }
        $emitter = new RecordingEmitter($this->clock);
        $refusal = $service->stream($this->request('POST', $this->streamBody($body), ['origin' => 'https://example.org'], $server, $userId), $emitter);
        return [$refusal, $emitter];
    }

    protected function seedPosts(): void
    {
        \WpStub::addPost(['ID' => 1, 'post_title' => 'River dam opens after ten years', 'post_content' => '<p>The river dam opened in 2019 after a long build.</p>', 'post_date' => '2019-05-01 10:00:00']);
        \WpStub::addPost(['ID' => 2, 'post_title' => 'Town council budget', 'post_content' => 'The council passed its budget. The dam was mentioned once.', 'post_date' => '2020-02-01 10:00:00']);
        \WpStub::addPost(['ID' => 3, 'post_title' => 'Draft about the dam', 'post_content' => 'Secret draft on the dam.', 'post_status' => 'draft']);
        \WpStub::addPost(['ID' => 4, 'post_title' => 'Private dam notes', 'post_content' => 'Private dam.', 'post_status' => 'private']);
        \WpStub::addPost(['ID' => 5, 'post_title' => 'Future dam story', 'post_content' => 'Scheduled dam.', 'post_status' => 'future']);
        \WpStub::addPost(['ID' => 6, 'post_title' => 'Locked dam story', 'post_content' => 'Password dam.', 'post_password' => 'pw']);
        \WpStub::addPost(['ID' => 7, 'post_title' => 'Dam page', 'post_content' => 'A page about the dam.', 'post_type' => 'page']);
    }
}
