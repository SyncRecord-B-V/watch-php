<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Keep the test output clean: watch-php's own diagnostics go to error_log.
SyncRecord\Watch\Diagnostics::enable(false);
