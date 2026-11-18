<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Http;

use IndianOpinion\SseChatWidget\Chat\JsonCollector;
use IndianOpinion\SseChatWidget\Chat\SseEmitter;
use IndianOpinion\SseChatWidget\Plugin;

/**
 * The REST routes under sse-chat/v1 (section 5.1). Every method is routed
 * here so the checks run in the documented order and a wrong method gets
 * 405 rather than the REST server's 404.
 */
final class RestController
{
    public const NAMESPACE = 'sse-chat/v1';

    public static function register(): void
    {
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
        foreach (['session' => 'session', 'stream' => 'stream', 'forget' => 'forget'] as $route => $method) {
            register_rest_route(self::NAMESPACE, '/' . $route, [
                'methods' => $methods,
                'callback' => [self::class, $method],
                'permission_callback' => '__return_true',
            ]);
        }
    }

    public static function incoming(\WP_REST_Request $request): IncomingRequest
    {
        $headers = [];
        foreach ($request->get_headers() as $name => $values) {
            $headers[(string) $name] = is_array($values) ? (string) reset($values) : (string) $values;
        }
        return new IncomingRequest(
            $request->get_method(),
            $headers,
            (string) $request->get_body(),
            $_SERVER,
            self::readerUserId()
        );
    }

    /**
     * The logged-in reader's id, for the log only. REST cookie auth drops
     * the user without a nonce, and cached pages cannot carry one, so the
     * logged-in cookie is read directly. It grants nothing.
     */
    private static function readerUserId(): int
    {
        $current = get_current_user_id();
        if ($current > 0) {
            return $current;
        }
        if (defined('LOGGED_IN_COOKIE') && isset($_COOKIE[LOGGED_IN_COOKIE]) && is_string($_COOKIE[LOGGED_IN_COOKIE])) {
            $id = wp_validate_auth_cookie(wp_unslash($_COOKIE[LOGGED_IN_COOKIE]), 'logged_in');
            return is_int($id) ? $id : 0;
        }
        return 0;
    }

    public static function session(\WP_REST_Request $request): \WP_REST_Response
    {
        $t = microtime(true);
        $out = Plugin::service()->session(self::incoming($request));
        $response = self::json($out['body'], $out['status']);
        $response->header('Server-Timing', sprintf('checks;dur=%.1f', (microtime(true) - $t) * 1000));
        return $response;
    }

    public static function forget(\WP_REST_Request $request): \WP_REST_Response
    {
        $out = Plugin::service()->forget(self::incoming($request));
        $response = self::json($out['body'], $out['status']);
        if (isset($out['retry_after'])) {
            $response->header('Retry-After', (string) $out['retry_after']);
        }
        return $response;
    }

    /**
     * Streams and exits once the checks pass; returns a JSON error otherwise.
     */
    public static function stream(\WP_REST_Request $request): \WP_REST_Response
    {
        $t = microtime(true);
        $incoming = self::incoming($request);
        $service = Plugin::service();

        if ($incoming->wantsJson()) {
            $collector = new JsonCollector();
            $refusal = $service->stream($incoming, $collector);
            if ($refusal !== null) {
                return self::refusal($refusal, $t);
            }
            return self::json($collector->body(), 200);
        }

        $refusal = $service->stream($incoming, new SseEmitter());
        if ($refusal !== null) {
            return self::refusal($refusal, $t);
        }
        exit;
    }

    private static function refusal(Refusal $r, float $started): \WP_REST_Response
    {
        $response = self::json($r->body(), $r->status);
        if ($r->retryAfter !== null) {
            $response->header('Retry-After', (string) $r->retryAfter);
        }
        $response->header('Server-Timing', sprintf('checks;dur=%.1f', (microtime(true) - $started) * 1000));
        return $response;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(array $body, int $status): \WP_REST_Response
    {
        $response = new \WP_REST_Response($body, $status);
        $response->header('Cache-Control', 'no-store, private');
        $response->header('X-Robots-Tag', 'noindex');
        return $response;
    }
}
