<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Deploy markers (POST /api/v1/deploys). Errors and metrics link to a deploy by app version, so
 * the version sent here should be the same string as WATCH_APP_VERSION.
 */
final class Deploy
{
    /**
     * @return array<string, ?string>|null null when no version is known
     */
    public static function payload(
        Config $config,
        ?string $version = null,
        ?string $deployedBy = null,
        ?string $revision = null,
    ): ?array {
        $head = Git::head($config->root);
        $version = Text::presence($version, 100)
            ?? $config->appVersion
            ?? ($head !== null ? substr($head, 0, 12) : null);
        if ($version === null) {
            return null;
        }
        $deployedBy = Text::presence($deployedBy, 100)
            ?? $config->env('WATCH_DEPLOYED_BY')
            ?? $config->env('USER')
            ?? $config->env('USERNAME');

        return [
            'version' => Text::cutChars($version, 100),
            'environment' => $config->environment,
            'revision' => Text::presence($revision, 100) ?? $head,
            'deployed_by' => $deployedBy === null ? null : Text::cutChars($deployedBy, 100),
        ];
    }

    public static function send(
        Config $config,
        TransportInterface $transport,
        ?string $version = null,
        ?string $deployedBy = null,
        ?string $revision = null,
    ): Result {
        $payload = self::payload($config, $version, $deployedBy, $revision);
        if ($payload === null) {
            return Result::drop('no version: pass one, set WATCH_APP_VERSION, or run inside a git checkout');
        }

        return $transport->post(Transport::DEPLOYS, $payload);
    }
}
