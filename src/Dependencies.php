<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Tells Watch which Composer packages the app runs (vendor/composer/installed.json, else
 * composer.lock), plus the PHP version and the framework, so it can warn about packages with
 * known vulnerabilities (POST /api/v1/dependencies, "ecosystem": "composer"). Sent when they
 * change, and at most once a day otherwise.
 */
final class Dependencies
{
    public const FRAMEWORKS = [
        'laravel/framework',
        'symfony/framework-bundle',
        'slim/slim',
        'codeigniter4/framework',
        'cakephp/cakephp',
        'yiisoft/yii2',
        'laminas/laminas-mvc',
    ];
    public const MAX_PACKAGES = 2000;
    public const INTERVAL = 86400;

    private const STATE = 'dependencies';

    /**
     * @return list<array{name: string, version: string}>
     */
    public static function packages(string $root): array
    {
        $file = self::sourceFile($root);
        if ($file === null) {
            return [];
        }
        $json = @file_get_contents($file);
        if (!is_string($json)) {
            return [];
        }

        return str_ends_with($file, 'installed.json') ? self::parseInstalled($json) : self::parseLock($json);
    }

    /**
     * The file the package list comes from: installed.json (what is really installed), else
     * composer.lock.
     */
    public static function sourceFile(string $root): ?string
    {
        foreach ([$root . '/vendor/composer/installed.json', $root . '/composer.lock'] as $file) {
            if (is_file($file) && is_readable($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * vendor/composer/installed.json: {"packages": [...]} (Composer 2) or a list (Composer 1).
     *
     * @return list<array{name: string, version: string}>
     */
    public static function parseInstalled(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $list = array_is_list($data) ? $data : ($data['packages'] ?? []);

        return self::normalizeList(is_array($list) ? $list : []);
    }

    /**
     * composer.lock: the "packages" section (not the dev packages).
     *
     * @return list<array{name: string, version: string}>
     */
    public static function parseLock(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_array($data['packages'] ?? null)) {
            return [];
        }

        return self::normalizeList($data['packages']);
    }

    /**
     * "v7.9.2" becomes "7.9.2"; dev versions ("dev-main") are kept as they are.
     */
    public static function normalizeVersion(string $version): string
    {
        return (string) preg_replace('/\A[vV](?=\d)/', '', trim($version));
    }

    /**
     * The first known framework among the packages, else WordPress when $wp_version is set.
     *
     * @param list<array{name: string, version: string}> $packages
     * @return array{name: string, version: string}|null
     */
    public static function framework(array $packages): ?array
    {
        $versions = array_column($packages, 'version', 'name');
        foreach (self::FRAMEWORKS as $name) {
            if (isset($versions[$name])) {
                return ['name' => $name, 'version' => (string) $versions[$name]];
            }
        }

        return self::wordpress();
    }

    /**
     * The framework of the running app, from Composer's runtime API (cheap, no file parsing).
     *
     * @return array{name: string, version: string}|null
     */
    public static function runtimeFramework(): ?array
    {
        $installed = 'Composer\\InstalledVersions';
        try {
            if (class_exists($installed)) {
                foreach (self::FRAMEWORKS as $name) {
                    if ($installed::isInstalled($name)) {
                        $version = $installed::getPrettyVersion($name);

                        return ['name' => $name, 'version' => self::normalizeVersion((string) $version)];
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through.
        }

        return self::wordpress();
    }

    /**
     * @param list<array{name: string, version: string}>|null $packages
     * @return array<string, mixed>|null null when there is nothing to report
     */
    public static function payload(Config $config, ?array $packages = null): ?array
    {
        $packages ??= self::packages($config->root);
        $framework = self::framework($packages);
        if ($packages === [] && $framework === null) {
            return null;
        }
        $payload = [
            'environment' => $config->environment,
            'ecosystem' => 'composer',
            'host' => $config->host,
            'version' => $config->appVersion,
            'php' => PHP_VERSION,
            'framework' => $framework,
            'packages' => $packages,
        ];

        return array_filter($payload, static fn ($value): bool => $value !== null);
    }

    /**
     * Changes only when a package, the PHP version, the framework or the environment changes.
     *
     * @param array<string, mixed> $payload
     */
    public static function digest(array $payload): string
    {
        $lines = [];
        foreach (is_array($payload['packages'] ?? null) ? $payload['packages'] : [] as $package) {
            if (is_array($package)) {
                $lines[] = ($package['name'] ?? '') . ' ' . ($package['version'] ?? '');
            }
        }
        sort($lines, SORT_STRING);
        $lines[] = 'php ' . (string) ($payload['php'] ?? '');
        $lines[] = 'framework ' . (string) Json::encode($payload['framework'] ?? null);
        $lines[] = 'environment ' . (string) ($payload['environment'] ?? '');

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * The payload when it should be sent now (changed, or last sent over a day ago), else null.
     * Cheap when nothing changed: compares the package file's size and time first.
     *
     * @return array<string, mixed>|null
     */
    public static function due(Config $config, Spool $spool, ?int $now = null): ?array
    {
        $now ??= time();
        $stamp = self::stamp($config);
        $state = self::readState($spool);
        $sentAt = is_int($state['sent_at'] ?? null) ? $state['sent_at'] : 0;
        $recent = $now - $sentAt < self::INTERVAL;
        if ($recent && ($state['stamp'] ?? null) === $stamp) {
            return null;
        }
        $payload = self::payload($config);
        if ($payload === null) {
            return null;
        }
        $digest = self::digest($payload);
        if ($recent && ($state['digest'] ?? null) === $digest) {
            self::writeState($spool, $digest, $stamp, $sentAt);

            return null;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function markSent(Config $config, Spool $spool, array $payload, ?int $now = null): void
    {
        self::writeState($spool, self::digest($payload), self::stamp($config), $now ?? time());
    }

    /**
     * @return list<array{name: string, version: string}>
     */
    private static function normalizeList(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || !is_string($item['version'] ?? null)) {
                continue;
            }
            $name = strtolower(trim($item['name']));
            $version = self::normalizeVersion($item['version']);
            if ($name === '' || $version === '') {
                continue;
            }
            $out[$name] = ['name' => $name, 'version' => $version];
        }
        ksort($out, SORT_STRING);

        return array_slice(array_values($out), 0, self::MAX_PACKAGES);
    }

    /**
     * @return array{name: string, version: string}|null
     */
    private static function wordpress(): ?array
    {
        $version = $GLOBALS['wp_version'] ?? null;

        return is_string($version) && $version !== '' ? ['name' => 'wordpress', 'version' => $version] : null;
    }

    private static function stamp(Config $config): string
    {
        $file = self::sourceFile($config->root);
        $parts = [PHP_VERSION, $config->environment];
        if ($file !== null) {
            $parts[] = $file;
            $parts[] = (string) @filemtime($file);
            $parts[] = (string) @filesize($file);
        }
        $wordpress = self::wordpress();
        if ($wordpress !== null) {
            $parts[] = 'wordpress ' . $wordpress['version'];
        }

        return implode('|', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private static function readState(Spool $spool): array
    {
        $text = $spool->readState(self::STATE);
        $state = $text === null ? null : json_decode($text, true);

        return is_array($state) ? $state : [];
    }

    private static function writeState(Spool $spool, string $digest, string $stamp, int $sentAt): void
    {
        $json = Json::encode(['digest' => $digest, 'stamp' => $stamp, 'sent_at' => $sentAt]);
        if ($json !== null) {
            $spool->writeState(self::STATE, $json);
        }
    }
}
