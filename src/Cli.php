<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * vendor/bin/watch: test, deploy, ping, dependencies, config, flush.
 *
 * Exit codes: 0 ok, 1 failed (Watch refused or could not be reached), 2 wrong usage,
 * 3 not configured.
 */
final class Cli
{
    public const OK = 0;
    public const FAILED = 1;
    public const USAGE = 2;
    public const NOT_CONFIGURED = 3;

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    private ?Config $config;

    /**
     * @param resource|null $out
     * @param resource|null $err
     */
    public function __construct(?Config $config = null, $out = null, $err = null)
    {
        $this->config = $config;
        $this->out = $out ?? self::open('php://stdout');
        $this->err = $err ?? self::open('php://stderr');
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $args = array_values(array_slice($argv, 1));
        $command = $args[0] ?? 'help';
        [$positional, $options] = self::parseArgs(array_slice($args, 1));
        try {
            return match ($command) {
                'test' => $this->test(),
                'deploy' => $this->deploy($options),
                'ping' => $this->ping($positional, $options),
                'dependencies' => $this->dependencies($options),
                'config' => $this->showConfig(),
                'flush' => $this->flush(),
                'help', '--help', '-h' => $this->help(),
                'version', '--version', '-V' => $this->version(),
                default => $this->unknown($command),
            };
        } catch (\Throwable $error) {
            $this->error('watch: ' . $error->getMessage());

            return self::FAILED;
        }
    }

    /**
     * @param list<string> $args
     * @return array{0: list<string>, 1: array<string, string|true>}
     */
    public static function parseArgs(array $args): array
    {
        $positional = [];
        $options = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $parts = explode('=', substr($arg, 2), 2);
                $options[$parts[0]] = $parts[1] ?? true;
            } else {
                $positional[] = $arg;
            }
        }

        return [$positional, $options];
    }

    private function test(): int
    {
        $config = $this->config();
        if (!$config->isEnabled()) {
            return $this->notConfigured($config);
        }
        $transport = new Transport($config);
        $filter = new Filter($config->filterKeys);
        $scope = new Scope();

        $entry = LogEntry::build($config, $filter, 'info', 'watch-php test: this log line was sent by vendor/bin/watch test', [
            'event' => 'watch_test',
        ], $scope);
        $logs = $transport->post(Transport::LOGS, ['entries' => [$entry]]);
        $this->line('Log line: ' . $logs->describe());

        try {
            throw new TestError('This is a test error from vendor/bin/watch test');
        } catch (TestError $error) {
            $notice = ErrorReport::fromThrowable($config, $filter, $error, $scope, null, true, [], 'watch#test');
        }
        $errors = $transport->post(Transport::ERRORS, ['errors' => [$notice]]);
        $this->line('Error:    ' . $errors->describe());

        if ($logs->isOk() && $errors->isOk()) {
            $this->line('Both arrived. Open the app in Watch to see them.');

            return self::OK;
        }
        $this->error('Not everything arrived. Check WATCH_URL and WATCH_API_KEY (vendor/bin/watch config).');

        return self::FAILED;
    }

    /**
     * @param array<string, string|true> $options
     */
    private function deploy(array $options): int
    {
        $config = $this->config();
        if (!$config->isEnabled()) {
            return $this->notConfigured($config);
        }
        $payload = Deploy::payload(
            $config,
            self::option($options, 'version'),
            self::option($options, 'by'),
            self::option($options, 'revision'),
        );
        if ($payload === null) {
            $this->error('No version: pass --version=..., set WATCH_APP_VERSION, or run inside a git checkout.');

            return self::USAGE;
        }
        $result = (new Transport($config))->post(Transport::DEPLOYS, $payload);
        if ($result->isOk()) {
            $this->line('Deploy ' . $payload['version'] . ' recorded (' . $config->environment . ').');

            return self::OK;
        }
        $this->error('Deploy not recorded: ' . $result->describe());

        return self::FAILED;
    }

    /**
     * @param list<string> $positional
     * @param array<string, string|true> $options
     */
    private function ping(array $positional, array $options): int
    {
        $token = $positional[0] ?? null;
        if ($token === null || trim($token) === '') {
            $this->error('Usage: watch ping <token or ping URL> [--fail] [--message=...]');

            return self::USAGE;
        }
        $config = $this->config();
        $failed = isset($options['fail']);
        $message = self::option($options, 'message');
        $url = CheckIn::url($config, $token, $failed);
        if ($url === null) {
            $this->error('WATCH_URL is not set: pass the full ping URL or set WATCH_URL.');

            return self::NOT_CONFIGURED;
        }
        if (CheckIn::ping($config, Transport::defaultSender($config), $token, $failed, $message)) {
            $this->line('Ping sent' . ($failed ? ' (failed run)' : '') . '.');

            return self::OK;
        }
        $this->error('Ping not accepted. Check the token, WATCH_URL and the environment (pings are off in "test").');

        return self::FAILED;
    }

    /**
     * @param array<string, string|true> $options
     */
    private function dependencies(array $options): int
    {
        $config = $this->config();
        $payload = Dependencies::payload($config);
        if ($payload === null) {
            $this->error('No vendor/composer/installed.json or composer.lock found in ' . $config->root . ' (set WATCH_ROOT).');

            return self::FAILED;
        }
        $packages = is_array($payload['packages'] ?? null) ? count($payload['packages']) : 0;
        $framework = is_array($payload['framework'] ?? null)
            ? $payload['framework']['name'] . ' ' . $payload['framework']['version']
            : 'none found';
        $this->line('PHP ' . PHP_VERSION . ', framework: ' . $framework . ', ' . $packages . ' packages.');
        if (isset($options['dry-run'])) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::OK;
        }
        if (!$config->isEnabled()) {
            return $this->notConfigured($config);
        }
        $result = (new Transport($config))->post(Transport::DEPENDENCIES, $payload);
        if (!$result->isOk()) {
            $this->error('Not sent: ' . $result->describe());

            return self::FAILED;
        }
        $spool = new Spool($config->spoolDir);
        if ($spool->usable(false)) {
            Dependencies::markSent($config, $spool, $payload);
        }
        $this->line('Sent to Watch.');

        return self::OK;
    }

    private function showConfig(): int
    {
        $config = $this->config();
        foreach ($config->describe() as $name => $value) {
            $this->line(str_pad($name, 20) . $value);
        }
        $problems = $config->problems();
        if ($problems === []) {
            $this->line('');
            $this->line('Ready to send.');

            return self::OK;
        }
        $this->line('');
        foreach ($problems as $problem) {
            $this->line('Problem: ' . $problem);
        }

        return self::NOT_CONFIGURED;
    }

    private function flush(): int
    {
        $config = $this->config();
        if (!$config->isEnabled()) {
            return $this->notConfigured($config);
        }
        $spool = new Spool($config->spoolDir);
        if (!$spool->usable(false)) {
            $this->line('Nothing to send (no spool at ' . $config->spoolDir . ').');

            return self::OK;
        }
        $files = count($spool->dueFiles('metrics')) + count($spool->dueFiles('logs'));
        if ($files === 0) {
            $this->line('Nothing to send (minutes still open are sent once they are over).');

            return self::OK;
        }
        $client = new Client($config, null, $spool, new Request([], [], [], [], false), true);
        $result = $client->sendSpool();
        if ($result === null) {
            $this->line('Another process is sending right now.');

            return self::OK;
        }
        if ($result) {
            $this->line('Sent ' . $files . ' spool file(s).');

            return self::OK;
        }
        $this->error('Watch could not be reached; the files are kept for the next try.');

        return self::FAILED;
    }

    private function help(): int
    {
        $this->line(<<<'TEXT'
            Usage: vendor/bin/watch <command> [options]

            Commands:
              test                          Send a test log line and a test error
              deploy [--version=] [--by=]   Record a deploy (version: WATCH_APP_VERSION, else git)
                     [--revision=]
              ping <token> [--fail]         Heartbeat check-in (token or full ping URL)
                     [--message=]
              dependencies [--dry-run]      Send the installed Composer packages and PHP version
              config                        Show the settings (API key masked)
              flush                         Send the spooled logs and metrics of finished minutes

            Settings come from WATCH_* environment variables (WATCH_URL, WATCH_API_KEY, ...).
            TEXT);

        return self::OK;
    }

    private function version(): int
    {
        $this->line('watch-php ' . Watch::VERSION . ' (PHP ' . PHP_VERSION . ')');

        return self::OK;
    }

    private function unknown(string $command): int
    {
        $this->error('Unknown command "' . $command . '". Run vendor/bin/watch help.');

        return self::USAGE;
    }

    private function notConfigured(Config $config): int
    {
        $this->error('Watch is not set up:');
        foreach ($config->problems() as $problem) {
            $this->error('  - ' . $problem);
        }

        return self::NOT_CONFIGURED;
    }

    private function config(): Config
    {
        return $this->config ??= Config::fromArray();
    }

    /**
     * @param array<string, string|true> $options
     */
    private static function option(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function line(string $text): void
    {
        fwrite($this->out, $text . "\n");
    }

    private function error(string $text): void
    {
        fwrite($this->err, $text . "\n");
    }

    /**
     * @return resource
     */
    private static function open(string $target)
    {
        $handle = fopen($target, 'w');
        if ($handle === false) {
            throw new \RuntimeException('cannot open ' . $target);
        }

        return $handle;
    }
}
