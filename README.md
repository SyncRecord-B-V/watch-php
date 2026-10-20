# watch-php

The PHP client for Sync Record Watch. It sends your app's errors, logs, response times, outgoing API calls,
background jobs, deploys, heartbeats and installed Composer packages to Watch.

It works in any PHP app: Laravel, Symfony, Slim, CodeIgniter, CakePHP, Yii, Laminas, WordPress or plain PHP.
You add one line to your front controller. Watch never throws into your app and never changes your output,
status codes or headers.

## Requirements

- PHP 8.1 or newer (8.1, 8.2, 8.3, 8.4 and 8.5).
- `ext-json`.
- Optional: `ext-curl` (preferred; without it PHP streams are used), `ext-zlib` (gzip).
- No other packages. The adapters for PSR-3, PSR-15, PSR-18 and Guzzle only load when you use them.

## Install

```bash
composer require syncrecord/watch-php
```

## Quick start

Set two environment variables. You find both on the app's key page in Watch.

```bash
WATCH_URL=https://watch.example.com
WATCH_API_KEY=lh_your_server_key
```

Add one line at the top of your front controller (`public/index.php`, `wp-config.php`, ...), right after
Composer's autoloader:

```php
require __DIR__ . '/../vendor/autoload.php';

SyncRecord\Watch\Watch::start();
```

Check the setup:

```bash
vendor/bin/watch config   # shows the settings, key masked
vendor/bin/watch test     # sends a test log line and a test error
```

Without `WATCH_URL` or `WATCH_API_KEY` Watch is off and does nothing. It is also off in the `test` and
`testing` environments, unless you set `WATCH_ENABLED=true`.

## What is sent, and when

| What | When |
|---|---|
| Errors: uncaught exceptions, fatal errors, `Watch::notify()` | At the end of the request, after the visitor has the response (PHP-FPM). At once in CLI scripts and workers. |
| Logs: `Watch::log()`, `Watch::logger()`, PHP warnings | Written to a small local spool and sent about once a minute per server. |
| Performance: requests per route, outgoing HTTP per host, jobs per class | Added up per minute in the spool and sent about once a minute per server. |
| Installed Composer packages, PHP version, framework | When they change, and at most once a day otherwise. |
| Deploys | When you run `vendor/bin/watch deploy` or call `Watch::deploy()`. |
| Heartbeats | When you call `Watch::checkIn()` or run `vendor/bin/watch ping`. |

How delivery works:

- **PHP-FPM, Apache mod_php, LiteSpeed.** Each request writes its logs and numbers to a spool directory
  (one file per minute, locked while writing). The first request after a minute is over sends that minute,
  under a lock so only one process sends. This keeps it to about one request per minute per server, well
  under Watch's limit of 1,200 requests per minute per app. With PHP-FPM or LiteSpeed the response is
  finished first (`fastcgi_finish_request()`), so visitors never wait for Watch.
- **CLI scripts and long-running workers** (queue workers, RoadRunner, Swoole, FrankenPHP worker mode).
  Logs and numbers stay in memory and are sent every 10 seconds or 500 log lines, and when the script ends.
  Call `Watch::flush()` between jobs in a worker loop.
- **No writable spool directory.** Logs and numbers are sent at the end of each request instead.

Failed sends are retried (on 408, 425, 429 and 5xx answers and network errors). Spool files wait for the
next try; files older than 1 hour, or a spool over 20 MB, are deleted.

## Configuration

Pass an array to `Watch::start([...])`, or set environment variables (read with `getenv()`, `$_ENV` and
`$_SERVER`). The array wins.

| Option | Environment variable | Default |
|---|---|---|
| `url` | `WATCH_URL` | none (Watch is off) |
| `api_key` | `WATCH_API_KEY` | none (Watch is off) |
| `enabled` | `WATCH_ENABLED` | on when URL and key are set, off in `test` / `testing` |
| `environment` | `WATCH_ENV` | `WATCH_ENVIRONMENT`, then `APP_ENV`, then `production` |
| `app_version` | `WATCH_APP_VERSION` | `HEROKU_SLUG_COMMIT`, `GIT_SHA` or `SOURCE_VERSION` (first 12 characters) |
| `app_name` | `WATCH_APP_NAME` | none |
| `host` | `WATCH_HOST` | `DYNO`, `HOSTNAME` or the machine's host name |
| `allow_http` | `WATCH_ALLOW_HTTP` | `false` (https only, except local hosts) |
| `send_logs` | `WATCH_SEND_LOGS` | `true` |
| `send_errors` | `WATCH_SEND_ERRORS` | `true` |
| `performance` | `WATCH_PERFORMANCE` | `true` |
| `min_level` | `WATCH_MIN_LEVEL` | `info` |
| `log_requests` | `WATCH_LOG_REQUESTS` | `false` (one log line per request; log volume costs money) |
| `root` | `WATCH_ROOT` | the directory that holds `vendor/` |
| `spool_dir` | `WATCH_SPOOL_DIR` | `sys_get_temp_dir()/watch-php-<hash of URL and key>` |
| `filter_keys` | `WATCH_FILTER_KEYS` | none (comma list, added to the built-in list) |
| `report_dependencies` | `WATCH_REPORT_DEPENDENCIES` | `true` |
| `finish_request` | `WATCH_FINISH_REQUEST` | `true` (finish the response before sending) |
| `warnings_as_errors` | `WATCH_WARNINGS_AS_ERRORS` | `false` (PHP warnings become log lines) |
| `capture_errors` | `WATCH_CAPTURE_ERRORS` | `true` (install the exception and error handlers) |
| `worker_mode` | `WATCH_WORKER_MODE` | automatic (CLI and FrankenPHP workers buffer in memory) |
| `timeout` | `WATCH_TIMEOUT` | `5` seconds |
| `connect_timeout` | `WATCH_CONNECT_TIMEOUT` | `2` seconds |
| `batch_size` | `WATCH_BATCH_SIZE` | `500` log lines (1 to 1000) |
| `flush_interval` | `WATCH_FLUSH_INTERVAL` | `10` seconds (CLI and workers) |
| `diagnostics` | `WATCH_DIAGNOSTICS` | `true` (Watch's own problems go to PHP's `error_log`, once a minute at most) |
| `ignore_errors` | | exception classes never reported, added to a built-in list of "not found" and validation exceptions |
| `before_notify` | | `fn (array $notice): ?array`, change an error or return `null` to drop it |
| `before_send` | | `fn (array $entry): ?array`, change a log line or return `null` to drop it |
| `breadcrumbs_max` | | `40` |
| `backtrace_lines` | | `100` |
| `source_frames`, `source_lines` | | `10` frames, `3` lines either side |

## The API

```php
use SyncRecord\Watch\Watch;

Watch::start();                                         // once, at the top
Watch::notify($exception, ['invoice_id' => 42]);        // a caught exception
Watch::log('warning', 'Payment {id} retried', ['id' => 7]);
Watch::logger()->error('Export failed', ['exception' => $e]);
Watch::setRoute('InvoiceController#show');              // names the request
Watch::setUser($user->id, $user->email);                // the e-mail is only sent with errors
Watch::context(['tenant' => 'acme']);                   // added to errors and log lines
Watch::addBreadcrumb('custom', 'Cart recalculated', ['items' => 3]);
Watch::job('SendInvoice', fn () => $mailer->send($invoice));
Watch::recordHttp('api.example.com', 123.4, 200, $responseHeaders);
Watch::recordQuery(4.2);
Watch::checkIn('your-heartbeat-token');
Watch::deploy();
Watch::flush();
```

Requests are named by `Watch::setRoute()` when your framework or code calls it. Otherwise Watch uses the
method and the path with IDs replaced, like `GET /invoices/{id}`. Numbers, UUIDs and long hex strings count
as IDs. Paths with slugs (`/blog/my-first-post`) each get their own name, so call `setRoute()` for those.

## Framework recipes

### Laravel

Start Watch in `public/index.php`, right after the autoloader:

```php
require __DIR__.'/../vendor/autoload.php';

SyncRecord\Watch\Watch::start();
```

Laravel installs its own exception handler, so report exceptions from `bootstrap/app.php` (Laravel 11 and 12):

```php
use SyncRecord\Watch\Watch;

->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $exceptions) {
    $exceptions->report(function (Throwable $e) {
        Watch::notify($e, [], handled: false);
    });
})
```

Name requests and set the user in a middleware or a service provider:

```php
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use SyncRecord\Watch\Watch;

Event::listen(RouteMatched::class, function (RouteMatched $event) {
    Watch::setRoute($event->route->getActionName());   // "App\Http\Controllers\InvoiceController@show"
    if ($user = $event->request->user()) {
        Watch::setUser($user->getAuthIdentifier());
    }
});
```

Database time per request:

```php
DB::listen(fn ($query) => Watch::recordQuery($query->time));
```

A logging channel (Laravel already has `psr/log` and Monolog). Add a small class:

```php
// app/Logging/WatchLogger.php
namespace App\Logging;

use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use SyncRecord\Watch\Adapter\Psr3Logger;

final class WatchLogger
{
    public function __invoke(array $config): Logger
    {
        return new Logger('watch', [new PsrHandler(new Psr3Logger())]);
    }
}
```

and the channel in `config/logging.php` (add `watch` to your `stack` channel to use it next to your files):

```php
'watch' => [
    'driver' => 'custom',
    'via' => App\Logging\WatchLogger::class,
],
```

Queue jobs: wrap the job's work with `Watch::job(static::class, fn () => ...)`, or listen to the queue events.
Run `vendor/bin/watch deploy` in your deploy script.

### Symfony

Start Watch in `public/index.php` before the kernel boots. Symfony's ErrorHandler takes over PHP's
handlers, so report exceptions from a `kernel.exception` listener:

```php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use SyncRecord\Watch\Watch;

final class WatchListener
{
    #[AsEventListener(priority: -10)]
    public function onRequest(RequestEvent $event): void
    {
        $controller = $event->getRequest()->attributes->get('_controller');
        if (is_string($controller)) {
            Watch::setRoute($controller);   // "App\Controller\InvoiceController::show"
        }
    }

    #[AsEventListener]
    public function onException(ExceptionEvent $event): void
    {
        Watch::notify($event->getThrowable(), [], handled: false);
    }
}
```

If your stack is PSR-15 based, `SyncRecord\Watch\Adapter\Psr15Middleware` does both.

### Slim

```php
use SyncRecord\Watch\Adapter\Psr15Middleware;
use SyncRecord\Watch\Watch;

Watch::start();
$app = Slim\Factory\AppFactory::create();
$app->add(new Psr15Middleware());   // add it before addRoutingMiddleware(), so it runs after routing
$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, true, true);
```

The middleware names requests by their route pattern (`GET /invoices/{id}`), records the status and time,
and reports exceptions that pass through it.

### CodeIgniter 4

Start Watch in `public/index.php` after the autoloader. Name requests in a filter or a base controller:

```php
Watch::setRoute(service('router')->controllerName() . '::' . service('router')->methodName());
```

Report exceptions from your exception handler (`app/Config/Exceptions.php`), or call `Watch::notify()`.

### CakePHP

Start Watch in `webroot/index.php`. Name requests in `AppController::beforeFilter()`:

```php
Watch::setRoute($this->request->getParam('controller') . 'Controller#' . $this->request->getParam('action'));
```

CakePHP's middleware queue accepts PSR-15 middleware: `$middlewareQueue->add(new Psr15Middleware())`.

### Yii 2

Start Watch in `web/index.php`. Name requests with an event:

```php
Yii::$app->on(yii\base\Application::EVENT_BEFORE_ACTION, function ($event) {
    Watch::setRoute($event->action->getUniqueId());   // "invoice/view", sent as "GET invoice/view"
});
```

### Laminas

Start Watch in `public/index.php`. In a module's `onBootstrap()`, listen to `MvcEvent::EVENT_ROUTE` and call
`Watch::setRoute()` with the matched route name; listen to `MvcEvent::EVENT_DISPATCH_ERROR` and
`EVENT_RENDER_ERROR` and call `Watch::notify()` with the exception. Mezzio apps use the PSR-15 middleware.

### WordPress

Add this to `wp-config.php`, above the line that loads `wp-settings.php`:

```php
require __DIR__ . '/vendor/autoload.php';   // where you installed watch-php with Composer
SyncRecord\Watch\Watch::start();
```

Or use a must-use plugin, `wp-content/mu-plugins/watch.php`:

```php
<?php
require WP_CONTENT_DIR . '/watch/vendor/autoload.php';
SyncRecord\Watch\Watch::start();
```

The WordPress version is reported with the vulnerable-packages data.

### Plain PHP

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

use SyncRecord\Watch\Watch;

Watch::start();
Watch::setRoute('InvoiceController#show');
```

## Outgoing HTTP calls

Watch counts calls per host: count, 5xx, 4xx, 429s, timeouts, time, and the API's remaining quota from its
headers (`RateLimit-*`, `X-RateLimit-*`, HubSpot, Salesforce, Shopify, Exact Online).

Guzzle:

```php
$stack = GuzzleHttp\HandlerStack::create();
$stack->push(SyncRecord\Watch\Adapter\GuzzleMiddleware::create(), 'watch');
$client = new GuzzleHttp\Client(['handler' => $stack]);
```

Any PSR-18 client:

```php
$client = new SyncRecord\Watch\Adapter\Psr18Client($yourPsr18Client);
```

Anything else (curl, `wp_remote_get()`, ...):

```php
$start = hrtime(true);
$response = wp_remote_get('https://api.example.com/items');
Watch::recordHttp(
    'api.example.com',
    (hrtime(true) - $start) / 1e6,
    wp_remote_retrieve_response_code($response) ?: null,
    wp_remote_retrieve_headers($response)->getAll(),
    is_wp_error($response) ? 'connection' : null,
);
```

## Jobs

```php
Watch::job('App\Jobs\SendInvoice', function () use ($invoice) {
    // ...
}, queue: 'mail');
```

Watch counts runs, failures and time per job class. A failure is reported as an error with the job class as
its component (so the Jobs page links them) and the exception is thrown again. In a worker loop, call
`Watch::flush()` between jobs.

## Deploys

```bash
vendor/bin/watch deploy                       # version from WATCH_APP_VERSION, else the git commit
vendor/bin/watch deploy --version=1.4.2 --by=jan
```

Errors and metrics link to a deploy by version, so set `WATCH_APP_VERSION` to the same value in the app.

## Heartbeats

```php
Watch::checkIn('your-heartbeat-token');                    // ping now
Watch::checkIn('your-heartbeat-token', fn () => export()); // ping when it worked, /fail when it threw
```

From cron:

```bash
php artisan export && vendor/bin/watch ping your-heartbeat-token || vendor/bin/watch ping your-heartbeat-token --fail
```

`ping` takes `--message=...` too. A full ping URL works in place of a token. Pings need `WATCH_URL` but no
API key. They are off in the `test` environment.

## Vulnerable packages

Watch reads `vendor/composer/installed.json` (or `composer.lock`), the PHP version and the framework
(`laravel/framework`, `symfony/framework-bundle`, `slim/slim`, `codeigniter4/framework`, `cakephp/cakephp`,
`yiisoft/yii2`, `laminas/laminas-mvc`, or WordPress) and sends them when they change, at most once a day
otherwise. Watch then warns about packages with known vulnerabilities. To send now, or to see what is sent:

```bash
vendor/bin/watch dependencies --dry-run
vendor/bin/watch dependencies
```

## Privacy and filtering

- Values whose key contains `passw`, `email`, `secret`, `token`, `_key`, `crypt`, `salt`, `certificate`,
  `otp`, `ssn`, `cvv`, `cvc` or `iban` are replaced by `[FILTERED]`, at every level. Add your own with
  `WATCH_FILTER_KEYS=coupon,pin`. A rule with a dot, like `account.pin`, matches nested keys.
- In URLs, query values whose name looks secret or personal (token, key, auth, session, signature, code,
  email, name, phone, address, iban, zip, birth, ...) are masked. Long or odd query strings are left out.
- Headers: only a short list of harmless headers keeps its value (Accept, User-Agent, Referer, Host, ...).
  `Authorization`, `Cookie`, `X-Api-Key`, CSRF tokens and IP-forwarding headers are never sent.
- Cookies: names only. Session: only its size, never its contents.
- Request parameters (query and form fields) are filtered the same way. Request bodies are not sent.
- The e-mail address from `Watch::setUser()` is only sent with errors, because you pass it on purpose.

## Limits

- Errors: at most 20 per request; 100 backtrace lines; messages up to 8 KB.
- Log lines: up to 32 KB of message, 64 KB per line; at most 10,000 waiting in memory.
- Metrics: up to 300 routes, 100 hosts and 200 job classes per minute.
- Spool: one file per kind and minute (up to 5 MB each), files older than 1 hour or a spool over 20 MB are
  deleted.
- Network: 2 s to connect, 5 s in total.

## Troubleshooting

- Run `vendor/bin/watch config` and `vendor/bin/watch test`. They show what is missing and whether Watch
  answers.
- Watch writes its own problems to PHP's error log, prefixed with `[watch-php]`, once a minute at most.
- Nothing arrives from web requests: check that the PHP user can write `WATCH_SPOOL_DIR`. If a CLI command
  running as another user (root) created the directory first, delete it or point `WATCH_SPOOL_DIR` at a
  directory the web server owns.
- Logs arrive a minute late: that is the spool. Errors are sent at the end of each request.
- Without PHP-FPM or LiteSpeed (for example Apache mod_php) the visitor waits while Watch sends at the end of
  the request. This happens at most about once a minute per server, with a 5 s timeout.
- The PHP session is written after Watch sends at the end of a request. If Watch is slow to answer, other
  requests of the same visitor wait for the session lock. Call `session_write_close()` early if that matters.
- Frameworks that install their own exception handler (Laravel, Symfony) need the `notify()` hook from the
  recipes above. Fatal errors are always caught.
- `http://` URLs are refused except for `localhost`, `127.*`, `::1`, `*.localhost` and `*.test`. Set
  `WATCH_ALLOW_HTTP=true` only on a private network you trust.

## Development

```bash
composer install
vendor/bin/phpunit
```

The tests never use the network: they use a fake transport.

## License

MIT. Copyright SyncRecord B.V.
