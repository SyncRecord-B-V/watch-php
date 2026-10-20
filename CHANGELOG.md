# Changelog

All notable changes to watch-php are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [0.1.0] - Unreleased

First version.

### Added

- `Watch::start()`: one line in the front controller; settings from the array or `WATCH_*` environment variables.
- Errors: uncaught exceptions, fatal errors, `Watch::notify()`, exception chains as causes, backtraces in
  watch_client's format relative to the project root, source code around app frames, breadcrumbs, filtered
  request details, user, context and app info.
- PHP warnings, notices and deprecations as log lines and breadcrumbs (optionally as errors).
- Logs: `Watch::log()` and `Watch::logger()` with PSR-3 methods and `{placeholder}` interpolation.
- Performance per minute: requests per route, outgoing HTTP per host (with API quota headers and 429s),
  jobs per class, with the same 20-bucket histogram as Watch.
- A local spool so logs and metrics leave a server about once a minute instead of once per request.
- In-memory buffers for CLI scripts and long-running workers; `Watch::flush()`.
- `Watch::job()`, `Watch::recordHttp()`, `Watch::recordQuery()`, `Watch::setRoute()`, `Watch::setUser()`,
  `Watch::context()`, `Watch::addBreadcrumb()`.
- Deploys (`Watch::deploy()`, `vendor/bin/watch deploy`) and heartbeats (`Watch::checkIn()`,
  `vendor/bin/watch ping`).
- Installed Composer packages, PHP version and framework for vulnerability alerts.
- Optional adapters: PSR-3 logger, PSR-15 middleware, PSR-18 client decorator, Guzzle middleware.
- `vendor/bin/watch` with `test`, `deploy`, `ping`, `dependencies`, `config` and `flush`.

### Fixed (review before release)

- Overlapping requests in one process (Swoole/OpenSwoole coroutines) no longer share user, route, context
  or request data: each coroutine has its own request state.
- Copying context, log fields and breadcrumb data is bounded (2,000 values, 256 KB of text, 20 levels), and
  filtered keys are replaced before their value is read, so a large or self-referencing value can't exhaust
  memory.
- Spool sending keeps to its 15-second budget: the deadline is checked before every batch and retry, each
  request only gets the time that is left, and unsent records stay in the spool.
- CLI scripts and workers report Composer packages without a spool directory, at the first flush and then
  daily (remembered in `<spool dir>-state`, so short cron runs don't report every time).
- Logs in the spool are never sent twice after a batch was accepted: the file is updated after every batch.
- Spool metrics are only sent with a full timeout left, so the budget can't turn a send into a lost minute.
- Field names over 256 bytes are dropped with their value before filtering or copying (one
  `[KEY TOO LONG]` marker), so a huge key can't exhaust memory.
- Request states from `Client::resolveContextWith()` are released with `Client::releaseContext()`; the
  PSR-15 middleware calls it after every request, also when the request throws. Code that runs after the
  release gets an empty state that isn't kept. Workers (RoadRunner, FrankenPHP) drop the finished
  request's data, and Swoole registers its cleanup once per coroutine (keep-alive connections).
- Oversized header names are skipped before they are lowercased or reformatted (outgoing quota headers,
  incoming request headers).
