<?php

/**
 * Runs offline, without WordPress: feeds a recorded chat completions stream
 * through the parser the built-in caller uses and prints the deltas, the
 * citation markers found and the usage.
 *
 *   php examples/parse-recorded-stream.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use IndianOpinion\SseChatWidget\Chat\CitationTracker;
use IndianOpinion\SseChatWidget\Model\SseLineParser;

$raw = (string) file_get_contents(dirname(__DIR__) . '/tests/fixtures/chat-completions-stream.txt');
$parser = new SseLineParser();
$tracker = new CitationTracker();
$usage = null;

// Feed it in awkward 9-byte pieces, as a network would.
foreach (str_split($raw, 9) as $piece) {
    foreach ($parser->feed($piece) as $data) {
        if (trim($data) === '[DONE]') {
            echo "\n[DONE]\n";
            continue;
        }
        $json = json_decode($data, true);
        if (!is_array($json)) {
            continue;
        }
        $text = $json['choices'][0]['delta']['content'] ?? '';
        if (is_string($text) && $text !== '') {
            echo 'delta: ' . json_encode($text) . "\n";
            $tracker->feed($text);
        }
        if (isset($json['usage'])) {
            $usage = $json['usage'];
        }
    }
}

echo 'markers: ' . implode(', ', $tracker->markers()) . "\n";
echo 'usage: ' . json_encode($usage) . "\n";
