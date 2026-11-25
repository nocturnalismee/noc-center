# NOC Center Realtime Updates Design

**Status:** Proposed for review  
**Date:** 2026-11-25  
**Selected approach:** A — native Laravel Reverb on VPS with polling fallback  
**Related:** `docs/superpowers/specs/2026-11-22-noc-dashboard-design.md`

## Intent

Replace the current “refresh only when the user acts” behavior on operational screens with near-realtime updates over Laravel Reverb. The dashboard is a NOC console: a stale fleet table or alert detail is an operational hazard, not a cosmetic issue.

The production target is a VPS, but the application must remain deployable on ordinary shared hosting. Reverb is therefore an enhancement path, not a hard runtime dependency.

## Goals

- Deliver near-realtime updates on:
  - dashboard fleet summary, table, open alerts, collection status, and activity card;
  - servers list and server table;
  - server detail, including metrics, tabs, alert tab, and performance chart;
  - alerts list and alert detail;
  - global alert bell;
  - “waiting for first sample” page.
- Use private channels with authorization based on the existing active-user and policy model.
- Keep a bounded polling fallback for shared hosting and for Reverb outages.
- Avoid sensitive data in broadcast payloads; clients refresh data through authenticated Livewire requests.
- Keep the existing asset pipeline: build locally/CI, commit `public/build`, no Node in production.
- Make the feature flag server-controlled so enabling/disabling realtime does not require rebuilding assets.

## Non-goals

- No realtime update on Activity, Users, or Settings pages.
- No realtime update on create/edit/token workflows except the global alert bell.
- No multi-node Reverb cluster, Redis pub/sub, or horizontal WebSocket scaling in this iteration.
- No replacement of the Livewire data model with a separate SPA.
- No public channels and no client-trusted payloads.
- No removal of polling as a safety net.

## Constraints

- Laravel `^13.17`, PHP `^8.3`, Livewire `^4.4`.
- SQLite remains the production database for this iteration; PostgreSQL migration path must remain open.
- Production assets are prebuilt; `public/build` is deployed with the release.
- Reverb requires a long-running process and a WebSocket reverse-proxy route. Shared hosting cannot reliably provide that process.
- SQLite writes must remain short; broadcasting must not run inside the ingest transaction, and ingest must still return `202` if broadcasting fails.
- All timestamps remain UTC in storage; UI display remains `Asia/Jakarta`.
- Existing role model: `admin`, `operator`, `viewer`; only active users may connect.
- CSP currently allows only `connect-src 'self'`; it must be extended for WebSocket origins.

## Approaches considered

### A — Native Reverb on VPS with polling fallback (selected)

Run `php artisan reverb:start` as a systemd service on the VPS, proxy `/app` and `/apps` through Nginx, and use Laravel Echo in the browser. Keep the existing `wire:poll` paths as a slower safety net. A server-rendered feature flag disables Echo on shared hosting.

**Trade-off:** two paths to test and one extra daemon to operate, but it is the only option that is both native Reverb and compatible with the shared-hosting requirement.

### B — Reverb only, no polling

Simpler component markup and fewer requests, but a Reverb outage silently freezes every live screen. It also drops shared-hosting support.

### C — Managed WebSocket service

Pusher/Ably works on shared hosting, but it is not Reverb, adds a recurring external dependency, and does not fit the self-hosted NOC model. Deferred unless the deployment target changes.

## Architecture

```text
Agent
  -> POST /api/ingest
     -> StoreSample (short DB transaction)
     -> AlertEngine (post-commit)
     -> Realtime::emit(SampleStored)
     -> Realtime::emit(AlertChanged) when an alert opens/acknowledges/resolves

Scheduler / RunTracker
  -> Realtime::emit(OperationsChanged)

Server management actions
  -> Realtime::emit(ServerChanged)

Laravel Reverb
  -> private-fleet
  -> private-alerts
  -> private-server.{serverId}
  -> private-alert.{alertId}

Browser (Laravel Echo)
  -> debounced Event -> Livewire $wire.$refresh()
  -> chart refresh when a server-detail chart is present
  -> wire:poll fallback remains active at a slower interval
```

### Broadcast events

| Event | Payload | Channels | Emitted from |
|---|---|---|---|
| `SampleStored` | `server_id`, `sample_id`, `collected_at`, `health`, `status` | `fleet`, `server.{serverId}` | ingest after store/alert evaluation |
| `AlertChanged` | `alert_id`, `server_id`, `change` (`opened`/`acknowledged`/`resolved`), `status`, `severity` | `alerts`, `alert.{alertId}`, `server.{serverId}` | alert engine |
| `ServerChanged` | `server_id`, `change` (`created`/`updated`/`deleted`/`token_rotated`/`token_revoked`) | `fleet`, `server.{serverId}` | server/token services |
| `OperationsChanged` | `task_key`, `status`, `finished_at` | `fleet` | `RunTracker` |

Event names are broadcast without a leading dot and subscribed with Laravel Echo’s `.` prefix (`listen('.SampleStored', ...)`).

### Channel authorization

| Channel | Rule |
|---|---|
| `fleet` | authenticated user is active |
| `alerts` | active user and `Gate::allows('viewAny', Alert::class)` |
| `server.{serverId}` | active user and `Gate::allows('view', $server)` |
| `alert.{alertId}` | active user and `Gate::allows('view', $alert)` |

Inactive users must never authorize a channel, even if their session is still valid.

### Emission rules

- Events are dispatched only when `config('noc.realtime.enabled')` is true, and the emission helper catches/reports broadcast or queue failures.
- Broadcast events implement `ShouldBroadcast` and `$afterCommit = true`.
- Ingest never fails because Reverb is unavailable; queued broadcast failures are reported and retried by the queue worker.
- Alert payloads contain identifiers and state only. Alert titles, messages, context, and credentials stay in the database and are fetched by the authenticated Livewire request.
- Server update events are emitted only by explicit management actions, not by the per-sample `Server` update, to avoid a broadcast storm.

## Client behavior

### Runtime configuration

The feature flag and public Reverb connection data are rendered by Blade into a nonce-protected script, not baked in with `VITE_*`:

```html
<script nonce="{{ $cspNonce }}">
window.__NOC_REALTIME__ = @json([
    'enabled' => config('noc.realtime.enabled'),
    'key' => config('reverb.apps.apps.0.key'),
    'host' => config('reverb.apps.apps.0.options.host'),
    'port' => (int) config('reverb.apps.apps.0.options.port', 8080),
    'scheme' => config('reverb.apps.apps.0.options.scheme', 'http'),
]);
</script>
```

This lets operators disable realtime by changing `NOC_REALTIME=false` without rebuilding the frontend.

### Echo client

`resources/js/realtime.js` owns Echo creation and subscription cleanup. `resources/js/app.js` starts it after Livewire. The app exposes a narrow `window.NocRealtime` API:

```js
window.NocRealtime = {
    enabled,
    subscribe(channel, event, handler), // returns unsubscribe
    status,                            // 'connected' | 'connecting' | 'unavailable'
};
```

The client must not put event payloads into HTML. Events are only refresh triggers.

### Livewire refresh strategy

An Alpine data component, `nocRealtimeRefresh`, subscribes to one or more channel/event pairs, debounces refreshes (default 750 ms), calls `$wire.$refresh()`, and refreshes any mounted chart after the morph. A slow `wire:poll` remains in the DOM:

| Screen | Realtime trigger | Fallback |
|---|---|---:|
| Dashboard | `fleet`, `alerts`, `operations` | 60 s |
| Server table / servers list | `fleet` | 60 s |
| Server detail | `server.{id}`, `alerts` | 60 s |
| Alerts index | `alerts` | 60 s |
| Alert detail | `alert.{id}`, `server.{id}` | 60 s |
| Alert bell | `alerts` | 30 s |
| Waiting page | `server.{id}` | 5 s |

When `NOC_REALTIME=false`, the fallback intervals are used without Echo and remain the current 30 s/5 s behavior where applicable.

### Chart behavior

After a Livewire morph, `refreshSeriesCharts()` must destroy and remount each `[data-series-chart]`. The existing chart’s empty/loading/error states remain authoritative; realtime must not bypass them.

## Deployment

### VPS

- Run `php artisan reverb:start --host=127.0.0.1 --port=8080` under systemd as `www-data`.
- Set:

```dotenv
BROADCAST_CONNECTION=reverb
NOC_REALTIME=true
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_HOST=panel.example.com
REVERB_PORT=443
REVERB_SCHEME=https
```

- Nginx proxies both `/app` and `/apps` to `127.0.0.1:8080`, including WebSocket upgrade headers and forwarded proto.
- CSP `connect-src` includes `wss://panel.example.com` in production and `ws://localhost:8080` in local development.
- The queue worker (`noc-queue.service`) processes queued broadcasts; `reverb:restart` is called after deploy if the Reverb process must pick up cached config.
- `reverb:start` logs go to journald; a failed service is visible in `systemctl status noc-reverb`.

### Shared hosting

- Set `NOC_REALTIME=false`; no Reverb process is started.
- `BROADCAST_CONNECTION` may remain `log`/`null`.
- Existing polling continues to update the screens.
- The Reverb assets and event classes remain in the build; they are inert without the flag.

## Security

- Private channels only; no public fleet data.
- Channel callbacks re-check active status and policies.
- Broadcast payloads contain no alert text, tokens, email addresses, IPs, or query text.
- CSP remains nonce-based; the only new connect origin is the Reverb WebSocket origin.
- Reverb app secret never reaches the browser.
- `/broadcasting/auth` uses the existing web/session/CSRF middleware.
- `REVERB_APP_KEY` is public by design; `REVERB_APP_SECRET` is server-only.

## Testing strategy

- Unit tests for event payloads, channel names, and `broadcastAs()` names.
- Feature tests for `/broadcasting/auth` authorization: active/inactive user, allowed/denied server, allowed/denied alert.
- Feature tests that ingest emits `SampleStored`; alert open/ack/resolve emits `AlertChanged`; management actions emit `ServerChanged`; `RunTracker` emits `OperationsChanged`.
- Feature tests that `NOC_REALTIME=false` renders the fallback poll and does not boot Echo; `true` renders the realtime hook.
- CSP test asserts the WebSocket origin is present only when realtime is enabled.
- `npm run build` must succeed and the built manifest must reference the new assets.
- Manual two-browser smoke test on VPS: ingest a sample, open an alert, acknowledge/resolve, rotate a token; each screen updates without a manual refresh; stop Reverb and verify the polling safety net still refreshes.

## Rollout and rollback

1. Merge behind `NOC_REALTIME=false`; verify existing behavior is unchanged.
2. Install Reverb and the systemd unit on the VPS; keep the flag off while checking `systemctl status` and Nginx `/app`.
3. Enable `NOC_REALTIME=true` in staging, run the smoke test, then enable production.
4. If Reverb is unhealthy, set `NOC_REALTIME=false`; no asset rebuild is required. The app falls back to polling.
5. Roll back the code release as usual; Reverb can be stopped independently without affecting HTTP ingest.

## Open decisions left to the builder

- Exact debounce value per component (default 750 ms; bell may use 250 ms).
- Whether to show a small “Realtime unavailable” status in the UI; not required for correctness.
- Whether to add a dedicated `/realtime/health` endpoint; only if systemd/journald visibility is insufficient.
- Whether to add Redis for cache/session/queue when the VPS grows; out of scope for this iteration.

## Review focus

- A stale or duplicate sample must never produce a broadcast that makes the UI regress to older metrics.
- An inactive or deleted user must not retain a private-channel subscription.
- A Reverb outage must not block ingest, alert evaluation, or normal HTTP page loads.
- A burst of samples must not trigger an unbounded Livewire refresh loop; debounce and per-component cleanup are mandatory.
- A chart must not remain stuck on a previous range or skeleton after a realtime refresh; chart refresh must respect the server-detail range and tab state.
