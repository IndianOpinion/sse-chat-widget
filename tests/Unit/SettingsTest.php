<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Unit;

use IndianOpinion\SseChatWidget\Admin\SettingsPage;
use IndianOpinion\SseChatWidget\Settings;
use IndianOpinion\SseChatWidget\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class SettingsTest extends TestCase
{
    /** T62, case 75. */
    public function testOutOfRangeValuesAreRejectedPerFieldAndKeepOldValue(): void
    {
        $old = ['max_tokens' => 700, 'passages' => 5];
        $input = [
            'max_tokens' => '63', 'passages' => '11', 'passage_words' => '39', 'message_chars' => '4001',
            'history_window' => '-1', 'turns_per_conversation' => '0', 'per_ip_hour' => '1001',
            'concurrent_ip' => '11', 'concurrent_site' => '101', 'daily_messages' => '0', 'daily_tokens' => '999',
            'max_stream_seconds' => '14', 'session_hours' => '169', 'retention_days' => '3651',
            'temperature' => '1.6', 'min_similarity' => '1.1', 'base_url' => 'http://remote.example/v1',
            'model' => str_repeat('m', 129), 'caller' => 'other', 'no_context' => 'maybe', 'log_mode' => 'verbose',
            'prompt_template' => 'No placeholder here', 'post_types' => ['attachment'], 'timezone' => 'Mars/Base',
            'allowed_origins' => "http://insecure.example", 'trusted_proxies' => 'not-an-ip',
        ];
        [$values, $errors] = Settings::validate($input, $old, ['post', 'page']);
        foreach (array_keys($input) as $field) {
            self::assertArrayHasKey($field, $errors, $field);
        }
        self::assertSame(700, $values['max_tokens'], 'old value kept');
        self::assertSame(5, $values['passages']);
        self::assertSame('', $values['base_url']);

        [$values, $errors] = Settings::validate([
            'max_tokens' => '64', 'passages' => '10', 'daily_tokens' => '', 'base_url' => 'http://localhost:11434/v1/',
            'temperature' => '0', 'retention_days' => '0', 'allowed_origins' => "https://a.example\nhttps://B.example:443",
            'trusted_proxies' => "10.0.0.0/8\n192.0.2.1", 'post_types' => ['post', 'page'], 'timezone' => 'Asia/Kolkata',
            'prompt_template' => "Use {passages}.\r\n", 'strings' => ['title' => 'Ask us', 'paused' => ''],
        ], [], ['post', 'page']);
        self::assertSame([], $errors);
        self::assertSame('http://localhost:11434/v1', $values['base_url']);
        self::assertSame(['https://a.example', 'https://b.example'], $values['allowed_origins']);
        self::assertSame(0, $values['daily_tokens']);
        self::assertSame("Use {passages}.\n", $values['prompt_template']);
        self::assertSame('Ask us', $values['strings']['title']);
        self::assertSame(Settings::defaultStrings()['paused'], $values['strings']['paused']);
    }

    public function testSaveNeverChangesKillAndRejectsMissingLibraryCaller(): void
    {
        \WpStub::$options[Settings::OPTION] = ['kill' => true];
        $errors = SettingsPage::applySave(['kill' => '0', 'model' => 'abc'], null);
        self::assertSame([], $errors);
        self::assertTrue(Settings::load()->bool('kill'));
        self::assertSame('abc', Settings::load()->string('model'));
    }

    /** T63, case 76. */
    public function testKeyStoredWithAutoloadOffNeverPrintedAndEmptyKeeps(): void
    {
        delete_option(Settings::KEY_OPTION);
        Settings::storeKey('sk-live-abcdefgh1234');
        self::assertFalse(\WpStub::$autoload[Settings::KEY_OPTION]);
        self::assertSame(['key' => 'sk-live-abcdefgh1234', 'source' => 'settings'], Settings::apiKey());
        self::assertSame('********1234', Settings::maskedKey());
        Settings::storeKey('   ');
        self::assertSame('sk-live-abcdefgh1234', Settings::apiKey()['key'], 'empty input keeps the key');
        self::assertArrayNotHasKey('api_key', Settings::defaults(), 'the settings option never holds the key');

        putenv('SSE_CHAT_API_KEY=sk-env-key-5678');
        self::assertSame(['key' => 'sk-env-key-5678', 'source' => 'environment'], Settings::apiKey());
        putenv('SSE_CHAT_API_KEY');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstantKeyWins(): void
    {
        putenv('SSE_CHAT_API_KEY=sk-env');
        define('SSE_CHAT_API_KEY', 'sk-constant-9999');
        self::assertSame(['key' => 'sk-constant-9999', 'source' => 'constant'], Settings::apiKey());
        putenv('SSE_CHAT_API_KEY');
    }

    public function testDefaultsFilterAndDisplayStringsFilter(): void
    {
        add_filter('sse_chat_settings_defaults', static fn (array $d): array => ['passages' => 6] + $d);
        self::assertSame(6, (new Settings())->int('passages'));
        add_filter('sse_chat_display_strings', static fn (array $s): array => ['title' => 'Pregunta'] + $s);
        self::assertSame('Pregunta', (new Settings())->displayString('title'));
        self::assertSame('Something went wrong. Please try again.', (new Settings())->displayString('no_such_key'));
    }
}
