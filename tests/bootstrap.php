<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/wordpress.php';
require_once __DIR__ . '/stubs/wpdb.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Unit tests get a wpdb with no connection: any query fails, which is what
// a dead database looks like. Database tests replace it.
$GLOBALS['wpdb'] = new wpdb(null);
