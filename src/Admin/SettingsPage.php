<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Admin;

use IndianOpinion\SseChatWidget\Http\RestController;
use IndianOpinion\SseChatWidget\Limits\Stats;
use IndianOpinion\SseChatWidget\Limits\WpdbCounterStore;
use IndianOpinion\SseChatWidget\Log\Schema;
use IndianOpinion\SseChatWidget\Model\CallerFactory;
use IndianOpinion\SseChatWidget\Model\LlmJsonAsker;
use IndianOpinion\SseChatWidget\Plugin;
use IndianOpinion\SseChatWidget\Settings;
use IndianOpinion\SseChatWidget\Support\SystemClock;

/**
 * Settings under Settings > Chat widget (cases 75 to 78, section 11).
 */
final class SettingsPage
{
    public const SLUG = 'sse-chat-widget';
    public const REFUSAL_CODES = ['disabled', 'origin_refused', 'bad_request', 'too_large', 'bad_encoding', 'session_invalid',
        'empty_message', 'message_too_long', 'conversation_unknown', 'conversation_full', 'paused', 'busy', 'rate_ip',
        'rate_session', 'daily_cap', 'spend_denied', 'limits_unavailable', 'not_configured'];

    public static function hooks(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_sse_chat_save', [self::class, 'handleSave']);
        add_action('admin_post_sse_chat_kill', [self::class, 'handleKill']);
        add_action('admin_post_sse_chat_test', [self::class, 'handleTest']);
        add_action('admin_post_sse_chat_loopback', [self::class, 'handleLoopback']);
        add_action('admin_notices', [self::class, 'notices']);
        add_filter('plugin_row_meta', [self::class, 'rowMeta'], 10, 2);
    }

    public static function menu(): void
    {
        add_options_page('Chat widget', 'Chat widget', Plugin::capability('settings'), self::SLUG, [self::class, 'render']);
    }

    /**
     * @return list<string>
     */
    public static function publicTypes(): array
    {
        $types = get_post_types(['public' => true], 'names');
        return array_values(array_diff(array_map('strval', is_array($types) ? $types : []), ['attachment']));
    }

    /**
     * Validate and store a submission. Returns per-field errors.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function applySave(array $input, ?string $apiKeyInput): array
    {
        $stored = get_option(Settings::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $before = (bool) ($stored['kill'] ?? false);
        // The kill switch has its own button; a settings save never changes it.
        unset($input['kill']);
        [$values, $errors] = Settings::validate($input, $stored, self::publicTypes());
        $values['kill'] = $before;
        if ($values['caller'] === 'buffered-json' && !LlmJsonAsker::available()) {
            $values['caller'] = 'stream';
            $errors['caller'] = 'The buffered-json caller needs indianopinion/llm-json-php, which is not installed.';
        }
        update_option(Settings::OPTION, $values);
        if ($apiKeyInput !== null) {
            Settings::storeKey($apiKeyInput);
        }
        return $errors;
    }

    /**
     * Toggle the kill switch from the screen. The constant cannot be undone here.
     */
    public static function applyKill(bool $on): ?string
    {
        if (!$on && defined('SSE_CHAT_KILL') && constant('SSE_CHAT_KILL') === true) {
            return 'The kill switch is set by the SSE_CHAT_KILL constant in wp-config.php and cannot be turned off here.';
        }
        Plugin::setKill($on, 'settings_screen');
        return null;
    }

    /**
     * Capability and nonce check for an admin-post action.
     */
    public static function authorised(string $action, string $context = 'settings'): bool
    {
        if (!current_user_can(Plugin::capability($context))) {
            return false;
        }
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        return $nonce !== '' && wp_verify_nonce($nonce, $action) !== false;
    }

    public static function handleSave(): void
    {
        if (!self::authorised('sse_chat_save')) {
            wp_die('You are not allowed to do that.', 403);
        }
        $input = isset($_POST['sse_chat']) && is_array($_POST['sse_chat']) ? wp_unslash($_POST['sse_chat']) : [];
        $key = isset($_POST['sse_chat_api_key']) && is_string($_POST['sse_chat_api_key']) ? trim(wp_unslash($_POST['sse_chat_api_key'])) : null;
        $errors = self::applySave(is_array($input) ? $input : [], $key);
        set_transient('sse_chat_errors_' . get_current_user_id(), $errors, 120);
        self::back($errors === [] ? 'saved' : 'errors');
    }

    public static function handleKill(): void
    {
        if (!self::authorised('sse_chat_kill')) {
            wp_die('You are not allowed to do that.', 403);
        }
        $on = isset($_POST['kill']) && $_POST['kill'] === 'on';
        $error = self::applyKill($on);
        if ($error !== null) {
            set_transient('sse_chat_errors_' . get_current_user_id(), ['kill' => $error], 120);
        }
        self::back($error === null ? 'kill' : 'errors');
    }

    public static function handleTest(): void
    {
        if (!self::authorised('sse_chat_test')) {
            wp_die('You are not allowed to do that.', 403);
        }
        $question = isset($_POST['question']) && is_string($_POST['question']) ? sanitize_textarea_field(wp_unslash($_POST['question'])) : '';
        $context = isset($_POST['context_post']) ? absint($_POST['context_post']) : 0;
        $out = Plugin::service()->adminTest($question, $context, get_current_user_id());
        set_transient('sse_chat_test_' . get_current_user_id(), [
            'question' => $question,
            'refusal' => $out['refusal'] !== null ? $out['refusal']->body() : null,
            'events' => $out['events'],
            'prompt' => $out['prompt'],
        ], 300);
        self::back('test');
    }

    /**
     * Loopback check (section 10): can an anonymous POST reach the session route?
     */
    public static function handleLoopback(): void
    {
        if (!self::authorised('sse_chat_loopback')) {
            wp_die('You are not allowed to do that.', 403);
        }
        $response = wp_remote_post(rest_url(RestController::NAMESPACE . '/session'), [
            'headers' => ['Content-Type' => 'application/json', 'Origin' => (string) home_url()],
            'body' => '{}',
            'timeout' => 10,
            'cookies' => [],
            'sslverify' => (bool) apply_filters('https_local_ssl_verify', false),
        ]);
        if (is_wp_error($response)) {
            $result = 'The loopback request failed: ' . $response->get_error_message();
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $result = $code === 200
                ? 'Anonymous REST access works.'
                : sprintf('The session route answered HTTP %d. A security plugin or server rule may be blocking anonymous REST requests.', $code);
        }
        set_transient('sse_chat_loopback_' . get_current_user_id(), $result, 300);
        self::back('loopback');
    }

    private static function back(string $flag): void
    {
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'sse_chat' => $flag], admin_url('options-general.php')));
        exit;
    }

    public static function notices(): void
    {
        if (!current_user_can(Plugin::capability('settings'))) {
            return;
        }
        $settings = Settings::load();
        $messages = [];
        if ($settings->bool('enabled') && !$settings->isConfigured()) {
            $messages[] = 'Chat widget: the provider URL, model or API key is missing, so the chat refuses every question (not_configured).';
        }
        if (!Schema::needsInstall() && !Schema::healthy()) {
            $messages[] = 'Chat widget: the log tables are missing columns (' . (string) get_option(Schema::STATUS_OPTION, '') . '). Logging is blocked until this is fixed; answers still stream.';
        }
        global $wpdb;
        $stats = new Stats(new WpdbCounterStore($wpdb), $settings, new SystemClock());
        if ($stats->get('log_write_failed') > 0) {
            $messages[] = sprintf('Chat widget: %d log writes failed today. Check the PHP error log.', $stats->get('log_write_failed'));
        }
        if ($settings->string('caller') === 'buffered-json' && !LlmJsonAsker::available()) {
            $messages[] = 'Chat widget: the buffered-json caller is selected but indianopinion/llm-json-php is not installed. The built-in streaming caller is used.';
        }
        foreach ($messages as $m) {
            echo '<div class="notice notice-warning"><p>' . esc_html($m) . '</p></div>';
        }
    }

    /**
     * @param list<string> $meta
     * @return list<string>
     */
    public static function rowMeta(array $meta, string $file): array
    {
        if ($file !== plugin_basename(Plugin::file())) {
            return $meta;
        }
        $deletes = Settings::load()->bool('delete_log_on_uninstall')
            || (defined('SSE_CHAT_DELETE_LOG_ON_UNINSTALL') && constant('SSE_CHAT_DELETE_LOG_ON_UNINSTALL') === true);
        $meta[] = esc_html($deletes
            ? 'Uninstall will delete the conversation log and audit trail.'
            : 'Uninstall keeps the conversation log and audit trail.');
        return $meta;
    }

    /**
     * Status box figures (section 11).
     *
     * @return array<string, string>
     */
    public static function status(Settings $settings): array
    {
        global $wpdb;
        $stats = new Stats(new WpdbCounterStore($wpdb), $settings, new SystemClock());
        $kill = $settings->killSwitch();
        $factory = new CallerFactory($settings);
        $custom = apply_filters('sse_chat_retriever', null, $settings->all());
        $lastError = get_option('sse_chat_last_provider_error', null);
        $snapshot = $stats->snapshot(self::REFUSAL_CODES);
        $refusals = [];
        foreach ($snapshot as $k => $v) {
            if (str_starts_with($k, 'refused:')) {
                $refusals[] = substr($k, 8) . ' ' . $v;
            }
        }
        return [
            'Enabled' => $settings->bool('enabled') ? 'yes' : 'no',
            'Configured' => $settings->isConfigured() ? 'yes' : 'no',
            'Kill switch' => $kill['on'] ? 'on (' . $kill['source'] . ')' : 'off',
            'Caller' => $settings->string('caller') . ($factory->notice() !== null ? ' (falls back to stream)' : ''),
            'Retriever' => is_object($custom) ? 'custom (' . get_class($custom) . ')' : 'keyword',
            'Vector index' => 'not shipped in 0.1.0 (format fixed; see docs/ARCHITECTURE.md)',
            'Messages today' => $snapshot['messages'] . ' of ' . $settings->int('daily_messages'),
            'Tokens today' => $snapshot['tokens'] . ($settings->int('daily_tokens') > 0 ? ' of ' . $settings->int('daily_tokens') : ' (no cap)'),
            'Refusals today' => $refusals === [] ? 'none' : implode(', ', $refusals),
            'Aborted answers today' => (string) $snapshot['aborted'],
            'Uncited answers today' => (string) $snapshot['uncited'],
            'Log write failures today' => (string) $snapshot['log_write_failed'],
            'Last provider error' => is_array($lastError) && isset($lastError['code'], $lastError['time'])
                ? $lastError['code'] . ' at ' . gmdate('Y-m-d H:i', (int) $lastError['time']) . ' UTC' : 'none',
        ];
    }

    public static function render(): void
    {
        if (!current_user_can(Plugin::capability('settings'))) {
            wp_die('You are not allowed to see this page.', 403);
        }
        $settings = Settings::load();
        $values = $settings->all();
        $uid = get_current_user_id();
        $errors = get_transient('sse_chat_errors_' . $uid);
        $errors = is_array($errors) ? $errors : [];
        delete_transient('sse_chat_errors_' . $uid);
        $kill = $settings->killSwitch();
        $key = Settings::apiKey();

        echo '<div class="wrap"><h1>Chat widget</h1>';
        foreach ($errors as $field => $msg) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html((string) $field) . ':</strong> ' . esc_html((string) $msg) . ' The old value was kept.</p></div>';
        }

        echo '<h2>Status</h2><table class="widefat striped" style="max-width:48rem"><tbody>';
        foreach (self::status($settings) as $label => $value) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table>';

        $loop = get_transient('sse_chat_loopback_' . $uid);
        if (is_string($loop)) {
            echo '<p><strong>REST check:</strong> ' . esc_html($loop) . '</p>';
            delete_transient('sse_chat_loopback_' . $uid);
        }
        self::smallForm('sse_chat_loopback', 'Check anonymous REST access', []);

        echo '<h2>Kill switch</h2>';
        if ($kill['source'] === 'constant') {
            echo '<p>The kill switch is on through the SSE_CHAT_KILL constant. It cannot be turned off from this screen.</p>';
        } else {
            self::smallForm('sse_chat_kill', $kill['on'] ? 'Turn the chat back on' : 'Pause the chat now', ['kill' => $kill['on'] ? 'off' : 'on']);
        }

        echo '<h2>Settings</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('sse_chat_save');
        echo '<input type="hidden" name="action" value="sse_chat_save">';
        echo '<table class="form-table" role="presentation"><tbody>';
        self::checkbox('enabled', 'Enabled', (bool) $values['enabled']);
        self::text('base_url', 'Provider base URL', (string) $values['base_url'], 'An OpenAI-compatible endpoint, for example https://api.example.com/v1. The plugin adds /chat/completions.');
        self::text('model', 'Model', (string) $values['model'], 'Up to 128 characters. Never sent to the browser.');
        echo '<tr><th scope="row"><label for="sse-chat-key">API key</label></th><td>';
        if ($key['source'] === 'constant' || $key['source'] === 'environment') {
            echo '<p>Set by the ' . esc_html($key['source']) . ' (' . esc_html(Settings::maskedKey()) . ').</p>';
        } else {
            echo '<input type="password" id="sse-chat-key" name="sse_chat_api_key" value="" autocomplete="off" class="regular-text" placeholder="' . esc_attr(Settings::maskedKey()) . '">';
            echo '<p class="description">Leave empty to keep the stored key. Stored with autoload off and never shown again.</p>';
        }
        echo '</td></tr>';
        $callers = ['stream' => 'Built-in streaming caller'];
        if (LlmJsonAsker::available()) {
            $callers['buffered-json'] = 'Buffered JSON (llm-json-php)';
        }
        self::select('caller', 'Caller', (string) $values['caller'], $callers);
        self::text('max_tokens', 'Max output tokens', (string) $values['max_tokens'], '64 to 4,000.');
        self::text('temperature', 'Temperature', (string) $values['temperature'], '0 to 1.5.');
        echo '<tr><th scope="row"><label for="sse-chat-prompt_template">Prompt template</label></th><td><textarea id="sse-chat-prompt_template" name="sse_chat[prompt_template]" rows="12" class="large-text code">' . esc_textarea((string) $values['prompt_template']) . '</textarea>';
        echo '<p class="description">Placeholders: {site_name}, {site_description}, {date}, {passages}, {context_post}. Must contain {passages}. Never sent to the browser.</p></td></tr>';
        echo '<tr><th scope="row">Post types</th><td>';
        foreach (self::publicTypes() as $type) {
            $checked = in_array($type, $settings->list('post_types'), true) ? ' checked' : '';
            echo '<label><input type="checkbox" name="sse_chat[post_types][]" value="' . esc_attr($type) . '"' . $checked . '> ' . esc_html($type) . '</label><br>';
        }
        echo '</td></tr>';
        foreach (
            [
            'passages' => ['Passages', '1 to 10.'],
            'passage_words' => ['Passage length (words)', '40 to 400.'],
            'message_chars' => ['Message length (characters)', '100 to 4,000.'],
            'history_window' => ['History window (messages)', '0 to 40.'],
            'turns_per_conversation' => ['Turns per conversation', '1 to 100.'],
            'per_ip_hour' => ['Messages per IP per hour', '1 to 1,000.'],
            'per_session_total' => ['Messages per session', '1 to 1,000.'],
            'per_session_10min' => ['Messages per session per 10 minutes', '1 to 1,000.'],
            'concurrent_ip' => ['Open streams per IP', '1 to 10.'],
            'concurrent_site' => ['Open streams across the site', '1 to 100.'],
            'daily_messages' => ['Daily message cap', '1 to 1,000,000.'],
            'daily_tokens' => ['Daily token cap', 'Empty or 0 for off; otherwise 1,000 or more. Calls in flight when it is reached can pass it.'],
            'timezone' => ['Day time zone', 'Empty for the site time zone.'],
            'max_stream_seconds' => ['Max stream duration (seconds)', '15 to 300.'],
            'session_hours' => ['Session lifetime (hours)', '1 to 168.'],
            'retention_days' => ['Log retention (days)', '0 keeps forever. Rows past this are marked eligible; only you delete them.'],
            ] as $field => [$label, $help]
        ) {
            self::text($field, $label, (string) $values[$field], $help);
        }
        self::select('no_context', 'When nothing is found', (string) $values['no_context'], ['decline' => 'Decline without calling the model', 'answer' => 'Call the model anyway']);
        self::checkbox('show_related', 'Show related posts when an answer cites nothing', (bool) $values['show_related']);
        self::select('log_mode', 'Log mode', (string) $values['log_mode'], ['full' => 'Full', 'metadata' => 'Metadata only', 'off' => 'Off']);
        self::checkbox('delete_log_on_uninstall', 'Delete the conversation log when the plugin is uninstalled', (bool) $values['delete_log_on_uninstall']);
        self::lines('allowed_origins', 'Extra allowed origins', $settings->list('allowed_origins'), 'One https origin per line. The site origin is always allowed.');
        self::lines('trusted_proxies', 'Trusted proxies', $settings->list('trusted_proxies'), 'IPs or CIDR ranges, one per line. Empty means X-Forwarded-For is ignored.');
        self::checkbox('launcher', 'Floating launcher on every page', (bool) $values['launcher']);
        echo '<tr><th scope="row">Launcher: excluded post types</th><td>';
        foreach (self::publicTypes() as $type) {
            $checked = in_array($type, $settings->list('launcher_exclude_types'), true) ? ' checked' : '';
            echo '<label><input type="checkbox" name="sse_chat[launcher_exclude_types][]" value="' . esc_attr($type) . '"' . $checked . '> ' . esc_html($type) . '</label><br>';
        }
        echo '<input type="hidden" name="sse_chat[launcher_exclude_types][]" value=""></td></tr>';
        self::lines('launcher_exclude_paths', 'Launcher: excluded path prefixes', $settings->list('launcher_exclude_paths'), 'One per line, starting with /.');
        echo '<tr><th scope="row">Display strings</th><td>';
        foreach ($settings->strings() as $k => $v) {
            echo '<p><label>' . esc_html($k) . '<br><input type="text" class="large-text" name="sse_chat[strings][' . esc_attr($k) . ']" value="' . esc_attr($v) . '" maxlength="500"></label></p>';
        }
        echo '</td></tr></tbody></table>';
        submit_button('Save settings');
        echo '</form>';

        echo '<h2>Send a test question</h2><p>Runs the full path as you, counts against the caps and is logged as admin_test.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('sse_chat_test');
        echo '<input type="hidden" name="action" value="sse_chat_test">';
        echo '<p><textarea name="question" rows="3" class="large-text" required></textarea></p>';
        echo '<p><label>Context post id (optional) <input type="number" name="context_post" min="0" value="0"></label></p>';
        submit_button('Send a test question', 'secondary');
        echo '</form>';
        self::renderTestResult($uid);
        echo '</div>';
    }

    private static function renderTestResult(int $uid): void
    {
        $test = get_transient('sse_chat_test_' . $uid);
        if (!is_array($test)) {
            return;
        }
        delete_transient('sse_chat_test_' . $uid);
        echo '<h3>Result</h3>';
        if (is_array($test['refusal'] ?? null)) {
            echo '<p>Refused: ' . esc_html((string) ($test['refusal']['code'] ?? '')) . '</p>';
            return;
        }
        $text = '';
        $sources = [];
        foreach (is_array($test['events'] ?? null) ? $test['events'] : [] as $e) {
            if (!is_array($e) || !isset($e[0], $e[1]) || !is_array($e[1])) {
                continue;
            }
            if ($e[0] === 'delta') {
                $text .= (string) ($e[1]['text'] ?? '');
            } elseif ($e[0] === 'citations') {
                $sources = is_array($e[1]['sources'] ?? null) ? $e[1]['sources'] : [];
            } elseif ($e[0] === 'error') {
                echo '<p>Error: ' . esc_html((string) ($e[1]['code'] ?? '')) . '</p>';
            }
        }
        echo '<div style="white-space:pre-wrap;max-width:48rem">' . esc_html($text) . '</div>';
        echo '<h4>Sources</h4><ul>';
        foreach ($sources as $s) {
            if (is_array($s)) {
                echo '<li>[' . esc_html((string) ($s['n'] ?? '')) . '] <a href="' . esc_url((string) ($s['url'] ?? '')) . '">' . esc_html((string) ($s['title'] ?? '')) . '</a></li>';
            }
        }
        echo '</ul><h4>Assembled prompt (administrators only)</h4>';
        foreach (is_array($test['prompt'] ?? null) ? $test['prompt'] : [] as $m) {
            if (is_array($m)) {
                echo '<p><strong>' . esc_html((string) ($m['role'] ?? '')) . '</strong></p><pre style="white-space:pre-wrap;max-width:60rem">' . esc_html((string) ($m['content'] ?? '')) . '</pre>';
            }
        }
    }

    /**
     * @param array<string, string> $fields
     */
    private static function smallForm(string $action, string $label, array $fields): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:1rem">';
        wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        foreach ($fields as $k => $v) {
            echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
        submit_button($label, 'secondary', 'submit', false);
        echo '</form>';
    }

    private static function text(string $field, string $label, string $value, string $help): void
    {
        echo '<tr><th scope="row"><label for="sse-chat-' . esc_attr($field) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="sse-chat-' . esc_attr($field) . '" name="sse_chat[' . esc_attr($field) . ']" value="' . esc_attr($value) . '">';
        echo '<p class="description">' . esc_html($help) . '</p></td></tr>';
    }

    private static function checkbox(string $field, string $label, bool $on): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
        echo '<input type="hidden" name="sse_chat[' . esc_attr($field) . ']" value="0">';
        echo '<label><input type="checkbox" name="sse_chat[' . esc_attr($field) . ']" value="1"' . ($on ? ' checked' : '') . '> ' . esc_html($label) . '</label></td></tr>';
    }

    /**
     * @param array<string, string> $options
     */
    private static function select(string $field, string $label, string $value, array $options): void
    {
        echo '<tr><th scope="row"><label for="sse-chat-' . esc_attr($field) . '">' . esc_html($label) . '</label></th><td><select id="sse-chat-' . esc_attr($field) . '" name="sse_chat[' . esc_attr($field) . ']">';
        foreach ($options as $k => $text) {
            echo '<option value="' . esc_attr($k) . '"' . selected($value, $k, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select></td></tr>';
    }

    /**
     * @param list<string> $lines
     */
    private static function lines(string $field, string $label, array $lines, string $help): void
    {
        echo '<tr><th scope="row"><label for="sse-chat-' . esc_attr($field) . '">' . esc_html($label) . '</label></th><td>';
        echo '<textarea id="sse-chat-' . esc_attr($field) . '" name="sse_chat[' . esc_attr($field) . ']" rows="3" class="regular-text code">' . esc_textarea(implode("\n", $lines)) . '</textarea>';
        echo '<p class="description">' . esc_html($help) . '</p></td></tr>';
    }
}
