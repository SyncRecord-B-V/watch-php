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
