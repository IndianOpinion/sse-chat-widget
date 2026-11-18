<?php

/**
 * Autoloader for installs without Composer. Composer users get the same
 * mapping from composer.json; loading both is harmless.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'IndianOpinion\\SseChatWidget\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// The optional llm-json-php library, when installed next to the plugin
// without Composer, is picked up from lib/llm-json-php.
if (!class_exists('IndianOpinion\\LlmJson\\Client') && is_file(__DIR__ . '/lib/llm-json-php/bootstrap.php')) {
    require_once __DIR__ . '/lib/llm-json-php/bootstrap.php';
}
