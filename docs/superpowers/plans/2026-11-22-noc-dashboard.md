# NOC Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Laravel 13 monitoring dashboard at `dashboard/` that receives the existing `noc-agent` schema-v1 payload, stores it in SQLite, and presents a multi-user, role-aware UI with alerts, charts, and token management.

**Architecture:** One Laravel monolith: an API ingest endpoint writes one narrow sample row plus one detail row per check-in, then evaluates alerts; Blade + Livewire 4 pages read that data through indexed repositories and rollup tables. Ingest is synchronous and idempotent; rollups, liveness, retention, and email run from the scheduler. All frontend assets are prebuilt at development/CI time and deployed as static files, so production needs only PHP + SQLite + cron.

**Tech Stack:** PHP 8.3+ (dev image 8.4), Laravel 13.17+, Livewire 4.4+, Laravel Fortify 1.43+, SQLite (WAL), Blade, Alpine.js 3, Tailwind CSS 4.3, Chart.js 4.6, Vite 8 (build-time only), PHPUnit 12, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-11-22-noc-dashboard-design.md`

## Global Constraints

- Application lives in `dashboard/`. Never modify the legacy root `collector-cpanel.sh` or break the agent contract in `agent/README.md` §9.
- PHP 8.3+ (recommended 8.4); Laravel 13; Livewire 4; Fortify; SQLite only. No MySQL backend, no Filament, no React/Vue/Svelte/SPA.
- Laravel 13 requires PHP 8.3+. If a target host caps at PHP 8.2, stop and document the Laravel 12 fallback; never silently downgrade the framework or skip the PHP check.
- All full-page Livewire components use `Route::livewire()`; do not pass class components to `Route::get()`. Livewire 4 `wire:model` uses new defaults: use `.live.blur` or `.deep` only where Livewire 3 behavior is required.
- Laravel 13 sets `cache.serializable_classes = false`; cache only scalars/arrays, never model or DTO objects unless explicitly allow-listed.
- The scaffold uses PHPUnit 12 (Laravel 13 default), not Pest. Keep test files in `tests/Feature` and `tests/Unit`.
- `node`/`npm` run only on developer machines or CI. Production serves committed `public/build` files; no CDN, no runtime build, no remote fonts or scripts.
- UI copy is English only. Timestamps stored UTC, displayed in `Asia/Jakarta`.
- Roles: `admin`, `operator`, `viewer`. No public registration. First admin via `php artisan noc:create-admin`.
- Agent token format `noc_` + 43 base64url chars (32 random bytes), stored only as HMAC-SHA256 of the plaintext keyed by `APP_KEY`, plus an 8-char prefix. Plaintext is displayed exactly once.
- Ingest: `POST /api/v1/ingest`, Bearer auth, body ≤ 65,536 bytes, schema_version 1, response `202` on success and on idempotent retry.
- Idempotency key: unique `(server_id, collected_at)`. A duplicate returns 202 but never inserts a second sample/detail or double-counts rollups.
- Status thresholds: `stale_after = last_seen_at + max(2.5 × interval, 150s)`, `offline_after = last_seen_at + max(5 × interval, 300s)`.
- Alert channels: **in-app mandatory** (the `alerts` row), **email via SMTP** using `MAIL_*`, and **Telegram optional** (`bot_token` encrypted, `chat_id`, optional `message_thread_id`). Delivery state is per channel and event (`opened`/`resolved`) in `alert_deliveries`; acknowledge stops reminders, and resolve sends one recovery; no webhook/Slack in MVP.
- Retention: samples 14 days, details 7 days, 5m rollups 30 days, 1h rollups 365 days, 1d rollups 730 days, resolved alerts 365 days, `alert_deliveries` 90 days, audit 365 days, failed jobs 30 days, revoked/expired token rows 90 days.
- Explicit registration only: a valid token must already map to a server; ingest never creates servers.
- Every PHP/composer/artisan command runs through `dashboard/tools/php.sh` (podman image `localhost/hosters-php:8.4`). Never assume `php`, `composer`, or `sqlite3` exist on the host.
- Every task ends green: `dashboard/tools/php.sh php artisan test` plus `dashboard/tools/php.sh ./vendor/bin/pint --test`.

## Review Focus

1. **Oversized or chunked ingest bodies** — when PHP `post_max_size`/`upload_max_filesize` truncates or empties a body, the endpoint must still return `413` JSON (never `400`, `500`, or a false `202`). Test `Content-Length` above 65,536, a chunked body with no length, and exactly 65,536 vs 65,537 bytes.
2. **Retried check-ins at the same `collected_at`** — a lost `202` causes the agent to resend. Expect one sample, one detail, no rollup double-count, unchanged alert state, and `last_seen_at` still advancing.
3. **Agent clock skew and configurable intervals** — expect `collected_at` clamped to `now ± 10 minutes`, charts ordered and gap-aware, and liveness derived from dashboard `last_seen_at`, not agent time.
4. **Missing module data and zero denominators** — SMART/quota/Imunify may never have run, values may be `0`, and rollups may have zero samples. Expect "Not reported", no false alerts, no division by zero, and no zero-filled chart points.
5. **Untrusted agent strings and dynamic keys** — panel/module keys, hostnames, and MySQL query text are attacker-influenced. Expect Blade escaping, key/row/length caps, masked query text, and no executable HTML in tables or the stored snapshot.

---

### Task 1: Laravel scaffold, local toolchain, and CI wrapper

**Files:**
- Create: `dashboard/` (via Laravel skeleton), `dashboard/tools/php.sh`, `dashboard/tools/test.sh`
- Modify: `dashboard/phpunit.xml`, `dashboard/package.json`, `dashboard/.gitignore`
- Test: `dashboard/tests/Feature/ScaffoldTest.php`

**Interfaces:**
- Produces: `dashboard/tools/php.sh` accepting any command (e.g. `tools/php.sh php artisan test`), mapping the repo into the podman image with host UID ownership.
- Produces: `dashboard/tools/test.sh` running Pint then the PHP suite; `--with-assets` also runs `npm run build`.

- [ ] **Step 1: Scaffold the application**

```bash
podman run --rm --userns=keep-id -v "$PWD:/work:Z" -w /work \
  localhost/hosters-php:8.4 \
  composer create-project laravel/laravel:^13.0 dashboard --no-interaction
```

- [ ] **Step 2: Add runtime and build dependencies**

```bash
cd dashboard
../dashboard/tools/php.sh composer require livewire/livewire:^4.4 laravel/fortify:^1.43
../dashboard/tools/php.sh php artisan fortify:install
npm install
npm install chart.js@^4.6 date-fns@^4.4 chartjs-adapter-date-fns@^3.0
```

`fortify:install` must publish `config/fortify.php`, add `App\Providers\FortifyServiceProvider` to `bootstrap/providers.php`, and publish its migration.

- [ ] **Step 3: Create the wrappers**

`dashboard/tools/php.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec podman run --rm --userns=keep-id \
  -v "$ROOT:/app:Z" -w /app -e HOME=/tmp \
  localhost/hosters-php:8.4 "$@"
```

`dashboard/tools/test.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
./tools/php.sh ./vendor/bin/pint --test
./tools/php.sh php artisan test "$@"
if [[ ${1:-} == --with-assets ]]; then npm run build; fi
```

`chmod +x dashboard/tools/*.sh`.

- [ ] **Step 4: Configure the test environment**

In `phpunit.xml`, set `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `BCRYPT_ROUNDS=4`.

In `database.php`, keep SQLite but add `'busy_timeout' => env('DB_BUSY_TIMEOUT', 5000)`, `'journal_mode' => env('DB_JOURNAL_MODE', 'wal')`, `'synchronous' => env('DB_SYNCHRONOUS', 'normal')`, `'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true)`, and `'transaction_mode' => env('DB_TRANSACTION_MODE', 'IMMEDIATE')`. Laravel 13 only honors `transaction_mode` on PHP 8.4+; on PHP 8.3 the connection stays `DEFERRED`, so the SQLite pragma test must not assert `BEGIN IMMEDIATE`.

- [ ] **Step 5: Verify the skeleton and the wrapper**

Run: `dashboard/tools/test.sh`
Expected: Pint passes and the default Laravel 13 tests pass.

- [ ] **Step 6: Commit**

```bash
git add dashboard
git commit -m "chore(dashboard): scaffold Laravel 13 with Livewire 4, Fortify, and SQLite"
```

---

### Task 2: Authentication, roles, and first admin

**Files:**
- Create: `dashboard/app/Enums/UserRole.php`, `dashboard/app/Console/Commands/CreateAdmin.php`, `dashboard/app/Policies/{ServerPolicy,ServerTokenPolicy,AlertPolicy,UserPolicy,SettingPolicy,AuditPolicy}.php`, `dashboard/resources/views/auth/login.blade.php`, `dashboard/resources/views/auth/two-factor-challenge.blade.php`, `dashboard/resources/views/dashboard-placeholder.blade.php`, `dashboard/tests/Feature/Auth/{LoginTest,CreateAdminTest}.php`, `dashboard/tests/Unit/UserRoleTest.php`
- Modify: `dashboard/database/migrations/0001_01_01_000000_create_users_table.php`, `dashboard/app/Models/User.php`, `dashboard/app/Providers/AppServiceProvider.php`, `dashboard/app/Providers/FortifyServiceProvider.php`, `dashboard/config/fortify.php`, `dashboard/routes/web.php`, `dashboard/bootstrap/app.php`

**Interfaces:**
- Produces: `UserRole` backed enum with `Admin`, `Operator`, `Viewer`; `User::role(): UserRole`; `User::isAdmin(): bool`; `User::canOperate(): bool` (admin or operator).
- Produces: `php artisan noc:create-admin --name= --email= --password=` — fails if any user exists unless `--force`.
- Produces: policies registered for `Server`, `ServerToken`, `Alert`, `User`, `Setting`, `AuditEvent`; `ServerTokenPolicy` delegates `viewAny/create/revoke/rotate` to `ServerPolicy@manageTokens`.
- Produces: dashboard route set requires `auth`; `/register` resolves 404; a temporary `GET /dashboard` returns `dashboard-placeholder` until Task 15 replaces it.

- [ ] **Step 1: Write the failing auth tests**

`LoginTest` must assert: valid active user posts `/login` → redirect `/dashboard` and authenticated; wrong password → session error and guest; inactive user → guest; sixth attempt in a minute → 429; `GET /register` → 404; `GET /dashboard` as guest → redirect `/login`.

`CreateAdminTest` must assert the command creates an `admin` with verified email, hashes the password, and refuses a second run without `--force`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `dashboard/tools/php.sh php artisan test --filter='LoginTest|CreateAdminTest'`
Expected: FAIL (routes/command missing).

- [ ] **Step 3: Implement the role, fields, and command**

Add migration columns `role` string default `viewer`, `is_active` boolean default true, `must_change_password` boolean default false, `notify_email` boolean default true, `last_login_at` nullable timestamp. Cast `role` to `UserRole`, `password` hashed, `is_active`/`notify_email`/`must_change_password` boolean. Never expose `role` to mass assignment.

`CreateAdmin` uses `Validator`, creates the user, and sets `role=admin`, `is_active=true`, `email_verified_at=now()`. It must not print the password.

- [ ] **Step 4: Wire Fortify and policies**

In `config/fortify.php` set `'home' => '/dashboard'`, `'views' => true`, and features to `resetPasswords`, `updatePasswords`, `twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true, 'window' => 0])`. Do **not** enable registration or email verification.

In `FortifyServiceProvider::boot()`, register `Fortify::loginView`, `Fortify::twoFactorChallengeView`, and `RateLimiter::for('login', fn ($r) => Limit::perMinute(5)->by(Str::lower($r->input('email')).'|'.$r->ip()))`.

Policies: `ServerPolicy` — `viewAny/view` all authenticated; `create/update/delete/manageTokens` admin+operator; `viewSnapshot` admin only. `AlertPolicy` — `viewAny/view` all; `acknowledge/resolve` admin+operator. `UserPolicy`, `SettingPolicy`, `AuditPolicy` — admin only, except audit `viewAny` allows operator.

In `bootstrap/app.php`, add `redirectGuestsTo('/login')` and a `dashboard` named route group with `auth` middleware. Register `Route::view('/dashboard', 'dashboard-placeholder')->middleware('auth')->name('dashboard')` as the temporary target for post-login redirects.

- [ ] **Step 5: Run the tests**

Run: `dashboard/tools/php.sh php artisan test --filter='LoginTest|CreateAdminTest|UserRoleTest'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add dashboard
git commit -m "feat(dashboard): add authentication, roles, policies, and first-admin command"
```

---

### Task 3: Domain schema, models, and SQLite pragmas

**Files:**
- Create: `dashboard/database/migrations/2026_11_22_000100_create_servers_table.php`, `..._000200_create_server_tokens_table.php`, `..._000300_create_metric_samples_table.php`, `..._000400_create_metric_details_table.php`, `..._000500_create_metric_rollups_table.php`, `..._000600_create_alerts_table.php`, `..._000650_create_alert_deliveries_table.php`, `..._000700_create_settings_table.php`, `..._000800_create_audit_events_table.php`
- Create: `dashboard/app/Models/{Server,ServerToken,MetricSample,MetricDetail,MetricRollup,Alert,AlertDelivery,Setting,AuditEvent}.php`
- Create: `dashboard/app/Enums/{ServerHealth,ServerStatus,AlertSeverity,AlertStatus,AlertRule}.php`
- Create: `dashboard/tests/Feature/Database/{SchemaTest,SqlitePragmaTest}.php`
- Modify: `dashboard/config/database.php`

**Interfaces:**
- Produces models with relations: `Server::tokens()`, `samples()`, `rollups()`, `alerts()`, `latestSample()`; `ServerToken::server()`; `MetricSample::detail()`, `server()`; `MetricDetail::sample()`; `MetricRollup::server()`; `Alert::server()`, `deliveries()`; `AlertDelivery::alert()`.
- Produces enums: `ServerHealth::{Ok,Warning,Critical,Unknown}`, `ServerStatus::{NeverSeen,Online,Stale,Offline}`, `AlertSeverity::{Warning,Critical}`, `AlertStatus::{Open,Acknowledged,Resolved}`, `AlertRule` (14 cases listed in spec §9.1).
- Produces: `Server::status(): ServerStatus` computed from `last_seen_at`/`stale_after`/`offline_after` using `now()`.

- [ ] **Step 1: Write the failing schema tests**

`SchemaTest` asserts: server ULID is 26 chars; token hash unique; duplicate `(server_id, collected_at)` throws; two resolved alerts may both have `active_key = null`; deleting a server cascades tokens/samples/details/rollups/alerts/deliveries; deleting a sample cascades its detail; deleting an alert cascades its deliveries; `(alert_id, channel, event)` is unique; `decimal` columns accept 2-decimal values.

`SqlitePragmaTest` creates a file-backed connection in `storage/framework/testing/wal.sqlite`, migrates, and asserts `PRAGMA journal_mode` is `wal`, `PRAGMA busy_timeout` is `5000`, `PRAGMA foreign_keys` is `1`.

- [ ] **Step 2: Run to verify failure**

Run: `dashboard/tools/php.sh php artisan test --filter='SchemaTest|SqlitePragmaTest'`
Expected: FAIL (tables/models missing).

- [ ] **Step 3: Implement migrations exactly as specified**

Use the columns from spec §7. Critical details:

- `servers.id` char(26) ULID PK; `name` unique; `agent_uuid` indexed but **not** unique; `interval_seconds` default 60; `stale_after`/`offline_after` nullable; latest metric columns nullable; `health` default `unknown`; `issue_codes` JSON nullable; `is_active` default true; soft deletes; indexes on `last_seen_at`, `health`, `panel`, `hostname`, `is_active`.
- `server_tokens`: ULID PK, FK cascade, `token_hash` char(64) unique, `token_prefix` char(8), `label`, `last_used_at`, `last_used_ip`, `expires_at`, `revoked_at`, `rotated_from_id` nullable, `created_by` FK users nullOnDelete.
- `metric_samples`: bigint PK, FK server cascade, `collected_at`/`received_at`, `schema_version`, `agent_version`, `interval_seconds`, all core metric columns from spec §7.4, `payload_bytes`, `payload_hash` char(64) nullable, `created_at`; unique `(server_id, collected_at)`; indexes `(server_id, collected_at)` and `(collected_at)`.
- `metric_details`: `sample_id` PK/FK cascade, `panel_metrics` JSON, `extensions` JSON, `size_bytes`, `created_at`.
- `metric_rollups`: bigint PK, FK cascade, `resolution_seconds` smallint, `bucket_start`, `samples`, sum/min/max columns from spec §7.6, `created_at`/`updated_at`; unique `(server_id, resolution_seconds, bucket_start)`; index `(server_id, resolution_seconds, bucket_start)`.
- `alerts`: ULID PK, FK cascade, `rule_key`, `severity`, `status`, `active_key` **nullable unique**, `title`, `message`, `context_json`, `first_seen_at`, `last_seen_at`, ack/resolve fields, `notification_count` default 0, `last_notified_at`; indexes `(server_id, status)`, `(status, severity, last_seen_at)`, `last_seen_at`.
- `alert_deliveries`: bigint PK, `alert_id` ULID FK cascade, `channel` (`email`/`telegram`), `event` (`opened`/`resolved`, default `opened`), `status` (`pending`/`sent`/`failed`/`skipped`), `recipient_count` default 0, `attempts` default 0, `last_attempt_at`, `sent_at`, `next_retry_at`, `error` nullable (max 500, redacted), timestamps; unique `(alert_id, channel, event)`; index `(channel, status, next_retry_at)`.
- `settings`: `key` string PK, `value` text, `type` string, `is_secret` boolean default false, `updated_by` FK users nullOnDelete, timestamps.
- `audit_events`: bigint PK, `user_id` FK nullOnDelete, `action`, `target_type`, `target_id`, `ip`, `user_agent`, `meta_json`, `created_at` only; indexes `created_at`, `(user_id, created_at)`, `(target_type, target_id)`.

Models: `HasUlids` on Server/ServerToken/Alert; `SoftDeletes` on Server; `AlertDelivery` belongs to `Alert`; `$guarded = []` only where a service always supplies validated arrays, otherwise explicit `$fillable`. Cast `issue_codes`, `context_json`, `panel_metrics`, `extensions`, `meta_json` to `array`; timestamps to `datetime`; enums to their enum classes; percentages to `float`; `settings.is_secret` to `boolean`.

`AuditEvent` overrides `update()` and `delete()` to throw `LogicException`.

- [ ] **Step 4: Register the enum casts and run the tests**

Run: `dashboard/tools/php.sh php artisan test --filter='SchemaTest|SqlitePragmaTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add dashboard
git commit -m "feat(dashboard): add domain schema, models, enums, and SQLite pragmas"
```

---

### Task 4: Settings repository and append-only audit log

**Files:**
- Create: `dashboard/app/Services/SettingsRepository.php`, `dashboard/app/Services/AuditLogger.php`, `dashboard/config/alerts.php`, `dashboard/config/retention.php`, `dashboard/tests/Feature/Settings/SettingsRepositoryTest.php`, `dashboard/tests/Feature/Audit/AuditLogTest.php`
- Modify: `dashboard/app/Providers/AppServiceProvider.php` (event listeners)

**Interfaces:**
- Produces: `SettingsRepository::get(string $key, mixed $default = null): mixed`, `set(string $key, mixed $value, string $type = 'string', bool $isSecret = false): void`, `hasSecret(string $key): bool`, `forget(string $key): void`. Secret rows are encrypted with `Crypt`; `get()` decrypts in memory and never returns ciphertext.
- Produces: `AuditLogger::log(string $action, ?Model $subject = null, array $meta = []): AuditEvent`; actor/IP/user-agent taken from the current request; secrets and query text must never be passed in `$meta`. Settings audits record key names and `is_secret`, never secret values.
- Produces: `config/alerts.php` defaults for every rule threshold, `reminder_hours=6`, `retry_backoff_seconds=[30,120,300]`, `test_rate_per_minute=3`, `channels.email.enabled=true`, `channels.telegram.enabled=false`, `rules.service_down.enabled=true`, and `rules.security_disabled.enabled=false`; `config/retention.php` day counts from Global Constraints.

- [ ] **Step 1: Write failing tests**

`SettingsRepositoryTest`: DB value overrides config default; `set` invalidates the cache within the same request; unknown key returns the supplied default; non-scalar values are rejected; a secret value is stored as ciphertext (raw DB value differs from plaintext), `get()` returns the plaintext, `hasSecret()` is true, and overwriting with `isSecret=false` clears the flag.

`AuditLogTest`: `log()` records actor id, IP, and action; metadata is JSON; calling `update()` or `delete()` on an `AuditEvent` throws; login/logout events are recorded through Fortify listeners (use `Illuminate\Auth\Events\Login`/`Logout`).

- [ ] **Step 2: Implement and run**

Run: `dashboard/tools/php.sh php artisan test --filter='SettingsRepositoryTest|AuditLogTest'`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add dashboard
git commit -m "feat(dashboard): add settings repository, alert defaults, and audit log"
```

---

### Task 5: Server registry and one-time token service

**Files:**
- Create: `dashboard/app/Services/Servers/ServerService.php`, `dashboard/app/Services/Tokens/TokenService.php`, `dashboard/app/Services/Tokens/IssuedToken.php`, `dashboard/tests/Feature/Servers/ServerServiceTest.php`, `dashboard/tests/Feature/Tokens/TokenServiceTest.php`

**Interfaces:**
- Produces: `ServerService::create(array $attributes, ?User $actor = null): Server`; validates name uniqueness, interval 10–3600, and writes an audit event `server.created`.
- Produces: `TokenService::issue(Server $server, ?User $actor = null, ?string $label = null, ?CarbonInterface $expiresAt = null): IssuedToken` — audits `token.created`.
- Produces: `TokenService::rotate(Server $server, int $graceMinutes = 1440, ?User $actor = null): IssuedToken` — issues the new token, sets the old token's `expires_at = now()->addMinutes($graceMinutes)`, records `rotated_from_id`, and audits `token.rotated`.
- Produces: `TokenService::revoke(ServerToken $token, ?User $actor = null): void` — sets `revoked_at` and audits `token.revoked`.
- Produces: `TokenService::authenticate(string $plain): ?ServerToken` — returns an active, unexpired, unrevoked token whose server is active and not soft-deleted.
- Produces: `IssuedToken::$plain` (string, only in memory), `::$token` (ServerToken).

- [ ] **Step 1: Write failing tests**

Assert: issued plaintext matches `^noc_[A-Za-z0-9_-]{43}$`; stored `token_hash` equals `hash_hmac('sha256', $plain, config('app.key'))`; `token_prefix` is the first 8 chars; plaintext is absent from the database; duplicate issue produces different tokens; `authenticate` rejects wrong/revoked/expired tokens and tokens of inactive or deleted servers; rotation leaves the old token valid before `expires_at` and invalid after; revoke is immediate; every mutation writes an audit event.

- [ ] **Step 2: Implement and run**

Run: `dashboard/tools/php.sh php artisan test --filter='ServerServiceTest|TokenServiceTest'`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add dashboard
git commit -m "feat(dashboard): add server registry and hashed one-time token service"
```

---

### Task 6: Ingest route, body limit, token auth, and rate limits

**Files:**
- Create: `dashboard/routes/api.php`, `dashboard/app/Http/Controllers/Api/IngestController.php`, `dashboard/app/Http/Middleware/{EnsureIngestBodySize,AuthenticateAgentToken,ThrottleIngest}.php`, `dashboard/config/ingest.php`, `dashboard/tests/Feature/Ingest/IngestAuthTest.php`
- Modify: `dashboard/bootstrap/app.php`

**Interfaces:**
- Produces: `POST /api/v1/ingest` with middleware order `ingest.size`, `ingest.auth`, `ingest.throttle`; the authenticated `ServerToken` is available as `$request->attributes->get('noc_token')`.
- Produces JSON errors: `401 {"error":"unauthorized"}`, `403 {"error":"inactive_server"}`, `413 {"error":"payload_too_large"}`, `429 {"error":"rate_limited"}` with `Retry-After`.
- Produces: `config/ingest.php` with `max_bytes=65536`, `per_token_per_minute=30`, `failed_per_ip_per_minute=60`, `global_per_minute=1000`.

- [ ] **Step 1: Write failing tests**

Assert the exact contract: valid token → 202 `{"status":"accepted"}`; missing/malformed/revoked/expired token → 401; token of inactive server → 403; deleted server → 401; GET → 405; body of 65,537 bytes → 413; body of 65,536 bytes is not 413; 31 requests in a minute from one token → 429 with `Retry-After`; failed attempts from one IP beyond 60/minute → 429. Use `Log::spy()` to assert the full token never appears in any log call; only its 8-char prefix may appear.

- [ ] **Step 2: Run to verify failure**

Run: `dashboard/tools/php.sh php artisan test --filter=IngestAuthTest`
Expected: FAIL (route missing → 404).

- [ ] **Step 3: Implement the pipeline**

`EnsureIngestBodySize` compares `Content-Length` when present and `strlen($request->getContent())` always; over the limit returns 413 before auth. Handle an empty body with a present `Content-Length > limit` as 413.

`AuthenticateAgentToken` matches `^Bearer (noc_[A-Za-z0-9_-]{43})$`, calls `TokenService::authenticate`, and distinguishes inactive-server 403 from unknown-token 401. Log only `substr($plain, 0, 8)`.

`ThrottleIngest` checks the failed-IP limiter before auth and the per-token limiter after; it also increments a global key. Use `RateLimiter::tooManyAttempts`, `hit`, and `availableIn`.

The controller returns `202 {"status":"accepted"}` with `Cache-Control: no-store` for now; storage arrives in Task 7.

- [ ] **Step 4: Run the tests**

Run: `dashboard/tools/php.sh php artisan test --filter=IngestAuthTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add dashboard
git commit -m "feat(dashboard): add ingest route with body limit, bearer auth, and rate limits"
```

---

### Task 7: Payload validation, idempotent storage, and server state

**Files:**
- Create: `dashboard/app/Services/Ingest/{IngestPayload,PayloadValidator,StoreSample,IngestResult}.php`, `dashboard/tests/Feature/Ingest/{PayloadValidationTest,IngestStorageTest,IngestIdempotencyTest}.php`
- Modify: `dashboard/app/Http/Controllers/Api/IngestController.php`

**Interfaces:**
- Produces: `PayloadValidator::validate(string $rawJson): IngestPayload` — throws `Illuminate\Validation\ValidationException` for schema errors (422) and `JsonException`/`InvalidArgumentException` for malformed JSON (400).
- Produces: `IngestPayload` readonly DTO with `schemaVersion`, `agentId`, `hostname`, `panel`, `collectedAt` (CarbonImmutable UTC), `intervalSeconds`, `osName|null`, `osVersion|null`, `kernel|null`, `agentVersion|null`, `metrics` (array), `panelMetrics` (array), `extensions` (array).
- Produces: `StoreSample::store(ServerToken $token, IngestPayload $payload, int $bytes, string $hash): IngestResult`.
- Produces: `IngestResult::$created` (bool), `::$sample` (MetricSample), `::$server` (Server). Task 8 adds `::$issues` once `IssueSet` exists; until then the server's `health` is `ok` and `issue_codes` is `[]`.

- [ ] **Step 1: Write failing validation tests**

Accept the exact `agent/README.md` payload. Field rules:

- `schema_version` integer equal to 1; unknown top-level keys ignored.
- `agent.id` string ≤ 64; `agent.hostname` string ≤ 253; `agent.panel` string ≤ 32 matching `^[A-Za-z0-9][A-Za-z0-9_.-]*$`; `agent.collected_at` RFC3339 parseable; `agent.interval_seconds` integer 10–3600.
- `system.os.{name,version,kernel}` optional strings ≤ 128; `system.uptime_seconds` integer ≥ 0.
- Every metric in the README example is required and numeric within bounds: percentages 0–100, bytes/seconds/uptime ≥ 0, load ≥ 0, cores 1–1024, processes ≥ 0.
- `panel_metrics`/`extensions` required flat maps; keys match `^[A-Za-z][A-Za-z0-9_.-]{0,63}$`; values string|int|float|bool|null; at most 512 keys each; strings ≤ 1024 bytes; no nested arrays/objects. Note: the agent may omit these maps by dropping them on oversize — accept `{}`.
- Malformed JSON → 400; wrong shape/ranges → 422; 65,537 bytes → 413.
- `collected_at` more than 10 minutes in the past or future is clamped to `now ± 10 minutes`; `received_at` stays `now()`.

- [ ] **Step 2: Write failing storage tests**

Assert: one sample + one detail + updated server fields; core columns mapped correctly (`cpu_usage_pct` from `metrics.cpu.usage_pct`, `net_rx_bps` from `metrics.network.rx_bytes_per_sec`, etc.); `last_seen_at`/`stale_after`/`offline_after` set from dashboard clock; `payload_bytes`/`payload_hash` correct; duplicate POST → one sample, one detail, two 202s, `last_seen_at` advances; two concurrent inserts for the same key do not throw `QueryException`; a 5,000-key panel map is rejected with 422 rather than stored.

- [ ] **Step 3: Implement validation and storage**

Use `Validator::make` with the exact rules above, then map to `IngestPayload`. Decode with `json_decode($raw, true, 8, JSON_THROW_ON_ERROR)`.

`StoreSample` runs one short transaction: `MetricSample::insertOrIgnore([...])`; if affected, read the id by `(server_id, collected_at)` and insert `MetricDetail`; always update the server's identity, latest metric columns, `last_seen_at`, `last_sample_at`, thresholds, `health`, and `issue_codes`; touch `last_used_at`/`last_used_ip` only when `last_used_at` is null or older than one minute. Never perform HTTP, mail, or rollup work inside the transaction.

- [ ] **Step 4: Run the tests**

Run: `dashboard/tools/php.sh php artisan test --filter='PayloadValidationTest|IngestStorageTest|IngestIdempotencyTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add dashboard
git commit -m "feat(dashboard): validate agent payloads and store idempotent samples"
```

---

### Task 8: Issue detection from metrics, panel, and extensions

**Files:**
- Create: `dashboard/app/Issues/{IssueDetector,IssueSet}.php`, `dashboard/tests/Unit/Issues/IssueDetectorTest.php`, `dashboard/tests/Feature/Issues/IssueIntegrationTest.php`
- Modify: `dashboard/app/Services/Ingest/StoreSample.php`

**Interfaces:**
- Produces: `IssueDetector::detect(Server $server, MetricSample $latest, array $panelMetrics, array $extensions): IssueSet`.
- Produces: `IssueSet::$health` (`ServerHealth`), `::$codes` (`list<string>` of `AlertRule` values), `::$details` (`array<string, array{value: mixed, threshold: mixed}>`).
- Produces: `IssueDetector` reads thresholds through `SettingsRepository::get("alerts.{key}", config("alerts.{key}"))` so Task 20's settings page changes behavior.
- Modifies: `StoreSample` now returns `IngestResult` with `::$issues`, and writes `health`/`issue_codes` from the detected set in the same transaction.
- Rules: `cpu_high`, `memory_high`, `disk_high`, `inode_high`, `load_high`, `smart_failed`, `smart_warning`, `service_down`, `mysql_unreachable`, `quota_over`, `module_cache_stale`, `security_disabled`. `server_offline`/`server_stale` belong to Task 10. `security_disabled` runs only when `alerts.rules.security_disabled.enabled=true`; `service_down` is enabled by default.

- [ ] **Step 1: Write failing tests for every rule**

Each rule gets one positive and one negative test. Exact defaults: CPU warning ≥ 90, critical ≥ 95 for 3 consecutive samples; RAM warning ≥ 90, critical ≥ 95; disk/inode warning ≥ 85, critical ≥ 95; load warning `load1 > cores`, critical `load1 > 2 × cores` for 3 consecutive samples; SMART failed on `smart.status=failed`, warning on `smart.status=warning` or any `smart.disk.<dev>.{realloc,pending} > 0`; service down when `service.<name>.installed=1`, `.enabled=1`, `.running=0`; MySQL down when `mysql.available` exists and equals 0; quota when `diskquota.over > 0`; module cache stale when SMART > 45d, diskquota > 48h, Imunify > 30m; security disabled when `security.csf_enabled` is 0/false or `security.modsec` is `off`/`0`.

Missing keys must never trigger a rule or be treated as zero. `security_disabled` does not trigger by default even when CSF/ModSecurity is off; it triggers only after `alerts.rules.security_disabled.enabled` is set to true. `health` is critical if any critical, warning if any warning, else ok.

- [ ] **Step 2: Implement and run**

Run: `dashboard/tools/php.sh php artisan test --filter='IssueDetectorTest|IssueIntegrationTest'`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add dashboard
git commit -m "feat(dashboard): detect resource, module, and security issues"
```

---

### Task 9: Alert engine, deduplication, and notification channels

**Files:**
- Create: `dashboard/app/Alerts/{AlertEngine,AlertNotifier}.php`, `dashboard/app/Alerts/Channels/{AlertChannel,EmailChannel,TelegramChannel}.php`, `dashboard/app/Jobs/SendAlertNotification.php`, `dashboard/app/Notifications/{AlertOpened,AlertResolved}.php`, `dashboard/app/Console/Commands/SendAlertReminders.php`, `dashboard/tests/Feature/Alerts/{AlertEngineTest,AlertNotificationTest,TelegramChannelTest}.php`
- Modify: `dashboard/app/Http/Controllers/Api/IngestController.php`, `dashboard/config/alerts.php`

**Interfaces:**
- Produces: `AlertEngine::evaluate(Server $server, IssueSet $issues): void` — opens or updates one alert per `(server, rule)`, resolves alerts whose rule disappeared, and sets `active_key = "{$server->id}:{$rule}"` only while open/acknowledged.
- Produces: `AlertEngine::resolve(Alert $alert, ?User $actor = null, ?string $note = null, bool $notify = true): void`; on the transition to resolved it calls `AlertNotifier::dispatch($alert, 'resolved')` exactly once when `$notify` is true. Administrative closes (server deletion) pass `$notify = false`.
- Produces: `AlertEngine::acknowledge(Alert $alert, User $actor): void`; acknowledge stops reminders but does not resolve.
- Produces: `AlertNotifier::dispatch(Alert $alert, string $event = 'opened'): void` — creates one `alert_deliveries` row per enabled external channel and event (`opened` or `resolved`), then dispatches a job after commit. For `resolved`, only channels that already have an `opened` row are eligible. In-app needs no delivery row because the alert row is the notification.
- Produces: `AlertNotifier::sendTest(string $channel, User $actor): void` — sends one test to the actor's email or the configured Telegram chat; audits `alert_channel.tested`; throws a validation exception with a redacted message on failure.
- Produces: `DeliveryResult` DTO in `app/Alerts/Channels/` with `status` (`sent|failed|skipped`), `recipientCount`, and optional redacted `error`; `AlertChannel::send(Alert $alert, string $event): DeliveryResult`; `EmailChannel` sends `AlertOpened` for `opened` and `AlertResolved` for `resolved` to active admin/operator users with `notify_email=true`; `TelegramChannel` sends the matching message to the configured chat.
- Produces: `SendAlertNotification` queue job with `$event`, `$tries = 3`, `backoff()` from `config('alerts.retry_backoff_seconds')`, unique per `(alert_id, channel, event)`.
- Produces: `SendAlertReminders` command — for alerts with status `open` only, whose `opened` delivery is older than `alerts.reminder_hours`, marks that delivery pending and dispatches again; honors `next_retry_at`; acknowledged and resolved alerts are skipped.
- Produces: `AlertOpened` and `AlertResolved` notifications containing server name, rule title, trigger value, timestamp, and a URL; never tokens, bot token, or MySQL query text.
- Telegram payload: `chat_id`, `text`, `parse_mode=HTML`, `disable_web_page_preview=true`, and `message_thread_id` **only when non-empty**. Fixed host `api.telegram.org`, connect timeout 5s, total timeout 10s, `429 retry_after` stored in `next_retry_at`.

- [ ] **Step 1: Write failing tests**

Assert: first evaluation opens exactly one alert; identical second evaluation updates `last_seen_at` and keeps one row; two different servers with the same rule get separate rows; condition disappearing resolves and sets `active_key=null`; acknowledge does not resolve; severity escalation updates severity; reminder sent once after 6 hours to an `open` alert and not before; acknowledging stops further reminders; resolving sends exactly one recovery per channel that had an `opened` delivery and a second resolve does not duplicate it; viewer receives no email; only active admin/operator users with `notify_email=1` receive email; email excludes token/query text; recovery text excludes the same secrets.

Telegram: disabled channel makes no HTTP call; enabled but missing chat id is skipped; `Http::fake` receives `chat_id`; `message_thread_id` present when configured and absent when null/empty; HTML special characters are escaped; `429` with `retry_after=42` sets `next_retry_at` about 42 seconds ahead; a connection failure records a redacted error and never the bot token or full URL; one channel failing still lets the other send; in-app row still exists.

Delivery: dispatch creates one row per `(alert, channel, event)`; `attempts` increments; `sent` sets `sent_at`; `failed` respects backoff; resolving creates a `resolved` row while the `opened` row stays intact; ingest still returns 202 when the notifier throws (log and swallow after commit).

- [ ] **Step 2: Implement and wire after commit**

The controller calls `AlertEngine->evaluate(...)` after `StoreSample` commits, wrapped in `try/catch (Throwable $e)` with `report($e)`. `AlertEngine::resolve()` calls `AlertNotifier::dispatch($alert, 'resolved')` only on the transition, after commit. `AlertNotifier::dispatch()` creates per-channel/per-event deliveries. Never let alerting change the ingest response.

- [ ] **Step 3: Run the tests**

Run: `dashboard/tools/php.sh php artisan test --filter='AlertEngineTest|AlertNotificationTest|TelegramChannelTest'`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add dashboard
git commit -m "feat(dashboard): add deduplicated alerts with email, Telegram, and recovery"
```

---

### Task 10: Liveness scheduler for stale and offline servers

**Files:**
- Create: `dashboard/app/Console/Commands/EvaluateLiveness.php`, `dashboard/tests/Feature/Alerts/LivenessTest.php`
- Modify: `dashboard/routes/console.php`

**Interfaces:**
- Produces: `php artisan noc:evaluate-liveness` — evaluates every active server, opens `server_offline` (critical) or `server_stale` (warning), resolves the other, and refreshes `servers.health`. When stale escalates to offline, resolve stale with `notify: false`; when the server returns, resolve offline with recovery enabled.
- Produces: `AlertEngine::refreshServerHealth(Server $server): void` — health is `critical` if any open/acknowledged alert is critical, `warning` if any is warning, `ok` when a sample exists, `unknown` when never seen.
- Produces: schedule entry every minute with `withoutOverlapping()` (single node, no `onOneServer` requirement).
- Produces: schedule entry for `noc:alert-reminders` every 5 minutes with `withoutOverlapping()`, so email/Telegram reminders and retries are independent of ingest.

- [ ] **Step 1: Write failing tests**

Freeze time. An `online` server gets no liveness alert; one exactly at `stale_after` is still online; just after it opens `server_stale`; just after `offline_after` it resolves stale without a recovery delivery and opens `server_offline`; when a sample arrives again, the next run resolves offline with one recovery per channel and restores `ok`/`warning` from remaining issues. A soft-deleted or inactive server gets no alert.

- [ ] **Step 2: Implement and run**

Run: `dashboard/tools/php.sh php artisan test --filter=LivenessTest`
Expected: PASS. Also run `dashboard/tools/php.sh php artisan schedule:list` and confirm both `noc:evaluate-liveness` (every minute) and `noc:alert-reminders` (every 5 minutes) are listed.

- [ ] **Step 3: Commit**

```bash
git add dashboard
git commit -m "feat(dashboard): schedule stale and offline server evaluation"
```

---

### Task 11: Rollups and backfill

**Files:**
- Create: `dashboard/app/Services/Metrics/RollupService.php`, `dashboard/app/Console/Commands/RollupMetrics.php`, `dashboard/tests/Feature/Metrics/RollupTest.php`
- Modify: `dashboard/routes/console.php`

**Interfaces:**
- Produces: `RollupService::rollup(Server $server, int $resolutionSeconds, CarbonImmutable $from, CarbonImmutable $to): int` — recomputes completed buckets and upserts by `(server_id, resolution_seconds, bucket_start)`; returns bucket count.
- Produces: `php artisan noc:rollup [--server=] [--resolution=300] [--from=] [--to=] [--backfill-days=0]`.
- Scheduled every 5 minutes: 5m over the last 12 buckets from samples, 1h over the last 3 buckets from 5m, 1d over the last 2 buckets from 1h. Skip the current, incomplete bucket.

- [ ] **Step 1: Write failing tests**

Assert: 300s/3600s/86400s buckets align to UTC boundaries; `samples` counts rows; `avg = sum / samples` (weighted when built from smaller rollups), min/max correct; rerunning the same range is idempotent; a late sample inside the recompute window changes the bucket; buckets with no data are absent (not zero); a 1h bucket built from 5m has `samples` equal to the sum of the 5m counts; `1d` built from `1h` is weighted correctly.

- [ ] **Step 2: Implement, run, and commit**

Run: `dashboard/tools/php.sh php artisan test --filter=RollupTest`
Expected: PASS.

```bash
git add dashboard
git commit -m "feat(dashboard): add 5m, 1h, and 1d metric rollups with backfill"
```

---

### Task 12: Retention pruning

**Files:**
- Create: `dashboard/app/Services/Metrics/RetentionService.php`, `dashboard/app/Console/Commands/PruneData.php`, `dashboard/tests/Feature/Retention/PruneTest.php`
- Modify: `dashboard/routes/console.php`, `dashboard/config/retention.php`

**Interfaces:**
- Produces: `RetentionService::prune(bool $dryRun = false): array<string,int>` — deletes in batches of 5,000, at most 50 batches per table per run; details before samples; prunes `alert_deliveries` at 90 days; returns deleted counts.
- Produces: `php artisan noc:prune [--dry-run]`.
- Scheduled daily at 03:30 with `withoutOverlapping()`.

- [ ] **Step 1: Write failing tests**

Freeze time. Data at the retention boundary survives; one second older is deleted. Details older than 7 days are deleted even when their sample is younger than 14 days (impossible in normal flow but tested directly). Rollups are pruned per resolution with the configured day counts. Resolved alerts older than 365 days, `alert_deliveries` older than 90 days, audit older than 365 days, failed jobs older than 30 days, and revoked/expired token rows older than 90 days are deleted. Open alerts, active tokens, and users are never touched. `--dry-run` deletes nothing and reports counts.

- [ ] **Step 2: Implement, run, and commit**

Run: `dashboard/tools/php.sh php artisan test --filter=PruneTest`
Expected: PASS.

```bash
git add dashboard
git commit -m "feat(dashboard): add batched retention pruning with dry run"
```

---

### Task 13: Chart series repository and API

**Files:**
- Create: `dashboard/app/Services/Metrics/MetricSeriesRepository.php`, `dashboard/app/Http/Controllers/ServerSeriesController.php`, `dashboard/config/metrics.php`, `dashboard/tests/Feature/Metrics/SeriesApiTest.php`

**Interfaces:**
- Produces: `GET /servers/{server}/series?metric=cpu&range=24h` (auth + `ServerPolicy@view`) returning `{"metric":"cpu","range":"24h","resolution":60,"points":[{"t":"2026-11-22T12:00:00Z","v":12.5}]}` with `Cache-Control: private, max-age=15`.
- Produces: `MetricSeriesRepository::series(Server $server, string $metric, string $range): array`.
- Ranges: `1h,6h,24h` → raw samples; `7d` → 300s rollups; `30d,90d` → 3600s; `1y` → 86400s. Metrics: `cpu`, `iowait`, `memory`, `swap`, `disk`, `inode`, `load`, `rx`, `tx`, `processes`.
- Cap: 240 points for ranges ≤ 24h, 1,000 otherwise; server-side bucket averaging beyond the cap; gaps stay absent.

- [ ] **Step 1: Write failing tests**

Assert: each range selects the correct source table (use `DB::listen`); values map correctly (`memory` → `mem_used_pct`; rollup `v` = sum/samples); points are ascending; missing buckets are absent; a 1440-point 24h request returns ≤ 240 points; unknown metric/range → 422; guest → redirect/401; viewer can read; soft-deleted server → 404.

- [ ] **Step 2: Implement, run, and commit**

Run: `dashboard/tools/php.sh php artisan test --filter=SeriesApiTest`
Expected: PASS.

```bash
git add dashboard
git commit -m "feat(dashboard): add chart series repository and JSON endpoint"
```

---

### Task 14: UI foundation, design system, and security headers

**Files:**
- Create: `dashboard/resources/views/layouts/{app,guest}.blade.php`, `dashboard/resources/views/components/{status-pill,stat-card,empty-state,sparkline,icon}.blade.php`, `dashboard/resources/css/app.css`, `dashboard/resources/js/app.js`, `dashboard/app/Http/Middleware/SecurityHeaders.php`, `dashboard/app/Support/Sparkline.php`, `dashboard/tests/Feature/Ui/LayoutTest.php`, `dashboard/tests/Feature/Security/HeadersTest.php`
- Modify: `dashboard/bootstrap/app.php`, `dashboard/app/Providers/AppServiceProvider.php`, `dashboard/.gitignore`, `dashboard/vite.config.js`

**Interfaces:**
- Produces: layout with 240px sidebar, topbar, dark mode default, theme toggle persisted in `localStorage`, skip link, `aria-current` nav.
- Produces: `GET /` redirects to `/dashboard`; authenticated `GET /dashboard` still renders the Task 2 placeholder until Task 15 replaces it.
- Produces: Tailwind tokens `ok/warning/critical/unknown/accent`, Inter self-hosted with system fallback, tabular numerals, 13px tables.
- Produces: `Sparkline::svg(array $values, int $width = 96, int $height = 28): string` — inline SVG, no JS, gaps break the line.
- Produces: `SecurityHeaders` middleware setting HSTS (production only), `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `frame-ancestors 'none'`, and a nonce-based CSP: `script-src 'self' 'nonce-…' 'unsafe-eval'` (Alpine requirement, documented), `style-src 'self' 'unsafe-inline'`, `img-src 'self' data:`, `font-src 'self'`, `connect-src 'self'`, `base-uri 'self'`, `form-action 'self'`.
- Produces: committed `public/build` artifacts; `.gitignore` no longer ignores `/public/build` but still ignores `/public/hot`.

- [ ] **Step 1: Write failing tests**

`LayoutTest`: authenticated `GET /dashboard` (temporary placeholder) contains sidebar links, the skip link, a theme bootstrap script with a nonce, the status-pill text for `ok/warning/critical/unknown`, and no `http://`/`https://` asset URL other than `app.url`. `HeadersTest`: responses carry all headers; CSP includes the same nonce as the rendered script tag; HSTS absent when not secure.

- [ ] **Step 2: Implement the layout and build pipeline**

Use Vite manual bundling so production has no injected remote scripts:

```js
import '../css/app.css';
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
Livewire.start();
```

Use `@livewireScriptConfig` in the layout. Add the theme bootstrap as the only inline script, with `nonce="{{ $cspNonce }}"`. Tailwind 4 dark variant:

```css
@import "tailwindcss";
@custom-variant dark (&:where(.dark, .dark *));
@theme { --color-accent: #2563eb; --color-ok: #047857; /* … */ }
```

Vendor `InterVariable.woff2` under `resources/fonts/` and reference it from CSS with `font-display: swap`; if the font file cannot be obtained, fall back to `ui-sans-serif, system-ui, sans-serif` and record that in the README.

- [ ] **Step 3: Verify the build and tests**

Run: `npm run build && dashboard/tools/php.sh php artisan test --filter='LayoutTest|HeadersTest'`
Expected: build succeeds, `public/build/manifest.json` exists, tests PASS.

- [ ] **Step 4: Commit**

```bash
git add dashboard
git commit -m "feat(dashboard): add design system, layout, dark mode, and CSP headers"
```

---

### Task 15: Fleet overview and server table

**Files:**
- Create: `dashboard/app/Livewire/Dashboard.php`, `dashboard/app/Livewire/Servers/ServerTable.php`, `dashboard/app/Services/FleetSummary.php`, Blade views under `dashboard/resources/views/livewire/`, `dashboard/tests/Feature/Ui/DashboardTest.php`, `dashboard/tests/Unit/SparklineTest.php`
- Modify: `dashboard/routes/web.php`

**Interfaces:**
- Produces: `Route::livewire('/dashboard', Dashboard::class)` full-page Livewire 4 component with four summary cards (Online x/y, Needs attention, Hot resources, Data gaps), recent alerts count, and the server table.
- Produces: `FleetSummary::cards(): array{total:int,online:int,attention:int,hot:int,gaps:int}` via one aggregate query cached 20s.
- Produces: `ServerTable` with `#[Url]` properties `status`, `panel`, `group`, `q`, `sort`, `dir`, `problems`; 25 rows/page; `wire:poll.30s.visible`; one batched sparkline query per page.
- Produces: `SparklineTest` for the pure SVG helper.

- [ ] **Step 1: Write failing tests**

Assert: cards match seeded data; status filter uses `stale_after`/`offline_after` and never-seen (`last_seen_at=null`); problems filter excludes `ok`; search matches name/hostname; panel/group filters; default sort is health severity then hostname; 30 servers paginate 25; query count for a 25-row page stays ≤ 10 (no N+1); empty database shows the add-server CTA.

- [ ] **Step 2: Implement, run, and commit**

Run: `dashboard/tools/php.sh php artisan test --filter='DashboardTest|SparklineTest'`
Expected: PASS.

```bash
git add dashboard
git commit -m "feat(dashboard): add fleet overview, filters, and server table"
```

---

### Task 16: Server detail, KPIs, and charts

**Files:**
- Create: `dashboard/app/Livewire/Servers/Show.php`, `dashboard/resources/views/livewire/servers/show.blade.php`, `dashboard/resources/js/charts.js`, `dashboard/tests/Feature/Ui/ServerDetailTest.php`
- Modify: `dashboard/routes/web.php`, `dashboard/resources/js/app.js`

**Interfaces:**
- Produces: `Route::livewire('/servers/{server}', Show::class)` with sticky header, status pill, last-seen, agent version, panel, actions, KPI tiles, tabs `overview|performance|panel|modules|alerts|history` via `?tab=`, and range `?range=1h|6h|24h|7d|30d|90d|1y`.
- Produces: `charts.js` exporting `mountSeriesChart(el, url)`; Chart.js 4.6 and `chartjs-adapter-date-fns` are loaded with `await import(...)` only on this page; four-series maximum, units in ticks, gap breaking at 2.5× interval.

- [ ] **Step 1: Write failing tests**

Assert: never-seen server shows "Never seen" and "Waiting for first check-in"; offline server shows "Data ends … ago" and no misleading flat line marker; KPI values come from the latest sample; each tab renders its section; invalid tab falls back to overview; chart container carries the series URL and a `<noscript>` text summary; viewer can view.

- [ ] **Step 2: Implement, run, and commit**

Run: `dashboard/tools/php.sh php artisan test --filter=ServerDetailTest`
Expected: PASS.

```bash
git add dashboard
git commit -m "feat(dashboard): add server detail page with KPI tiles and charts"
```

---

### Task 17: Generic panel/module rendering and stored snapshot

**Files:**
- Create: `dashboard/app/Support/MetricPresenter.php`, `dashboard/app/Support/MysqlQueryMasker.php`, `dashboard/app/Http/Controllers/StoredSnapshotController.php`, `dashboard/config/metric_labels.php`, `dashboard/resources/views/livewire/servers/tabs/{panel,modules}.blade.php`, `dashboard/resources/views/servers/snapshot.blade.php`, `dashboard/tests/Feature/Ui/PanelMetricsTest.php`, `dashboard/tests/Feature/Ui/StoredSnapshotTest.php`
- Modify: `dashboard/app/Livewire/Servers/Show.php`

**Interfaces:**
- Produces: `MetricPresenter::groups(array $flat): array` — groups by prefix (`cpanel`, `service`, `security`, `mysql`, `smart`, `diskquota`, `imunify`, `other`) using `config/metric_labels.php` for label/unit/type; caps at 200 rows and marks truncation.
- Produces: SMART table rows from `smart.disk.<dev>.*`; quota top list sorted by `used_gb`; Imunify card; services table with per-row severity.
- Produces: `GET /servers/{server}/snapshot` admin-only, latest detail only, `Cache-Control: no-store`, audit action `server.snapshot_viewed`; MySQL `info` masked by `MysqlQueryMasker::mask()` unless the value is already absent.
- Produces: every rendered agent key/value escaped by Blade; unknown keys under a collapsed "Other metrics".

- [ ] **Step 1: Write failing tests**

Assert: `{!! !!}` never appears in these views; a value `<script>alert(1)</script>` renders escaped; 250 keys render 200 plus a truncation notice; SMART rows show health/realloc/pending/power-on hours/written GB and "Not reported" when missing; quota over count and top users; MySQL info masked (`SELECT * FROM users WHERE id = 1` → `SELECT * FROM users WHERE id = ?`); viewer gets 403 on snapshot; operator too; admin gets 200 with `no-store` and an audit row.

- [ ] **Step 2: Implement, run, and commit**

Run: `dashboard/tools/php.sh php artisan test --filter='PanelMetricsTest|StoredSnapshotTest'`
Expected: PASS.

```bash
git add dashboard
git commit -m "feat(dashboard): render panel modules generically and add admin snapshot"
```

---

### Task 18: Add-server wizard, one-time token reveal, and waiting screen

**Files:**
- Create: `dashboard/app/Livewire/Servers/Create.php`, `dashboard/app/Http/Controllers/TokenRevealController.php`, `dashboard/app/Livewire/Servers/Waiting.php`, views under `dashboard/resources/views/`, `dashboard/tests/Feature/Ui/CreateServerTest.php`
- Modify: `dashboard/routes/web.php`

**Interfaces:**
- Produces: `Route::livewire('/servers/create', Create::class)` two-step wizard: step 1 name/hostname/group/interval/notes, step 2 review and submit.
- Produces: on submit, one DB transaction creates the server and token, flashes `issued_token` (`server_id`, `plain`, `prefix`), and redirects to `GET /servers/{server}/token-reveal`.
- Produces: `TokenRevealController::show()` pulls the flash, aborts 404 on missing/mismatched server, returns `no-store`, and shows the install commands with `config('app.url')`. A second visit or refresh shows 404/redirect, never the token.
- Produces: `Route::livewire('/servers/{server}/waiting', Waiting::class)` polls every 5s for 10 minutes, then stops with troubleshooting commands.
- Authorization: admin/operator only.

- [ ] **Step 1: Write failing tests**

Assert: viewer cannot open create/reveal/waiting; validation errors per step; successful submit stores only the hash; redirect target; reveal page contains the plaintext once, the install command, and `Cache-Control: no-store`; second GET does not contain the token; the token never appears in the Livewire HTML before the reveal; waiting page shows "Waiting for first check-in" and stops polling after 10 minutes.

- [ ] **Step 2: Implement, run, and commit**

Run: `dashboard/tools/php.sh php artisan test --filter=CreateServerTest`
Expected: PASS.

```bash
git add dashboard
git commit -m "feat(dashboard): add server wizard with one-time token reveal"
```

---

### Task 19: Token management and alert center

**Files:**
- Create: `dashboard/app/Livewire/Servers/{Tokens,Edit}.php`, `dashboard/app/Livewire/Alerts/{Index,Show,AlertBell}.php`, views under `dashboard/resources/views/livewire/`, `dashboard/tests/Feature/Ui/TokenManagementTest.php`, `dashboard/tests/Feature/Ui/AlertCenterTest.php`
- Modify: `dashboard/routes/web.php`, `dashboard/resources/views/layouts/app.blade.php`

**Interfaces:**
- Produces: `Route::livewire('/servers/{server}/tokens', Tokens::class)` listing active/revoked tokens with prefix, created, last used, expiry/grace countdown; rotate → new plaintext via the reveal flow; revoke requires typing the server name.
- Produces: `Route::livewire('/servers/{server}/edit', Edit::class)` admin+operator form for name, hostname, group, notes, interval; `ServerService::update(Server, array $attributes, ?User $actor = null)` audits `server.updated`.
- Produces: `ServerService::delete(Server $server, ?User $actor = null)` — soft-deletes, revokes every token, resolves every open alert with `AlertEngine::resolve(..., notify: false)`, audits `server.deleted`; metrics are retained until retention.
- Produces: `Route::livewire('/alerts', Alerts\Index::class)` filters severity/status/server; `Route::livewire('/alerts/{alert}', Alerts\Show::class)` detail with safe context and a per-channel/per-event delivery panel (`In-app` always delivered; `Email`/`Telegram` × `Opened`/`Recovery` status, attempts, redacted error); acknowledge and resolve (note required) actions; `AlertBell` polls 30s and shows open+acknowledged count.
- Audit actions: `token.rotated`, `token.revoked`, `alert.acknowledged`, `alert.resolved`, `server.updated`, `server.deleted`.
- Authorization: rotate/revoke/ack/resolve/edit/delete admin+operator; viewer read-only.

- [ ] **Step 1: Write failing tests**

Assert: rotation shows the new plaintext once and old token works during grace then fails; revoke is immediate and requires the exact server name; edit updates name/group/interval and audits `server.updated`; delete soft-deletes, revokes tokens, resolves alerts without creating recovery deliveries, and audits `server.deleted`; viewer sees no action buttons and direct calls are forbidden; acknowledge does not resolve and stops reminders; resolve requires a note and sets `resolved_by`; bell count excludes resolved; filters work; alert detail never renders MySQL query text or the Telegram bot token; delivery panel shows `Not configured` when Telegram is disabled, `Failed` with a redacted error after a fake failure, and a separate `Recovery` row after resolve.

- [ ] **Step 2: Implement, run, and commit**

Run: `dashboard/tools/php.sh php artisan test --filter='TokenManagementTest|AlertCenterTest'`
Expected: PASS.

```bash
git add dashboard
git commit -m "feat(dashboard): add token lifecycle and alert center"
```

---

### Task 20: User management, settings, profile, and audit page

**Files:**
- Create: `dashboard/app/Livewire/Settings/{Users,UserForm,Profile,AlertSettings}.php`, `dashboard/app/Livewire/Activity/Index.php`, `dashboard/app/Http/Middleware/EnsurePasswordChanged.php`, views, `dashboard/tests/Feature/Ui/{UserManagementTest,SettingsTest,ActivityTest}.php`
- Modify: `dashboard/bootstrap/app.php`, `dashboard/routes/web.php`, `dashboard/app/Providers/FortifyServiceProvider.php`

**Interfaces:**
- Produces: `Route::livewire('/settings/users', Settings\Users::class)` admin-only create/edit role/deactivate/reset-password; last active admin cannot be demoted, deactivated, or deleted; passwords are hashed and `must_change_password=true` for admin-set passwords.
- Produces: `Route::livewire('/settings/profile', Settings\Profile::class)` name/email/password/notify_email; changing email resets `email_verified_at` and requires the current password.
- Produces: `Route::livewire('/settings/alerts', Settings\AlertSettings::class)` admin-only form with validated alert thresholds; SMTP status read from `config('mail.*')` (never the password); Telegram `enabled`, `chat_id`, `message_thread_id`, and write-only `bot_token` (stored via `set(..., isSecret: true)`, shown only as “Configured” with Replace/Clear); and Send test buttons for email/Telegram calling `AlertNotifier::sendTest()`. Changing Telegram settings audits `settings.updated`; Send test audits `alert_channel.tested` and is rate-limited by `alerts.test_rate_per_minute`.
- Produces: `Route::livewire('/activity', Activity\Index::class)` admin+operator audit list with action/actor/date filters; no edit/delete.
- Produces: `EnsurePasswordChanged` redirects flagged users to profile except for logout/profile routes.

- [ ] **Step 1: Write failing tests**

Assert: role changes and deactivation work; last-admin guard; viewer/operator forbidden from `/settings/users` and `/settings/alerts`; operator can read `/activity`, viewer cannot; threshold changes affect `IssueDetector`; flagged user is redirected to profile until the password changes; audit records user/role/settings changes; no route updates/deletes audit rows.

Settings/alerts: saving a Telegram bot token stores ciphertext and the rendered HTML does not contain the plaintext on a later visit; clearing it removes the secret; invalid `chat_id`/`message_thread_id` fails validation; email status never renders the SMTP password; Send test is admin-only, rate-limited to 3/minute, and creates an audit event; a failing test returns a redacted error and does not leak the bot token.

- [ ] **Step 2: Implement, run, and commit**

Run: `dashboard/tools/php.sh php artisan test --filter='UserManagementTest|SettingsTest|ActivityTest'`
Expected: PASS.

```bash
git add dashboard
git commit -m "feat(dashboard): add user management, settings, profile, and audit UI"
```

---

### Task 21: Two-factor authentication enrollment and challenge

**Files:**
- Create: `dashboard/app/Livewire/Settings/TwoFactor.php`, `dashboard/resources/views/livewire/settings/two-factor.blade.php`, `dashboard/tests/Feature/Ui/TwoFactorTest.php`
- Modify: `dashboard/resources/views/auth/two-factor-challenge.blade.php`, `dashboard/config/fortify.php`

**Interfaces:**
- Produces: profile section to enable 2FA (QR SVG via the user's `twoFactorQrCodeSvg()`), confirm a 6-digit code, display recovery codes once, regenerate, and disable with password confirmation.
- Produces: challenge page posting `code` or `recovery_code` to Fortify's `/two-factor-challenge`; rate-limited by the `two-factor` limiter.
- Uses Fortify actions: `EnableTwoFactorAuthentication`, `ConfirmTwoFactorAuthentication`, `DisableTwoFactorAuthentication`, `GenerateNewRecoveryCodes`.

- [ ] **Step 1: Write failing tests**

Assert: enable creates a secret but 2FA is not active until confirmed; wrong code does not confirm; correct code activates; recovery codes are shown once and hashed/encrypted at rest; disable requires the current password; an active-2FA user is redirected to the challenge after login; a valid TOTP passes; a recovery code works once; viewer/user cannot enable 2FA for another user.

- [ ] **Step 2: Implement, run, and commit**

Run: `dashboard/tools/php.sh php artisan test --filter=TwoFactorTest`
Expected: PASS.

```bash
git add dashboard
git commit -m "feat(dashboard): add TOTP two-factor enrollment and challenge"
```

---

### Task 22: Deployment, backup/restore, and operations

**Files:**
- Create: `dashboard/README.md`, `dashboard/ops/shared-hosting/{README.md,deploy.sh,cron.example}`, `dashboard/ops/vps/{nginx.conf,noc-queue.service,noc-scheduler.service,noc-scheduler.timer}`, `dashboard/ops/backup.sh`, `dashboard/app/Console/Commands/BackupDatabase.php`, `dashboard/tests/Feature/Ops/BackupTest.php`
- Modify: root `README.md` (link the dashboard), `dashboard/.env.example`

**Interfaces:**
- Produces: `php artisan noc:backup --path=/absolute/file.sqlite` using `VACUUM INTO`; fails if the destination exists; verifies `PRAGMA integrity_check` and prints row counts.
- Produces: `ops/backup.sh` (timestamped path, retention) and `ops/shared-hosting/deploy.sh` (release dir, symlink, `php -v` guard requiring >= 8.3, migrate `--force --isolated`, config/route/view cache, fail if `public/build/manifest.json` is missing).
- Produces: shared-hosting cron line `* * * * * cd ~/noc/current && php artisan schedule:run >> /dev/null 2>&1`; queue as `queue:work --stop-when-empty --max-time=50 --tries=3 --sleep=1` in the scheduler with `withoutOverlapping`.
- Produces: VPS units for scheduler and one queue worker, Nginx config with `root .../public`, `client_max_body_size 128k`, PHP-FPM, and deny rules for `.env`/SQLite.
- Produces: documented env matrix: shared hosting `CACHE_STORE=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=database`; VPS `redis`. `.env.example` includes `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, and a note that email/Telegram need a running queue worker.
- Produces: `dashboard/README.md` section “Alert channels” covering SMTP setup, Telegram bot creation, `chat_id`, how to obtain a forum topic `message_thread_id`, Send test, the requirement for outbound SMTP/HTTPS, acknowledge-stops-reminders behavior, and one recovery message on resolve. It documents that Telegram is optional and that in-app alerts never depend on either channel.
- Produces: `dashboard/README.md` section "PostgreSQL / TimescaleDB migration rehearsal" with the exact manual steps (separate Postgres 16 container, `DB_CONNECTION=pgsql`, migrate, replay backup, run suite, switch back), plus the SQLite → Postgres trigger thresholds from spec §16.

- [ ] **Step 1: Write the failing backup test**

Assert: backup file is created and passes `PRAGMA integrity_check`; a second run with the same path fails; restoring the backup into a fresh SQLite file preserves server/sample counts; `APP_KEY` change invalidates tokens is documented and covered by a test.

- [ ] **Step 2: Implement the command and scripts, then run**

Run: `dashboard/tools/php.sh php artisan test --filter=BackupTest`
Expected: PASS.

- [ ] **Step 3: Verify the deploy script in a scratch directory**

Run `bash -n dashboard/ops/shared-hosting/deploy.sh dashboard/ops/backup.sh` and `dashboard/tools/php.sh php artisan route:list`. If `systemd-analyze` is available, also run `systemd-analyze verify dashboard/ops/vps/*.service dashboard/ops/vps/*.timer`.
Expected: no shell syntax errors; systemd units valid where the tool exists; all routes registered.

- [ ] **Step 4: Commit**

```bash
git add dashboard README.md
git commit -m "docs(dashboard): add deployment, backup, restore, and operations runbooks"
```

---

### Task 23: Final verification and acceptance

**Files:**
- Create: `dashboard/tests/Feature/Acceptance/MvpAcceptanceTest.php`, `dashboard/tools/load-ingest.php`, `dashboard/app/Console/Commands/MakeLoadFixture.php`
- Modify: `dashboard/README.md` (acceptance checklist)

**Interfaces:**
- Produces: `php artisan noc:make-load-fixture --servers=100 --path=/tmp/noc-load.json` creating 100 servers + tokens in a throwaway DB and writing `{name, token, payload}` entries.
- Produces: `tools/load-ingest.php --url= --fixture= --seconds=60 --concurrency=100` using `curl_multi`, reporting p50/p95 latency, status counts, and SQLite `database is locked` errors.

- [ ] **Step 1: Write the acceptance test**

Seed 3 servers and drive the real HTTP contract: ingest → dashboard cards/table → detail series → alert opens → in-app row exists → email/Telegram `opened` deliveries recorded → acknowledge stops reminders → resolve → one `resolved` delivery per channel; rotate token → old works in grace, then fails; viewer forbidden from mutations; admin user CRUD; retention dry run; backup/restore. Use `Notification::fake()` and `Http::fake()` to assert email recipients and the Telegram `message_thread_id`; assert no 500s for invalid input and the exact status codes from the agent contract.

- [ ] **Step 2: Run the full verification**

```bash
cd dashboard
./tools/test.sh --with-assets
./tools/php.sh composer audit
./tools/php.sh php artisan about
./tools/php.sh php artisan route:list
```

Expected: Pint clean, all tests pass, `composer audit` reports no advisories, assets build from a clean tree, all routes present.

- [ ] **Step 3: Run the load check**

Start the app in the podman image (`php artisan serve --host=0.0.0.0 --port=8000`), create the fixture, run `tools/load-ingest.php` for 60 seconds at concurrency 100.
Expected: zero `database is locked`, p95 ingest < 200 ms on this machine, no 5xx.

- [ ] **Step 4: Run the agent compatibility check**

Point a scratch agent config at `http://127.0.0.1:8000/api/v1/ingest` with `ALLOW_INSECURE_HTTP=1` and a fixture token; run `agent/collector.sh` once. Expected: exit 0, `202` accepted, one sample row, dashboard shows the server online, and no token appears in any log.

- [ ] **Step 5: Security checklist**

Confirm: `.env` and `*.sqlite` are outside `public/` and denied by Nginx; all responses carry the CSP/security headers; agent token plaintext and Telegram bot token are absent from DB (token hashed, bot token encrypted), logs, HTML after reveal, and notifications; `APP_DEBUG=false` in production; cookies `Secure`/`HttpOnly`/`SameSite=Lax`; rate limits return 429; last-admin guard holds; audit log is append-only.

- [ ] **Step 6: Commit**

```bash
git add dashboard
git commit -m "test(dashboard): add MVP acceptance, load fixture, and final verification"
```

---

## Self-Review Notes

- **Spec coverage:** ingest/auth/tokens (§7–§8), alerts + liveness + email/Telegram delivery (§9), status semantics (§10), charts/rollups (§11), every screen (§12), components (§13), security (§14), deployment (§15), PostgreSQL portability rules (Task 3/7/11/13 keep queries in Eloquent with ULIDs and JSON columns), testing (§17).
- **Deferred by spec:** webhook/Slack, per-user Telegram binding, maintenance windows, critical escalation, public status page, managed groups, per-server thresholds, mobile app, extra panel plugins, TimescaleDB. Nothing in this plan depends on them.
- **Portability guard:** no `strftime`, `julianday`, `AUTOINCREMENT`, `WITHOUT ROWID`, partial indexes, or JSON1 operators in application code; time bucketing and aggregation live in PHP; `insertOrIgnore`/`upsert` are the only conflict strategies. Task 22 documents the manual PostgreSQL rehearsal (`DB_CONNECTION=pgsql`, run migrations, replay a backup, run the suite against Postgres) because this host's dev image ships without `pdo_pgsql`; the code must not need changes for it.
- **Review Focus tests are owned:** oversized bodies → Task 6; duplicate retries → Task 7; clock skew → Task 7; missing modules/zero samples → Task 8/11; untrusted keys/XSS → Task 14/17.
- **Toolchain reality:** this host has no PHP/composer/sqlite3; every command goes through the podman wrapper. `dashboard/tools/php.sh` is created in Task 1 before any other command runs.
