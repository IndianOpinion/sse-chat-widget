<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Model;

use IndianOpinion\SseChatWidget\Model\Transport\CurlTransport;
use IndianOpinion\SseChatWidget\Model\Transport\Transport;
use IndianOpinion\SseChatWidget\Model\Transport\WpHttpTransport;
use IndianOpinion\SseChatWidget\Settings;
use IndianOpinion\SseChatWidget\Support\DebugLog;

/**
 * Chooses the model caller (cases 52, 53, 58, 59): a filter-supplied caller,
 * else the buffered-json caller when chosen and the library is present,
 * else the built-in streaming caller.
 */
final class CallerFactory
{
    private string $name = 'stream';

    private ?string $notice = null;

    /**
     * @param (callable(): bool)|null $libraryAvailable
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly ?Transport $transport = null,
        private $libraryAvailable = null,
    ) {
    }

    public function make(): ModelCaller
    {
        $custom = apply_filters('sse_chat_model_caller', null, $this->settings->all());
        if ($custom instanceof ModelCaller) {
            $this->name = 'filter:' . get_class($custom);
            return $custom;
        }
        if ($custom !== null) {
            DebugLog::throttled('invalid_caller', 'sse_chat_model_caller returned something that is not a ModelCaller; using the built-in caller.', 3600);
        }

        $key = Settings::apiKey()['key'];
        $base = $this->settings->string('base_url');
        // The transport timeout sits just past the stream maximum, so the chat
        // service's own deadline (case 49) fires first.
        $timeout = $this->settings->int('max_stream_seconds') + 5;

        if ($this->settings->string('caller') === 'buffered-json') {
            $available = $this->libraryAvailable !== null ? ($this->libraryAvailable)() : LlmJsonAsker::available();
            if ($available) {
                $this->name = 'buffered-json';
                return new BufferedJsonCaller(new LlmJsonAsker($base, $key, $this->settings->string('model')), $timeout);
            }
            $this->notice = 'The buffered-json caller is selected but indianopinion/llm-json-php is not installed. The built-in streaming caller is used.';
        }

        $transport = $this->transport ?? (CurlTransport::available() ? new CurlTransport() : new WpHttpTransport());
        $this->name = $transport->streams() ? 'stream' : 'stream (buffered: no curl)';
        return new StreamingCaller($transport, $base, $key, $timeout);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function notice(): ?string
    {
        return $this->notice;
    }
}
