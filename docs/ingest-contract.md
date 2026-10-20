# Watch ingest contract (for watch-php)

The exact rules the Watch hub (`/Users/ferdinandcrayop/mqt/watch`, Rails 8.1.4) uses when it takes in
data, and how the Ruby client (`/Users/ferdinandcrayop/mqt/watch_client`, v0.10.0) builds each
payload. A PHP client that follows this file sends the same data in the same format.
Paths are relative to each repo. `W:` = hub (`watch/`), `C:` = Ruby client (`watch_client/lib/watch_client/`).

---

## 0. Things that apply to every endpoint

### 0.1 Transport (what the Ruby client does) — `C:transport.rb`
| Item | Value |
|---|---|
| Method | `POST` to `"#{WATCH_URL.chomp('/')}#{path}"` (`transport.rb:305-307`) |
| Headers | `Content-Type: application/json`, `Content-Encoding: gzip`, `Authorization: Bearer <api_key>`, `User-Agent: watch_client/<VERSION> ruby/<RUBY_VERSION>` (`transport.rb:80-86`) |
| Body | `gzip(JSON.generate(object))` (`transport.rb:264-266`). Batches are wrapped: logs `{"entries":[...]}`, errors `{"errors":[...]}`. Deploys, metrics, database and dependencies send the object as it is (`transport.rb:221-226`). |
| TLS | Refuses non-https except `localhost`, `127.0.0.1`, `::1`, `127.x.x.x`, `*.localhost`, `*.test`, or when `WATCH_ALLOW_HTTP=true` (`configuration.rb:400-408, 428-433`) |
| Timeouts | open/read/write = `config.timeout` = 5 s; keep-alive 30 s, one persistent connection per transport (`transport.rb:309-320`) |
| Result | 2xx → ok. **Retry** on `408, 425, 429, 500, 502, 503, 504` and on network errors (IOError, EOFError, SocketError, SystemCallError, Timeout, OpenTimeout, ReadTimeout, HTTPBadResponse, Zlib::Error). **Drop** on everything else (400, 401, 413, 422 …) (`transport.rb:215-219, 293-303`). |
| Backoff (logs/errors) | `min(2**(attempt-1), 30) + rand*0.5` seconds, `max_retries = 4`, then the batch is dropped and counted (`client.rb:156-178`, `configuration.rb:343`). On flush/shutdown: 1 retry while the deadline (`shutdown_timeout = 5`) allows, else 0. |
| Metrics/dependencies/deploy | Metrics: one immediate retry if the result says retry (`metrics.rb:170-171`). Deploy and dependencies: no retry. |

The hub never asks for `Retry-After`; it does not send one (Rails `rate_limit` answers with the custom JSON below).

### 0.2 Authentication — `W:app/controllers/api/v1/base_controller.rb`
- `token = request.authorization.to_s.delete_prefix("Bearer ").strip` (`:32`).
- `ClientApp.authenticate(token)`: blank or not starting with `"lh_"` → nil. Otherwise it looks up `active` apps by `api_key_digest = SHA256 hex(token)` (`W:app/models/client_app.rb:6, 111-119`). Keys look like `lh_` + 40 base58 characters (`client_app.rb:97-99`). A paused (inactive) app gets the same 401.
- Failure → **401** `{"error":"invalid or missing API key"}` (`base_controller.rb:34`).
- Browser key `lhb_…` is public and only works on `/api/v1/browser_*` via `?key=`.

### 0.3 Gzip — `W:config/initializers/loghub.rb` (Rack middleware `Loghub::GzipRequestBody`)
- Active when `PATH_INFO` starts with `/api/` **and** `Content-Encoding` is `gzip` (case-insensitive, trimmed) (`:50-53`). Plain (uncompressed) JSON works too; then no inflation happens.
- Runs **before** auth and the Rails rate limits.
- Per-IP gzip throttle: `MAX_GZIP_PER_IP_PER_MINUTE = 3_000` (cache key `gzip-ip:<ip>:<epoch/60>`) → **429** `{"error":"rate limit exceeded"}` (`:15, 24, 42-48`).
- Compressed body read cap `MAX_COMPRESSED_BYTES = 8 MiB` (`:10`); inflated cap `MAX_INFLATED_BYTES = 20 MiB` (`:11`), or `256 KiB` for `/api/v1/browser_*` (`:12`). Over either → **413** `{"error":"payload too large"}` (`:28-30`).
- Broken gzip → **400** `{"error":"invalid gzip body"}` (`:36-37`).
- After inflating it sets `CONTENT_LENGTH` to the inflated size and removes the header (`:32-34`).

### 0.4 Body size, JSON, wrong types — `base_controller.rb`
- `MAX_BODY_BYTES = 20.megabytes` against `Content-Length` (after inflation) → **413** `{"error":"payload too large"}` (`:6, 37-41`). Runs after auth.
- Body is parsed with `JSON.parse(request.raw_post)` (`:43-45`); the Content-Type is **not** checked (`wrap_parameters false`, `:8`). Bad JSON → **400** `{"error":"invalid JSON"}` (in each controller).
- A field of the wrong type that raises `TypeError`/`NoMethodError` while ingesting → **422** `{"error":"malformed report"}` (`:22-25`). (Most numeric fields go through `IngestValue` and never raise; see 0.6.)

### 0.5 Rate limits (Rails `rate_limit`, 1-minute windows)
| Scope | Limit | Where | Answer |
|---|---|---|---|
| per IP, all `/api/v1` (non-browser) controllers, before the key check | 3 000/min | `base_controller.rb:12-13` | 429 `{"error":"rate limit exceeded"}` |
| per app, after the key check | **1 200/min** | `base_controller.rb:17-18` | 429 same body |
| gzip per IP (middleware) | 3 000/min | `loghub.rb:15` | 429 same body |
| browser errors | 60/min per IP, 600/min per app | `browser_errors_controller.rb:15, 21` | 204 + `X-Watch-Refused: rate-limited` |
| browser vitals | 120/min per IP, 6 000/min per app | `browser_vitals_controller.rb:14, 20` | 204 + header |
| heartbeat ping | 120/min per IP, 30/min per token | `heartbeat_pings_controller.rb:9-12` | 429 `text/plain` `Too many pings\n` |

Rails 8.1's `rate_limit` keys its cache entry by controller path + name + `by`, so the `api-app` 1 200/min budget is
**per endpoint** (logs, errors, metrics … each have their own). **Important for PHP:** a client that sends one
request per PHP web request goes over 1 200/min on any busy app. PHP must batch (a spool/APCu/shared-memory
buffer drained by a sidecar, a cron job or a `register_shutdown_function` that only sends every N seconds).

### 0.6 Number and text coercion — `W:app/services/ingest_value.rb`
- `INT_MAX = 2**31-1`, `BIGINT_MAX = 2**63-1`, `FLOAT_MAX = 1e15` (`:5-7`).
- `int(v)`: Integer → clamp ±INT_MAX; finite Float → `to_i` (truncates) then clamp; String → `to_i` (leading digits, else 0); anything else → 0 (`:11-18`).
- `num(v)`: Numeric/String → `to_f`, non-finite → 0.0, clamp ±1e15 (`:20-27`).
- `counter(v)`: nil stays nil, else `int(v).clamp(0, INT_MAX)` (`:30-32`). `big_counter`: same for 0..2**63-1 (`:35-45`). `positive_num`: nil stays nil, else `num` clamped to 0..1e15 (`:48-50`).
- `add_int(a,b)`: sum clamped to ±INT_MAX (`:53-55`).
- `text(v, max)`: only String/Numeric/Symbol (else `""`); forced UTF-8, invalid bytes removed, control characters `[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]` removed, `strip`, first `max` **characters** (`:65-69`).
- LogIngestor and ErrorIngestor have their own `text(value, max_bytes)`: `to_s`, `scrub("?")`, delete NUL, cut at **bytes** (`byteslice` then drop a broken last character) (`log_ingestor.rb:119-125`, `error_ingestor.rb:174-179`). Note: here a Hash/Array value is turned into its Ruby `to_s` text instead of being refused, so always send strings.

### 0.7 `mark_seen!`
`client_app.last_seen_at` is updated at most once a minute (`client_app.rb:137-139`). It is set by logs (if any rows),
errors (if any accepted), deploys, metrics and database. **Not** by dependencies. The setup checklist step "connected" means
`last_seen_at.present?` (`W:app/services/app_setup.rb:53`).

---

## 1. POST /api/v1/logs

Controller `W:app/controllers/api/v1/logs_controller.rb`, ingestor `W:app/services/log_ingestor.rb`.

**Body:** `{"entries":[{...}, ...]}` or a bare JSON array (`logs_controller.rb:10`). Other shapes → **422** `{"error":"expected an \"entries\" array"}`.

**Response:** **202** `{"accepted":n,"rejected":n,"dropped":n}` (`:17`).
- `rejected` = entries past `MAX_ENTRIES = 1_000` (`log_ingestor.rb:10, 31`) + entries that are not JSON objects (`:34-38`).
- `dropped` = entries over the account's monthly log volume (not stored, but counted as usage) (`:42-46, 65-78`). Bytes per row: `message.bytesize + payload.to_json.bytesize + Plan::LINE_OVERHEAD_BYTES` (`W:app/services/usage_meter.rb:87-90`).
- All entries may be dropped/rejected and it is still 202. Do **not** retry on `dropped`.

### Entry fields (`log_ingestor.rb:3-8, 17, 87-105`)
`KNOWN_KEYS = %w[timestamp logged_at level message environment host request_id user_id job_id]`. **Every other key goes into `payload` (jsonb).**

| Field | Type | Hub rule | Ruby client fills it with |
|---|---|---|---|
| `timestamp` (or `logged_at`) | ISO 8601 string | `Time.iso8601` (strict ISO 8601, needs a timezone). Blank → received time. Outside `[now-2.days, now+1.hour]` (`MAX_PAST`, `MAX_FUTURE`, `:15-16`) → received time and the original kept in `payload.original_timestamp`. Unparseable → received time, `original_timestamp` = first 64 chars (`:108-117`). | `time.utc.iso8601(6)` → `2026-10-20T12:34:56.123456Z` (`C:entry.rb:37`) |
| `level` | string or int | `LogEntry.level_value`: Integer → clamp 0..5; `/\A\d+\z/` string → int clamp; else downcase/strip, aliases `warning→warn, err→error, critical/crit/emergency/alert→fatal, trace→debug`; unknown → `info` (`W:app/models/log_entry.rb:6-26`). Stored as 0..5 = `debug info warn error fatal unknown`. | `debug`/`info`/`warn`/`error`/`fatal`/`unknown` (`C:entry.rb:10, 91-98`) |
| `message` | string | byte-cut at `MAX_MESSAGE_BYTES = 32 KiB`; nil → `""` (`:11, 102`) | text, ANSI colours `/\e\[[\d;]*m/` removed, `strip`, cut at 32 KiB (`C:entry.rb:17, 123-127`) |
| `environment` | string | 32 bytes | `config.environment` (`WATCH_ENVIRONMENT` / `RAILS_ENV` / `RACK_ENV` / `development`) |
| `host` | string | 255 bytes | `ENV DYNO` / `HOSTNAME` / `Socket.gethostname` |
| `request_id` | string | 100 bytes | from the context (X-Request-ID / Rails request id) |
| `user_id` | string | 100 bytes → column `user_ref` | `current_user_id` resolver, only when the context has a request_id (`C:entry.rb:47`) |
| `job_id` | string | 100 bytes | from the job context |
| anything else | any JSON | kept in `payload`. If `payload.to_json` > `MAX_PAYLOAD_BYTES = 16 KiB` the payload becomes `{"_truncated":true,"_bytes":N}` (`:12, 127-132`) | `app_version`, `job_class`, `queue`, `progname`, structured fields, request summary fields |

### How the Ruby client builds one entry — `C:entry.rb:27-82`
1. `level` = name; message split: a Hash message is filtered, then text = `message`/`msg`/`event`/`"k=v k2=v2"`; the remaining keys become fields (`:106-121`).
2. Base: `timestamp, level, message, environment, host`, plus `app_version` when set (`:36-43`).
3. Context fields (`with_context`, `request_id`, `job_id`, `job_class`, `queue`) are filtered and added as scalars (`:46`).
4. Structured/payload fields are added but never overwrite `RESERVED = timestamp level message environment host request_id user_id job_id` (`:20, 49-52`).
5. nil values are removed. Size limit per entry `MAX_ENTRY_BYTES = 64 KiB`: core fields other than message cut at 255 chars, then any extra field whose JSON > `MAX_FIELD_BYTES = 8 KiB` becomes `"[TRUNCATED: n bytes]"`, then all extras are dropped with `fields_truncated: true` (`:13-16, 60-82`).
- Batching: `batch_size = 500` (validated 1..1000), `flush_interval = 3 s`, `max_queue = 10_000` entries / 16 MiB, oldest dropped first (`configuration.rb:339-345`, `client.rb:43-51`).
- Default `min_level = info`.

Request summary log line (Rails) — `C:request_subscriber.rb:259-282`: level `error` when status ≥ 500 else `info`; message `"GET /invoices/42 200 in 45ms (InvoicesController#show)"`; payload `event:"request", method, path (filtered URL), status, duration_ms, controller, action, format, view_ms, db_ms, exception` (nil values removed).

---

## 2. POST /api/v1/errors

Controller `W:app/controllers/api/v1/errors_controller.rb`, ingestor `W:app/services/error_ingestor.rb`.

**Body:** `{"errors":[notice, ...]}` or a bare array. Else **422** `{"error":"expected an \"errors\" array"}`.
**Response:** **202** `{"accepted":n,"rejected":n,"dropped":n}`. `rejected` = past `MAX_NOTICES = 100` (`:10, 27`) + non-objects; `dropped` = over the monthly error-events quota (`:29-35`).
Ruby client: separate queue, `batch_size: 20`, `flush_interval: 1` (`C:../watch_client.rb:53-56`).

### Notice fields (`error_ingestor.rb:48-116`)
| Field | Type | Hub rule | Ruby client |
|---|---|---|---|
| `exception_class` | string | 255 bytes; blank → `"UnknownError"` (`:51`) | `exception.class.name` |
| `message` | string | 8 KiB bytes (`MAX_MESSAGE_BYTES`, `:12, 52`); nil → `""` | `Entry.clean(message)` cut at 8 KiB (`C:notice.rb:34`) |
| `backtrace` | array of strings | first `MAX_BACKTRACE_LINES = 200`, each line 1 000 bytes, nil lines skipped (`:11, 53`) | first `backtrace_lines = 100` lines, app-root prefix removed (see §2.1) |
| `causes` | array of `{exception_class, message, backtrace}` | first 5; whole JSON ≤ 64 KiB else `[]` (`:95`) | up to `MAX_CAUSES = 3`, each backtrace first 10 lines (`C:notice.rb:118-132`) |
| `fingerprint` | string | 1 000 bytes; blank → default (§2.2) (`:55`) | only when the app passes one |
| `occurred_at` | ISO 8601 | `Time.iso8601`; outside `[now-2.days, now+1.hour]` or invalid → now (no original kept) (`:167-172`) | `time.utc.iso8601(6)` |
| `environment` | string | 32 bytes | config |
| `host` | string | 255 bytes | config |
| `app_version` | string | 64 bytes (`:56`); stored as first/last seen version | `WATCH_APP_VERSION` / `HEROKU_SLUG_COMMIT` / `GIT_SHA` / `SOURCE_VERSION`, first 12 chars (`configuration.rb:457-460`) |
| `request_id` | string | 100 bytes | context |
| `user_id` | string | 100 bytes → `user_ref` | only inside a request (`C:notice.rb:46`) |
| `component` | string | 255 bytes | `job_class` from context, else `"Admin::InvoicesController#show"` (controller path camelized + `Controller#action`) (`C:notice.rb:279-292`) |
| `handled` | bool | `ActiveModel::Type::Boolean.cast`, default false (`:104`) | `true`/`false` |
| `tags` | array | **only on a new group**: first 5, `to_s` (`:67`) | not sent (browser errors get `["javascript"]`) |
| `request` | object | whole JSON ≤ 64 KiB else `{}`; `remote_ip` (64 bytes), `user_agent` (500), `referrer` (1 000) are also copied to columns (`:96, 113-115, 141-143`) | see below |
| `context` | object | ≤ 64 KiB else `{}` | context fields minus `request_id`, merged with `notify(context:)`, filtered (`C:notice.rb:59-61`) |
| `source` | array of objects | first 20 objects, ≤ 64 KiB (`:105`) | §2.3 |
| `breadcrumbs` | array of objects | **last** 100 objects, ≤ 64 KiB (`:106`) | §2.4 |
| `session`, `cookies`, `cgi_env`, `app_env`, `notifier` | objects | non-objects → `{}`; each ≤ 64 KiB else `{}` (`:107-111`) | §2.5 |
| `job` | object | `{class (255), id (100), queue (100), attempt (Integer 0..10 000 only), arguments}`; `arguments` JSON > 8 KiB → `"[TOO LARGE]"` (`:128-139`) | §2.6 |

`limit_json` replaces a section whose JSON is over `MAX_JSON_BYTES = 64 KiB` with the fallback (`:13, 181-183`) and removes NUL from keys/values, nesting depth > 32 → nil (`:186-195`).

The Ruby client removes nil values from the notice (`C:notice.rb:63`).

### 2.1 Backtrace line format (the important one)
Ruby lines look like
```
app/models/invoice.rb:42:in 'Invoice#total'            # Ruby 3.4 style
app/models/invoice.rb:42:in `total'                     # older Ruby style
/usr/local/bundle/gems/activerecord-8.1.4/lib/active_record/base.rb:10:in 'block in foo'
```
- Client: lines that start with `"#{app_root}/"` and contain neither `/vendor/` nor `/gems/` lose that prefix, so they become relative (`C:notice.rb:105-116`). Everything else stays absolute.
- Hub "app frame" test, `W:app/models/error_occurrence.rb:4-16`:
  ```ruby
  APP_FRAME        = %r{\A(?:\./)?(?:app|lib|config|db)/}
  JS_APP_FRAME     = %r{\A/[^\s:]*\.m?js:\d+}
  JS_LIBRARY_FRAME = %r{\.min\.|/vendor/|/node_modules/}
  app_frame?(line) = APP_FRAME.match?(line) || (JS_APP_FRAME.match?(line) && !JS_LIBRARY_FRAME.match?(line))
  ```
  Only relative paths under `app/`, `lib/`, `config/`, `db/` (optionally `./`) count as the app's own code. **`src/`, `routes/`, `database/`, `resources/`, `templates/`, `public/`, `bin/` do NOT match.** A Symfony app (`src/`) would have no app frames: the culprit becomes the first line and the page shows only the first line under "Your app's frames". Fix needed on the hub (extend the regex, ideally per platform), or the PHP client must accept that.
- Culprit = first app frame, else the first line, cut to 255 chars (`error_ingestor.rb:157-159`).
- **Default fingerprint** (`error_ingestor.rb:163-165`):
  ```ruby
  "#{exception_class}|#{culprit.to_s.sub(/:\d+(?=:in |\z)/, '')}"
  ```
  The first `:<digits>` that is followed by `:in ` or by the end of the string is removed (line numbers ignored, so editing the file keeps the group). The group key is `SHA256 hex(fingerprint)` (`W:app/models/error_group.rb:24-26`), unique per app.
  For PHP to group well the line must be `<file>:<line>:in '<function>'` or `<file>:<line>` (nothing after the number). With `file.php(42): foo()` (PHP's own trace text) the regex does not match and the line number stays in the fingerprint, so every edit creates a new group.
- Recommended PHP line (byte-compatible with Ruby 3.4 style):
  `src/Billing/Invoice.php:42:in 'App\Billing\Invoice->total'` (relative to the project root when inside it and not under `vendor/`), and `/var/www/vendor/laravel/framework/src/…/Foo.php:10:in 'Illuminate\…\Foo->bar'` for library frames. Method label: `Class->method` / `Class::method` / `{closure}` / `function`. Use `'…'` quotes (the client regex accepts both `` ` `` and `'`).
- PHP note: PHP's trace frame N gives the **function that was called** at file:line (the caller's position). To print "the function this line is in" like Ruby, take `file`/`line` from frame N and `class`/`function` from frame N+1 (the first line is the throw position `$e->getFile():$e->getLine()` with the function of frame 0). Without that the fingerprints still work, but the labels are shifted.
- Display: the page shows app frames (first 15) with their source and the full list; `backtrace_line_tag` is bold for app frames (`W:app/helpers/errors_helper.rb:13-17`, `W:app/views/error_groups/show.html.erb:265-298`). Causes show their first 10 lines.

### 2.2 Grouping rules — `error_ingestor.rb:59-87`
- Group found or created by `(client_app, SHA256(fingerprint))`. A new group copies `fingerprint, exception_class, message, culprit, first/last_seen_at, first_seen_version, tags`.
- Every notice: `occurrences_count += 1`, `exception_class`/`message` overwritten with the newest, `culprit` updated when present, `last_seen_at = max`, `last_seen_version`, `last_environment`.
- A group in state `resolved` reopens (`regressed_at = now`) → "regression" alert. Ignored groups still count occurrences.
- Alerts: `ErrorNotificationJob` for new / regression when the app has channels and the switches allow it, at most once per `notified_recently?` window (`:145-154`).
- To group differently, send `fingerprint` (any string ≤ 1 000 bytes).

### 2.3 `source` items (`C:notice.rb:235-261`)
`{"index": <backtrace index>, "file": "app/models/x.rb", "line": 42, "method": "total", "start": 39, "lines": ["…", …]}`.
Only for relative frames (not starting with `/`), first `source_frames = 10` such frames, `source_lines = 3` lines either side, each line `chomp[0,300]`, files < 1 MB. Client regex:
```ruby
FRAME = /\A(?<file>.+?):(?<line>\d+)(?::in [`'](?<method>.*)')?/
```
The hub matches `source` to backtrace lines by `index` (`show.html.erb:266`) and marks `line` (`errors_helper.rb:78-83`).

### 2.4 Breadcrumbs (`C:breadcrumbs.rb:291-305`)
`{"at": time.utc.iso8601(3), "category": "request|query|render|job|mail|http|cache|log|custom|…", "message": ≤300 chars, "metadata": {… filtered, "duration_ms": n}}`.
Kept per request/job, last `breadcrumbs_max = 40`. The hub shows `category` with a badge (`request query render job mail http cache log click navigation console custom`, `errors_helper.rb:28-41`), `metadata.duration_ms` in its own column and the other metadata as `k: v`. Ruby examples: `request` "InvoicesController#show" `{method,path,format}`; `query` (SQL with literals replaced by `?`) `{name,duration_ms,cached}`; `http` "GET https://host/path 200" `{duration_ms}`; `log` (warn+ log lines) `{level}`.

### 2.5 Request / session / env sections (`C:notice.rb:141-233`)
- `request`: `{method, url (filtered, no fragment), params (filtered; JSON > 16 KiB → {"_truncated":true}), remote_ip, referrer (filtered, ≤1000), user_agent (≤500), headers}` with nil removed. `headers`: every `HTTP_*` env key as `User-Agent` style names (strip `HTTP_`, split `_`, capitalize, join `-`), values ≤ 500 chars. These are always `[FILTERED]`: `HTTP_COOKIE HTTP_AUTHORIZATION HTTP_PROXY_AUTHORIZATION HTTP_X_API_KEY HTTP_X_CSRF_TOKEN HTTP_X_FORWARDED_FOR HTTP_X_REAL_IP HTTP_CF_CONNECTING_IP HTTP_TRUE_CLIENT_IP HTTP_FORWARDED` (`:18-19`). Referer is URL-filtered.
- The hub shows `request.url` + `request.method`, `request.params` (Params tab), `cgi_env` or else `request.headers` (Environment tab).
- `cgi_env`: `REQUEST_METHOD SERVER_NAME SERVER_PORT SERVER_PROTOCOL SERVER_SOFTWARE SCRIPT_NAME PATH_INFO REMOTE_ADDR CONTENT_TYPE CONTENT_LENGTH HTTPS rack.url_scheme` (≤500), `QUERY_STRING` (filtered), `REQUEST_URI`/`ORIGINAL_FULLPATH` (filtered), plus all `HTTP_*` keys (raw names), filtered. PHP: the same keys from `$_SERVER`.
- `session`: filtered session hash (also `/csrf/i`, `/\Asession_id\z/`, `/\Awarden\./`), only when the session was loaded. `cookies`: names with values `[FILTERED]` unless `send_cookie_values`. Each section: JSON > `MAX_SECTION_BYTES = 16 KiB` → `{"_truncated":true}`.
- `app_env` (`C:app_env.rb`): `{environment_name, hostname, project_root, revision, pid, ruby_version, rails_version, stats: {rss_mb, load:[1,5,15]}}` nil removed. Shown as raw JSON, so PHP can send `php_version`, `framework`, `framework_version` instead.
- `notifier`: `{"name":"watch_client","version":"0.10.0","language":"ruby"}`. Shown as "name version". PHP: `{"name":"watch-php","version":"x.y.z","language":"php"}`.
- Filter: default keys `passw email secret token _key crypt salt certificate otp ssn cvv cvc iban` (substring, case-insensitive) plus the app's filter list, at every level; mask `[FILTERED]` (`C:configuration.rb:303`, `C:filter.rb`). URL query values whose name matches `/token|secret|passw|key|auth|session|signature|code|e?mail|name|phone|tel|address|iban|zip|postcode|birth/i` are masked too; queries > 4096 bytes / > 100 params / deeper than 8 are left out (`C:filter.rb:18, 54-103`).

### 2.6 Job details (`C:job_details.rb`)
`{"class": "SyncJob", "id": "<job id>", "queue": "default", "attempt": <executions or retry_count+1>, "arguments": [...]}` nil removed.
Arguments only when the job opts in or `send_job_arguments`: max 200 values / ~4 KiB, strings ≤ 300 chars (`…` appended when cut), emails → `[FILTERED]`, records → `"Model#id"`, depth > 6 → `[TOO DEEP]`, cycles `[CIRCULAR]`, cut → `[TRUNCATED]`, final JSON > 8 KiB → `"[TOO LARGE: n bytes]"`. Without arguments the page says "Not sent (`send_job_arguments` is off)".

### 2.7 `component` naming matters
- `W:app/services/job_errors.rb:29-33` and `W:app/services/error_hints.rb:200-202` treat a component **without `#` and not starting with `"page "`** as a **job class**. So web errors must use a `#`: `"InvoiceController#show"` (or `"App\Http\Controllers\InvoiceController#show"`); job errors use the job class only (`"App\Jobs\SyncInvoices"`); console commands use e.g. `"artisan#app:sync"` if they should not count as jobs.
- The job page links errors by `component == job_class` (`job_errors.rb:9-25`), so the job class in metrics (`jobs[].job_class`) must equal the error's `component`.

---

## 3. POST /api/v1/deploys — `W:app/controllers/api/v1/deploys_controller.rb`

Body (object; else 422 `{"error":"expected a JSON object"}`):
| Field | Rule |
|---|---|
| `version` | `to_s.first(100)`; **required** (`Deploy` validates presence, ≤100) (`W:app/models/deploy.rb:4`) |
| `environment` | `first(32).presence` |
| `revision` | `first(100).presence` |
| `deployed_by` | `first(100).presence` |
`deployed_at` = time received (no timestamp from the client). **201** `{"id":n}`; validation failure **422** `{"error":"Version can't be blank"}`.
Ruby: `notify_deploy(version: app_version, environment:, revision: nil, deployed_by: nil)` → `{"version","environment","revision","deployed_by"}` (nil kept as JSON null; the hub turns `null.to_s` into "" → nil) (`C:../watch_client.rb:112-122`). Rake task: `VERSION` env or app_version or `git rev-parse --short=12 HEAD`; `DEPLOYED_BY` or `USER` (`C:tasks.rake:25-34`).
Errors and metrics link to a deploy by `app_version == deploy.version`, so send the same string.

---

## 4. POST /api/v1/metrics (in depth)

Controller `W:app/controllers/api/v1/metrics_controller.rb`, ingestor `W:app/services/metrics_ingestor.rb`.
Body must be an object (else 422 `{"error":"expected a JSON object"}`). **202** `{"accepted": <number of metric rows upserted>}`. Calls `mark_seen!`.

### 4.1 Top level (Ruby `C:metrics.rb:143-162`)
```json
{
  "sampled_at": "2026-10-20T12:34:00Z",    // now.utc.iso8601 (no fraction)
  "host": "web-1", "pid": 1234,
  "environment": "production", "version": "a1b2c3d4e5f6",   // sent, NOT read by the hub
  "requests": [...], "http": [...], "jobs": [...], "job_http": [...],
  "queues": {...}, "process": {...}
}
```
- `sampled_at`: `Time.zone.parse`; if nil, > now+2 min or < now-30 min → now (`:59-69`).
  - metric rows go into the **5-minute period** `floor(epoch(at)/300)*300` (`:59-63`, `PERIOD = 5.minutes`, `:14`);
  - queue/process/quota samples go into `at.beginning_of_minute` (`:65-69`).
- `environment` and `version` are ignored by the ingestor (metrics are not split by environment).
- `host`, `pid` are only used for the process sample (§4.7). **Metric rows are not per host or pid.**
- Ruby skips the send when requests/http/jobs/job_http are empty and there is no process/queues sample (`:166-168`). Sent once per `max(database_interval=60, 15)` s per process (`:280-282, 299-306`).

### 4.2 Aggregation semantics — ADD, not replace (safe for many PHP processes)
- Rows from `requests` (kind `request`, name = `endpoint`), `http` (kind `http`, name = `host`), `jobs` (kind `job`, name = `job_class`), `job_http` (kind `job_http`, name = `"host|JobClass"`) (`:44-47`).
- Stored with `MetricPeriod.upsert_all(..., unique_by: :index_metric_periods_unique, on_duplicate: ADD_ON_CONFLICT)` (`:49`). Unique index: `(client_app_id, kind, resolution, name, period_start)` (`W:db/structure.sql:3579`).
- `ADD_ON_CONFLICT` (`:16-33`):
  ```sql
  count = LEAST(metric_periods.count::bigint + EXCLUDED.count, 2147483647)::integer,
  error_count / client_errors / failures / timeouts / rate_limited / queries  -- same: added, capped at INT_MAX
  total_ms = metric_periods.total_ms + EXCLUDED.total_ms,
  max_ms = GREATEST(metric_periods.max_ms, EXCLUDED.max_ms),
  db_ms, view_ms, http_ms, wait_ms                                             -- added
  wait_max_ms = GREATEST(...),
  queue = COALESCE(EXCLUDED.queue, metric_periods.queue),
  buckets = loghub_add_arrays(metric_periods.buckets, EXCLUDED.buckets)        -- element-wise add, capped at INT_MAX
  ```
  `loghub_add_arrays(a,b)` (`structure.sql:30-44`): NULL → the other; length = max of both; `a[i] = LEAST(coalesce(a[i],0)::bigint + coalesce(b[i],0), 2147483647)`.
- **So yes: any number of PHP processes/requests may each send partial counts for the same 5-minute period and name; they add up.** Postgres `INSERT … ON CONFLICT DO UPDATE` is atomic per row.
- Within one report the same name may appear twice; those are merged first (`rows_for`, `:183-212`).
- Caveats:
  1. Not idempotent: if the hub stored a report but the answer got lost (timeout) and the client retries, the numbers count twice. Ruby accepts that (one retry). PHP should retry metrics at most once, and only on connection errors before sending / 429 / 5xx.
  2. Rate limit 1 200 reports/min per app (§0.5): many PHP-FPM workers each sending per minute is fine (≈ workers × 1/min); per request is not.
  3. Concurrent multi-row upserts that touch the same rows in a different order can deadlock in Postgres (→ 500, the transaction is rolled back, so a retry is safe). Sort items by name inside each list to lower that risk.
  4. Hourly rollup (`W:app/jobs/metrics_rollup_job.rb`) rebuilds the last 3 hours from 5-minute rows every hour, so late reports (`sampled_at` up to 30 min old) are included.
  5. `max_ms`/`wait_max_ms` take the max; everything else is summed. Averages are `total_ms / count`.

### 4.3 Common row fields and per-field clamps (`rows_for`, `:183-212`)
For every item (must be an object; `name` = `text(item[name_key], 255)`; skipped if name empty or `int(count) <= 0`):
| Field | Clamp |
|---|---|
| `count` | `int`, must be > 0 |
| `errors` → `error_count` | `int.clamp(0, count)` |
| `client_errors`, `failures`, `timeouts`, `rate_limited` | `int.clamp(0, count)` |
| `queries` | `counter` (0..INT_MAX) |
| `total_ms` | `num.clamp(0, 1e12)` |
| `max_ms` | `num.clamp(0, 1e9)` |
| `db_ms`, `view_ms`, `http_ms` | `num.clamp(0, 1e12)` |
| `wait_ms` | `num.clamp(0, 1e13)` |
| `wait_max_ms` | `num.clamp(0, 1e11)` |
| `queue` | only for kind `job`: `text(queue, 100).presence` |
| `buckets` | `Histogram.clean(item["buckets"])` (§4.4) |
Fields not sent count as 0. List caps: `requests` first `MAX_ENDPOINTS = 300`, `http` first `MAX_HOSTS = 100`, `jobs` first `MAX_JOB_CLASSES = 200`, `job_http` first `MAX_JOB_HOSTS = 500` (`:9-13`). The Ruby client has the same caps per minute and ignores new names once full (`C:metrics.rb:24-27`).

### 4.4 Histogram (must be identical)
Client `C:histogram.rb`, hub `W:app/models/histogram.rb:5, 13-25`:
```
BOUNDS (ms) = [5, 10, 25, 50, 75, 100, 150, 200, 300, 500, 750, 1000, 1500, 2000, 3000, 5000, 7500, 10000, 20000]   # 19 bounds
SIZE = 20
index(ms) = first i with ms <= BOUNDS[i], else 19
```
So bucket 0 = `ms <= 5` (also 0 and negatives), bucket 1 = `5 < ms <= 10`, …, bucket 18 = `10000 < ms <= 20000`, bucket 19 = `> 20000`. Comparison is on the float value (5.0 → 0, 5.01 → 1).
PHP:
```php
const BOUNDS = [5,10,25,50,75,100,150,200,300,500,750,1000,1500,2000,3000,5000,7500,10000,20000];
function bucket(float $ms): int { foreach (BOUNDS as $i => $b) { if ($ms <= $b) return $i; } return 19; }
```
Hub cleaning: `Array(values).first(20).map { [IngestValue.int(v), 0].max }` padded with zeros to 20 (`Histogram.clean`). Longer arrays are cut, floats truncated, negatives → 0, non-numbers → 0. Always send exactly 20 integers.
Percentiles are estimated with linear interpolation inside a bucket (last bucket upper = 30 000) (`histogram.rb:35-54`).

### 4.5 `requests[]` (kind `request`)
Ruby (`C:metrics.rb:69-87, 264-277`, from `process_action.action_controller`, `C:request_subscriber.rb:258-260`):
```json
{"endpoint":"InvoicesController#show","count":12,"errors":1,"total_ms":540.3,"max_ms":120.5,
 "db_ms":200.1,"view_ms":150.2,"http_ms":30.0,"buckets":[0,2,5,3,1,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0]}
```
- `errors` = responses with status ≥ 500 (an exception without status counts as 500).
- `db_ms` / `view_ms` = Rails `db_runtime` / `view_runtime`; `http_ms` = time in outgoing HTTP during the request.
- Floats are rounded to 1 decimal (`finish`, `:276-278`).
- The Performance page shows the split as Database / Views / Outside calls / "Ruby / other" (= total − db − view − http) (`W:app/helpers/performance_helper.rb:27`).
- `endpoint` is any string ≤ 255. PHP suggestion: `Controller#action` (Laravel `InvoiceController#show`, Symfony controller `App\Controller\InvoiceController::show` → `InvoiceController#show`) or `GET /invoices/{id}` for closures. Never raw URLs (cardinality: max 300 names per report).

### 4.6 `http[]` (kind `http`) incl. quotas and 429s
Ruby (`C:metrics.rb:91-113`, from the Net::HTTP hook `C:net_http_propagation.rb:35-72`):
```json
{"host":"api.example.com","count":40,"errors":2,"client_errors":3,"rate_limited":1,"failures":1,"timeouts":1,
 "total_ms":4210.5,"max_ms":900.2,"buckets":[...20...],
 "quotas":[{"name":"default","limit":5000,"remaining":4980,"reset_at":"2026-10-20T13:00:00Z"}]}
```
- `host` = `address`, plus `:port` when not the default (443 for https, 80 for http).
- `errors` += 1 when status ≥ 500 **or** the call failed; `client_errors` when 400..499; `rate_limited` when status == 429; `failures` when the call raised (`timeout` or `connection`); `timeouts` when failure == `timeout`. Timeouts = OpenTimeout/ReadTimeout/WriteTimeout/Timeout::Error; connection = SystemCallError/SocketError/IOError/SSLError.
- `quotas`: latest quota per name of this minute (`row['quotas'][name] = quota`).
- Hub quota storage (`store_api_quotas`, `:128-181`): per http item (first 100), first `MAX_QUOTAS_PER_HOST = 4` quotas; `name = text(name,30).presence || "default"`; `limit = big_counter.clamp(0, 2**62)`, skipped unless > 0; `remaining = big_counter.clamp(0, limit)`; `reset_at` parsed, kept only if within `(now-1 min, now+400 days)`. Upsert by `(client_app_id, host, name, sampled_at minute)` → **latest report of the minute wins (replace)** (`structure.sql:3201`). Budget: max `MAX_QUOTA_HOSTS_PER_APP = 50` hosts and 4 names per host seen in the last day; new ones over that are dropped.
- Quota header parsing (`C:api_quota.rb`): headers > 256 bytes ignored; numbers `/\A\s*-?\d{1,19}(?:\.\d{1,6})?\s*\z/`; names: `default` from `RateLimit-Limit/-Remaining/-Reset` (reset relative seconds) or one `RateLimit: limit=…, remaining=…, reset=…` header (`FIELD = /\A\s*([a-z]{1,9})\s*=\s*(\d{1,19})\s*\z/i`, also `r`/`t`, max 8 fields); `minute` from `X-RateLimit-Minutely-*`; `X-RateLimit-*` → `day` when `X-RateLimit-Minutely-Remaining` exists (Exact Online), else `default`; HubSpot `X-HubSpot-RateLimit-Daily(-Remaining)` → `day`; Salesforce `Sforce-Limit-Info: api-usage=used/limit` → `day`; Shopify `X-Shopify-Shop-Api-Call-Limit: used/limit` → `bucket`. Unique by name, first 4. `limit` must be > 0 and ≤ 2**62; `remaining` clamped to 0..limit. Reset: relative seconds when the header is IETF or the number < 10 000 000; Unix ms when > 100 000 000 000; else Unix s; else HTTP date; dropped if < now-60 s or > now+400 days; sent as `utc.iso8601`.

### 4.7 `jobs[]` (kind `job`) and `job_http[]` (kind `job_http`)
Ruby (`C:metrics.rb:117-140, 249-262`, recorded by `C:active_job.rb` / `sidekiq.rb`):
```json
{"job_class":"SyncInvoicesJob","queue":"default","count":10,"errors":1,"total_ms":12000.5,"max_ms":3000.1,
 "buckets":[...20...],"wait_ms":520.0,"wait_max_ms":200.0,"db_ms":4000.2,"http_ms":6000.3,"queries":120}
```
- `errors` = runs that raised. `wait_ms` = sum of (start − max(enqueued_at, scheduled_at)) per run, `wait_max_ms` = max. `db_ms`/`queries` = SQL time and number of queries during the run (no schema/cached queries); `http_ms` = time in outgoing HTTP (`C:job_timer.rb`). The job page shows SQL / outside calls / "Ruby / other" (`W:app/views/performance/job.html.erb:41`).
- `queue` is the last non-empty value (`COALESCE`).
- `job_http` items (outgoing calls made while a job runs, per job class and host):
  `{"job_class":"SyncInvoicesJob","host":"api.example.com","count":12,"errors":1,"rate_limited":0,"total_ms":800.0}` (no buckets; the hub fills zeros). The hub builds the name `"#{host.delete('|').first(120)}|#{job_class.first(130)}"` cut at 255 (`:112-121`, `W:app/models/metric_period.rb:6, 25-27`); items with an empty host or job_class are skipped. Per app at most `MAX_JOB_HOSTS_PER_APP = 1_000` distinct names seen in the last day; new names over that are dropped (`:108-126`).
- PHP job classes (Laravel queue jobs, Symfony Messenger handlers) go into `jobs[]` with the same `job_class` string used as error `component`.

### 4.8 `queues` (global, replace) — `store_queues`, `:72-88`
```json
{"adapter":"sidekiq","queues":[{"name":"default","size":12,"latency_s":3.2}],
 "failed":3,"retries":1,"dead":0,"scheduled":5,"running":2}
```
- `adapter` ≤ 30 chars; first `MAX_QUEUES = 50` queues with a name; `size` int clamp ≥ 0; `latency_s` = age of the oldest waiting job, `num.clamp(0, 1e9)`.
- An extra totals row with queue `"*"` (`QueueSample::TOTALS`): size = sum, latency = max, and `failed retries dead scheduled running` (counters, nil when not sent).
- Upsert by `(client_app_id, queue, sampled_at minute)` (`structure.sql:3635`): **latest report of the minute wins**. Every process may send it; they all send the same global numbers.
- Ruby adapters: `solid_queue` (sends `failed`, `scheduled`, `running`) and `sidekiq` (sends `retries`, `dead`, `scheduled`, `running`) (`C:job_queues.rb`). The Jobs page has a note for `solid_queue` only (`W:app/views/performance/jobs.html.erb:20`). PHP: e.g. `laravel-redis`, `laravel-database`, `symfony-messenger`.
- Alert "queue backlog": oldest job waiting > threshold 3 samples in a row (`W:app/services/performance_alerts.rb:158-171`).

### 4.9 `process` (per host+pid, replace) — `store_process`, `:90-102`
```json
{"role":"web","rss_mb":312.4,"threads":14,"uptime_s":3600,"gc_count":12,"gc_major":1,"gc_time_ms":80,
 "heap_live_slots":1200000,"puma":{"running":5,"max_threads":5,"pool_capacity":3,"backlog":0,"requests_count":1000}}
```
- Stored in `process_samples` keyed by `(client_app_id, sampled_at minute, host, pid)` (`structure.sql:3621`); `ProcessSample.upsert` without an add rule → **replaces** that row. `host` = `text(body.host, 255)`, `pid` = `int(body.pid)`.
- Columns: `role` (≤20, Ruby: `web` / `worker` / `other`), `rss_mb` (positive_num), `threads`, `uptime_s`, `gc_count`, `gc_major`, `gc_time_ms` (counters; GC numbers are **deltas since the previous sample**), `heap_live_slots` (big_counter), `puma_running`, `puma_max_threads`, `puma_pool_capacity`, `puma_backlog` (counters). `requests_count` is ignored.
- Used by: Processes page (one line per `host:pid`, `W:app/models/process_sample.rb:5-7`), charts summing `rss_mb` and `puma_max_threads - puma_pool_capacity` per minute (`W:app/services/performance_report.rb:157-217`, `W:app/models/process_hourly.rb`), alerts `memory_growth` (per `host:pid`, > 1.5× in 3 h and > 300 MB), `puma_saturated` (sum of `puma_backlog` > 0 three minutes in a row), `gc_heavy` (avg `gc_time_ms`/60 000 > 10 % with ≥ 10 samples) (`performance_alerts.rb:53-58, 173-216`).
- **PHP caution:** PHP-FPM has many short-lived worker pids. A sample per worker pid floods the Processes page and makes `memory_growth` meaningless. Send at most one process sample per host per minute (e.g. the FPM master pid, or `pid: 0`, with total RSS of the pool), or leave `process` out. Map FPM status (`active processes`, `max children`, `listen queue`) onto `puma.running / max_threads / pool_capacity / backlog` only if the hub labels are made platform-neutral (they say "Puma"). `gc_*` and `heap_live_slots` can be left out (nil).

---

## 5. POST /api/v1/dependencies (and the Security feature)

### 5.1 Endpoint — `W:app/controllers/api/v1/dependencies_controller.rb`, `W:app/services/dependencies_ingestor.rb`
Ruby payload (`C:dependencies.rb:267-276`), sent once per boot ~20 s after a server/worker starts (`:322-334`):
```json
{"environment":"production","host":"web-1","version":"a1b2c3d4e5f6","ruby":"3.4.7","rails":"8.1.4",
 "gems":[{"name":"rack","version":"3.1.8"}, ...],"digest":"<sha256 hex>"}
```
Hub (`dependencies_ingestor.rb:16-38`):
- `gems`: first `MAX_GEMS = 2_000`; each must be an object; `name.strip` must match `/\A[\w.-]+\z/` and `version.strip` must match `/\A[\w.]+\z/`; stored as `{name.first(100) => version.first(50)}`. **No valid gem → 422 `{"error":"no gems in the report"}`.**
  - **Composer names `vendor/package` contain `/` → all rejected. Versions like `1.2.3-beta1`, `v2.0.0-RC1`, `dev-main` (with `-`) are rejected; `v1.2.3` passes.** The hub needs changes before PHP can use this endpoint.
- `environment` = `text(env,32).presence || "production"`.
- The client's `digest` is **ignored**; the hub computes `SHA256(sorted "name version" lines joined by "\n")`.
- One `app_dependencies` row per `(client_app_id, environment)` (unique, `structure.sql:3208`), overwritten each time: `digest, gems (jsonb {name: version}), reported_at, ruby_version (≤20), rails_version (≤20), app_version (≤100, from "version"), host (≤255)`.
- Unchanged (same digest and same ruby version) → **202** `{"result":"unchanged"}`; else `DependencyScanJob.perform_later(app.id)` and **202** `{"result":"changed"}`. Does not call `mark_seen!`.

### 5.2 Tables (`W:db/structure.sql`)
- `app_dependencies` (`:184-195`): `id, client_app_id, environment NOT NULL, digest NOT NULL, ruby_version, rails_version, app_version, host, gems jsonb DEFAULT '{}' NOT NULL, reported_at NOT NULL`.
- `gem_advisories` (`:700-714`): `gem NOT NULL, identifier NOT NULL, title NOT NULL, url, criticality, cvss float, published_on date, patched_versions varchar[] NOT NULL, unaffected_versions varchar[] NOT NULL, description, timestamps`; unique `(gem, identifier)` (`:3446`).
- `dependency_findings` (`:456-466`): `client_app_id, gem_advisory_id, environment, gem, version, first_found_at, resolved_at, notified bool`; partial unique `(client_app_id, environment, gem_advisory_id) WHERE resolved_at IS NULL` (`:3299`).
- No ecosystem column anywhere: "gem" is the package name; `"ruby"` is a pseudo gem for Ruby itself.

### 5.3 Advisory import — `W:app/services/gem_advisory_importer.rb`, `W:app/jobs/advisory_update_job.rb`
- Daily at 05:00 (`W:config/recurring.yml:45-48`): download `https://github.com/rubysec/ruby-advisory-db/archive/refs/heads/master.tar.gz` (`LOGHUB_ADVISORY_DB_URL`; ≤ 60 MB, ≤ 5 redirects), then `DependencyScanJob.perform_now` (all apps with dependencies).
- Files matching `%r{/(gems/[^/]+|rubies/ruby)/[^/]+\.ya?ml\z}`; `gem` = `"ruby"` for `rubies/ruby`, else YAML `gem`.
- `identifier` = `"CVE-<cve>"`, else `"GHSA-<ghsa>"`, else `"OSVDB-<osvdb>"`, else file basename (≤100). `title` squished ≤255 (fallback identifier), `url` ≤500, `criticality` downcased, `cvss` = `cvss_v3 || cvss_v2`, `published_on` from `date`, `patched_versions`/`unaffected_versions` = arrays of RubyGems requirement strings (e.g. `"~> 2.2.9"`, `">= 3.0.9"`), `description` ≤2 000.
- `upsert_all` by `(gem, identifier)` in slices of 1 000, updating the content columns. Advisories are never deleted.

### 5.4 Matching — `W:app/models/gem_advisory.rb`, `W:app/services/dependency_scanner.rb`
- For each `AppDependency` of the app: `versions = gems` plus `versions["ruby"] = ruby_version` when present; candidates = `GemAdvisory.where(gem: versions.keys)` (by name only).
- `affects?(version)`: `Gem::Version.new(version)`; **safe** if any `patched_versions` or `unaffected_versions` requirement is satisfied, where each requirement string is split on `,` into a `Gem::Requirement` (all parts must hold); otherwise affected. Unparseable version → not affected (`gem_advisory.rb:9-16, 39-45`).
- Opens a finding for each new match (`environment, gem, version, first_found_at`), updates `version` of an existing open one, sets `resolved_at` on open findings that no longer match (`dependency_scanner.rb:11-32`).
- New findings → `SecurityNotifier` when `app.perf_alerts_enabled` and the app has Slack/email channels; then `notified = true` (`:36-41`).
- **Fixed version** (`GemAdvisory#fix_for`, `:20-31`): candidates = every version number found in `patched_versions` (each requirement split on `,`, first `/\d[\w.]*/` of each part); the lowest candidate `> current` that this advisory does not affect.
- **Upgrade target** (`W:app/models/gem_upgrade.rb:45-54`): over **all** known advisories of the gem (also ones the current version doesn't have), the lowest fix candidate `> current` that **none** of them affects; nil = "no fix yet". `step` = `major`/`minor`/`patch` from `Gem::Version#segments` (`:57-66`). `command` (`:71-79`): `bundle update <gem> --patch --strict` / `--minor --strict` / `bundle update <gem>`; nil for `ruby`. Sorted by worst severity (`CRITICALITY_ORDER = critical 0, high 1, medium 2, low 3, none 4`); `severity` = criticality or from cvss (≥9 critical, ≥7 high, ≥4 medium, else low).

### 5.5 Where Security shows up
- `W:app/controllers/security_controller.rb`, `W:app/views/security/index.html.erb` (columns App / Vulnerable gems / **Ruby / Rails** / Gems / Last report; text "Ruby Advisory Database", "Gemfile.lock", `bin/rails watch:dependencies SEND=1`), `W:app/views/security/show.html.erb` (`Ruby x · Rails y · n gems`, "Gems to update", Ruby upgrade text with `.ruby-version`, Bundler command, `Gemfile.lock`, `gem "x", ">= y"` hint via `Gem::Version`).
- `W:app/services/security_notifier.rb` (Slack/email: "runs N gems with known vulnerabilities", "Update to x or newer"; mail via `UptimeMailer.monitor_alert`).
- Counts: `W:app/controllers/dashboards_controller.rb:57`, `W:app/controllers/client_apps_controller.rb:54` (`distinct.count(:gem)`), `W:app/views/dashboards/show.html.erb:261-262`, `W:app/views/client_apps/show.html.erb:218`, `W:app/helpers/navigation_helper.rb:76`, `W:app/services/nav_counts.rb:44` (`:vulnerable_gems`), `W:app/services/attention_items.rb:118-123`, `W:app/views/shared/_attention.html.erb:47`, `W:app/helpers/plain_language_helper.rb:31-35`, `W:app/views/performance/show.html.erb:134`, `W:app/views/docs/security.html.erb`.

### 5.6 What a "composer" ecosystem needs (summary)
1. Ingest: accept `/` in names (e.g. `/\A[\w.-]+(\/[\w.-]+)?\z/`) and composer versions (`v`, `-`, `+` parts; strip a leading `v`); store an ecosystem (`app_dependencies.ecosystem` / `packages` or a `"composer"` key), plus `php_version` and framework (`laravel/framework`, `symfony/*`) instead of `ruby`/`rails`.
2. Advisories: `gem_advisories` needs an `ecosystem` column and the unique key `(ecosystem, gem, identifier)`; import PHP advisories (FriendsOfPHP/security-advisories YAML: `reference: composer://vendor/package`, `branches: {name: {versions: ['>=1.0', '<1.2.3']}}`, `cve`, `title`, `link`; or the Packagist API `https://packagist.org/api/security-advisories/?packages[]=…` with `affectedVersions: ">=1.0,<1.2.3|>=2.0,<2.1"`, `advisoryId`, `cve`, `severity`).
3. Matching: `Gem::Version`/`Gem::Requirement` don't understand composer constraints (`|`/`||` = OR, `,` or space = AND, `^`, `~`, stability suffixes). Composer advisories list **affected** ranges, RubyGems list **safe** ranges, so `affects?` must branch per ecosystem. PHP itself (`php` pseudo package) would need its own source.
4. Fix version: composer advisories have no patched list; the fix candidate is the upper bound `<x.y.z` of each affected range.
5. Command: `composer update vendor/package --with-dependencies` (`composer require vendor/package:^x.y` for a major step); the texts in §5.5 must be per platform.
6. `DependencyScanner`: `versions["ruby"]` → per ecosystem; queries by `(ecosystem, name)`.

---

## 6. POST /api/v1/database (note only)

`W:app/controllers/api/v1/databases_controller.rb`, `W:app/services/database_ingestor.rb`. Object body: `{"sampled_at","host","pid","pool":{...},"health":{...},"slow_queries":[{"fingerprint","sql","name","count","total_ms","max_ms","location","request_id","last_at"}],"top_queries":{"available","queries"|"reason"},"tables":{"tables","unused_indexes"}}`. Sample keyed `(client_app_id, sampled_at, host, pid)` (`structure.sql:3271`). Plan decides what is kept (Free: one sample per 5 min, no details). Limits `MAX_SLOW_QUERIES = 200`, `MAX_GROUPS_PER_APP = 2_000`, `MAX_SQL = 4_000`, snapshots every 4 min (top queries) / 50 min (tables), ≤ 512 KiB. **202** `{"accepted":true,"sample_stored":bool,"slow_queries":n,"snapshots":n}`. PostgreSQL-specific (`pg_stat_statements`, etc.); out of scope for a first PHP version.

## 7. Browser endpoints (note only)

`POST /api/v1/browser_errors?key=lhb_…` and `/api/v1/browser_vitals?key=lhb_…` are for `watch.js` in the page, not for the server client. Public browser key, `Origin` must be one of the app's allowed origins, JSON sent as `text/plain` (sendBeacon), body ≤ 64 KiB / 4 KiB, always **204** (refusals in `X-Watch-Refused`). Errors are turned into a notice by `W:app/services/browser_notice.rb` (frames like `/assets/x.js:12:5:in 'fn'`, tags `["javascript"]`, component `"page /path"`). Vitals use `Histogram::VITALS_MS`/`CLS` and add into `vitals_periods` like metrics. A PHP helper only needs to print the `<script>` tag (Ruby: `watch_tag`, `C:browser_tag.rb`) with `WATCH_BROWSER_KEY`.

## 8. GET/POST /ping/<token>[/fail] — heartbeats

`W:config/routes.rb:84-85`, `W:app/controllers/heartbeat_pings_controller.rb`.
- Route: `match "ping/:token(/:result)", via: [:get, :post]`, `token: /[A-Za-z0-9_-]{8,64}/`, `result: /fail|success/`. HEAD works as GET. No API key: the token is the secret (`SecureRandom.urlsafe_base64(18)`, stored as SHA256) (`W:app/models/heartbeat.rb:11, 49-59`).
- Message: `?msg=…`, or the raw POST body (first 1 000 bytes) when the request is not form data; UTF-8 scrubbed, stripped, `truncate(250)` (`:24-28`).
- Answers (`text/plain`): **200** `OK\n` or `OK (paused)\n`; **404** `Not found\n`; **429** `Too many pings\n` (120/min per IP, 30/min per token). Unknown `result` → no route (404).
- `/fail` → success: false ("Late at once"); `/success` and no suffix → success.
- Ruby (`C:check_in.rb`): `POST`, `Content-Type: text/plain; charset=utf-8`, `User-Agent: watch_client/<v> ruby/<v>`, body = message first 250 chars, timeouts 5 s, true on 2xx. Token → `"#{WATCH_URL}/ping/#{URI.encode_www_form_component(token)}"`; a full URL is used as is with a trailing `/fail|/success` removed first; `/fail` appended when failed. With a block: ping on success, `/fail` with `"#{e.class}: #{e.message}"` on exception, then re-raise. Off in the `test` environment. Same https rule as §0.1.

---

## 9. Everything in the hub that assumes Ruby/Rails

Grouped by what a PHP platform would need to change. (`W:` paths; admin pages for the hub's own Solid Queue jobs are left out: `app/controllers/admin/job*`, `app/views/admin/*`, `app/models/job_browser.rb`.)

### 9.1 Error tracking
- `app/models/error_occurrence.rb:4` — `APP_FRAME = %r{\A(?:\./)?(?:app|lib|config|db)/}`: Rails folders only (§2.1). Used by `error_ingestor.rb:157-159` (culprit → fingerprint), `errors_helper.rb:13-17`, `error_groups/show.html.erb:268`, `browser_notice.rb:98`.
- `app/services/error_ingestor.rb:163-165` — default fingerprint regex `/:\d+(?=:in |\z)/` expects Ruby's `file:line:in 'm'`.
- `app/services/error_hints.rb` — the whole catalogue is Ruby/Rails classes (`NoMethodError`, `NameError` + Zeitwerk/Gemfile hints, `ActiveJob::DeserializationError`, `ActiveRecord::*`, `PG::*`, `Pundit`, `retry_on` job hint with a Rails guide link, `bin/rails db:migrate`, `database.yml`, Puma threads). `job?(component)` = no `#` and not `page ` (`:200-202`). PHP exceptions (`TypeError`, `Error`, `ErrorException`, `Illuminate\Database\QueryException`, `PDOException`, `Symfony\…\NotFoundHttpException`) mostly get no hint; some regexes (`/(?<!Connection)Timeout/`, `/RateLimit|TooManyRequests/`, `/./` + rate-limit message) will match.
- `app/services/job_errors.rb:1-33` — job class detection via component without `#` (§2.7).
- `app/views/error_groups/show.html.erb:305` — "Add some with `WatchClient.notify(e, context: { ... })` or `WatchClient.with_context`"; `:335` "Not sent (`send_job_arguments` is off in this app)"; `:333` "Records are shown as `Model#id`"; `:343` "They come with the watch_client gem"; `:384` "send_cookie_values = true"; `:411` "Client library" (shows `notifier`).
- `app/helpers/errors_helper.rb:63` — user agent list contains `Ruby`, `Faraday` (add `GuzzleHttp`, `Symfony HttpClient`).

### 9.2 Performance, jobs, processes
- `app/helpers/performance_helper.rb:27` and `app/views/performance/job.html.erb:41` — label "Ruby / other".
- `app/views/performance/_setup.html.erb:3-6` — "controller action", `bundle update watch_client`, `config.performance_monitoring`, `bin/rails watch:deploy`.
- `app/views/performance/jobs.html.erb:8-9, 20` — "the watch_client gem times every ActiveJob job (Solid Queue, Sidekiq…)", `solid_queue` note.
- `app/views/performance/processes.html.erb:8-9, 24-25, 39, 51-52` — "Puma, Sidekiq or job process", "Puma threads busy", "Puma busy" column; data columns `puma_*` (`app/models/process_sample.rb:1, 14-17`, `process_hourly.rb`, `performance_report.rb:157-217`).
- `app/services/performance_alerts.rb:17, 24, 56, 193-203` and `app/services/alert_guide.rb:75-78` — `puma_saturated` ("All Puma threads busy", "more Puma threads"); `memory_growth` per `host:pid`; `gc_heavy`.
- `app/views/performance/job.html.erb:27-34`, `api_host.html.erb:46`, `app/views/dashboards/show.html.erb:118` — "watch_client 0.7/0.10 or newer".
- `app/services/app_health.rb:4`, `app/services/app_summary.rb:62` ("Connect the app with watch_client…").

### 9.3 Database pages
- `app/views/databases/_header.html.erb:15`, `_setup.html.erb:3-9` (`bundle update watch_client`, `config/initializers/watch_client.rb`, `bin/rails watch:database`), `top_queries.html.erb:7`, `tables.html.erb:7` ("The gem reads them…"), `app/models/db_sample.rb:1`, `slow_query_group.rb:1`.

### 9.4 Security
All of §5.5, plus `app/models/app_dependency.rb`, `gem_advisory.rb`, `gem_upgrade.rb` (Bundler commands, `ruby?`), `dependency_finding.rb`, `app/jobs/advisory_update_job.rb` ("Ruby Advisory Database"), `dependency_scan_job.rb`, `app/services/gem_advisory_importer.rb`, `dependency_scanner.rb` (`versions["ruby"]`), `dependencies_ingestor.rb` (name/version regexes, `ruby`/`rails`), `app/mailers/uptime_mailer.rb:29`.

### 9.5 Setup, onboarding, docs
- `app/services/app_setup.rb:6, 51-59` — guide `connect-rails`; step "Install the watch_client gem", hint "then run bin/rails watch:test"; deploys hint "Run bin/rails watch:deploy"; browser hint "watch_tag in the layout".
- `app/views/client_apps/key.html.erb:18-19` — renders `setup/ruby` with `framework: "rails"`, "Not a Rails app? See Ruby without Rails".
- `app/views/setup/_ruby.html.erb` — Gemfile line, `bundle install`, `bin/rails generate watch_client:install`, `bin/rails watch:test`/`watch:test_error`/`watch:deploy`, `WatchClient.start!`, `bundle exec ruby -r watch_client …`. `app/views/setup/_env.html.erb` is platform-neutral (`WATCH_URL`, `WATCH_API_KEY`, `WATCH_APP_NAME`).
- `app/views/client_apps/edit.html.erb:27, 37-41` — "next to the Rails ones", "Rails app with watch_client", `WATCH_BROWSER_KEY`, `<%= watch_tag %>`.
- `app/views/client_apps/show.html.erb:52, 222` — "How to connect a Rails app · Other Ruby apps", "run bin/rails watch:deploy".
- `app/views/dashboards/show.html.erb:365`, `app/views/performance/show.html.erb:98` — "bin/rails watch:deploy".
- `app/views/heartbeats/show.html.erb:104-109` — "Rails (watch_client)" `WatchClient.check_in(...)` sample.
- `app/controllers/docs_controller.rb:10-11` — doc pages `connect-rails`, `connect-ruby` (no PHP page). Ruby/Rails text in `app/views/docs/`: `connect_rails` (9 hits), `connect_ruby` (7), `deploys` (4), `security` (4), `database_setup`, `heartbeats_setup`, `jobs_setup` (2 each), `browser`, `dashboard`, `getting_started`, `jobs`, `performance`, `uptime`, `uptime_setup` (1 each).
- `app/controllers/security_controller.rb:1-2`, `performance_controller.rb:1`, `databases_controller.rb:1`, API controller comments — comments only.

---

## 10. Checklist for byte-compatible PHP payloads

1. `POST`, `Authorization: Bearer lh_…`, `Content-Type: application/json`, `Content-Encoding: gzip` (`gzencode($json)`; plain JSON works too), `User-Agent: watch-php/<v> php/<PHP_VERSION>`. https only (local hosts excepted).
2. JSON: `json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE)`; empty maps must encode as `{}` not `[]` (`(object)[]`), lists as arrays. Never send NaN/INF.
3. Timestamps: `occurred_at`/`timestamp` = `(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z')` (= Ruby `iso8601(6)`); metrics `sampled_at` = `gmdate('Y-m-d\TH:i:s\Z')`; breadcrumbs `at` = `…->format('Y-m-d\TH:i:s.v\Z')`.
4. Levels: `debug info warn error fatal unknown` (map PSR-3: `notice→info`, `warning→warn`, `critical/alert/emergency→fatal`; the hub maps `warning/critical/alert/emergency` too).
5. Logs ≤ 1 000 per batch (Ruby default 500), message ≤ 32 KiB, extra fields ≤ 16 KiB JSON or the hub replaces the whole payload with `{_truncated,_bytes}`.
6. Errors ≤ 100 per batch, backtrace lines `relative/path.php:LINE:in 'Class->method'`, app paths relative to the project root, ≤ 200 lines (Ruby sends 100), components with `#` for web, plain class for jobs.
7. Metrics: per minute per process (or per host via a shared buffer), 20-bucket histograms with the bounds above, counts as ints, ms as floats rounded to 1 decimal, lists sorted by name, `count > 0`, ≤ 300/100/200/500 items. Partial reports from many processes are safe (rows are **added**); `queues`, `process` and quotas are **replaced** per minute (process per `host+pid`).
8. Retry 408/425/429/5xx/network with backoff; never retry 400/401/413/422. Treat `dropped`/`rejected` in a 202 as final.
9. Dependencies: not usable for composer until the hub accepts `/` in names and `-` in versions (§5.6).
10. Heartbeats: `POST {WATCH_URL}/ping/{rawurlencode(token)}[/fail]`, `text/plain` body ≤ 250 chars.
