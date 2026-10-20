# watch-php: design decisions

Shared decisions for the PHP client (`watch-php`) and the Watch hub changes that support PHP apps.
The wire format is in `ingest-contract.md` (same folder); this file says what we build on top of it.

## Package

- Composer package `syncrecord/watch-php`, namespace `SyncRecord\Watch`, facade class `SyncRecord\Watch\Watch`.
  Repository: github.com/SyncRecord-B-V/watch-php. MIT licence (like watch_client).
- PHP 8.1 or newer (8.1 to 8.5). 8.1 no longer gets security updates, but many hosts still run it, and a
  monitoring tool is most useful on exactly those sites. Code stays 8.1-compatible: no 8.2+ syntax or
  functions (readonly classes, DNF types, standalone true/false/null types, typed class constants,
  #[\Override], property hooks, asymmetric visibility, pipe operator, json_validate, array_find…).
  `declare(strict_types=1);` everywhere.
- No required dependencies besides `ext-json`. Optional: `ext-curl` (preferred transport; falls back to
  PHP streams), `ext-zlib` (gzip), `psr/log` (PSR-3 adapter), `psr/http-server-middleware` (PSR-15
  middleware), `psr/http-client` (PSR-18 decorator), `guzzlehttp/guzzle` (middleware). Adapters live in their
  own classes and are only loaded when used, so a missing optional package never breaks anything.
- "Native PHP": works in any framework or none. Start it once at the top of the front controller
  (`public/index.php`, `wp-config.php`, …): `SyncRecord\Watch\Watch::start();`. Frameworks get thin, optional
  glue (PSR-15 middleware, a PSR-3 logger, `Watch::setRoute()`); recipes in the docs for Laravel, Symfony,
  Slim, CodeIgniter, CakePHP, Yii, Laminas, WordPress and plain PHP.

## Never break the app

- Every public method catches `\Throwable` internally; Watch never throws into the app and never changes
  output, status codes or headers. Handlers it installs chain to the previous ones.
- Network: short timeouts (connect 2 s, total 5 s), sent after the visitor has the response
  (`fastcgi_finish_request()` / `litespeed_finish_request()` in the shutdown function when available,
  setting `finish_request` = true by default; can be turned off).
- HTTPS required for `WATCH_URL` unless the host is local (`localhost`, `127.*`, `::1`, `*.localhost`,
  `*.test`) or `WATCH_ALLOW_HTTP=true` (same rule as watch_client).

## Configuration

Array passed to `Watch::start([...])`, else environment variables (getenv / $_ENV / $_SERVER), else defaults.
Names mirror watch_client: `WATCH_URL`, `WATCH_API_KEY`, `WATCH_ENABLED`, `WATCH_ENV` (fallback
`APP_ENV`, then `production`), `WATCH_APP_VERSION` (fallbacks like watch_client), `WATCH_APP_NAME`,
`WATCH_ALLOW_HTTP`, `WATCH_SEND_LOGS`, `WATCH_SEND_ERRORS`, `WATCH_PERFORMANCE`, `WATCH_MIN_LEVEL`,
`WATCH_ROOT` (project root for relative paths; default: the directory above `vendor/`), `WATCH_SPOOL_DIR`
(default `sys_get_temp_dir()/watch-php-<hash of URL+key>`), `WATCH_FILTER_KEYS` (comma list added to the
built-in list), `WATCH_LOG_REQUESTS` (one log line per request; default false: log volume costs money).
Without URL or key Watch is off and does nothing.

## Delivery model (PHP-FPM, Apache mod_php, CLI, long-running workers)

- **Errors**: sent at the end of the request (or at once in CLI / when `flush()` is called), one batch per
  request, with one retry on 408/425/429/5xx. Nothing kept on disk.
- **Logs and metrics**: written to a small local spool (append-only JSON lines in `WATCH_SPOOL_DIR`, one file
  per minute, `flock`ed), and sent by whichever request runs after the minute is over (a non-blocking lock
  so only one process sends). This keeps it to about one POST per minute per server instead of one per
  request, which respects the hub's 1,200 requests/minute/app limit. Metrics of a minute are added up
  before sending (the hub also adds, so partial sends are safe; but never resend a batch that got a 2xx).
  Failed sends keep the file for the next try; files older than 1 hour or a spool over 20 MB are deleted.
  Without a writable spool directory: send at the end of each request (logs) and skip per-minute metrics
  aggregation (send each request's numbers with the logs flush).
- **CLI and long-running workers** (queues, Swoole/RoadRunner/FrankenPHP worker mode): in-memory buffers
  flushed every 10 s or 500 entries and at shutdown; `Watch::flush()` for workers between jobs.
- No per-process samples (`process`) from PHP-FPM in v1 (the Processes page is meant for long-lived
  processes; PHP-FPM workers would flood it).

## What it collects

- **Errors**: uncaught exceptions (`set_exception_handler`), fatal errors (`register_shutdown_function` +
  `error_get_last()` for E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR),
  `Watch::notify($e, $context)`. PHP warnings/notices/deprecations (`set_error_handler`, respecting
  `error_reporting()` and `@`) become log lines (warn) and breadcrumbs, not errors (option to report them as
  errors). Exception chains (`getPrevious()`) included as causes.
- **Backtrace lines** in watch_client's format, relative to the project root:
  `src/Controller/InvoiceController.php:42:in 'App\Controller\InvoiceController->show'`
  (`->` instance, `::` static, `{closure}` as PHP names it, top-level code `'{main}'`). The hub groups by
  class + first app frame without the line number, so this format keeps groups stable across edits.
- **Component**: web requests `Controller#action` when the app/framework sets it (`Watch::setRoute()`),
  else `GET /invoices/{id}` (method + path with numeric/uuid/hex segments replaced by `{id}`); jobs: the job
  class name exactly as in metrics. The hub treats a component starting with an HTTP method and a space
  as a web request (hub change).
- **Request context** (filtered): method, URL with secret/personal query values masked, route, headers
  (allow-list, Authorization/Cookie/X-Api-Key etc. never), params (filtered), session size only, user id via
  `Watch::setUser($id, $email = null)`, request ID (from `X-Request-Id` or generated).
- **Logs**: `Watch::log($level, $message, $context)`, `Watch::logger()` (PSR-3-compatible methods; a real
  `Psr\Log\LoggerInterface` adapter when psr/log is installed), Monolog handler later. PSR-3 `{placeholder}`
  interpolation; context fields go to `payload` after filtering.
- **Performance** (per minute, aggregated): `requests` per route (count, 5xx errors, 4xx, total/max ms, the
  20-bucket histogram), outgoing `http` per host (count, failures, timeouts, 429s, ms, quota headers),
  `jobs` per job class (`Watch::job('SendInvoice', fn() => ...)`: runs, failures, ms). Optional DB time per
  request via `Watch::recordQuery($ms)` (framework adapters later).
- **Outgoing HTTP**: Guzzle middleware, PSR-18 decorator, and `Watch::recordHttp($host, $ms, $status,
  $headers)` for anything else (curl, WordPress `wp_remote_*` later). Quota headers parsed exactly like
  watch_client's ApiQuota (same headers, 256-byte limit, bounded numbers).
- **Deploys**: `vendor/bin/watch deploy` (version from `WATCH_APP_VERSION`, else git) and `Watch::deploy()`.
- **Heartbeats**: `Watch::checkIn($tokenOrUrl, ?callable $work = null)` and `vendor/bin/watch ping <token> [--fail]`.
- **Vulnerable packages**: the installed Composer packages (`composer.lock` or `vendor/composer/installed.json`)
  and the PHP version, sent to `/api/v1/dependencies` with `"ecosystem": "composer"` when they change (hash
  kept in the spool dir, at most once a day otherwise) and by `vendor/bin/watch dependencies`.
- **CLI** `vendor/bin/watch`: `test` (sends a test log line and error), `deploy`, `ping`, `dependencies`,
  `config` (shows settings, key masked to the last 4 characters), `flush`.

## Dependencies payload (composer)

```json
{ "environment": "production", "ecosystem": "composer", "php": "8.5.1",
  "framework": { "name": "laravel/framework", "version": "12.3.0" },
  "packages": [ { "name": "guzzlehttp/guzzle", "version": "7.9.2" } ] }
```

Versions are normalized: a leading `v` is removed; dev versions (`dev-main`) are sent as is and never matched.

## Hub changes (Watch)

1. `client_apps.platform` (`ruby` | `php`, default `ruby`), chosen when creating an app and editable; set
   to `php` automatically the first time composer dependencies arrive for an app still on the default with no
   Ruby dependencies. Setup guides, checklist, key page, docs and wording follow the platform.
2. Errors: app frames also under `src/`, `routes/`, `resources/`, `database/`, `templates/`, `public/`,
   `modules/` (never `vendor/`); components starting with an HTTP method are web requests, not jobs.
3. Security: an `ecosystem` on advisories and findings (`rubygems` | `composer`); composer advisories from the
   Packagist security advisories API (`https://packagist.org/api/security-advisories/`, fields `affectedVersions`
   in Composer constraint syntax, `severity`, `cve`, `link`, `title`); matching with a Composer constraint
   matcher; fixed version = lowest released stable version not affected by any known advisory (versions from
   `https://repo.packagist.org/p2/<vendor>/<package>.json`); commands
   `composer update vendor/package --with-all-dependencies` (patch/minor within the constraint) or
   `composer require vendor/package:^X.Y` (major step).
