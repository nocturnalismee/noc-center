# NOC Center Dashboard — Design

**Status:** draft untuk review
**Tanggal:** 2026-11-22
**Terkait:** `docs/superpowers/specs/2026-11-22-noc-agent-foundation-design.md`, `agent/README.md`

## 1. Ringkasan

Dashboard NOC Center adalah aplikasi web terpusat untuk menerima metrik dari
server yang menjalankan `noc-agent`, menyimpannya, dan menampilkannya dalam satu
antarmuka yang ringan. Agent sudah ada dan mengirim schema-v1 JSON setiap 60
detik ke `POST /api/v1/ingest` dengan Bearer token per server.

Dashboard harus cukup murah untuk berjalan di shared hosting biasa atau VPS
kecil, tetapi dirancang supaya bisa pindah ke PostgreSQL/TimescaleDB ketika
jumlah server atau frekuensi kirim bertambah.

Stack:

- Laravel 13 (13.17+), PHP 8.3+ (Laravel 13 mendukung PHP 8.3–8.5;
  PHP 8.4 direkomendasikan).
- SQLite dengan WAL untuk MVP.
- Blade + Livewire 4 + Alpine.js 3 + Tailwind CSS 4.
- Chart.js 4.6 untuk grafik.
- Vite 8 hanya untuk build asset di lokal/CI; hasil build statis.
- **Tanpa React, Vue, Svelte, atau SPA framework apa pun.** Tidak ada Node.js
  yang dibutuhkan di server. Semua asset di-build lokal/CI menjadi file statis
  di `public/build`, lalu di-deploy sebagai file biasa. Shared hosting hanya
  menjalankan PHP.
- Redis opsional untuk cache/session/queue di VPS; shared hosting memakai
  driver file/database.
- **Tanpa Filament.**
- Laravel Fortify 1.43+ untuk login, reset password, TOTP 2FA, dan passkeys
  opsional.
- PHPUnit 12 (default skeleton Laravel 13) dan Laravel Pint untuk test/format.

### 1.1 Versi stack terverifikasi (2026-11-22)

| Package | Versi | Catatan |
| --- | --- | --- |
| `laravel/framework` | 13.43.0 | PHP 8.3–8.5; security support sampai 2028-03-17. |
| `laravel/laravel` | 13.11.0 | Skeleton; butuh `laravel/framework ^13.17`, PHP `^8.3`. |
| `livewire/livewire` | 4.4.9 | Mendukung Laravel 13; full-page via `Route::livewire`. |
| `laravel/fortify` | 1.43.0 | TOTP 2FA dan passkeys opsional. |
| `tailwindcss`, `@tailwindcss/vite` | 4.3.5 | Build-time; tidak ada runtime Node. |
| `vite` | 8.3.8 | Node `^20.19` atau `>=22.12`. |
| `laravel-vite-plugin` | 3.2.1 | Vite 8. |
| `chart.js` | 4.6.1 | Plus `date-fns` 4.4.0 dan `chartjs-adapter-date-fns` 3.0.0. |
| `alpinejs` | 3.17.5 | Sudah dibundel Livewire; tidak diimpor terpisah. |

Versi di atas diverifikasi lewat Context7 dan registry pada 2026-11-22. Saat
implementasi dimulai, jalankan `composer outdated` dan `npm outdated`; pin versi
final di `composer.lock` dan `package-lock.json`. Jangan memakai CDN.

MVP sudah mencakup:

- Dashboard monitoring dan riwayat metrik.
- Manajemen server dan token.
- **System alerting**: in-app wajib, email via SMTP, dan Telegram opsional
  (mendukung `message_thread_id` untuk topik forum).
- **Multi-user dengan role** admin/operator/viewer.
- Audit log.
- Deployment shared hosting dan VPS kecil.
- Jalur migrasi PostgreSQL/TimescaleDB.

## 2. Tujuan

1. Satu tempat untuk melihat status semua server: online, stale, offline,
   never seen.
2. Menampilkan metrik inti agent: CPU, iowait, load, RAM, swap, disk root,
   inode, network, proses, uptime, OS/kernel.
3. Menampilkan metrik panel dan module secara generik: cPanel, MySQL, SMART,
   disk quota, Imunify, dan field baru tanpa mengubah kode backend setiap kali
   agent menambah field.
4. Menyediakan alur tambah server yang aman: buat server, buat token sekali
   tampil, salin perintah instalasi, lalu token tidak bisa dilihat lagi.
5. Memberi peringatan yang bisa ditindaklanjuti: server offline, resource
   tinggi, SMART bermasalah, service mati, quota over, module cache stale.
6. Mendukung beberapa user dengan pembatasan akses yang jelas dan audit.
7. Menjaga data tetap ringan: retensi, rollup, dan query chart yang tidak
   memindai seluruh tabel.
8. Bisa di-deploy di shared hosting tanpa Redis, tanpa supervisor, dan tanpa
   akses root.
9. Menyediakan jalur migrasi yang jelas ke PostgreSQL/TimescaleDB.

## 3. Non-goals

- Bukan pengganti `smartd`, Prometheus, Zabbix, atau sistem observability
  lengkap.
- Tidak ada public status page.
- Tidak ada aplikasi mobile native.
- Tidak ada multi-tenant organisasi, billing, atau RBAC per-object yang rumit.
- Tidak ada pembuatan otomatis plugin Plesk/DirectAdmin/CyberPanel/aaPanel.
- Tidak ada eksekusi command ke server monitored dari dashboard. Dashboard
  hanya menerima data; tidak membuka inbound port ke server.
- Tidak ada Filament.
- Channel alert MVP: in-app (wajib), email via SMTP (wajib), Telegram
  (opsional, satu bot dan satu chat/topic global). Webhook/Slack masuk fase
  berikutnya lewat interface channel yang sama.
- Tidak ada binding Telegram per user di MVP; target Telegram adalah chat/topic
  operasional yang diatur admin.
- Tidak ada penyimpanan payload mentah penuh per menit; yang disimpan adalah
  metrik inti dan detail panel/extensions yang sudah tervalidasi.

## 4. Batasan dan asumsi

- Target awal 10–100 server pada interval 60 detik.
- SQLite hanya di disk lokal; tidak boleh NFS/CIFS.
- Shared hosting menyediakan PHP 8.3/8.4, `pdo_sqlite`, `sqlite3`, cron, dan
  document root yang bisa diarahkan ke `public/`. Target hosting sudah
  dikonfirmasi mendukung PHP 8.3/8.4, jadi Laravel 13 dipakai. Jika host
  berubah dan hanya menyediakan PHP 8.2, gunakan Laravel 12 sebagai fallback
  dan catat pengecualian itu di README deploy.
- Shared hosting mungkin tidak punya Redis, supervisor, root, atau cron per
  menit. Rancangan harus tetap berfungsi dengan cron 5 menit; ingest tetap
  real-time karena dipicu HTTP.
- VPS kecil memakai Nginx + PHP-FPM, Redis, dan systemd.
- Waktu disimpan UTC; UI menampilkan Asia/Jakarta.
- **Semua copy UI berbahasa Inggris.** Dokumen desain ini boleh berbahasa
  Indonesia; string aplikasi berada di `lang/en`.
- **Frontend tanpa Node di server.** Tailwind dan Vite dijalankan di mesin
  developer/CI; hasil build (`public/build`, `manifest.json`) ikut artefak
  deploy. Halaman tidak boleh bergantung pada CDN, `npm`, atau build saat
  request. Semua font, JavaScript, dan CSS disajikan dari domain sendiri.
- Awalnya satu admin; MVP mendukung admin/operator/viewer sejak awal.
- Token agent dibuat dashboard; agent tidak pernah membuat token.
- Payload maksimum 64 KiB; agent sudah memotong `panel_metrics` lalu
  `extensions` jika melebihi batas.
- Ingest tidak memakai queue. Queue hanya untuk email/Telegram alert, rollup,
  pruning, dan pekerjaan terjadwal lain.
- SMTP memakai konfigurasi `MAIL_*` di `.env`; kredensial tidak disimpan di
  database. Token bot Telegram bersifat rahasia dan disimpan terenkripsi.

## 5. Pendekatan

### A. Laravel monolith + Blade/Livewire (dipilih)

Satu aplikasi Laravel melayani halaman dashboard dan endpoint ingest. Halaman
dirender server-side; Livewire menangani interaksi dan polling; Chart.js
menggambar grafik. SQLite menjadi database utama.

**Kelebihan:** paling cepat dibangun, paling murah di-deploy, tidak perlu
mengelola dua aplikasi, cocok untuk shared hosting, dan autentikasi/CSRF/Blade
sudah terintegrasi.

**Kekurangan:** halaman berat bergantung pada kualitas query; tidak bisa
horizontal scaling dengan SQLite; Livewire butuh perhatian pada polling dan
query N+1.

### B. Laravel API + SPA React/Vue

**Kelebihan:** pemisahan frontend/backend jelas, cocok jika nanti ada aplikasi
mobile.

**Kekurangan:** dua build/deploy, autentikasi token/CORS lebih rumit, dan
biaya operasional lebih tinggi untuk MVP yang hanya butuh dashboard internal.

### C. FastAPI + React

**Kelebihan:** backend Python ringan dan frontend modern.

**Kekurangan:** dua stack, dua deployment, dan tidak sesuai dengan pilihan
Laravel yang sudah ditetapkan.

**Keputusan:** A. Alasan utama bukan sekadar preferensi stack, tetapi biaya
deploy dan jumlah bagian yang harus diamankan/dioperasikan.

## 6. Arsitektur

```text
Server monitored
  noc-agent (timer 60s)
        │ HTTPS POST /api/v1/ingest
        │ Authorization: Bearer <server-token>
        ▼
┌─────────────────────────────────────────────────────┐
│ Laravel app                                         │
│                                                     │
│  Ingest controller + token middleware               │
│        │                                            │
│        ├─ validasi schema & ukuran                  │
│        ├─ idempotent insert metric_samples          │
│        ├─ insert metric_details                     │
│        ├─ update servers (latest metrics/health)    │
│        └─ evaluate alert rules                      │
│                                                     │
│  Blade + Livewire dashboard                         │
│        ├─ fleet overview / server list              │
│        ├─ server detail + Chart.js                  │
│        ├─ alerts center                             │
│        └─ server/token/user/settings management     │
│                                                     │
│  Scheduler (cron)                                   │
│        ├─ offline + stale checks                    │
│        ├─ rollup 5m/1h/1d                           │
│        ├─ retention pruning                         │
│        └─ queue work (shared hosting)               │
└─────────────────────────────────────────────────────┘
        │
        ▼
  SQLite (WAL, local disk)
  Redis optional (VPS: cache/session/queue)
```

Prinsip:

- Ingest ditulis sinkron dalam request. Jangan antrekan penulisan metrik; queue
  hanya untuk email dan pekerjaan terjadwal.
- Dashboard hanya membaca database; tidak memanggil server monitored.
- Semua field panel/module disimpan sebagai JSON flat dan ditampilkan generik.
- Alert dievaluasi setelah data tersimpan; kegagalan alert tidak boleh
  menggagalkan ingest.
- Rollup dihitung di PHP dari data mentah agar tidak bergantung pada SQL
  spesifik SQLite; ini menjaga jalur migrasi ke PostgreSQL.
- Tidak ada kode yang mengasumsikan SQLite selamanya. Gunakan Eloquent/query
  builder, ULID, dan kolom JSON portabel.

## 7. Model data

Semua tabel domain memakai ULID kecuali `users` (bigint standar Laravel) dan
tabel internal Laravel. Waktu disimpan UTC.

### 7.1 `users`

- `id` bigint primary key.
- `name`, `email` unique, `password`.
- `role` string: `admin`, `operator`, `viewer`; default `viewer`.
- `is_active` boolean default true.
- `must_change_password` boolean default false.
- `notify_email` boolean default true.
- `last_login_at` nullable.
- `two_factor_secret`, `two_factor_recovery_codes`,
  `two_factor_confirmed_at` (Fortify), `remember_token`, timestamps.
- Tidak ada registrasi publik. Admin pertama dibuat lewat artisan command
  `php artisan noc:create-admin`.
- Admin dapat membuat user, mengganti role, menonaktifkan, dan mereset
  password. Admin terakhir tidak boleh dihapus/dinonaktifkan.

### 7.2 `servers`

- `id` ULID primary key.
- `name` unique (label manusia), `hostname`, `panel` default `none`.
- `server_type` nullable (tipe server: `backup`, `shared hosting`, `vps`,
  `web`, dst.; nilai bebas, bukan enum), `notes` nullable.
- `provider`, `location`, `label` nullable (metadata identifikasi; `label`
  adalah kode aset/billing data center, mis. `JHD-001`).
- `ip_address` nullable (IP utama, IPv4/IPv6, hanya identifikasi; bukan sumber
  kebenaran koneksi agent).
- `alerts_enabled` boolean default `true` (kontrol alert per server).
- `os_name`, `os_version`, `kernel`, `agent_version` nullable.
- `agent_uuid` nullable (UUID diagnostik dari agent; **tidak unique** karena
  clone VM bisa menduplikasi).
- `interval_seconds` default 60.
- `last_seen_at` nullable (waktu ingest sukses terakhir, clock dashboard).
- `last_sample_at` nullable (waktu `collected_at` terakhir diterima).
- `stale_after`, `offline_after` nullable (dihitung dari `last_seen_at` dan
  `interval_seconds`).
- Metrik terakhir yang didenormalisasi untuk server list:
  `cpu_usage_pct`, `cpu_iowait_pct`, `load1`, `load5`, `load15`,
  `mem_used_pct`, `swap_used_pct`, `disk_used_pct`, `disk_inode_pct`,
  `net_rx_bps`, `net_tx_bps`, `processes_running`, `processes_blocked`,
  `uptime_seconds`.
- `health` string: `ok`, `warning`, `critical`, `unknown` (dari issue rules).
- `issue_codes` JSON array (kode masalah aktif).
- `is_active` boolean default true.
- `deleted_at` nullable (soft delete).
- timestamps.
- Index: `last_seen_at`, `is_active`, `hostname`, `panel`, `health`.

Status online/stale/offline **tidak disimpan** sebagai kolom yang di-update
terus-menerus; status dihitung dari `last_seen_at`, `stale_after`, dan
`offline_after`. `health`/`issue_codes` di-update saat ingest dan oleh
scheduler untuk alert offline.

### 7.3 `server_tokens`

- `id` ULID primary key.
- `server_id` FK ke `servers` (cascade on delete).
- `token_hash` char(64) unique (HMAC-SHA256 dengan `APP_KEY`).
- `token_prefix` char(8) untuk identifikasi di UI/log.
- `label` nullable.
- `last_used_at`, `last_used_ip` nullable.
- `expires_at` nullable.
- `revoked_at` nullable.
- `rotated_from_id` nullable (jejak rotasi).
- `created_by` FK ke `users` nullable.
- timestamps.
- Index: `server_id`, `revoked_at`, `expires_at`.

Token format: `noc_` + 32 byte acak base64url (memenuhi regex agent
`^[A-Za-z0-9._~-]{16,256}$`). Token asli hanya ditampilkan sekali saat
dibuat/rotate; tidak pernah disimpan plaintext, log, URL, atau payload.

### 7.4 `metric_samples`

Satu baris per ingest sukses. Ini tabel terbesar.

- `id` bigint primary key.
- `server_id` ULID FK (cascade).
- `collected_at` timestamp UTC.
- `received_at` timestamp UTC.
- `schema_version` smallint default 1.
- `agent_version` string nullable.
- `interval_seconds` smallint.
- `cpu_usage_pct` decimal(5,2).
- `cpu_iowait_pct` decimal(5,2).
- `cpu_cores` smallint.
- `load1`, `load5`, `load15` decimal(8,3).
- `mem_total_bytes`, `mem_used_bytes` bigint; `mem_used_pct` decimal(5,2).
- `swap_total_bytes`, `swap_used_bytes` bigint; `swap_used_pct` decimal(5,2).
- `disk_total_bytes`, `disk_used_bytes` bigint; `disk_used_pct`,
  `disk_inode_pct` decimal(5,2).
- `net_rx_bps`, `net_tx_bps` bigint.
- `processes_running`, `processes_blocked` integer.
- `uptime_seconds` bigint.
- `payload_bytes` integer.
- `payload_hash` char(64) nullable.
- `created_at` timestamp.
- Unique: `(server_id, collected_at)` — kunci idempotensi retry agent.
- Index: `(server_id, collected_at desc)`, `collected_at`.

Catatan: payload mentah penuh tidak disimpan. Yang disimpan adalah metrik inti
yang sudah didenormalisasi plus `metric_details`.

### 7.5 `metric_details`

1:1 dengan `metric_samples`; menyimpan JSON yang lebih besar/sensitif agar
scan tabel utama tetap cepat.

- `sample_id` bigint primary key, FK `metric_samples` cascade.
- `panel_metrics` JSON nullable.
- `extensions` JSON nullable.
- `size_bytes` integer.
- `created_at` timestamp.
- Retensi lebih pendek dari `metric_samples` (default 7 hari).

### 7.6 `metric_rollups`

Agregat untuk chart jangka panjang.

- `id` bigint primary key.
- `server_id` ULID FK.
- `resolution_seconds` smallint: `300`, `3600`, `86400`.
- `bucket_start` timestamp UTC.
- `samples` integer.
- `cpu_usage_sum`, `cpu_iowait_sum`, `mem_used_pct_sum`,
  `disk_used_pct_sum`, `load1_sum`, `net_rx_bps_sum`, `net_tx_bps_sum`.
- `cpu_usage_min`, `cpu_usage_max`, `mem_used_pct_min`, `mem_used_pct_max`,
  `disk_used_pct_min`, `disk_used_pct_max`, `load1_max`.
- `created_at`, `updated_at`.
- Unique: `(server_id, resolution_seconds, bucket_start)`.
- Index: `(server_id, resolution_seconds, bucket_start desc)`.

Rata-rata dihitung dari `sum / samples`, bukan average-of-averages. Rollup
`1h` dibangun dari `5m`; `1d` dari `1h`; bucket terakhir boleh dihitung ulang.

### 7.7 `alerts`

- `id` ULID primary key.
- `server_id` ULID FK cascade.
- `rule_key` string (mis. `cpu_high`, `server_offline`, `smart_failed`).
- `severity` string: `warning`, `critical`.
- `status` string: `open`, `acknowledged`, `resolved`.
- `active_key` string nullable, **unique**; berisi `server_id:rule_key` saat
  open/acknowledged, `NULL` saat resolved. Unique nullable bekerja di SQLite
  dan PostgreSQL sehingga hanya satu alert aktif per rule/server.
- `title`, `message` string.
- `context_json` JSON nullable (nilai pemicu, threshold, sample terakhir).
- `first_seen_at`, `last_seen_at`.
- `acknowledged_at`, `acknowledged_by` FK users nullable.
- `resolved_at`, `resolved_by` FK users nullable, `resolution_note` nullable.
- `notification_count` integer default 0 (agregat untuk UI).
- `last_notified_at` nullable (keberhasilan terakhir di channel mana pun).
- timestamps.
- Index: `(server_id, status)`, `(status, severity, last_seen_at)`,
  `last_seen_at`.

### 7.8 `alert_deliveries`

Satu baris per alert per channel per event; sumber kebenaran untuk throttle,
retry, dan status recovery. `notification_count`/`last_notified_at` di `alerts`
hanya ringkasan UI.

- `id` bigint primary key.
- `alert_id` ULID FK cascade.
- `channel` string: `email`, `telegram`.
- `event` string: `opened`, `resolved`; default `opened`.
- `status` string: `pending`, `sent`, `failed`, `skipped`.
- `recipient_count` integer default 0.
- `attempts` tinyint default 0.
- `last_attempt_at`, `sent_at`, `next_retry_at` nullable.
- `error` string nullable, maksimum 500 karakter, sudah diredaksi; tidak boleh
  berisi token bot, URL Telegram lengkap, alamat email lengkap, atau query text.
- timestamps.
- Unique: `(alert_id, channel, event)`.
- Index: `(channel, status, next_retry_at)`.

Reminder tidak membuat baris baru; reminder memperbarui baris `event=opened`.
Recovery memakai baris terpisah `event=resolved`, sehingga keberhasilan
notifikasi awal dan recovery bisa dilihat per channel.

### 7.9 `settings`

- `key` string primary key.
- `value` text; terenkripsi dengan `Crypt` jika `is_secret = true`.
- `type` string (`int`, `float`, `bool`, `string`, `secret`).
- `is_secret` boolean default false. Nilai secret tidak pernah dikirim ke
  browser setelah disimpan; UI hanya menampilkan status “Configured” dan
  tombol replace/clear.
- `updated_by` FK users nullable.
- timestamps.
- Dipakai untuk threshold alert dan konfigurasi channel yang bisa diubah
  admin. Default ada di `config/alerts.php`; database menyimpan override.

### 7.10 `audit_events`

- `id` bigint primary key.
- `user_id` nullable FK.
- `action` string (mis. `server.created`, `token.revoked`, `auth.login`,
  `alert.acknowledged`, `user.role_changed`).
- `target_type`, `target_id` nullable.
- `ip`, `user_agent` nullable.
- `meta_json` JSON nullable (redacted).
- `created_at`.
- Index: `created_at`, `user_id`, `(target_type, target_id)`.
- Append-only; tidak ada update/delete dari UI.

### 7.11 Retensi

- `metric_samples`: 14 hari.
- `metric_details`: 7 hari.
- `metric_rollups` `5m`: 30 hari.
- `metric_rollups` `1h`: 365 hari.
- `metric_rollups` `1d`: 730 hari.
- `alerts` resolved: 365 hari.
- `alert_deliveries`: 90 hari.
- `audit_events`: 365 hari.
- `failed_jobs`: 30 hari.
- Token revoked/expired: metadata disimpan 90 hari.

Pruning dilakukan bertahap (batch 5.000–10.000 baris) saat traffic rendah,
bukan satu `DELETE` besar. `metric_details` dipangkas lebih dulu.

## 8. Alur ingest

`POST /api/v1/ingest`

1. Middleware memastikan `Content-Type: application/json`, body ≤ 65.536 byte,
   dan Bearer token ada. Body chunked dibaca maksimal 65.537 byte.
2. Token di-hash HMAC-SHA256 dengan `APP_KEY`, dicari di `server_tokens` yang
   belum revoked/expired dan server `is_active`. Jika tidak ada: `401` generik,
   log prefix token saja.
3. Rate limit: 30 request/menit per token, 60/menit untuk failed auth per IP,
   ceiling global 1.000/menit. Melebihi: `429` + `Retry-After`.
4. Validasi schema-v1:
   - `schema_version` harus `1`.
   - `agent` wajib: `id`, `hostname`, `panel`, `collected_at`, `interval_seconds`
     (10–3600).
   - `metrics` wajib dan numerik; `panel_metrics`/`extensions` flat map scalar,
     maksimum 128 key, string maksimum 1024 byte.
   - JSON depth maksimum 8. Field masa depan yang tidak dikenal diabaikan.
   - Tolak `422` jika bentuk data salah. Jangan pernah `eval`/menjalankan isi
     payload.
5. `collected_at` di-parse UTC. Jika menyimpang lebih dari 10 menit dari waktu
   server, clamp ke `now ± 10 menit`; `received_at` tetap waktu sebenarnya.
6. Dalam satu transaksi singkat:
   - `insertOrIgnore` `metric_samples` dengan kunci `(server_id,
     collected_at)`;
   - jika baris baru, insert `metric_details`;
   - update `servers` (hostname, panel, OS, kernel, agent version, interval,
     latest metrics, `last_seen_at`, `last_sample_at`, `stale_after`,
     `offline_after`, `health`, `issue_codes`);
   - update `server_tokens.last_used_at` hanya jika lebih lama dari 1 menit.
7. Setelah commit, evaluasi alert rules untuk server tersebut. Kegagalan
   evaluasi/email dicatat di log dan **tidak** mengubah respons ingest sukses.
8. Respons `202 {"status":"accepted"}`. Retry agent dengan payload sama tidak
   menambah baris (idempotent) dan tetap `202`; `last_seen_at` tetap diperbarui.
9. `400` invalid JSON, `401` token salah, `403` server nonaktif, `413` terlalu
   besar, `422` schema salah, `429` rate limit, `5xx` kegagalan server.

### 8.1 Token lifecycle

- **Add Server**: buat `servers` + `server_tokens`; token plaintext disimpan
  sebentar di session flash, lalu redirect ke halaman reveal `no-store`.
  Jangan pernah menaruh plaintext di public property Livewire (Livewire
  menyerialkan state ke HTML).
- **Rotate**: buat token baru; token lama tetap valid selama grace period
  default 24 jam (configurable), dengan countdown di UI. Admin bisa revoke
  segera. Tampilkan token baru sekali.
- **Revoke**: set `revoked_at`; agent langsung `401`.
- **Delete Server**: revoke semua token, soft delete server, metrik historis
  dibiarkan sampai retensi.
- Prefix token (8 karakter) ditampilkan di UI/log; token penuh tidak.

### 8.2 Explicit registration

Token valid harus sudah terhubung ke server. Ingest **tidak** membuat server
baru dari token arbitrer. Server dibuat hanya lewat UI/artisan. Ingest boleh
memperbarui hostname, panel, OS, kernel, agent version, dan interval.

## 9. Alerting

### 9.1 Rule engine

`AlertEngine` menerima server + sample terbaru, menghitung rule yang aktif,
lalu membuka/menutup alert. Threshold default di `config/alerts.php`, override
admin di tabel `settings`.

Rule MVP:

| Rule | Severity | Kondisi |
| --- | --- | --- |
| `server_offline` | critical | `now > offline_after` |
| `server_stale` | warning | `now > stale_after` dan belum offline |
| `cpu_high` | warning/critical | CPU ≥ 90 / ≥ 95 selama 3 sample berturut |
| `memory_high` | warning/critical | RAM ≥ 90 / ≥ 95 |
| `disk_high` | warning/critical | disk root ≥ 85 / ≥ 95 |
| `inode_high` | warning/critical | inode ≥ 85 / ≥ 95 |
| `load_high` | warning/critical | load1 > cores / > 2 × cores selama 3 sample |
| `smart_failed` | critical | `smart.status = failed` |
| `smart_warning` | warning | `smart.status = warning` atau realloc/pending > 0 |
| `service_down` | critical | service installed+enabled tetapi running = 0 |
| `mysql_unreachable` | critical | `mysql.available = 0` |
| `quota_over` | warning | `diskquota.over > 0` |
| `module_cache_stale` | warning | SMART > 45d, diskquota > 48h, Imunify > 30m, MySQL > 3m |
| `security_disabled` | warning | CSF/ModSecurity terdeteksi disabled (jika field ada); **opt-in**, default nonaktif |

Default rule: `service_down` aktif karena service yang di-enable tetapi mati
biasanya masalah nyata. `security_disabled` default nonaktif karena CSF atau
ModSecurity kadang sengaja dimatikan; admin mengaktifkannya di
`/settings/alerts` (`rules.security_disabled.enabled`).

Aturan detail:

- `server_offline`/`server_stale` dievaluasi scheduler tiap menit, bukan hanya
  saat ingest (server yang mati tidak mengirim data). Saat server bergerak dari
  `stale` ke `offline`, alert stale ditutup **tanpa recovery** dan alert
  offline dibuka; recovery hanya dikirim ketika server kembali online.
- `cpu_high`/`load_high` memakai 3 sample terakhir; query dibatasi dan
  ter-index.
- Rule yang tidak punya data (module belum pernah jalan) tidak dianggap nol;
  tidak ada alert sampai data benar-benar ada.
- Server dengan `alerts_enabled = false` dilewati oleh `AlertEngine` dan
  scheduler liveness; ingest, penyimpanan sample, health, dan issue detection
  tetap berjalan.
- Alert baru membuat `active_key`; alert yang kondisinya hilang di-resolve
  otomatis. Acknowledge tidak menutup alert, tetapi menghentikan reminder.
- Notifikasi keluar dikirim saat alert dibuka/reopen dan satu pesan recovery
  saat resolved. Reminder maksimal tiap 6 jam hanya selama alert berstatus
  `open`; alert `acknowledged` tidak di-remind lagi. Throttle dihitung
  **per channel** lewat `alert_deliveries`, sehingga kegagalan Telegram tidak
  menahan email dan sebaliknya.
- Alert resolved tetap disimpan untuk histori.

### 9.2 Channel

**In-app (wajib).** Alert adalah baris `alerts` itu sendiri; bell di topbar dan
halaman `/alerts` selalu tersedia. Channel ini tidak bisa dimatikan dari
settings, tidak memakai queue, dan tetap bekerja walau SMTP/Telegram gagal.

**Email via SMTP (wajib dikonfigurasi).** Laravel Notification melalui queue,
memakai `MAIL_*` di `.env` (`MAIL_MAILER=smtp`, host, port, username, password,
encryption, from). Penerima adalah user aktif dengan `notify_email=1` dan role
admin/operator. Email berisi server, rule, nilai pemicu, waktu, dan tautan ke
alert; versi HTML dan plain-text; semua nilai di-escape. Email tidak pernah
berisi token agent, bot token Telegram, atau MySQL query text.

**Recovery (email/Telegram).** Saat alert resolved, kirim satu pesan yang
menyebut server, rule, waktu pulih, dan durasi alert. Penerima email sama
dengan alert awal; Telegram memakai chat/topic yang sama. Recovery tidak
dikirim ulang untuk resolve berulang.

**Telegram (opsional).** Admin mengaktifkan di `/settings/alerts` dan mengisi:

- `bot_token` — secret, disimpan terenkripsi, ditampilkan sebagai “Configured”.
- `chat_id` — ID chat/grup (`-100...`) atau `@channelusername`.
- `message_thread_id` — opsional, bilangan bulat positif untuk topik forum.
  Jika kosong, field tidak dikirim sama sekali.
- `enabled` — default `false`.

Pengiriman memakai `POST https://api.telegram.org/bot{token}/sendMessage`
dengan `chat_id`, `text`, `parse_mode=HTML`,
`disable_web_page_preview=true`, dan `message_thread_id` bila diisi. Karena
token berada di path URL, URL lengkap tidak boleh masuk log, exception message,
audit, atau UI; error disimpan sebagai status code + pesan yang sudah
diredaksi. Host Telegram bersifat tetap (tidak ada URL dari user), timeout
connect 5 detik dan total 10 detik, dan `retry_after` dari respons `429`
dihormati. Satu target global per instalasi; binding per user ditunda.

**Webhook/Slack** belum MVP; interface `AlertChannel` disiapkan supaya
penambahan channel tidak mengubah rule engine.

### 9.3 Alert lifecycle

- `open` → `acknowledged` (operator/admin, audit) → `resolved` otomatis saat
  kondisi hilang, atau manual oleh operator/admin dengan catatan.
- Revoke/rotate token tidak menutup alert server; delete server menutup semua
  alert aktif **tanpa mengirim recovery** karena penutupan bersifat
  administratif, bukan pemulihan kondisi.
- Alert untuk server nonaktif tidak dibuat.
- Menonaktifkan alert per server (`alerts_enabled = false`) menutup semua alert
  aktif server itu secara administratif: tanpa recovery email/Telegram, dengan
  catatan “Alerts disabled for this server”, dan audit mencatat jumlah alert
  yang ditutup. Mengaktifkan kembali tidak membuka alert lama secara
  retroaktif; alert baru dibuat pada evaluasi berikutnya.
- Acknowledge menghentikan reminder email/Telegram sampai alert resolved;
  alert tetap terlihat sebagai acknowledged dan health server tetap mengikuti
  severity-nya.
- Saat transisi ke resolved, kirim tepat satu pesan recovery per channel yang
  aktif. Resolve berulang atau retry tidak boleh menggandakan pesan recovery.
- Resolve manual menghentikan semua reminder berikutnya; resolve otomatis
  terjadi saat rule tidak lagi terpenuhi.

### 9.4 Delivery, retry, dan test

- Saat alert dibuka, buat `alert_deliveries` `event=opened` untuk channel yang
  aktif lalu dispatch job setelah commit (`afterCommit`). Ingest tidak menunggu
  job.
- Job `SendAlertNotification` idempotent per `(alert_id, channel, event)`,
  retry 3 kali dengan backoff 30/120/300 detik, dan menulis status
  `sent`/`failed` + `next_retry_at`. Kegagalan permanen tidak mengubah status
  alert.
- Scheduler tiap 5 menit mengirim reminder hanya untuk alert berstatus `open`
  yang baris `opened`-nya belum sukses atau sudah lewat 6 jam, dan memproses
  `next_retry_at` yang sudah jatuh tempo. Alert `acknowledged` dilewati.
- Saat alert resolved, buat baris `event=resolved` dan dispatch satu pesan
  recovery, tetapi hanya untuk channel yang sudah punya baris `opened`.
  Channel yang baru diaktifkan setelah alert dibuka tidak dikirimi recovery.
  Baris recovery mengikuti retry/backoff yang sama dan tidak dibuat ulang jika
  sudah ada.
- Jika queue shared hosting belum berjalan, notifikasi tertunda sampai cron
  berikutnya; in-app tetap real-time.
- Tombol **Send test** di `/settings/alerts` mengirim pesan uji ke email admin
  yang meminta dan/atau chat Telegram terkonfigurasi. Aksi ini admin-only,
  rate-limited, dan dicatat sebagai `alert_channel.tested`.

## 10. Status dan issue

Status server dihitung dari clock dashboard (`received_at`), bukan clock agent:

- `never_seen`: `last_seen_at` null.
- `online`: `now <= stale_after`.
- `stale`: `stale_after < now <= offline_after`.
- `offline`: `now > offline_after`.

Dengan `interval_seconds = 60`:

- `stale_after = last_seen_at + max(2.5 × interval, 150 detik)`.
- `offline_after = last_seen_at + max(5 × interval, 300 detik)`.

`health` server adalah agregat issue: `critical` jika ada alert critical,
`warning` jika ada warning, selain itu `ok`; `unknown` jika belum ada sample.

Module freshness ditampilkan terpisah karena server bisa online sementara cache
SMART sudah 34 hari:

| Module | Aging | Stale |
| --- | --- | --- |
| SMART | 35 hari | 45 hari |
| Disk quota | 26 jam | 48 jam |
| Imunify | 15 menit | 30 menit |
| MySQL | 3 menit | 3 menit |

Field module yang tidak ada ditampilkan “Not reported”, bukan nol.

## 11. Query dan rollup

- Chart 1 jam / 6 jam / 24 jam: baca `metric_samples` mentah dengan index
  `(server_id, collected_at desc)`.
- Chart 7 hari: rollup `5m`.
- Chart 30 hari / 90 hari: rollup `1h`.
- Chart 1 tahun: rollup `1d`.
- Rollup dihitung scheduler setiap 5 menit untuk bucket yang sudah selesai:
  - `5m` dari `metric_samples`;
  - `1h` dari `5m`;
  - `1d` dari `1h`.
- Agregasi di PHP; volume per bucket kecil dan portabel.
- `upsert` idempotent berdasarkan `(server_id, resolution_seconds,
  bucket_start)`.
- Scheduler memakai `withoutOverlapping()` dan cache lock; kegagalan rollup
  tidak menggagalkan ingest.
- Chart data di-downsample maksimal 240 titik untuk 1h–24h dan 1.000 titik
  untuk range panjang; gap data tidak diisi nol.

## 12. Halaman dan UX

**UI copy is English only.** Semua label, empty state, error, dan email
menggunakan bahasa Inggris; format tanggal mengikuti locale user, waktu
ditampilkan Asia/Jakarta.

### 12.1 Peta halaman

| Route | Screen |
| --- | --- |
| `/login` | Sign in; rate-limited; generic errors. |
| `/` | Redirect to `/dashboard`. |
| `/dashboard` | Fleet overview, summary cards, condensed server table, recent alerts. |
| `/servers` | Full server list, filters, search, pagination. |
| `/servers/create` | Two-step add-server wizard + one-time token reveal. |
| `/servers/{server}` | Server detail: overview, performance, panel, modules, history, alerts. |
| `/servers/{server}/edit` | Edit metadata (name, hostname, type, provider, location, IP, label), interval, alerts on/off, notes. |
| `/servers/{server}/tokens` | Active/revoked tokens, rotate, revoke. |
| `/alerts` | Alert center, filters, acknowledge/resolve. |
| `/alerts/{alert}` | Alert detail, context, linked server, activity. |
| `/activity` | Audit log (admin/operator). |
| `/settings/profile` | Profile, password, 2FA, notification preference. |
| `/settings/users` | User list, invite/create, role, deactivate (admin). |
| `/settings/alerts` | Alert thresholds; SMTP status; Telegram bot/chat/topic; Send test (admin). |

### 12.2 Dashboard overview

- Four summary cards, each a link that applies a table filter:
  - **Online** `x/y`.
  - **Needs attention** (open critical/warning).
  - **Hot resources** (CPU/RAM/disk ≥ 90%).
  - **Data gaps** (stale/offline/never seen).
- Server table columns: status, server (name, hostname, type, label, IP),
  panel/version, CPU %, RAM %, disk %, network RX/TX, load, uptime, last seen,
  issue badges. Provider dan location tampil di detail, bukan kolom tabel.
- Default sort: severity, then hostname. Sortable numeric columns.
- Filters as chips with counts: All / Online / Stale / Offline / Never seen;
  type, provider, location, panel, “problems only”; search by name, hostname,
  IP, or label. State persisted in URL.
- Sparklines: server-rendered inline SVG from 24 hourly points (CPU + disk),
  no JS, `role="img"` with text summary. Do not instantiate 25 charts.
- Poll `wire:poll.30s.visible`; cache fleet summary 20 seconds.

### 12.3 Server detail

Sticky header: name, hostname, type, provider/location, IP, label, status pill,
last seen, agent version, panel, actions (Rotate token, Edit, View stored
snapshot).

KPI tiles: CPU, RAM, disk, load, network, processes, uptime.

Tabs (state in `?tab=`):

1. **Overview** — KPI + CPU/RAM/disk charts 1h.
2. **Performance** — CPU + iowait, load vs cores, RAM/swap, disk + inode,
   network RX/TX, processes. Ranges 1h/6h/24h/7d/30d/90d/1y.
3. **Panel** — cPanel facts, security card, services table, unknown keys in
   “Other metrics”. Raw JSON only in Stored Snapshot (admin).
4. **Modules** — SMART disk table (health, reallocated, pending, power-on
   hours, written GB), quota top users, Imunify scan status, MySQL queries.
   Each section shows cache age and “Not installed / skipped / Not reported”.
5. **Alerts** — open/resolved alerts for this server.
6. **History** — audit events for this server/tokens.

Charts: Chart.js 4.6, bundled locally, lazy-loaded on detail only; break lines
when gap > 2.5× interval; label “Data ends 14m ago” on offline servers; text
summary + “View data table” for accessibility.

### 12.4 Add server and token

Step 1 — tiga bagian:

- **Identity**: name (unique, required), hostname (required), primary IP
  (optional, IPv4/IPv6), label data center/billing (optional).
- **Placement**: type (free text dengan saran nilai yang sudah ada; bukan
  enum), provider (optional, free text), location (optional, free text).
- **Collection**: check-in interval (default 60, drives thresholds), alerts
  on/off (default on), notes (optional). Panel = auto.

Step 2: generate token, store only hash + prefix, flash plaintext to session,
redirect to one-time `no-store` reveal page. Show:

- “This token is shown only once.”
- Copy button and install commands.
- Required checkbox “I stored this token” before leaving.
- “Waiting for first check-in” screen polls every 5s, stops after 10 minutes,
  and offers troubleshooting (`systemctl status noc-agent.timer`,
  `journalctl -u noc-agent.service -n 50`).

Rotation: new token works immediately; old token grace 24h by default with
countdown; immediate revoke available. Revocation requires typing the server
name. Never re-display plaintext.

### 12.5 Alerts center

- List with severity, status, server, rule, first seen, last seen, value.
- Filters: severity, status, server, date.
- Actions: acknowledge, resolve (with note), open server.
- Empty state: “No active alerts”.
- Alert detail shows context JSON (safe fields only), timeline, and delivery
  status per channel and event (`Opened`, `Recovery`) with attempt count and a
  redacted error. “Send test” lives in alert settings, not on each alert.
- In-app is always shown as delivered; email/Telegram can be `Pending`,
  `Sent`, `Failed`, or `Not configured` independently.

### 12.6 Visual system

- Operate mode: precision and restraint, not decorative.
- 240px sidebar, 1440px content max, 8px spacing scale, 10px card radius,
  1px borders, minimal shadow.
- Inter self-hosted at 14/20; tables 13px; labels 11px uppercase; tabular
  numerals; monospace for hostnames/tokens/raw.
- Status colors (text/dot): ok `#047857`/`#10b981`, warning
  `#b45309`/`#f59e0b`, critical `#be123c`/`#f43f5e`, unknown
  `#64748b`/`#94a3b8`, accent `#2563eb`.
- Dark: backgrounds `#0b1120`/`#111827`, borders `#1f2937`, lighter status
  text. Dark mode default; light mode available; persisted toggle; no flash.
- Chart palette: CPU blue, RAM violet, disk amber, network cyan; max four
  series; no dual axes; units in ticks.
- Breakpoints 640/768/1024/1280: table becomes cards below 768; tabs scroll;
  sidebar slide-over; 44px targets.
- Accessibility: skip link, landmarks, visible 2px focus rings, `aria-sort`,
  modal focus trap, errors via `aria-describedby`, charts with text summary,
  polite live region only for explicit refresh, reduced motion.
- Loading: skeleton cards/rows/chart frames after 300ms; keep previous data
  during polls with a thin progress bar; per-component error with retry; no
  full-page spinner.

### 12.7 Frontend asset pipeline (no Node on the server)

| Layer | Choice | Notes |
| --- | --- | --- |
| Templating | Blade | Server-rendered; no hydration, no SPA router. |
| Interactivity | Livewire 4 | Full-page components via `Route::livewire`; network round-trip per action; progressive enhancement. |
| Micro-interactions | Alpine.js 3 | Menus, modals, copy, theme toggle, chart init. Shipped with Livewire. |
| CSS | Tailwind CSS 4 (Vite plugin) | JIT compile at build time; purged output, typically < 40 KB gzip. |
| Bundler | Vite 8 | Dev-only and CI-only; produces hashed files in `public/build`. |
| Charts | Chart.js 4.6 | Imported through Vite, lazy-loaded via dynamic `import()` on the detail page only. |
| Icons | Inline SVG sprite | No icon-font CDN, no runtime fetch. |
| Fonts | Inter, self-hosted `.woff2` | `font-display: swap`, preload one weight; no Google Fonts. |

Rules:

1. `npm`/`node` never run on production. Deploy ships `public/build` as static
   files; the `@vite` directive only reads `manifest.json`.
2. No CDN `<script>` or `<link>` tags. Everything is same-origin so CSP can
   stay strict and the dashboard still works if a third party is down.
3. No inline `<script>` blocks except the theme bootstrap and a CSP nonce.
   Livewire/Alpine are bundled, not remote-loaded.
4. `package.json` and `composer.json` lockfiles are committed; dependency
   updates are a deliberate change, not a deploy side effect.
5. A CI check builds assets from a clean checkout and fails if `public/build`
   is missing from the release artifact or the manifest references a missing
   file.
6. Alpine components follow the CSP build if a nonce-based CSP cannot cover
   `unsafe-eval`; verify in the browser before release.
7. Livewire 4 mengubah semantik `wire:model`: event dari child tidak lagi
   didengar default (gunakan `.deep` bila perlu) dan `.blur`/`.change` mengatur
   sinkronisasi client. Gunakan `wire:model.live.blur` bila perilaku Livewire 3
   dibutuhkan; input form standar tidak terpengaruh.
8. Laravel 13 memakai `cache.serializable_classes = false`. Jangan menyimpan
   object di cache; simpan scalar/array, atau allow-list class secara eksplisit.
9. `Route::livewire()` dipakai untuk semua full-page component; jangan
   `Route::get()` ke class component.

This keeps the "modern stack" the user asked for while remaining deployable on
hosting that offers only PHP + MySQL/SQLite + cron.

## 13. Component boundaries

- **Ingest**: controller + `IngestPayload` service + token middleware. One
  short transaction; no HTTP/rollup inside.
- **`FleetSummary`**: one aggregate query over `servers`, cached 20s.
- **`ServerTable`**: one component, never per-row components. `withPagination`,
  `#[Url]` filters, `wire:poll.30s.visible`, eager-loaded relations, one
  batched sparkline query for visible IDs, `wire:key` per row, 25 per page.
- **Server detail**: `ServerHeader` (poll 15s), `IssueList`, `MetricCards`
  (30s), `ChartPanel` (Alpine + fetch `/servers/{id}/series`; no Livewire poll
  so zoom/tooltips are not disturbed), lazy tabs (`#[Lazy]`),
  `StoredSnapshotTab` (admin, on-demand).
- **`Create`**, **`Tokens`**, **`Alerts\Index`**, **`Alerts\Show`**,
  **`Servers\Edit`**: actions only; plaintext token leaves via session
  flash + redirect.
- **`AlertBell`**, **`Alerts\Index`**, **`Alerts\Show`**.
- **`Settings\Users`**, **`Settings\UserForm`**, **`Settings\Profile`**,
  **`Settings\AlertSettings`**.
- **Alpine** only for ephemeral UI: menus, modals, copy, theme, chart init.
  Business logic stays in services so it is testable without the browser.
- **Queries**: `samples(server_id, collected_at)` indexed; store epochs as
  integers and bucket with `collected_at / 300`. Keep raw 14 days, details 7
  days, rollups as §7.11. Cap returned points. `paginate(25)`; cursor
  pagination only if servers exceed a few thousand.

## 14. Security

- HTTPS wajib; `APP_ENV=production`, `APP_DEBUG=false`, HSTS.
- Auth: Fortify, Argon2id, login rate limit 5/menit, reset 3/menit, pesan
  generik. TOTP 2FA tersedia; admin dianjurkan mengaktifkan.
- Tidak ada registrasi publik; admin pertama via artisan. User management hanya
  admin.
- Roles:
  - **admin**: semua, termasuk user, settings, token, stored snapshot.
  - **operator**: server/token, acknowledge/resolve alert, audit read; tanpa
    user/settings.
  - **viewer**: read-only; tanpa token, settings, stored snapshot, atau query
    text.
- Policies/Gates di setiap aksi; jangan hanya menyembunyikan tombol.
- Token agent: 32 byte acak, HMAC-SHA256 dengan `APP_KEY`, unique index,
  prefix 8 karakter, tampil sekali, revoke/rotate, `last_used_at` throttled.
  Rotasi APP_KEY membatalkan semua token; dokumentasikan.
- Ingest: stateless Bearer auth, CSRF tidak berlaku; rate limit per token/IP;
  validasi ukuran/shape; tidak ada `eval`; payload mentah penuh tidak
  disimpan.
- Dashboard: CSRF pada semua route session; Blade escaping (`{{ }}`);
  dilarang `{!! !!}` untuk data agent; validasi Livewire di server.
- SQL injection: Eloquent/query builder dan binding; tidak ada string SQL dari
  input; whitelist kolom sort/filter.
- XSS: key/value agent di-escape; JSON ditampilkan sebagai teks, bukan HTML.
- CSP: nonce-based; script hanya dari bundle sendiri; hindari CDN. Jika
  Livewire/Alpine membutuhkan pengecualian, dokumentasikan dan batasi hanya
  yang perlu; uji sebelum rilis.
- File permission: `.env` 600, SQLite 640/750, `storage` dan `bootstrap/cache`
  writable hanya oleh PHP user; database di luar `public/`.
- Audit log: login, logout, gagal login, create/update/delete server, token
  create/rotate/revoke, alert acknowledge/resolve, perubahan user/role,
  perubahan settings.
- Data sensitif: `mysql.query.*.info` dapat berisi query; default
  `MYSQL_INCLUDE_QUERY_TEXT=0`, tampilkan hanya untuk admin, jangan log, dan
  pertimbangkan redaksi literal.
- Email alert: escape semua nilai, jangan kirim token/query text, hormati
  preferensi user, dan throttle.
- Secret settings: `bot_token` Telegram disimpan dengan `Crypt` (APP_KEY) dan
  tidak pernah dikembalikan ke browser setelah disimpan. Rotasi APP_KEY
  membuat secret tidak terbaca; dokumentasikan dan sediakan alur isi ulang.
- Telegram: host API tetap, tidak ada URL dari user. Token ada di path URL,
  jadi URL lengkap, header, dan body request tidak boleh masuk log atau pesan
  exception; simpan hanya status code dan pesan yang diredaksi. Hormati
  `retry_after`. Jangan tampilkan chat ID penuh di UI publik; mask sebagian.
- Test notification: admin-only, rate-limited (mis. 3/menit), dicatat di audit,
  dan tidak menerima tujuan arbitrer dari user.
- Delivery log: `error` dibatasi 500 karakter dan tidak memuat alamat email
  lengkap, token, atau query text.
- Backup: `.backup` SQLite, terenkripsi, disimpan di luar server; uji restore.
- Dependency: `composer audit` dan update rutin; lockfile committed.
- Error: halaman error generik; detail hanya di log server.

## 15. Deployment

### 15.1 Shared hosting

- Document root diarahkan ke `public/`; `app/`, `storage/`, `.env`, dan SQLite
  di luar document root.
- **Tidak perlu Node/npm di server.** Artefak `public/build` (JS, CSS, font,
  `manifest.json`) di-build di lokal/CI dan diunggah bersama rilis. Deploy
  memverifikasi manifest ada; jika tidak ada, deploy gagal dengan pesan jelas.
- PHP 8.3/8.4 dengan ekstensi: `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`,
  `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `curl`, `intl`.
- SQLite di `~/noc-data/noc.sqlite`; direktori 750, file 640; bukan NFS.
- `DB_BUSY_TIMEOUT=5000`, `DB_JOURNAL_MODE=wal`, `DB_SYNCHRONOUS=normal`,
  `DB_TRANSACTION_MODE=IMMEDIATE`. Laravel 13 memakai `BEGIN IMMEDIATE` hanya
  pada PHP 8.4+; di PHP 8.3 transaksi tetap `DEFERRED`. Uji contention di
  kedua versi, jangan mengandalkan `IMMEDIATE` di PHP 8.3.
- `CACHE_STORE=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=database`
  (fallback tanpa Redis). Jika tersedia Redis, pakai Redis.
- Opsional: `runtime.sqlite` terpisah untuk cache/session/queue jika host
  mengizinkan dan terjadi contention; bukan syarat MVP.
- Cron tiap menit:
  `* * * * * cd ~/noc/current && php artisan schedule:run >> /dev/null 2>&1`.
- Scheduler menjalankan `queue:work --stop-when-empty --max-time=50 --tries=3`
  setiap menit, dengan `withoutOverlapping()`.
- `php artisan config:cache route:cache view:cache` setelah deploy.
- OPcache aktif; `APP_DEBUG=false`; log harian.
- Deployment berbasis release directory + symlink `current`; downtime singkat
  saat migrate dapat diterima dan diumumkan.
- Email memakai SMTP hosting (`MAIL_*` di `.env`, kredensial tidak di
  database). Shared hosting harus mengizinkan outbound TCP ke port SMTP dan
  HTTPS ke `api.telegram.org` bila Telegram dipakai.
- Jika email/Telegram gagal atau queue belum jalan, alert in-app tetap ada dan
  status pengiriman terlihat di detail alert.

### 15.2 VPS kecil

- Ubuntu 24.04/Debian 12, Nginx + PHP-FPM 8.4 (minimum 8.3).
- Redis untuk cache/session/queue; SQLite di SSD lokal.
- 1–2 worker `queue:work` via systemd (`Restart=always`, `MemoryMax=512M`).
- Scheduler via systemd timer atau cron.
- Firewall: hanya 22 (key-only), 80, 443; fail2ban untuk SSH/login.
- Backup harian `sqlite3 noc.sqlite ".backup ..."` + restic/borg terenkripsi
  ke offsite; retensi 7 harian, 4 mingguan, 12 bulanan.
- Logrotate 14 hari; monitoring eksternal `/up` dan dead-man check jika
  `max(last_seen_at)` lebih tua dari 3× interval.
- TLS via Let's Encrypt/Certbot; reload Nginx setelah renew.

### 15.3 Queue dan scheduler

- Ingest tidak memakai queue.
- Queue untuk email/Telegram alert, rollup, pruning, dan tugas terjadwal lain.
- Semua job idempotent, punya timeout, dan aman diulang.
- Shared hosting: job harus selesai < 50 detik; jika tidak, pecah per batch.
- VPS: worker systemd, `queue:restart` setelah deploy.

## 16. Migrasi ke PostgreSQL/TimescaleDB

Prinsip portabilitas:

- Gunakan ULID/UUID untuk domain, bukan auto-increment yang diandalkan di URL.
- Gunakan kolom `json` Laravel (SQLite text, Postgres JSONB).
- Jangan memakai SQL SQLite-specific di aplikasi; untuk bucket waktu, agregasi
  di PHP.
- Jangan mengandalkan `INSERT OR REPLACE`; gunakan `insertOrIgnore`/`upsert`.
- Semua timestamp UTC; Postgres `timestamptz`.
- Index dan unique constraint didefinisikan di migration, bukan manual.
- Pisahkan query metrik di repository/service agar mudah dioptimalkan.

Langkah migrasi:

1. Siapkan PostgreSQL, jalankan migration yang sama.
2. Ekspor data SQLite (pgloader atau command `noc:export`/`noc:import`).
3. Untuk TimescaleDB, ubah `metric_samples` menjadi hypertable pada
   `collected_at`, partition by `server_id` jika perlu.
4. Ganti rollup bertahap dengan continuous aggregate; pertahankan tabel rollup
   sebagai fallback.
5. Aktifkan `DB_CONNECTION=pgsql`, uji ingest, chart, alert, retensi, backup.
6. Rollback: kembalikan koneksi SQLite dan hentikan writer Postgres.

Ambang pindah: sekitar 300 server pada 15 detik, 50 juta baris raw, atau
p95 write > 50 ms. Sebelum itu, SQLite + rollup cukup.

## 17. Testing dan acceptance

### 17.1 Backend

- Feature test ingest: valid token → 202 + baris; token salah → 401; JSON
  invalid → 400; body > 64 KiB → 413; schema invalid → 422; rate limit → 429;
  server nonaktif → 403.
- Idempotensi: payload sama dua kali → satu sample, satu detail, dua 202;
  `last_seen_at` tetap maju.
- Token: generate, hash, prefix, revoke, rotate, grace, expired.
- Server: create/update/delete, soft delete, token revoke.
- Alert engine: setiap rule, dedup `active_key`, acknowledge, auto-resolve,
  throttle per channel, offline scheduler.
- Delivery: satu baris `alert_deliveries` per alert/channel/event; email hanya
  ke user aktif dengan `notify_email=1`; Telegram hanya jika enabled dan
  terkonfigurasi; retry 3x; kegagalan satu channel tidak menghentikan channel
  lain.
- Reminder: alert `open` mendapat reminder setelah 6 jam; alert
  `acknowledged` tidak mendapat reminder.
- Recovery: resolve mengirim tepat satu baris `resolved` per channel aktif;
  resolve berulang tidak menggandakan; kegagalan recovery tercatat terpisah.
- Default rule: `service_down` aktif; `security_disabled` nonaktif sampai
  admin mengaktifkannya.
- Telegram payload: `chat_id` benar, `message_thread_id` dikirim hanya bila
  diisi, `parse_mode=HTML`, teks di-escape, `retry_after` dihormati saat 429,
  dan token/URL lengkap tidak pernah muncul di log/DB/response.
- Settings secret: nilai `is_secret` tersimpan ciphertext, tidak muncul di HTML
  setelah disimpan, dan UI hanya menampilkan “Configured”.
- Send test: admin-only, rate-limited, audit tercatat; viewer/operator ditolak.
- Roles: admin/operator/viewer policies; last admin guard; user deactivation.
- Rollup: bucket 5m/1h/1d, weighted average, min/max, late recompute, upsert.
- Retention: prune batch, data di luar retensi hilang, data dalam retensi aman.
- Auth: login, logout, rate limit, reset, 2FA.
- Audit: event tercatat, tidak bisa diedit dari UI.
- SQLite: WAL aktif, busy timeout, unique constraint bekerja.

### 17.2 Frontend

- Livewire test: render dashboard, filter, pagination, add server, token sekali
  tampil, rotate/revoke, detail server, alert acknowledge/resolve, user CRUD.
- Chart data: range memilih tabel yang benar; tidak ada N+1.
- Token plaintext tidak muncul di HTML setelah reveal.
- Aksesibilitas: label, focus, kontras, keyboard.
- Browser smoke test opsional di VPS dengan Playwright.

### 17.3 Deployment

- Install di shared hosting fixture: document root, SQLite path, cron,
  migrate, login, ingest dari agent mock.
- Backup dan restore SQLite ke direktori lain.
- Health `/up` 200; ingest 202; login 200.
- Load test ringan: 100 server virtual, 60 detik, tidak ada `database is
  locked`, p95 ingest < 200 ms pada VPS kecil.
- Security checklist: headers, cookie flags, token tidak muncul di log,
  `.env`/SQLite tidak dapat diakses via HTTP, role tidak bisa bypass.

### 17.4 Acceptance MVP

- Agent yang sudah ada bisa mengirim ke dashboard tanpa perubahan payload.
- Dashboard menampilkan server, status, metrik inti, panel/extensions.
- Chart 1h/24h/7d/30d bekerja.
- Tambah server menghasilkan token sekali tampil dan perintah install.
- Token rotate/revoke/grace berlaku.
- Alert offline, CPU/RAM/disk, SMART, service down, quota over tampil dan bisa
  di-acknowledge/resolve.
- Alert in-app selalu muncul, termasuk saat SMTP/Telegram gagal.
- Email alert terkirim via SMTP untuk user yang memilih, tanpa token/query text.
- Telegram opsional mengirim ke chat/topic yang dikonfigurasi; `message_thread_id`
  benar-benar diteruskan saat diisi dan tidak dikirim saat kosong.
- Status pengiriman email/Telegram terlihat per channel dan event (`Opened`,
  `Recovery`) di detail alert.
- Alert yang di-acknowledge berhenti di-remind; resolve mengirim satu recovery
  tanpa duplikasi.
- Admin dapat membuat user, mengganti role, menonaktifkan user; viewer tidak
  bisa mengakses aksi terlarang.
- Data lama terhapus sesuai retensi; backup/restore terbukti.
- Shared hosting tanpa Redis bisa menjalankan ingest + dashboard + scheduler.

## 18. Risiko

- **SQLite single writer**: ingest, queue, dan polling bisa saling menunggu.
  Mitigasi: transaksi pendek, WAL, busy_timeout, queue rendah, pruning batch.
- **Shared hosting cron tidak tepat waktu**: rollup/retensi/email/Telegram
  tertunda. Ingest dan in-app tetap real-time; UI menampilkan status pengiriman.
- **Polling Livewire membebani SQLite**: batasi interval, query ter-index,
  cache ringkasan, pagination, `wire:poll.visible`.
- **Token bocor**: hash + revoke + prefix log; plaintext hanya sekali dan tidak
  masuk public Livewire state.
- **Alert storm**: dedup `active_key`, cooldown 6 jam per channel, auto-resolve,
  batasi rule per server.
- **Email deliverability**: shared hosting SMTP bisa masuk spam; in-app tetap
  sumber kebenaran dan kegagalan terlihat di detail alert.
- **Telegram tidak tersedia**: bot diblokir, chat ID salah, atau topik dihapus.
  Pengiriman gagal dengan error teredaksi; email dan in-app tidak terpengaruh.
- **Kebocoran bot token Telegram**: token ada di URL API. Jangan log URL/body,
  simpan terenkripsi, dan sediakan rotate token di UI.
- **Query text MySQL sensitif**: default off, akses admin, redaksi.
- **CSP vs Livewire/Alpine**: perlu uji; jika perlu pengecualian, dokumentasikan.
- **Migrasi Postgres**: perbedaan tipe JSON/timestamp; uji di staging.
- **Backup SQLite saat write**: gunakan `.backup`, bukan copy file mentah.
- **Server clock salah**: status memakai `received_at`; `collected_at` di-clamp.
- **Retensi terlalu agresif**: data chart hilang; konfigurasi dan dokumentasi.

## 19. Keputusan

Keputusan berikut dikunci untuk MVP; yang ditunda masuk fase berikutnya.

| # | Keputusan | Alasan |
| --- | --- | --- |
| 1 | Alert channel: **in-app wajib**, **email via SMTP wajib**, **Telegram opsional** dengan `message_thread_id`. Webhook/Slack fase 2 lewat interface `AlertChannel`. | In-app selalu tersedia; email sudah cukup untuk operasi; Telegram memberi kanal instan tanpa biaya. |
| 2 | 2FA **opsional**, dianjurkan untuk admin; bisa diwajibkan lewat setting `security.require_2fa_admin`. | Tidak memblokir instalasi; tetap bisa diperketat. |
| 3 | Retensi: raw **14 hari**, details **7 hari**, 5m **30 hari**, 1h **365 hari**, 1d **730 hari**, `alert_deliveries` **90 hari**. | Chart panjang tetap ringan; detail sensitif cepat hilang. |
| 4 | **SQLite-only** untuk v1. Tidak ada dukungan MySQL backend. | Satu matriks dukungan; SQLite sudah cukup untuk 100 server. |
| 5 | Backup **manual/script** yang disediakan; tidak mengandalkan fitur hosting. | Shared hosting tidak seragam; backup harus bisa diuji. |
| 6 | Group server **free text** dengan datalist saran di v1. | Menghindari tabel CRUD tambahan; mudah dimigrasikan nanti. |
| 7 | Rotation grace **24 jam** (configurable), plus revoke segera. | Agent punya waktu diperbarui tanpa downtime panjang. |
| 8 | Threshold alert **global** di `settings`, tanpa override per server di v1. | Cukup untuk fleet homogen; override per server fase 2. |
| 9 | Telegram memakai **satu bot dan satu chat/topic global**; binding per user ditunda. | Operasi NOC biasanya punya satu grup/topic; menghindari OAuth dan data pribadi. |
| 10 | SMTP dikonfigurasi lewat `.env` (`MAIL_*`), bukan tabel settings. | Kredensial email tidak masuk database/backup; mengikuti standar Laravel. |
| 11 | Bot token Telegram disimpan **terenkripsi** di `settings`, bukan plaintext. | SQLite tidak punya enkripsi at-rest; `Crypt` mengurangi risiko backup bocor. |
| 12 | Acknowledge **menghentikan reminder** sampai alert resolved; resolve mengirim **satu pesan recovery** per channel. | Ack berarti operator sudah menangani; recovery menutup loop operasi. |
| 13 | Email alert dikirim ke **semua admin/operator aktif** dengan `notify_email=1`. | Satu distribusi operasional, tanpa daftar penerima terpisah. |
| 14 | Operator boleh **mengelola server dan token** serta acknowledge/resolve; user dan settings hanya admin. | Operasi harian tidak selalu menunggu admin. |
| 15 | `service_down` aktif default; `security_disabled` **opt-in**. | Service enabled yang mati biasanya nyata; CSF/ModSecurity bisa sengaja dimatikan. |
| 16 | Maintenance window dan eskalasi critical **ditunda ke fase 2**. | MVP tetap ringkas; ack manual sudah mencukupi. |

Ditunda secara sadar: webhook/Slack, binding Telegram per user, maintenance
window, eskalasi critical, public status page, managed groups, per-server
thresholds, mobile app, plugin panel lain, dan TimescaleDB.

## 20. Rencana implementasi (ringkas)

Fase 1 — fondasi backend:

- Scaffold Laravel, Fortify, roles, layout Blade/Livewire.
- Migration `users`, `servers`, `server_tokens`, `metric_samples`,
  `metric_details`, `metric_rollups`, `alerts`, `alert_deliveries`, `settings`,
  `audit_events`.
- Ingest API + token middleware + validasi + idempotensi.
- Test backend.

Fase 2 — alerting dan manajemen:

- AlertEngine, rules, offline scheduler, email SMTP + Telegram channels,
  delivery retry, alert center.
- Token lifecycle, user management, roles/policies, audit.
- Test alert/role/security.

Fase 3 — dashboard:

- Overview, server list, detail, chart, panel/extensions generic.
- Add server + token reveal + waiting screen.
- Test Livewire dan aksesibilitas.

Fase 4 — operasional dan migrasi:

- Rollup, retensi, queue, shared hosting/VPS docs, backup/restore.
- Security headers, rate limit, load test.
- Repository/query portabel, uji PostgreSQL/TimescaleDB.
