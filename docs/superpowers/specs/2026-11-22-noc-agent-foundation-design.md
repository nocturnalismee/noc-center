# NOC Agent Foundation — Design

- Tanggal: 2026-11-22
- Status: approved (2026-11-22)
- Scope tahap ini: **agent collector + systemd service + kontrak API + mock endpoint lokal**
- Bukan scope tahap ini: dashboard UI, database produksi, autentikasi user, alerting, penyimpanan historis di server pusat.

## 1. Ringkasan

Agent adalah script Bash ringan yang dijalankan systemd timer di setiap server. Setiap run mengumpulkan metrik sistem, menjalankan plugin panel bila terdeteksi, membaca cache metrik berat, membentuk satu payload JSON, lalu mengirimnya via HTTPS ke endpoint ingest dashboard dengan token unik per server.

Agent harus tetap berfungsi di:

- server Linux biasa **tanpa panel**;
- cPanel;
- Plesk, DirectAdmin, CyberPanel, aaPanel, dan panel lain di masa depan.

Karena itu arsitekturnya adalah **core + plugin panel + modul sistem**, bukan satu script monolitik. Core selalu mengumpulkan metrik universal. Plugin hanya menambahkan metrik spesifik panel. Modul sistem menambahkan metrik opsional yang tidak terikat panel (SMART, disk quota per akun, MySQL process list, Imunify), dan tidak boleh menggagalkan pengiriman metrik core.

Metrik berat tidak dijalankan setiap menit. SMART berjalan lewat timer bulanan, disk quota lewat timer harian, dan Imunify lewat timer 5 menit; hasilnya disimpan di cache root-only. Agent utama membaca cache tersebut. MySQL process list tetap dieksekusi di run utama karena biayanya kecil.

## 2. Tujuan

1. Agent Bash yang ringan, aman, dan mudah dipasang di banyak server.
2. Satu payload JSON per interval, dikirim push ke dashboard; tidak perlu SSH dari dashboard ke server.
3. Identitas server berbasis token per server; tidak ada token di URL atau argumen proses. `agent.id` hanyalah ID diagnostik lokal, bukan identitas otorisasi.
4. Core metrics selalu tersedia, termasuk di server non-panel.
5. Panel plugin dapat ditambah tanpa mengubah core.
6. Kegagalan satu plugin, satu command, atau satu metrik tidak menggagalkan seluruh run.
7. Systemd unit yang aman, idempoten, dan mudah di-uninstall.
8. Ada mock endpoint lokal untuk menguji agent tanpa dashboard produksi.
9. Metrik berat (SMART, disk quota, Imunify) dijalankan sesuai jadwal dan disimpan sebagai cache, bukan setiap menit.
10. MySQL process list tersedia di run utama dengan biaya rendah.
11. Data cache membawa timestamp/age agar dashboard dapat menandai data yang sudah basi.

## 3. Non-goals dan anti-goals

### Non-goals tahap ini

- Dashboard web dan grafik realtime.
- Database pusat dan retensi data.
- Manajemen user/role dashboard.
- Alerting, notifikasi, dan incident management.
- Offline spool/queue yang mengirim ulang data setelah dashboard mati.
- Auto-update agent.
- Dukungan Windows, FreeBSD, atau init system selain systemd.
- Monitoring RAID, baik software (`mdadm`) maupun hardware (Dell PERC/LSI/HP/Adaptec). Status RAID tidak dikirim di schema v1.
- Implementasi penuh Plesk, DirectAdmin, CyberPanel, dan aaPanel. Tahap ini hanya menyediakan kontrak plugin; implementasi cPanel menjadi contoh pertama.

### Anti-goals

- Agent tidak boleh mengubah konfigurasi panel, service, firewall, atau paket.
- Agent tidak boleh melakukan `systemctl restart`, `kill`, `sed -i`, atau edit file konfigurasi.
- Token tidak boleh muncul di URL, `ps`, log, payload, atau pesan error.
- Agent tidak boleh mengevaluasi response API sebagai shell code.
- Agent tidak boleh menunggu tanpa batas; semua command eksternal memakai timeout.
- Agent tidak boleh menjalankan command berat setiap menit tanpa cache.

## 4. Konteks dan constraints

- Target utama: Linux dengan systemd dan Bash 4+.
- Panel target awal: cPanel. Panel lain menyusul lewat plugin.
- Tool yang boleh diandalkan: Bash, GNU coreutils, `curl`, `flock`, `ps`, `df`, `awk`, `sed`, `grep`.
- Tool opsional per modul: `smartctl`, `repquota`, `mysql`/`mysqladmin`, `imunify360-agent`. Jika tidak ada, modul terkait dilewati tanpa menggagalkan agent.
- `python3` tidak dibutuhkan oleh core agent. Python hanya dipakai oleh mock endpoint dan, bila tersedia, oleh modul Imunify untuk mem-parse JSON CLI; jika `python3` tidak ada, modul Imunify di-skip tanpa memengaruhi metrik core.
- Agent produksi dijalankan sebagai root karena sebagian command panel dan modul memerlukan root. Script tetap bisa dijalankan non-root untuk `--dry-run` dan test core, tetapi plugin/modul yang butuh root akan melaporkan kegagalan secara terisolasi.
- Dashboard berada di host terpisah; agent hanya membutuhkan outbound HTTPS.
- Payload maksimum 64 KiB. Jika lebih, seluruh `panel_metrics` dibuang lebih dulu, lalu seluruh `extensions`; jika core saja masih terlalu besar, run gagal dengan error yang jelas. Detail ada di bagian error handling.
- Interval default 60 detik, dapat dikonfigurasi 10–3600 detik. Metrik berat memakai timer terpisah, bukan interval utama.
- Batas waktu satu run utama: 45 detik, di bawah `TimeoutStartSec=50`. Run modul berat memakai `TimeoutStartSec=300`.

## 5. Pendekatan yang dipertimbangkan

### A. Satu script Bash monolitik

Semua metrik universal dan semua panel di dalam satu file, deteksi dengan `case`.

- Plus: instalasi paling sederhana.
- Minus: bagian cPanel yang besar bercampur dengan panel lain; sulit dites, sulit dirawat, rawan regresi.
- Tidak dipilih karena target multi-panel.

### B. Core Bash + plugin panel terpisah — **dipilih**

- Core `collector.sh`: config, lock, timeout, metrik universal, JSON, HTTP, logging, cleanup.
- `panels/<name>.sh`: plugin opsional untuk panel. Core mendeteksi plugin, menjalankan `detect`, lalu `collect`.
- `modules/<name>.sh`: modul sistem opsional (SMART, disk quota, MySQL, Imunify). Masing-masing punya mode `inline` (dijalankan di run utama) atau `external` (dijalankan timer sendiri dan dibaca dari cache).
- Plugin/modul menghasilkan baris metrik bertipe; core yang membentuk JSON.
- Plus: cPanel terisolasi; non-panel tetap jalan; modul berat punya jadwal sendiri; plugin/modul baru tidak menyentuh core; mudah dites per bagian.
- Minus: lebih banyak file dan perlu kontrak plugin/modul yang disiplin.
- Dipilih karena paling sesuai dengan target multi-panel, metrik berat terjadwal, dan syarat “ringan”.

### C. Agent binary Go

- Plus: JSON/HTTP/tipografi lebih kuat, satu binary.
- Minus: bertentangan dengan permintaan agent Bash; integrasi command panel tetap harus ditulis per panel; menambah kebutuhan build/cross-compile.
- Tidak dipilih.

## 6. Arsitektur

```text
┌──────────────── Server yang dimonitor ──────────────────┐
│                                                         │
│  systemd timer utama (default 60s)                      │
│        │                                                │
│        ▼                                                │
│  collector.sh (core, main mode)                         │
│    ├─ baca config + token (root-only)                   │
│    ├─ lock /run/noc-agent/agent.lock                    │
│    ├─ metrik universal (/proc, df, ps, uname)           │
│    ├─ deteksi plugin panel → panels/cpanel.sh           │
│    ├─ modul inline: modules/mysql.sh                    │
│    ├─ baca cache modul external:                        │
│    │     smart, diskquota, imunify                      │
│    ├─ bentuk JSON (schema v1)                           │
│    └─ HTTPS POST + Authorization: Bearer <token>        │
│                                                         │
│  timer terpisah untuk modul external                    │
│    ├─ noc-agent@smart.service    (bulanan)              │
│    ├─ noc-agent@diskquota.service (harian)              │
│    └─ noc-agent@imunify.service  (5 menit)              │
│         │                                               │
│         ▼                                               │
│    collector.sh --module <nama>                         │
│      └─ validasi output → tulis cache atomik            │
│         /var/lib/noc-agent/cache/<nama>.metrics         │
│                                                         │
└─────────────────────────────────────────────────────────┘
                         │
                         ▼
        Dashboard API (host terpisah, di luar scope)
        POST /api/v1/ingest
```

### 6.1 Core

Tanggung jawab core:

- membaca dan memvalidasi config;
- membaca token dari file terpisah;
- mencegah dua instance berjalan bersamaan;
- mengumpulkan metrik universal;
- mendeteksi dan menjalankan tepat satu plugin panel (kecuali `PANEL=none`);
- mendeteksi modul sistem, menjalankan modul `inline`, dan membaca cache modul `external`;
- dalam mode `--module`, menjalankan satu modul lalu menulis cache secara atomik tanpa mengirim HTTP;
- memvalidasi output plugin/modul;
- membentuk JSON;
- mengirim HTTP dengan retry terbatas;
- membersihkan temp file dan tidak membocorkan token;
- menulis error yang ringkas ke stderr/journal.

Core **tidak** mengetahui detail command cPanel/Plesk atau detail SMART/quota. Semua itu ada di plugin/modul.

### 6.2 Non-panel

Jika tidak ada plugin yang cocok:

- `agent.panel` bernilai `"none"`;
- `panel_metrics` bernilai `{}`;
- payload tetap dikirim.

Non-panel bukan mode khusus; ia adalah hasil normal dari deteksi plugin yang tidak menemukan panel.

### 6.3 Plugin panel

Plugin adalah file executable di `/usr/local/libexec/noc-agent/panels/*.sh`, dimiliki root, mode 700. Core memindai file secara terurut, menjalankan `detect`, dan memilih plugin pertama yang mengembalikan exit 0.

`detect` dibungkus timeout `PANEL_DETECT_TIMEOUT` (default 2 detik); `collect` dibungkus timeout `PANEL_TIMEOUT` (default 10 detik). Jika `PANEL=<nama>` eksplisit, core melewati `detect` dan langsung menjalankan `collect`; `agent.panel` diisi nama tersebut. Jika plugin yang diminta tidak ada, itu error config (exit 1). Jika `collect` gagal, `panel_metrics` kosong tetapi metrik core tetap dikirim.

Kontrak plugin:

```text
plugin detect
  exit 0  = panel terdeteksi
  exit 1  = bukan panel ini
  exit >1 = error; core mengabaikan plugin ini

plugin collect
  stdout  = baris metrik: key<TAB>type<TAB>value
  exit 0  = sukses
  exit >0 = plugin gagal; metrik core tetap dikirim
```

Aturan output:

- Satu baris satu metrik. Baris kosong dan baris yang dimulai `#` diabaikan.
- `key`: `^[A-Za-z][A-Za-z0-9_.-]{0,63}$`.
- `type`:
  - `n` = number. Validasi regex `^-?(0|[1-9][0-9]*)(\.[0-9]+)?$`.
  - `b` = boolean, nilai `0` atau `1`.
  - `s` = string, maksimum 1024 byte; newline/tab tidak diizinkan di value.
- Maksimum 128 metrik per plugin.
- Key duplikat ditolak; nilai pertama dipertahankan dan warning dicatat.
- `panel_metrics` adalah objek datar (flat object) berisi string/number/boolean. Array dan objek bersarang tidak didukung di schema v1.
- Plugin tidak boleh menulis ke stdout selain format di atas. Log/debug harus ke stderr.
- Plugin wajib memiliki timeout internal untuk command eksternal; core juga membungkus `collect` dengan `timeout`.
- Plugin boleh memakai cache di `$CACHE_DIR/panel/` untuk command berat. Cache harus aman terhadap symlink dan hanya berada di direktori root-owned.

Contoh plugin minimal:

```bash
#!/bin/bash
set -u

case "${1:-}" in
  detect)
    [ -x /usr/local/cpanel/cpanel ] || exit 1
    exit 0
    ;;
  collect)
    printf 'cpanel.version\ts\t%s\n' "$(cat /usr/local/cpanel/version 2>/dev/null)"
    printf 'cpanel.accounts\tn\t%s\n' "$(find /var/cpanel/users -maxdepth 1 -type f ! -name system 2>/dev/null | wc -l)"
    ;;
  *)
    exit 2
    ;;
esac
```

### 6.4 cPanel plugin tahap ini

Plugin cPanel adalah contoh nyata pertama dan harus read-only. Metrik awal:

- `cpanel.version` (string)
- `cpanel.accounts` (number)
- `cpanel.mailq` (number; `exim -bpc`, timeout)
- `cpanel.mysql_ping` (boolean; `mysqladmin ping`, timeout)
- `cpanel.webserver` (string; LiteSpeed/Apache)
- `cpanel.cagefs` (string; status CageFS)
- `security.csf_enabled` (boolean)
- `security.csf_testing` (string)
- `security.modsec` (string; engine mode)
- `service.<nama>.installed` (boolean)
- `service.<nama>.enabled` (boolean)
- `service.<nama>.running` (boolean)

Service diambil dari `whmapi1 servicestatus` dengan timeout. Nama service disanitasi ke `[A-Za-z0-9_.-]`. Jika `whmapi1` tidak ada/gagal, plugin tetap sukses dengan metrik yang berhasil dikumpulkan.

Metrik berat seperti SMART, Imunify, disk quota per akun, dan MySQL process list **tidak** diambil oleh plugin cPanel. Metrik tersebut adalah modul sistem terpisah (bagian 6.5) karena juga berguna di server non-panel. Pemisahan ini menjaga plugin cPanel tetap kecil dan membaca-only.

### 6.5 Modul sistem

Modul sistem adalah file executable di `/usr/local/libexec/noc-agent/modules/*.sh`, root-owned, mode 700. Berbeda dari plugin panel, core dapat menjalankan **lebih dari satu** modul. Modul tidak terikat panel dan tetap berguna di server Linux biasa.

Kontrak modul:

```text
module detect
  exit 0 = modul applicable dan enabled
  exit 1 = tidak applicable / disabled
  exit >1 = error; modul diabaikan

module mode
  stdout = "inline" atau "external"
  (default "inline" bila command tidak ada)

module collect
  stdout = baris metrik: key<TAB>type<TAB>value
  exit 0 = sukses
  exit >0 = gagal
```

Aturan output modul mengikuti aturan plugin panel (bagian 6.3), dengan tambahan:

- `key` **wajib** diawali `<nama-modul>.` (contoh `smart.status`, `mysql.long_queries`).
- Maksimum 128 metrik per modul.
- Modul `external` wajib mengeluarkan `<nama-modul>.updated_at` pada setiap collect sukses, walaupun tidak ada temuan.
- Modul tidak boleh menulis cache sendiri; core yang memvalidasi output dan menulis cache.
- Modul tidak boleh menulis ke stdout selain format metrik; log ke stderr.

Mode:

- **inline**: dijalankan di setiap run utama, dibungkus `MODULE_TIMEOUT` (default 5 detik). Dipakai untuk MySQL process list yang biayanya kecil.
- **external**: tidak dijalankan di run utama. Timer systemd memanggil `collector.sh --module <nama>`; mode ini tidak membutuhkan token dan tidak mengirim HTTP. Core memvalidasi output lalu menulis `$STATE_DIR/cache/<nama>.metrics` secara atomik (mode 600). Jika collect gagal, cache lama dipertahankan.
- Run utama membaca cache external hanya jika `detect` modul tersebut masih sukses. Core menambahkan `<nama>.cache_age_seconds` berdasarkan mtime cache agar dashboard bisa menandai data basi.

Jadwal default:

| Modul | Mode | Jadwal | Alasan |
|---|---|---|---|
| `mysql` | inline | setiap run (60 detik) | query PROCESSLIST ringan dengan timeout 3 detik |
| `smart` | external | bulanan, tanggal 1 pukul 03:15 + randomized 15 menit | `smartctl -H -A` membangunkan disk; `smartd` tetap disarankan untuk alert real-time |
| `diskquota` | external | harian pukul 03:45 + randomized 10 menit | `repquota` membaca quota file dan bisa lebih berat |
| `imunify` | external | setiap 5 menit | CLI Imunify memakan 1–2 detik Python |

Jadwal berada di unit `.timer`, bukan di config agent. Timer `smart` dan `diskquota` memakai `Persistent=true` agar run yang terlewat (server mati/reboot) dieksekusi setelah boot. Installer melakukan priming cache sekali di background agar dashboard tidak kosong sampai jadwal pertama.

Modul yang disediakan di fondasi:

- `mysql.sh` — `Threads_running`, jumlah query panjang, dan maksimal `MYSQL_MAX_QUERIES` query dari PROCESSLIST (user, db, time, state, info terpotong).
- `smart.sh` — jumlah disk, status keseluruhan, disk bermasalah, dan health/reallocated/pending per disk.
- `diskquota.sh` — top `QUOTA_TOP_N` akun berdasarkan pemakaian, jumlah akun, jumlah yang over quota, timestamp. Memakai daftar user cPanel bila ada; jika tidak, memakai user dari `repquota`.
- `imunify.sh` — status scan berjalan, age, jumlah file, target, timestamp.

Semua command modul read-only. `smartctl` hanya `-H -A`; `repquota` hanya membaca; MySQL hanya `PROCESSLIST`/`SHOW`; Imunify hanya `list --json`.

### 6.6 Panel lain di masa depan

- Plesk: plugin `panels/plesk.sh`.
- DirectAdmin: plugin `panels/directadmin.sh`.
- CyberPanel: plugin `panels/cyberpanel.sh`.
- aaPanel: plugin `panels/aapanel.sh`.

Masing-masing cukup mengikuti kontrak `detect`/`collect`. Core tidak perlu diubah. Tahap ini menyediakan `panels/_template.sh` sebagai kerangka, bukan implementasi palsu yang selalu mendeteksi panel.

## 7. Siklus eksekusi agent

1. Set `umask 077`, `LC_ALL=C`, dan `PATH` eksplisit.
2. Parse argumen: `--dry-run`, `--module NAME`, `--config PATH`, `--state-dir PATH`, `--help`, `--version`.
3. Baca config (default `/etc/noc-agent/agent.conf`), validasi owner/mode terhadap effective UID.
4. Jika mode `--module`: jalankan hanya modul tersebut, validasi output, tulis cache atomik, lalu exit. Mode ini tidak membaca token dan tidak mengirim HTTP. Jika bukan `--dry-run`, token hanya dibutuhkan pada mode run utama.
5. Jika bukan `--dry-run`, baca token dari `SERVER_TOKEN_FILE`, validasi owner/mode/format. Dry-run tidak memerlukan token.
6. Pastikan `STATE_DIR`, `CACHE_DIR`, dan `RUN_DIR` aman (dimiliki effective UID, bukan symlink, tidak group/world-writable). Pada produksi effective UID adalah root.
7. Ambil lock `flock -n` pada `$RUN_DIR/agent.lock` untuk run utama, atau `$RUN_DIR/module-<nama>.lock` untuk mode modul. Jika gagal, exit 0 tanpa error (run sebelumnya masih berjalan).
8. Kumpulkan metrik universal.
9. Deteksi panel dan jalankan plugin `collect` dengan timeout.
10. Deteksi modul: jalankan `detect`; untuk modul `inline` jalankan `collect`; untuk modul `external` baca cache yang sudah ada dan tambahkan `cache_age_seconds`.
11. Bentuk payload JSON, termasuk objek `extensions`.
12. Jika `--dry-run`, cetak JSON (atau output modul) ke stdout dan exit 0.
13. Kirim HTTPS dengan retry terbatas, dengan deadline total 45 detik.
14. Hapus temp file lewat trap; keluar dengan exit code yang sesuai.

Runtime budget run utama:

- Deteksi plugin: ≤2 detik.
- Core: sekitar 1–2 detik (satu window sampling CPU/network 1 detik).
- Plugin cPanel hangat: target ≤5 detik karena cache.
- Plugin cPanel dingin: ≤10 detik.
- Modul inline: MySQL ≤3 detik.
- HTTP: ≤28 detik untuk 3 percobaan.
- Total normal: ≤10 detik; deadline run 45 detik; systemd `TimeoutStartSec=50`.

Run modul external memakai `TimeoutStartSec=300` dan `HEAVY_MODULE_TIMEOUT` (default 240 detik). SMART dapat memakan waktu lebih lama pada banyak disk; quota dan Imunify jauh lebih cepat. Run modul external tidak memblokir run utama karena cache ditulis atomik.

## 8. Metrik core dan schema JSON

### 8.1 Prinsip

- Satuan byte untuk memory/disk/network; persen 0–100; durasi detik.
- Timestamp UTC RFC3339.
- `agent.id` adalah UUID yang dibuat sekali dan disimpan di `$STATE_DIR/agent-id`; dipakai untuk diagnostik/korelasi, bukan untuk otorisasi. ID ini bertahan saat reinstall normal dan rotasi token; `--purge` menghapusnya. Kloning VM dapat menduplikasi ID, tetapi dashboard tetap mengidentifikasi server dari token.
- Persentase CPU: `100 * (total - idle - iowait) / total`; iowait dilaporkan terpisah agar tidak dihitung dua kali.
- Memory used: `MemTotal - MemAvailable` (fallback `MemFree + Buffers + Cached`).
- Network: jumlah byte antarmuka non-loopback, diubah menjadi byte/detik.
- Disk: filesystem root (`/`) untuk v1.
- `panel_metrics` dan `extensions` datar; tidak ada array/objek bersarang di v1.
- `extensions` berisi metrik modul sistem dengan prefix `<nama-modul>.`; setiap modul maksimum 128 metrik.
- Modul `external` menyertakan `updated_at`; core menambahkan `<nama-modul>.cache_age_seconds` dari mtime cache. Data yang basi tetap dikirim agar dashboard dapat menampilkannya sebagai stale, bukan hilang.

### 8.2 Contoh payload

```json
{
  "schema_version": 1,
  "agent": {
    "version": "0.1.0",
    "id": "0f0f0f0f-1111-2222-3333-444444444444",
    "hostname": "web01",
    "panel": "cpanel",
    "collected_at": "2026-11-22T10:15:00Z",
    "interval_seconds": 60
  },
  "system": {
    "os": {
      "name": "AlmaLinux",
      "version": "9.5",
      "kernel": "5.14.0-503.el9.x86_64"
    },
    "uptime_seconds": 123456
  },
  "metrics": {
    "cpu": {
      "usage_pct": 12.4,
      "iowait_pct": 0.8,
      "load1": 0.42,
      "load5": 0.51,
      "load15": 0.48,
      "cores": 8
    },
    "memory": {
      "total_bytes": 16777216000,
      "used_bytes": 8388608000,
      "used_pct": 50.0
    },
    "swap": {
      "total_bytes": 2147483648,
      "used_bytes": 0,
      "used_pct": 0.0
    },
    "disk": {
      "root": {
        "total_bytes": 107374182400,
        "used_bytes": 53687091200,
        "used_pct": 50.0,
        "inode_pct": 12.0
      }
    },
    "network": {
      "rx_bytes_per_sec": 123456,
      "tx_bytes_per_sec": 65432
    },
    "processes": {
      "running": 3,
      "blocked": 0
    }
  },
  "panel_metrics": {
    "cpanel.version": "126.0.10",
    "cpanel.accounts": 42,
    "cpanel.mailq": 0,
    "cpanel.mysql_ping": true,
    "security.csf_enabled": true,
    "service.litespeed.running": true,
    "service.exim.running": true
  },
  "extensions": {
    "mysql.threads_running": 2,
    "mysql.long_queries": 0,
    "mysql.updated_at": 1763806500,
    "smart.updated_at": 1763800000,
    "smart.cache_age_seconds": 6500,
    "smart.disks": 4,
    "smart.bad_count": 0,
    "smart.status": "ok",
    "diskquota.updated_at": 1763770000,
    "diskquota.cache_age_seconds": 36500,
    "diskquota.accounts": 42,
    "diskquota.over": 1,
    "diskquota.top.alice.used_gb": 12.3,
    "imunify.updated_at": 1763806200,
    "imunify.cache_age_seconds": 300,
    "imunify.scanning": false
  }
}
```

### 8.3 Field wajib

- `schema_version` = 1
- `agent.version`, `agent.id`, `agent.hostname`, `agent.panel`, `agent.collected_at`, `agent.interval_seconds`
- `system.os.name`, `system.os.kernel`, `system.uptime_seconds`
- `metrics.cpu.usage_pct`, `metrics.cpu.iowait_pct`, `metrics.cpu.load1/5/15`, `metrics.cpu.cores`
- `metrics.memory.total_bytes`, `used_bytes`, `used_pct`
- `metrics.swap.total_bytes`, `used_bytes`, `used_pct`
- `metrics.disk.root.total_bytes`, `used_bytes`, `used_pct`, `inode_pct`
- `metrics.network.rx_bytes_per_sec`, `tx_bytes_per_sec`
- `metrics.processes.running`, `blocked`
- `panel_metrics` selalu ada; boleh `{}`.
- `extensions` selalu ada; boleh `{}`.
- Field yang tidak tersedia (misalnya swap tidak ada) tetap dikirim dengan nilai 0 agar schema stabil.
- Modul external yang belum pernah sukses tidak mengirim field-nya; ini berbeda dari nilai 0 karena berarti “belum ada data”, bukan “nol”.

### 8.4 Field modul sistem

Semua field di bawah adalah opsional; hanya dikirim jika modul applicable.

MySQL (`mysql.sh`):

- `mysql.available` (boolean)
- `mysql.threads_running` (number)
- `mysql.long_queries` (number)
- `mysql.updated_at` (number)
- `mysql.query.<i>.user`, `.db`, `.state`, `.info` (string) dan `.time` (number) untuk `i=0..MYSQL_MAX_QUERIES-1`; `.info` maksimum 150 karakter dan hanya jika `MYSQL_INCLUDE_QUERY_TEXT=1`.

SMART (`smart.sh`):

- `smart.updated_at` (number), `smart.cache_age_seconds` (number, ditambahkan core)
- `smart.disks` (number), `smart.bad_count` (number)
- `smart.status` (string: `ok`, `warning`, `failed`, `unknown`)
- `smart.detail` (string, maksimum 512 byte)
- `smart.disk.<dev>.health`, `.realloc`, `.pending` untuk tiap disk (maksimum `SMART_MAX_DISKS`); nama device disanitasi.

Disk quota (`diskquota.sh`):

- `diskquota.updated_at` (number), `diskquota.cache_age_seconds` (number)
- `diskquota.accounts` (number), `diskquota.over` (number)
- `diskquota.top.<user>.used_gb` (number) untuk top `QUOTA_TOP_N` akun; username disanitasi.

Imunify (`imunify.sh`):

- `imunify.updated_at` (number), `imunify.cache_age_seconds` (number)
- `imunify.scanning` (boolean)
- `imunify.age_s` (number), `imunify.files` (number)
- `imunify.target` (string, maksimum 20 karakter)

## 9. Kontrak API

### 9.1 Request

```http
POST {API_URL}
Authorization: Bearer <server-token>
Content-Type: application/json
Accept: application/json
User-Agent: noc-agent/0.1.0
X-Noc-Agent-Version: 0.1.0
```

- `API_URL` default: `https://monitor.example.com/api/v1/ingest`.
- Path dapat diubah lewat config; agent tidak meng-hardcode host dashboard.
- Token hanya di header. Tidak ada token di URL/query.
- Body adalah JSON schema v1.
- Maksimum body: 64 KiB.

### 9.2 Response dan retry

- `200`, `202`, `204`: sukses.
- `400`, `413`, `422`: payload ditolak; jangan retry. Log status dan alasan singkat.
- `401`, `403`: token salah/ditolak; jangan retry. Log tanpa token.
- `429`: retry dengan backoff.
- `5xx` dan kegagalan network/TLS: retry.
- `CURL_TIMEOUT` adalah batas per percobaan (default 8 detik). Maksimum 3 percobaan dengan jeda 1 detik lalu 3 detik; total HTTP ≤ 28 detik. Retry loop juga tunduk pada deadline run 45 detik; jika deadline tercapai, berhenti dan exit 3.
- Response body tidak dieksekusi/di-`eval`; tidak perlu di-parse untuk sukses.
- Jika semua percobaan gagal, exit code 3 agar systemd/journal mencatat kegagalan. Run berikutnya tetap dilakukan timer.

### 9.3 Mock endpoint

`tools/mock-ingest.py`:

- Python 3 standard library, tanpa dependency.
- Default listen `127.0.0.1:8787`, path `/api/v1/ingest`.
- Token diberikan lewat argumen `--token` atau environment test.
- Validasi `Authorization: Bearer`.
- Parse JSON; jika valid, cetak ringkasan field utama dan kembalikan `202 {"ok":true}`.
- Token salah → `401`; JSON invalid → `400`.
- Hanya untuk tes lokal, bukan produksi.

## 10. Keamanan

### 10.1 File dan permission

- Config: `/etc/noc-agent/agent.conf`, dimiliki effective UID, mode 600 (produksi: root:root).
- Token: `/etc/noc-agent/token`, dimiliki effective UID, mode 600 (produksi: root:root).
- State: `/var/lib/noc-agent`, dimiliki effective UID, mode 700 (produksi: root:root).
- Cache modul: `/var/lib/noc-agent/cache`, mode 700; file `.metrics` mode 600, root-owned, ditulis atomik.
- Run/lock/temp: `/run/noc-agent`, dimiliki effective UID, mode 700 (produksi: root:root).
- Script, plugin, dan modul: root:root, tidak group/world-writable.
- Agent menolak config/token/state yang symlink atau permission-nya tidak aman.
- Untuk test non-root, `--config` dan `--state-dir` boleh menunjuk file/direktori milik user test dengan mode aman; validasi tetap dijalankan terhadap effective UID.

### 10.2 Token

- Token dibuat di dashboard, bukan di agent. Format yang diterima agent: `^[A-Za-z0-9._~-]{16,256}$`.
- `--dry-run` tidak membaca token dan tidak mengirim request.
- Token dibaca dari file, bukan dari argumen command line.
- Header Authorization dikirim lewat temporary curl config file mode 600 di `/run/noc-agent`, bukan lewat `-H` di argv. File dihapus lewat trap.
- Token tidak pernah ditulis ke log, payload, pesan error, atau nama file.
- Rotasi token = ganti isi file token; tidak perlu mengubah config.

### 10.3 Transport

- Wajib HTTPS.
- Pengecualian hanya untuk tes lokal: `ALLOW_INSECURE_HTTP=1` dan host `127.0.0.1`/`localhost`.
- `curl --proto '=https'` (atau `=http,https` hanya pada mode tes lokal).
- Minimal TLS 1.2; verifikasi certificate aktif; tidak ada `-k` di produksi.
- `--max-time` dan `--connect-timeout` wajib.

### 10.4 Eksekusi

- Tidak ada `eval` terhadap input eksternal/response API.
- Plugin dan modul dijalankan dengan `timeout`; output divalidasi ketat.
- Plugin/modul tidak boleh menulis ke path di luar `STATE_DIR`/`CACHE_DIR`; ini tanggung jawab plugin dan diuji saat review. Modul external tidak menulis cache sendiri; core yang menulis.
- Agent tidak mengubah service/config/firewall/package.
- Command panel yang dipakai harus read-only.
- Command modul read-only: `smartctl -H -A`, `repquota`, MySQL `PROCESSLIST`/`SHOW`, dan Imunify `list --json`.
- `smartctl` dapat membangunkan disk; karena itu SMART berjalan bulanan, bukan setiap menit. `smartd` tetap disarankan untuk peringatan real-time.
- Query text MySQL dapat mengandung data sensitif. Agent memotongnya maksimum 150 karakter, membuang newline/tab, tidak menuliskannya ke log, dan menyediakan `MYSQL_INCLUDE_QUERY_TEXT=0` untuk mematikannya.
- Cache external hanya dapat dibaca/ditulis oleh effective UID (root di produksi); run utama memvalidasi ulang isi cache sebelum memasukkannya ke payload.

### 10.5 systemd hardening

Unit memakai hardening konservatif agar command panel tetap kompatibel:

```ini
NoNewPrivileges=yes
ProtectSystem=full
ProtectHome=read-only
PrivateTmp=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
LockPersonality=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
SystemCallArchitectures=native
UMask=0077
```

Catatan: `ProtectSystem=strict` tidak dipakai di v1 karena sebagian CLI panel mungkin perlu menulis log/temp di `/var`. Jika ada CLI yang membutuhkan akses tulis tambahan, dokumentasikan dan tambahkan `ReadWritePaths` spesifik; jangan melemahkan seluruh unit.

### 10.6 SELinux

Installer menjalankan `restorecon -RF` pada `/usr/local/libexec/noc-agent`, `/etc/noc-agent`, dan `/var/lib/noc-agent` jika `restorecon` tersedia. Executable dan plugin dipasang di `/usr/local/libexec/noc-agent` (bukan `/usr/local/lib`) agar mendapat konteks executable yang sesuai. Jika SELinux enforcing tetap memblokir eksekusi, installer mencetak peringatan dan langkah `restorecon`/`semanage fcontext` yang diperlukan; agent tidak pernah mematikan atau mengubah mode SELinux. cPanel umumnya berjalan dengan SELinux permissive/disabled; dukungan enforcing adalah best-effort untuk server non-panel.

## 11. Konfigurasi

Contoh `/etc/noc-agent/agent.conf`:

```bash
# Wajib
API_URL="https://monitor.example.com/api/v1/ingest"
SERVER_TOKEN_FILE="/etc/noc-agent/token"

# Opsional
INTERVAL_SECONDS=60
PANEL="auto"                 # auto | none | cpanel | plesk | directadmin | cyberpanel | aapanel
PANEL_DETECT_TIMEOUT=2
PANEL_TIMEOUT=10
CURL_TIMEOUT=8
MAX_RUN_SECONDS=45
STATE_DIR="/var/lib/noc-agent"
TLS_VERIFY=1
ALLOW_INSECURE_HTTP=0
DEBUG=0

# Modul sistem
ENABLE_MYSQL=1
ENABLE_SMART=1
ENABLE_DISKQUOTA=1
ENABLE_IMUNIFY=1
MODULE_DETECT_TIMEOUT=2
MODULE_TIMEOUT=5
HEAVY_MODULE_TIMEOUT=240
QUOTA_TOP_N=15
MYSQL_MAX_QUERIES=10
MYSQL_MIN_SECONDS=5
MYSQL_INCLUDE_QUERY_TEXT=1
SMART_MAX_DISKS=32
```

Aturan:

- `INTERVAL_SECONDS`: 10–3600. Timer unit dibuat dari nilai ini saat install; jika diubah setelah install, jalankan ulang installer atau edit unit timer.
- `PANEL_DETECT_TIMEOUT`: 1–5 detik; default 2.
- `PANEL_TIMEOUT`: 3–20 detik; default 10.
- `CURL_TIMEOUT`: 3–15 detik per percobaan; default 8.
- `MAX_RUN_SECONDS`: 20–45 detik; default 45 dan harus lebih kecil dari `TimeoutStartSec`.
- `MODULE_DETECT_TIMEOUT`: 1–5 detik; default 2.
- `MODULE_TIMEOUT`: 1–15 detik untuk modul inline; default 5.
- `HEAVY_MODULE_TIMEOUT`: 30–600 detik untuk mode `--module`; default 240. Harus lebih kecil dari `TimeoutStartSec=300`.
- `QUOTA_TOP_N`: 1–50; default 15.
- `MYSQL_MAX_QUERIES`: 0–50; default 10. `0` mematikan daftar query.
- `MYSQL_MIN_SECONDS`: 1–3600; default 5.
- `MYSQL_INCLUDE_QUERY_TEXT`: `0`/`1`; default 1. Set `0` bila query text dianggap sensitif.
- `SMART_MAX_DISKS`: 1–64; default 32.
- `ENABLE_*`: `0` mematikan modul. Modul yang disabled tidak dijalankan dan cache lamanya tidak dimasukkan ke payload.
- Jadwal `smart`/`diskquota`/`imunify` berada di unit `.timer`, bukan config ini.
- `PANEL=auto`: deteksi otomatis. `PANEL=none`: paksa non-panel. Nilai lain: plugin harus ada.
- `TLS_VERIFY=1` wajib di produksi; `0` hanya untuk test lokal.
- Config di-source oleh Bash; karena itu owner/mode harus aman. Tidak ada `eval`.

## 12. Struktur file

```text
agent/
  collector.sh
  lib/
    common.sh
    json.sh
  panels/
    cpanel.sh
    _template.sh
    README.md
  modules/
    mysql.sh
    smart.sh
    diskquota.sh
    imunify.sh
    _template.sh
    README.md
  systemd/
    noc-agent.service
    noc-agent.timer.in
    noc-agent@.service
    noc-agent-smart.timer
    noc-agent-diskquota.timer
    noc-agent-imunify.timer
  config/
    agent.conf.example
  install.sh
  uninstall.sh

tools/
  mock-ingest.py
  test-agent.sh

docs/superpowers/specs/
  2026-11-22-noc-agent-foundation-design.md
```

### 12.1 Systemd

`noc-agent.service`:

```ini
[Unit]
Description=NOC monitoring agent (one-shot collector)
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=root
Group=root
ExecStart=/usr/local/libexec/noc-agent/collector.sh
TimeoutStartSec=50
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=7
StateDirectory=noc-agent
StateDirectoryMode=0700
RuntimeDirectory=noc-agent
RuntimeDirectoryMode=0700
UMask=0077
# hardening sesuai bagian 10.5

[Install]
WantedBy=multi-user.target
```

`noc-agent@.service` (template untuk modul external):

```ini
[Unit]
Description=NOC monitoring agent module %i
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=root
Group=root
ExecStart=/usr/local/libexec/noc-agent/collector.sh --module %i
TimeoutStartSec=300
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=7
StateDirectory=noc-agent
StateDirectoryMode=0700
RuntimeDirectory=noc-agent
RuntimeDirectoryMode=0700
UMask=0077
# hardening sesuai bagian 10.5
```

`noc-agent.timer.in`:

```ini
[Unit]
Description=Run NOC monitoring agent every @INTERVAL@ seconds

[Timer]
OnBootSec=30s
OnUnitActiveSec=@INTERVAL@
AccuracySec=1s
Persistent=false
Unit=noc-agent.service

[Install]
WantedBy=timers.target
```

`noc-agent-smart.timer`:

```ini
[Unit]
Description=Monthly SMART collection for NOC agent

[Timer]
OnCalendar=*-*-01 03:15:00
RandomizedDelaySec=15m
AccuracySec=1m
Persistent=true
Unit=noc-agent@smart.service

[Install]
WantedBy=timers.target
```

`noc-agent-diskquota.timer`:

```ini
[Unit]
Description=Daily disk quota collection for NOC agent

[Timer]
OnCalendar=*-*-* 03:45:00
RandomizedDelaySec=10m
AccuracySec=1m
Persistent=true
Unit=noc-agent@diskquota.service

[Install]
WantedBy=timers.target
```

`noc-agent-imunify.timer`:

```ini
[Unit]
Description=Imunify scan status collection for NOC agent

[Timer]
OnBootSec=2m
OnUnitActiveSec=5m
AccuracySec=30s
Persistent=false
Unit=noc-agent@imunify.service

[Install]
WantedBy=timers.target
```

Timer `smart` dan `diskquota` memakai `Persistent=true`, sehingga run yang terlewat saat server mati akan dijalankan setelah boot. `RandomizedDelaySec` mencegah semua server di armada menjalankan SMART/`repquota` pada detik yang sama.

Lock agent memakai fd terpisah (`flock -n 9`): `/run/noc-agent/agent.lock` untuk run utama, `/run/noc-agent/module-<nama>.lock` untuk mode modul. Jika lock gagal, run dilewati dengan exit 0.

## 13. Installer

`install.sh` dijalankan sebagai root dan harus idempoten.

Langkah:

1. Cek `bash`, `curl`, `flock`, dan systemd.
2. Buat direktori:
   - `/usr/local/libexec/noc-agent` (755)
   - `/usr/local/libexec/noc-agent/panels` (755)
   - `/usr/local/libexec/noc-agent/modules` (755)
   - `/etc/noc-agent` (700)
   - `/var/lib/noc-agent` (700)
   - `/var/lib/noc-agent/cache` (700)
3. Copy script/plugin/modul dengan mode aman.
4. Jika config belum ada, buat dari `agent.conf.example` dan minta `API_URL` serta token lewat stdin/prompt; jangan menimpa config/token yang sudah ada tanpa `--force`.
5. Tulis token ke `/etc/noc-agent/token` mode 600.
6. Generate `noc-agent.timer` dari template dengan `INTERVAL_SECONDS`, lalu pasang timer modul external.
7. Jika SELinux enforcing dan `restorecon` tersedia, jalankan `restorecon -RF` pada direktori yang baru dibuat.
8. `systemctl daemon-reload`, lalu `systemctl enable --now noc-agent.timer noc-agent-smart.timer noc-agent-diskquota.timer noc-agent-imunify.timer`.
9. Prime cache di background agar dashboard tidak kosong sampai jadwal pertama: `systemctl start --no-block noc-agent@smart.service noc-agent@diskquota.service noc-agent@imunify.service`. Kegagalan prime tidak menggagalkan instalasi; timer akan mencoba lagi.
10. Opsional: jalankan satu kali `collector.sh --dry-run` untuk validasi config, lalu `systemctl start noc-agent.service` jika `--test` diberikan.

Opsi installer:

- `--api-url URL`
- `--token-file PATH` (untuk otomasi; hindari token di argv)
- `--interval SECONDS`
- `--prefix DIR` (default `/`) untuk test installer di direktori sementara; semua path/unit mengikuti prefix
- `--dry-run` (cetak aksi tanpa menulis)
- `--no-systemctl` (jangan panggil systemctl; untuk container/test)
- `--no-prime` (jangan jalankan priming cache modul berat)
- `--force`
- `--test`
- `--uninstall` atau script terpisah `uninstall.sh` (stop/disable timer, hapus unit; config/token dipertahankan kecuali `--purge`).

## 14. Logging dan error handling

- Log ke stdout/stderr; systemd menangkap ke journald.
- Sukses: tanpa output kecuali `DEBUG=1` (satu ringkasan: ukuran payload, panel, HTTP status).
- Error: pesan ringkas, tanpa token/header/payload.
- Exit code:
  - `0` sukses atau lock dilewati.
  - `1` config/token/state tidak valid.
  - `2` pengumpulan metrik core gagal.
  - `3` pengiriman gagal setelah retry.
  - `4` modul external gagal dikumpulkan (hanya mode `--module`; cache lama dipertahankan).
- Plugin error tidak mengubah exit code core; dicatat sebagai warning.
- Modul `inline` yang gagal/timeout tidak mengubah exit code core; metrik modul tersebut dihilangkan dan warning dicatat.
- Modul `external` yang gagal tidak menghapus cache lama. Run utama tetap membaca cache lama dan mengirim `cache_age_seconds` yang bertambah.
- Cache external yang hilang, bukan file regular, atau gagal divalidasi diabaikan; core tetap mengirim payload.
- Payload >64 KiB: buang **seluruh** `panel_metrics` lebih dulu, ukur ulang; jika masih >64 KiB, buang **seluruh** `extensions`; jika metrik core saja masih >64 KiB, exit 2 dengan error. Schema v1 tidak punya penanda “essential”.
- File temp dibersihkan via `trap` pada EXIT/INT/TERM.

## 15. Testing dan acceptance criteria

### Test otomatis

1. `bash -n` untuk semua script.
2. ShellCheck (jika tersedia) untuk core/plugin/installer.
3. `collector.sh --dry-run` menghasilkan JSON valid yang bisa dibaca `python3 -m json.tool`.
4. Schema test: field wajib ada, tipe benar, `panel_metrics` objek.
5. JSON escaping test: hostname/string berisi kutip, backslash, newline, karakter kontrol.
6. Plugin contract test dengan plugin palsu:
   - output valid diterima;
   - tipe salah/duplikat/format rusak diabaikan;
   - plugin exit gagal tidak menggagalkan core.
7. Mock endpoint:
   - token valid → 202;
   - token salah → 401 dan agent exit 3 tanpa retry;
   - 500 → retry lalu exit 3.
8. Lock test: instance kedua keluar 0 tanpa mengirim.
9. Tidak ada token di `ps`/log: inspeksi kode dan test dengan `ps` saat request berjalan.
10. `systemd-analyze verify` untuk unit (jika systemd tersedia).
11. Installer dry-run/idempotensi di direktori test (tanpa menyentuh systemd nyata), memakai `--prefix`, `--dry-run`, dan `--no-systemctl`.
12. Non-root `--dry-run` dengan config/state milik user test; tidak ada akses ke token atau `/run/noc-agent` produksi.
13. Detect timeout: plugin `detect` yang menggantung dihentikan dalam ≤2 detik dan plugin berikutnya tetap dicoba.
14. Explicit `PANEL=cpanel`: `detect` dilewati, `collect` tetap dijalankan; jika `collect` gagal, core tetap terkirim.
15. Module contract test dengan modul palsu:
   - `mode inline` dijalankan di run utama;
   - `mode external` tidak dijalankan di run utama, hanya cache-nya dibaca;
   - key tanpa prefix modul ditolak;
   - modul disabled tidak dijalankan dan cache-nya tidak dimasukkan.
16. `collector.sh --module smart --dry-run` mencetak baris metrik tanpa membaca token dan tanpa HTTP.
17. `--module` menulis cache atomik mode 600; collect gagal mempertahankan cache lama dan exit 4.
18. Cache age: mtime cache lama menghasilkan `*.cache_age_seconds` yang benar; cache hilang membuat field modul tidak ada, bukan nilai 0.
19. Payload >64 KiB: `panel_metrics` dibuang lebih dulu, lalu `extensions`; core saja yang terlalu besar memicu exit 2.
20. `systemd-analyze verify` untuk keempat timer dan template service.
21. File cache symlink/non-regular ditolak; cache hanya dibaca dari `STATE_DIR` milik effective UID.

### Acceptance criteria

- Di server non-panel, `--dry-run` menghasilkan payload lengkap dan valid; modul yang tool-nya tersedia muncul di `extensions`.
- Di server cPanel, plugin terdeteksi dan metrik cPanel muncul; jika `whmapi1` gagal, metrik core tetap terkirim.
- MySQL process list muncul di run utama tanpa memperlambat run secara berlebihan.
- SMART/diskquota/Imunify muncul dari cache setelah timer/priming berjalan; `cache_age_seconds` masuk akal.
- Mengirim ke mock endpoint dengan token valid menghasilkan 202.
- Token salah tidak pernah dikirim ulang dan tidak muncul di log.
- Agent tidak pernah mengubah service/config/firewall.
- Satu run normal selesai <10 detik di mesin test non-panel, tanpa menjalankan SMART/repquota/Imunify.
- Run modul external selesai dalam `HEAVY_MODULE_TIMEOUT`.
- Semua file config/token/state/cache memiliki permission yang diminta.

## 16. Rollout

1. Implementasi core + `lib/json.sh`.
2. Implementasi `panels/cpanel.sh` + `panels/_template.sh`.
3. Implementasi modul `mysql.sh`, `smart.sh`, `diskquota.sh`, `imunify.sh` + `_template.sh`.
4. Implementasi systemd unit utama, template modul, empat timer, dan installer/uninstaller.
5. Implementasi mock endpoint + test script.
6. Jalankan test lokal non-panel, termasuk mode `--module` dan cache.
7. Uji di satu server cPanel staging dengan token uji dan mock endpoint.
8. Setelah stabil, deploy ke server lain dan arahkan ke API produksi.
9. Panel lain ditambahkan satu per satu sebagai plugin, tanpa mengubah core.

## 17. Keputusan yang diserahkan ke builder

- Nama field final untuk service cPanel dan daftar service yang ditampilkan.
- Apakah `ps` atau pembacaan `/proc` dipakai untuk hitungan proses; keduanya dapat diterima selama ringan.
- Detail implementasi JSON encoder Bash dan escape karakter kontrol.
- Penyesuaian kecil systemd hardening jika ada CLI panel yang membutuhkan akses tambahan, dengan tetap mempertahankan prinsip least privilege.
- Apakah `uninstall.sh` terpisah atau subcommand installer.
- Nama/format log ringkas saat `DEBUG=1`.
- Enumerasi disk SMART: `lsblk -dno NAME,TYPE`, pengecualian `zram`/`dm-`/`loop`/`sr`; SMART di balik RAID controller (`smartctl -d megaraid`) tidak termasuk v1.
- Parsing `repquota -a -O csv` berbasis header `User`/`BlockUsed` agar kompatibel dengan quota-tools lama/baru.
- Query MySQL PROCESSLIST: filter `command <> 'Sleep'`, `time >= MYSQL_MIN_SECONDS`, `info IS NOT NULL`, batas 10, sanitasi newline/tab.
- Format cache external: baris metrik yang sama dengan output modul, ditulis atomik; `cache_age_seconds` dihitung core dari mtime.
- Apakah timer jadwal diekspos sebagai opsi installer (`--smart-calendar`, `--quota-calendar`) atau cukup diedit manual.

## 18. Risiko dan mitigasi

- **CLI panel tidak tersedia/path berbeda** → plugin mendeteksi dan gagal secara terisolasi; metrik core tetap dikirim.
- **CLI panel menggantung** → timeout per command + timeout plugin.
- **Payload terlalu besar** → batas 64 KiB; seluruh `panel_metrics` dibuang lebih dulu, lalu `extensions`; jika core saja masih terlalu besar, exit 2.
- **Token bocor lewat proses** → token file + curl config temp, bukan argv. Mode `--module` tidak membaca token sama sekali.
- **Symlink attack di direktori yang dapat ditulis user** → state/run/config milik effective UID (root di produksi); cache root-owned, file 600, ditulis atomik; cache non-regular/symlink ditolak.
- **systemd hardening memblokir CLI panel** → mulai dari hardening konservatif; tambah pengecualian spesifik, bukan mematikan seluruh proteksi.
- **SELinux enforcing memblokir eksekusi** → pasang di `/usr/local/libexec`, jalankan `restorecon`; jika tetap gagal, installer memberi peringatan dan langkah konteks yang diperlukan.
- **Kloning VM menduplikasi `agent.id`** → token tetap identitas otorisasi; duplikasi hanya memengaruhi diagnostik/korelasi.
- **Bash JSON tidak valid** → test escaping dan validasi JSON di CI/test; payload diuji dengan `python3 -m json.tool`.
- **Interval terlalu cepat membebani server** → default 60 detik; minimum 10 detik; command berat wajib cache.
- **SMART bulanan terlambat mendeteksi kegagalan** → `smartd` tetap disarankan untuk peringatan dini; collector bulanan adalah pelengkap visual/dashboard, bukan pengganti smartd. Jika server tidak memakai smartd, pertimbangkan jadwal SMART lebih sering (mis. mingguan).
- **SMART membangunkan disk** → `smartctl -H -A` dapat membangunkan disk tidur; karena itu hanya dijalankan bulanan dan dibatasi `SMART_MAX_DISKS`. SMART di balik RAID controller (`smartctl -d megaraid`) tidak termasuk v1.
- **Data quota harian menjadi basi** → dashboard harus menampilkan `diskquota.updated_at`/`cache_age_seconds`; data tetap dikirim meskipun sudah lebih dari 24 jam, dengan penanda umur. Jika quota berubah drastis, pengguna dapat memicu run manual.
- **Cache hilang/rusak setelah reboot atau upgrade** → cache yang tidak ada/invalid diabaikan; timer `Persistent=true` menjalankan ulang setelah downtime. Installer melakukan priming agar data tidak kosong sampai jadwal berikutnya.
- **Timer berat bertabrakan dengan run utama** → cache ditulis atomik (temp + rename); run utama hanya membaca. Lock terpisah untuk main dan tiap modul; cache lama dipertahankan jika modul gagal.
- **Banyak server mengirim pada menit yang sama** → `RandomizedDelaySec` pada timer SMART/quota dan jitter opsional pada timer utama; dashboard sebaiknya tetap menerima burst.
- **Query MySQL mengandung data sensitif** → teks query dipotong 150 karakter, newline/tab disanitasi, tidak ditulis ke log agent; `MYSQL_INCLUDE_QUERY_TEXT=0` dapat menonaktifkan pengiriman teks. Dashboard harus menganggap `extensions` sebagai data sensitif.
- **`repquota`/`smartctl`/`mysql` tidak ada** → modul `detect` gagal dan dilewati; tidak ada error yang menggagalkan payload core.
- **Imunify CLI berubah format** → parser ketat; jika gagal, cache lama dipertahankan dan exit 4; core tetap mengirim data lain.

## 19. Pertanyaan terbuka

Tidak ada yang menghalangi implementasi fondasi. Beberapa hal dapat disesuaikan tanpa mengubah arsitektur:

1. **SMART: bulanan vs lebih sering.** Rekomendasi: bulanan + `smartd` untuk peringatan dini. Jika tidak ada `smartd`, mingguan lebih aman.
2. **Imunify: default 5 menit.** Dapat diubah lewat timer; scan berjalan lambat, jadi 5 menit sudah cukup.
3. **Teks query MySQL: default dikirim (dipotong 150 karakter).** Bisa dimatikan lewat `MYSQL_INCLUDE_QUERY_TEXT=0` jika dashboard tidak membutuhkannya.
4. **Jadwal timer sebagai opsi installer** (`--smart-calendar`, `--quota-calendar`) atau cukup diedit manual setelah install.
5. Path endpoint produksi (default `/api/v1/ingest`) — config memakai URL penuh.
6. Interval default 60 detik — dapat diubah saat install.
