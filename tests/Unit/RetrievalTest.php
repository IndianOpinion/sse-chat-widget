<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Unit;

use IndianOpinion\SseChatWidget\Chat\ChatService;
use IndianOpinion\SseChatWidget\Model\CallerFactory;
use IndianOpinion\SseChatWidget\Retrieval\KeywordRetriever;
use IndianOpinion\SseChatWidget\Retrieval\Passage;
use IndianOpinion\SseChatWidget\Retrieval\PassageBuilder;
use IndianOpinion\SseChatWidget\Retrieval\PostGate;
use IndianOpinion\SseChatWidget\Retrieval\QueryBuilder;
use IndianOpinion\SseChatWidget\Retrieval\RetrievalQuery;
use IndianOpinion\SseChatWidget\Retrieval\Retriever;
use IndianOpinion\SseChatWidget\Tests\Fakes\ScriptedCaller;
use IndianOpinion\SseChatWidget\Tests\TestCase;

final class RetrievalTest extends TestCase
{
    /** T29, cases 33 and 35. */
    public function testQueryAddsPreviousMessageForShortFollowUpAndValidContextTitle(): void
    {
        self::assertSame("and the second one?\nWhen did the dam open in the valley", QueryBuilder::build('and the second one?', null, 'When did the dam open in the valley'));
        self::assertSame('When did the big river dam open after all?', QueryBuilder::build('When did the big river dam open after all?', null, 'earlier'));
        self::assertSame("what?\nRiver dam opens", QueryBuilder::build('what?', 'River dam opens', null));

        $this->seedPosts();
        $queries = [];
        add_filter('sse_chat_retriever', static function () use (&$queries): Retriever {
            return new class ($queries) implements Retriever {
                /** @param list<RetrievalQuery> $q */
                public function __construct(private array &$q)
                {
                }
                public function retrieve(RetrievalQuery $query): array
                {
                    $this->q[] = $query;
                    return [];
                }
            };
        });
        $service = $this->service();
        $token = $this->token($service);
        [, $e] = $this->ask(['session' => $token, 'message' => 'Tell me about the river dam opening', 'context_post' => 1], [], ['REMOTE_ADDR' => '203.0.113.7'], $service);
        $conversation = (string) $e->of('meta')[0]['conversation'];
        self::assertStringContainsString('River dam opens after ten years', $queries[0]->text);
        self::assertSame(1, $queries[0]->contextPostId);
        $this->ask(['session' => $token, 'message' => 'and then?', 'conversation' => $conversation, 'context_post' => 3], [], ['REMOTE_ADDR' => '203.0.113.7'], $service);
        self::assertStringContainsString('Tell me about the river dam opening', $queries[1]->text);
        self::assertStringNotContainsString('Draft about the dam', $queries[1]->text, 'a draft context post is ignored');
        self::assertNull($queries[1]->contextPostId);
        self::assertContains('context_ignored', $this->log->last()->notes);
    }

    /** T30, case 34. */
    public function testOnlyPublishedAllowedPostsEverAppear(): void
    {
        $this->seedPosts();
        add_filter('sse_chat_post_allowed', static fn (bool $ok, \WP_Post $p): bool => $ok && $p->ID !== 2, 10, 2);
        $retriever = new KeywordRetriever(new PostGate(['post']));
        $ids = array_map(static fn (Passage $p): int => $p->postId, $retriever->retrieve(new RetrievalQuery('dam', null, 10, ['post'])));
        self::assertSame([1], $ids);

        // A custom retriever that returns forbidden posts is gated too.
        add_filter('sse_chat_retriever', static fn () => new class implements Retriever {
            public function retrieve(RetrievalQuery $query): array
            {
                $out = [];
                foreach ([3, 4, 5, 6, 7, 2, 1] as $id) {
                    $out[] = new Passage($id, 'T' . $id, 'https://example.org/?p=' . $id, '', 'x');
                }
                return $out;
            }
        });
        $caller = new ScriptedCaller([[
            'chunks' => ['Answer [1] ', 'and [2].'],
            'beforeChunk' => static function (int $i): void {
                if ($i === 1) {
                    // Unpublished between retrieval and citation.
                    \WpStub::$posts[1]->post_status = 'draft';
                }
            },
        ]]);
        $this->caller = $caller;
        [, $e] = $this->ask(['message' => 'dam']);
        $passageBlock = $caller->requests[0]->messages[1]['content'];
        self::assertStringContainsString('[1] T1', $passageBlock);
        foreach ([2, 3, 4, 5, 6, 7] as $forbidden) {
            self::assertStringNotContainsString('T' . $forbidden . ' ', $passageBlock);
        }
        self::assertSame([], $e->of('citations')[0]['sources']);
    }

    /** T32, cases 36 and 38. */
    public function testKeywordRerankByTitleAndNoDuplicates(): void
    {
        \WpStub::addPost(['ID' => 10, 'post_title' => 'Budget news', 'post_content' => 'monsoon flood flood flood monsoon relief', 'post_date' => '2026-03-01']);
        \WpStub::addPost(['ID' => 11, 'post_title' => 'Monsoon flood relief', 'post_content' => 'Short piece.', 'post_date' => '2025-03-01']);
        \WpStub::addPost(['ID' => 12, 'post_title' => 'Other', 'post_content' => 'Mentions monsoon once.', 'post_date' => '2026-05-01']);
        $r = new KeywordRetriever(new PostGate(['post']));
        $out = $r->retrieve(new RetrievalQuery('monsoon flood relief', null, 3, ['post']));
        $ids = array_map(static fn (Passage $p): int => $p->postId, $out);
        self::assertSame(11, $ids[0], 'title hits weigh 3');
        self::assertSame(count($ids), count(array_unique($ids)));
        $q = \WpStub::$queries[0];
        self::assertSame(12, $q['posts_per_page'], 'four times the passage count');
        self::assertSame('publish', $q['post_status']);
        self::assertFalse($q['has_password']);
        self::assertSame(['relevance' => 'DESC', 'date' => 'DESC'], $q['orderby']);
        self::assertSame([], \WpStub::$filters['posts_search'][10] ?? [], 'the one-shot search filter is removed');
        self::assertSame(['monsoon', 'flood', 'relief'], KeywordRetriever::terms('What about the monsoon flood relief?'));
    }

    /** T33, cases 37 and 80. */
    public function testVectorFiltersWithoutBuiltInVectorRetrieverUseKeywordAndThrowingRetrieverFallsBack(): void
    {
        $this->seedPosts();
        add_filter('sse_chat_post_vectors', static fn () => [[1, array_fill(0, 8, 0.1)]]);
        add_filter('sse_chat_embed_query', static fn () => array_fill(0, 8, 0.1));
        $caller = new ScriptedCaller([['chunks' => ['From [1].']]]);
        $this->caller = $caller;
        [, $e] = $this->ask(['message' => 'river dam']);
        self::assertSame(1, $e->of('citations')[0]['sources'][0]['post']);

        add_filter('sse_chat_retriever', static fn () => new class implements Retriever {
            public function retrieve(RetrievalQuery $query): array
            {
                throw new \RuntimeException('vector store offline');
            }
        });
        [, $e] = $this->ask(['message' => 'river dam']);
        self::assertContains('retriever_fallback', $this->log->last()->notes);
        self::assertSame(1, $e->of('citations')[0]['sources'][0]['post']);
    }

    /** T34, case 38. */
    public function testPassageExcerpt(): void
    {
        $manual = \WpStub::addPost(['ID' => 20, 'post_excerpt' => 'Hand written summary.', 'post_content' => 'Body text']);
        $auto = \WpStub::addPost(['ID' => 21, 'post_content' => "<p>First <strong>bold</strong> [gallery ids=\"1\"] words\n\n here &amp; there one two three four five</p><script>x()</script>"]);
        $b = new PassageBuilder(5);
        self::assertSame('Hand written summary.', $b->excerpt($manual));
        self::assertSame('First bold words here & ...', $b->excerpt($auto));
        self::assertSame('one two', PassageBuilder::cut("  one \n two ", 5));
    }

    /** T70, case 80. */
    public function testBothRetrieversThrowingIsTheNoContextPath(): void
    {
        add_filter('sse_chat_retriever', static fn () => new class implements Retriever {
            public function retrieve(RetrievalQuery $query): array
            {
                throw new \RuntimeException('down');
            }
        });
        $broken = new class implements Retriever {
            public function retrieve(RetrievalQuery $query): array
            {
                throw new \RuntimeException('also down');
            }
        };
        $caller = new ScriptedCaller([['chunks' => ['x']]]);
        $this->caller = $caller;
        $settings = $this->settings();
        $service = new ChatService($settings, $this->clock, $this->counters, $this->state, $this->log, new CallerFactory($settings), $broken, null, static fn (): bool => true);
        [$r, $e] = $this->ask(['message' => 'anything at all'], [], ['REMOTE_ADDR' => '203.0.113.7'], $service);
        self::assertNull($r);
        self::assertSame('I could not find that on this site.', $e->text());
        self::assertSame([], $caller->requests, 'decline: no model call');
        self::assertSame('no_context', $this->log->last()->outcome);
        self::assertContains('retriever_failed', $this->log->last()->notes);
    }
}
