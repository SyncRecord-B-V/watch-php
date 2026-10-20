<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Sent by `vendor/bin/watch test` to check that errors arrive.
 */
final class TestError extends \RuntimeException
{
}
