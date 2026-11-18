<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Unit;

use IndianOpinion\SseChatWidget\Front\Widget;
use IndianOpinion\SseChatWidget\Settings;
use IndianOpinion\SseChatWidget\Tests\TestCase;

/** T1, case 1. */
final class WidgetMarkupTest extends TestCase
{
    public function testMarkupCarriesNoNonceTokenPromptOrModel(): void
    {
        update_option(Settings::OPTION, [
            'enabled' => true,
            'base_url' => 'https://llm.example.net/v1',
            'model' => 'secret-model-id',
            'prompt_template' => 'TEMPLATE-TEXT {passages}',
        ]);
        update_option('__stub_singular', true);
        update_option('__stub_queried', 42);
        \WpStub::$userId = 7;
        $html = Widget::shortcode(['title' => 'Ask "us" <b>', 'greeting' => 'Hi']);
        self::assertStringContainsString('data-sse-chat', $html);
        self::assertStringContainsString('data-endpoint="https://example.org/wp-json/sse-chat/v1/"', $html);
        self::assertStringContainsString('data-context="42"', $html);
        self::assertStringContainsString('data-title="Ask &quot;us&quot; &lt;b&gt;"', $html);
        foreach (['nonce', 'session', 'secret-model-id', 'TEMPLATE-TEXT', 'llm.example.net', 'sk-test', 'data-user'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $html, $forbidden);
        }
        self::assertSame(['script:sse-chat-widget', 'style:sse-chat-widget'], \WpStub::$options['__enqueued']);

        self::assertStringContainsString('data-context=""', Widget::shortcode(['context' => 'none']));
        update_option(Settings::OPTION, ['enabled' => false]);
        self::assertSame('', Widget::shortcode([]));
    }

    public function testBlockRendersTheSameMarkup(): void
    {
        update_option(Settings::OPTION, ['enabled' => true]);
        self::assertSame(Widget::shortcode(['title' => 'T']), Widget::block(['title' => 'T']));
    }

    public function testLauncherExclusions(): void
    {
        update_option(Settings::OPTION, ['enabled' => true, 'launcher' => true, 'launcher_exclude_paths' => ['/shop']]);
        $_SERVER['REQUEST_URI'] = '/news/item';
        self::assertTrue(Widget::launcherWanted());
        $_SERVER['REQUEST_URI'] = '/shop/cart';
        self::assertFalse(Widget::launcherWanted());
        update_option(Settings::OPTION, ['enabled' => true, 'launcher' => false]);
        self::assertFalse(Widget::launcherWanted());
        unset($_SERVER['REQUEST_URI']);
    }
}
