<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Unit;

use IndianOpinion\SseChatWidget\State\CacheWorkingState;
use IndianOpinion\SseChatWidget\Tests\Fakes\ScriptedCaller;
use IndianOpinion\SseChatWidget\Tests\TestCase;

final class ConversationTest extends TestCase
{
    /** T26, cases 29 and 30. */
    public function testHistoryWindowKeepsLastMessagesOldestFirst(): void
    {
        $this->seedPosts();
        $service = $this->service(['history_window' => 12, 'per_session_10min' => 100]);
        $token = $this->token($service);
        $caller = new ScriptedCaller([['chunks' => ['dam answer']]]);
        $this->caller = $caller;
        $conversation = '';
        for ($i = 1; $i <= 8; $i++) {
            [, $e] = $this->ask(['session' => $token, 'message' => 'dam question number ' . $i, 'conversation' => $conversation], [], ['REMOTE_ADDR' => '203.0.113.7'], $service);
            $conversation = (string) $e->of('meta')[0]['conversation'];
        }
        $last = $caller->requests[7]->messages;
        // system, 12 history messages, passages, reader message.
        self::assertCount(15, $last);
        self::assertSame('system', $last[0]['role']);
        self::assertSame('dam question number 2', $last[1]['content'], 'oldest kept first, older dropped');
        self::assertSame('assistant', $last[2]['role']);
        self::assertSame('dam answer', $last[12]['content']);
        self::assertStringStartsWith('PASSAGES', $last[13]['content']);
        self::assertStringEndsWith('dam question number 8', $last[14]['content']);
        // Working state exists and is not the log.
        self::assertSame(8, $this->state->items[$conversation]['turns']);
    }

    /** T27, case 31: expired working state with a log present is 404, never rebuilt. */
    public function testExpiredStateIsNotRebuiltFromLog(): void
    {
        $service = $this->service();
        $token = $this->token($service);
        [, $e] = $this->ask(['session' => $token, 'message' => 'first'], [], ['REMOTE_ADDR' => '203.0.113.7'], $service);
        $conversation = (string) $e->of('meta')[0]['conversation'];
        self::assertCount(1, $this->log->turns);
        $this->state->items = [];
        [$r] = $this->ask(['session' => $token, 'message' => 'second', 'conversation' => $conversation], [], ['REMOTE_ADDR' => '203.0.113.7'], $service);
        self::assertSame([404, 'conversation_unknown'], [$r?->status, $r?->errorCode]);
        self::assertSame([], $this->state->items);
    }

    /** T28, case 32. */
    public function testConversationIdsAreHexAndUnique(): void
    {
        $service = $this->service(['per_ip_hour' => 1000, 'daily_messages' => 100000]);
        [, $e] = $this->ask(['message' => 'hello'], [], ['REMOTE_ADDR' => '203.0.113.7'], $service);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $e->of('meta')[0]['conversation']);
        $seen = [];
        for ($i = 0; $i < 10000; $i++) {
            $seen[bin2hex(random_bytes(16))] = true;
        }
        self::assertCount(10000, $seen);
    }

    /** T69, case 85. */
    public function testWorkingStateUnavailableGivesOneTurnConversation(): void
    {
        $this->seedPosts();
        $this->state->broken = true;
        $caller = new ScriptedCaller([['chunks' => ['ok']]]);
        $this->caller = $caller;
        [$r, $e] = $this->ask(['message' => 'dam?']);
        self::assertNull($r);
        self::assertFalse($e->of('meta')[0]['history']);
        self::assertCount(3, $caller->requests[0]->messages, 'system, passages and the reader message only');
        self::assertContains('history_off', $this->log->last()->notes);
    }

    public function testCacheWorkingStateUsesTransientsOrObjectCache(): void
    {
        $store = new CacheWorkingState();
        $id = str_repeat('ab', 16);
        $state = ['session' => 's', 'turns' => 1, 'started' => 1, 'history' => [['role' => 'user', 'content' => 'x'], ['role' => 'system', 'content' => 'forged']]];
        self::assertTrue($store->set($id, $state, 60));
        self::assertArrayHasKey('sse_chat_c_' . $id, \WpStub::$transients);
        self::assertSame([['role' => 'user', 'content' => 'x']], $store->get($id)['history'] ?? null, 'only user and assistant turns survive');
        self::assertNull($store->get('../../etc'));
        \WpStub::$extCache = true;
        self::assertTrue($store->set($id, $state, 60));
        self::assertArrayHasKey($id, \WpStub::$cache['sse_chat']);
        $store->delete($id);
        self::assertNull($store->get($id));
        \WpStub::$transientsFail = true;
        self::assertFalse($store->set($id, $state, 60));
    }

    /** T71, case 86 (service part; table counts are checked in the database suite). */
    public function testForgetHidesClearsHistoryAndChangesNothingElse(): void
    {
        $service = $this->service();
        $token = $this->token($service);
        [, $e] = $this->ask(['session' => $token, 'message' => 'first'], [], ['REMOTE_ADDR' => '203.0.113.7'], $service);
        $conversation = (string) $e->of('meta')[0]['conversation'];
        $countersBefore = $this->counters->rows;
        $turnsBefore = $this->log->turns;

        // Another session's id: 404 and no change.
        $other = $service->forget($this->request('POST', (string) json_encode(['session' => $this->token($service), 'conversation' => $conversation])));
        self::assertSame(404, $other['status']);
        self::assertArrayHasKey($conversation, $this->state->items);
        self::assertSame([], $this->log->hidden);

        $out = $service->forget($this->request('POST', (string) json_encode(['session' => $token, 'conversation' => $conversation])));
        self::assertSame(200, $out['status']);
        self::assertTrue($out['body']['forgotten']);
        self::assertArrayNotHasKey($conversation, $this->state->items, 'working history cleared');
        self::assertTrue($this->log->hidden[$conversation]);
        self::assertEquals($turnsBefore, $this->log->turns, 'log rows unchanged');
        foreach ($countersBefore as $bucket => $windows) {
            foreach ($windows as $w => $row) {
                self::assertGreaterThanOrEqual($row['count'], $this->counters->rows[$bucket][$w]['count'] ?? 0, 'no counter lowered: ' . $bucket);
            }
        }

        [$r] = $this->ask(['session' => $token, 'message' => 'again', 'conversation' => $conversation], [], ['REMOTE_ADDR' => '203.0.113.7'], $service);
        self::assertSame(404, $r?->status);

        $unknown = $service->forget($this->request('POST', (string) json_encode(['session' => $token, 'conversation' => str_repeat('c', 32)])));
        self::assertSame(404, $unknown['status']);
        $foreign = $service->forget($this->request('POST', (string) json_encode(['session' => $token, 'conversation' => $conversation]), ['origin' => 'https://evil.example']));
        self::assertSame(403, $foreign['status']);
    }
}
