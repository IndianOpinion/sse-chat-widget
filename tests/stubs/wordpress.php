<?php

/**
 * A small stub layer so PHPUnit runs without WordPress. Only what the
 * plugin calls is here, with the behaviour the tests rely on. State lives
 * in WpStub and is reset before every test.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

final class WpStub
{
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $filters = [];
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, bool> */
    public static array $autoload = [];
    /** @var array<string, mixed> */
    public static array $transients = [];
    public static bool $transientsFail = false;
    /** @var array<string, array<string, mixed>> */
    public static array $cache = [];
    public static bool $extCache = false;
    /** @var array<int, WP_Post> */
    public static array $posts = [];
    /** @var list<array<string, mixed>> */
    public static array $queries = [];
    public static int $userId = 0;
    /** @var array<string, bool> */
    public static array $caps = [];
    /** @var array<string, string> */
    public static array $nonces = [];
    /** @var list<array{0: string, 1: list<mixed>}> */
    public static array $actions = [];
    /** @var array<string, int> */
    public static array $scheduled = [];
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public static array $remotePosts = [];
    /** @var array<string, mixed>|WP_Error|null */
    public static $remoteResponse = null;

    public static function reset(): void
    {
        self::$filters = [];
        self::$options = [];
        self::$autoload = [];
        self::$transients = [];
        self::$transientsFail = false;
        self::$cache = [];
        self::$extCache = false;
        self::$posts = [];
        self::$queries = [];
        self::$userId = 0;
        self::$caps = [];
        self::$nonces = [];
        self::$actions = [];
        self::$scheduled = [];
        self::$remotePosts = [];
        self::$remoteResponse = null;
    }

    public static function addPost(array $fields): WP_Post
    {
        $post = new WP_Post((object) array_merge([
            'ID' => count(self::$posts) + 1,
            'post_title' => 'Untitled',
            'post_content' => '',
            'post_excerpt' => '',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_password' => '',
            'post_date' => '2026-01-01 00:00:00',
        ], $fields));
        self::$posts[$post->ID] = $post;
        return $post;
    }
}

// Hooks.
function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    WpStub::$filters[$hook][$priority][] = [$cb, $args];
    return true;
}
function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $cb, $priority, $args);
}
function remove_filter(string $hook, callable $cb, int $priority = 10): bool
{
    foreach (WpStub::$filters[$hook][$priority] ?? [] as $i => [$c]) {
        if ($c === $cb) {
            unset(WpStub::$filters[$hook][$priority][$i]);
            return true;
        }
    }
    return false;
}
function has_filter(string $hook): bool
{
    return !empty(WpStub::$filters[$hook]);
}
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    $byPriority = WpStub::$filters[$hook] ?? [];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as [$cb, $n]) {
            $value = $cb(...array_slice([$value, ...$args], 0, max(1, $n)));
        }
    }
    return $value;
}
function do_action(string $hook, mixed ...$args): void
{
    WpStub::$actions[] = [$hook, $args];
    $byPriority = WpStub::$filters[$hook] ?? [];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as [$cb, $n]) {
            $cb(...array_slice($args, 0, max(0, $n)));
        }
    }
}
function did_action_with(string $hook): array
{
    $out = [];
    foreach (WpStub::$actions as [$h, $args]) {
        if ($h === $hook) {
            $out[] = $args;
        }
    }
    return $out;
}

// Options and transients.
function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
}
function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    WpStub::$options[$name] = $value;
    if ($autoload !== null) {
        WpStub::$autoload[$name] = (bool) $autoload;
    }
    return true;
}
function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = true): bool
{
    if (array_key_exists($name, WpStub::$options)) {
        return false;
    }
    WpStub::$options[$name] = $value;
    WpStub::$autoload[$name] = !in_array($autoload, [false, 'no', 'off'], true);
    return true;
}
function delete_option(string $name): bool
{
    unset(WpStub::$options[$name], WpStub::$autoload[$name]);
    return true;
}
function get_transient(string $name): mixed
{
    return WpStub::$transients[$name] ?? false;
}
function set_transient(string $name, mixed $value, int $ttl = 0): bool
{
    if (WpStub::$transientsFail) {
        return false;
    }
    WpStub::$transients[$name] = $value;
    return true;
}
function delete_transient(string $name): bool
{
    unset(WpStub::$transients[$name]);
    return true;
}
function wp_using_ext_object_cache(): bool
{
    return WpStub::$extCache;
}
function wp_cache_get(string $key, string $group = ''): mixed
{
    return WpStub::$cache[$group][$key] ?? false;
}
function wp_cache_set(string $key, mixed $value, string $group = '', int $ttl = 0): bool
{
    if (WpStub::$transientsFail) {
        return false;
    }
    WpStub::$cache[$group][$key] = $value;
    return true;
}
function wp_cache_delete(string $key, string $group = ''): bool
{
    unset(WpStub::$cache[$group][$key]);
    return true;
}
function wp_cache_flush_group(string $group): bool
{
    unset(WpStub::$cache[$group]);
    return true;
}

// Site.
function wp_salt(string $scheme = 'auth'): string
{
    return 'test-salt-' . $scheme;
}
function home_url(string $path = ''): string
{
    return 'https://example.org' . $path;
}
function rest_url(string $path = ''): string
{
    return 'https://example.org/wp-json/' . ltrim($path, '/');
}
function admin_url(string $path = ''): string
{
    return 'https://example.org/wp-admin/' . $path;
}
function plugins_url(string $path = '', string $file = ''): string
{
    return 'https://example.org/wp-content/plugins/sse-chat-widget/' . $path;
}
function plugin_basename(string $file): string
{
    return 'sse-chat-widget/' . basename($file);
}
function get_bloginfo(string $show = ''): string
{
    return $show === 'name' ? 'Example Site' : ($show === 'description' ? 'Reporting from the river towns' : '');
}
function wp_timezone(): DateTimeZone
{
    $tz = get_option('timezone_string', 'UTC');
    return new DateTimeZone(is_string($tz) && $tz !== '' ? $tz : 'UTC');
}
function wp_date(string $format, ?int $timestamp = null): string
{
    return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone(wp_timezone())->format($format);
}
function is_admin(): bool
{
    return false;
}
function is_singular(): bool
{
    return (bool) get_option('__stub_singular', false);
}
function get_queried_object_id(): int
{
    return (int) get_option('__stub_queried', 0);
}
function get_post_type(): string
{
    return 'post';
}
function wp_next_scheduled(string $hook): int|false
{
    return WpStub::$scheduled[$hook] ?? false;
}
function wp_schedule_event(int $ts, string $recurrence, string $hook): bool
{
    WpStub::$scheduled[$hook] = $ts;
    return true;
}
function wp_clear_scheduled_hook(string $hook): int
{
    unset(WpStub::$scheduled[$hook]);
    return 1;
}

// Users and security.
function get_current_user_id(): int
{
    return WpStub::$userId;
}
function current_user_can(string $cap): bool
{
    return WpStub::$caps[$cap] ?? false;
}
function wp_create_nonce(string $action): string
{
    $n = substr(md5($action), 0, 10);
    WpStub::$nonces[$n] = $action;
    return $n;
}
function wp_verify_nonce(string $nonce, string $action): int|false
{
    return (WpStub::$nonces[$nonce] ?? null) === $action ? 1 : false;
}
function wp_validate_auth_cookie(string $cookie = '', string $scheme = ''): int|false
{
    return false;
}

// Escaping and sanitising.
function esc_attr(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function esc_html(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function esc_html__(string $s, string $domain = ''): string
{
    return esc_html($s);
}
function esc_url(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function esc_textarea(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function wp_json_encode(mixed $v): string|false
{
    return json_encode($v);
}
function absint(mixed $v): int
{
    return abs((int) $v);
}
function sanitize_text_field(string $s): string
{
    return trim(strip_tags($s));
}
function sanitize_textarea_field(string $s): string
{
    return trim(strip_tags($s));
}
function wp_unslash(mixed $v): mixed
{
    return $v;
}
function wp_kses_post(string $s): string
{
    return $s;
}
function is_wp_error(mixed $v): bool
{
    return $v instanceof WP_Error;
}
function shortcode_atts(array $defaults, array $atts, string $tag = ''): array
{
    $out = [];
    foreach ($defaults as $k => $v) {
        $out[$k] = array_key_exists($k, $atts) ? $atts[$k] : $v;
    }
    return $out;
}
function wp_enqueue_script(string $handle): void
{
    WpStub::$options['__enqueued'][] = 'script:' . $handle;
}
function wp_enqueue_style(string $handle): void
{
    WpStub::$options['__enqueued'][] = 'style:' . $handle;
}
function selected(mixed $a, mixed $b, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

// HTTP.
function wp_remote_post(string $url, array $args = []): array|WP_Error
{
    WpStub::$remotePosts[] = [$url, $args];
    return WpStub::$remoteResponse ?? new WP_Error('http_request_failed', 'no stub response');
}
function wp_remote_retrieve_response_code(array|WP_Error $r): int|string
{
    return is_array($r) ? (int) ($r['response']['code'] ?? 0) : '';
}
function wp_remote_retrieve_body(array|WP_Error $r): string
{
    return is_array($r) ? (string) ($r['body'] ?? '') : '';
}

// Posts.
function get_post(mixed $post = null): ?WP_Post
{
    if ($post instanceof WP_Post) {
        return $post;
    }
    return WpStub::$posts[(int) $post] ?? null;
}
function get_the_title(mixed $post = 0): string
{
    $p = get_post($post);
    return $p ? (string) $p->post_title : '';
}
function get_permalink(mixed $post = 0): string|false
{
    $p = get_post($post);
    return $p ? 'https://example.org/?p=' . $p->ID : false;
}
function get_the_date(string $format = '', mixed $post = null): string
{
    $p = get_post($post);
    return $p ? (new DateTimeImmutable((string) $p->post_date))->format('j F Y') : '';
}
function strip_shortcodes(string $content): string
{
    return (string) preg_replace('/\[[^\]]+\]/', '', $content);
}
function wp_strip_all_tags(string $s): string
{
    $s = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $s);
    return trim(strip_tags($s));
}
function wp_delete_file(string $file): void
{
    if (is_file($file)) {
        unlink($file);
    }
}

class WP_Error
{
    /** @param mixed $data */
    public function __construct(private string $code = '', private string $message = '', private $data = null)
    {
    }
    public function get_error_code(): string
    {
        return $this->code;
    }
    public function get_error_message(): string
    {
        return $this->message;
    }
    public function get_error_data(): mixed
    {
        return $this->data;
    }
}

#[AllowDynamicProperties]
final class WP_Post
{
    public int $ID = 0;
    public string $post_title = '';
    public string $post_content = '';
    public string $post_excerpt = '';
    public string $post_status = 'publish';
    public string $post_type = 'post';
    public string $post_password = '';
    public string $post_date = '';

    public function __construct(object $data)
    {
        foreach (get_object_vars($data) as $k => $v) {
            $this->$k = $v;
        }
    }
}

/**
 * Fake WP_Query: searches WpStub::$posts with the same filters core would
 * apply, matching any term (the plugin's posts_search filter asks for that).
 */
class WP_Query
{
    /** @var list<WP_Post> */
    public array $posts = [];
    /** @var array<string, mixed> */
    public array $query_vars = [];

    public function __construct(array $args = [])
    {
        $this->query_vars = $args;
        WpStub::$queries[] = $args;
        // Run the posts_search filter the way core would, so the plugin's
        // one-shot filter is exercised.
        apply_filters('posts_search', ' AND (core) ', $this);
        $terms = preg_split('/\s+/', mb_strtolower((string) ($args['s'] ?? ''))) ?: [];
        $terms = array_values(array_filter($terms));
        $types = (array) ($args['post_type'] ?? ['post']);
        $hits = [];
        foreach (WpStub::$posts as $p) {
            if ($p->post_status !== ($args['post_status'] ?? 'publish')) {
                continue;
            }
            if (!in_array($p->post_type, $types, true)) {
                continue;
            }
            if (($args['has_password'] ?? null) === false && $p->post_password !== '') {
                continue;
            }
            $hay = mb_strtolower($p->post_title . ' ' . $p->post_excerpt . ' ' . $p->post_content);
            $score = 0;
            foreach ($terms as $t) {
                if (str_contains($hay, $t)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $hits[] = [$score, $p->post_date, $p];
            }
        }
        usort($hits, static fn ($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $this->posts = array_slice(array_map(static fn ($h) => $h[2], $hits), 0, (int) ($args['posts_per_page'] ?? 10));
    }

    public function get(string $key): mixed
    {
        return $this->query_vars[$key] ?? '';
    }
}

function get_post_types(array $args = [], string $output = 'names'): array
{
    return ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment'];
}
function get_user_by(string $field, mixed $value): WP_User|false
{
    $id = (int) get_option('__stub_user_' . $field . '_' . $value, 0);
    return $id > 0 ? new WP_User($id) : false;
}

class WP_User
{
    public function __construct(public int $ID = 0)
    {
    }
}
