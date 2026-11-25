# Analisis teknis NOC Center

**Tanggal:** 25 November 2026  
**Cakupan:** backend Laravel/API, penyimpanan dan pemrosesan metrik, operasi, frontend Livewire/Blade/JavaScript.  
**Metode:** tinjauan statis repository, konfigurasi, migrasi, dan alur implementasi. Tidak menjalankan aplikasi, load test, audit dependency, atau pengujian browser pada review ini.

## Ringkasan

Fondasi produk sudah matang untuk target awal 10–100 server: autentikasi token per server, validasi dan pembatasan payload, idempotensi dengan unique key, transaksi ingest yang relatif singkat, agregasi rollup bertingkat, retensi batch, queue alert, dan runbook deploy/backup sudah tersedia. Jadi pekerjaan berikutnya sebaiknya fokus mengukur perilaku produksi, mengurangi beban query ketika fleet tumbuh, dan membuat kondisi gagal lebih terlihat oleh operator.

Prioritas tertinggi:

1. Tambahkan pengukuran operasional yang bisa dipakai mengambil keputusan kapasitas (latensi/status ingest, antrean, keterlambatan rollup/retensi, ukuran DB/WAL dan lock/error).
2. Kurangi query berulang di layar Fleet dan pastikan refresh yang terlihat pengguna konsisten dengan data terbaru.
3. Tetapkan ambang kapasitas dan strategi pencarian/penyimpanan saat melewati sasaran SQLite saat ini.
4. Beri umpan balik di UI ketika request grafik gagal dan pastikan pola navigasi/refresh tetap mudah dipakai.

## Temuan backend

### Yang sudah baik

- `POST /api/v1/ingest` menjalankan pemeriksaan ukuran/media type, rate limit, autentikasi token, validasi payload, serta respons `no-store`.
- `StoreSample` memakai unique `(server_id, collected_at)` dan `insertOrIgnore` untuk retry idempoten; penyimpanan dan perubahan status server berada dalam transaksi.
- Alert punya active key unik, notification delivery dicatat, dan pengiriman eksternal dilakukan melalui job.
- Data historis memakai tabel rollup bertingkat 5 menit → 1 jam → 1 hari, dengan weighted mean, batas jumlah titik chart, serta retensi per tabel.
- Konfigurasi dan runbook sudah mengakui batas SQLite, menyediakan backup/restore, WAL, busy timeout, Redis untuk VPS, serta jalur migrasi PostgreSQL.

### Peluang peningkatan

| ID | Prioritas | Temuan dan dampak | Arah perbaikan |
|---|---|---|---|
| BE-01 | P1 | Dashboard menampilkan sebagian sinyal operasi (jumlah failed jobs, ukuran SQLite/WAL, waktu rollup), tetapi tidak ada metrik terstruktur untuk p95/p99 ingest, jumlah 4xx/5xx/429, durasi rollup/prune, queue age, atau kontensi DB. Tanpa tren, keputusan pindah dari SQLite hanya berdasarkan gejala. | Instrumentasi request/command dengan log terstruktur dan metrik ringkas; tampilkan health/lag dan tetapkan alert ambang. Hindari mencatat token atau payload sensitif. |
| BE-02 | P1 | `IngestController` memanggil `AlertEngine::evaluate()` setelah commit tetapi masih dalam request. Error alert memang tidak membatalkan sampel, namun evaluasi banyak rule dapat menambah latency ingest pada server yang sering memicu alert. | Ukur p95 ingest saat normal dan saat alert aktif. Jika biaya bermakna, pindahkan rekonsiliasi alert ke job dengan idempotency/order semantics yang jelas; pertahankan evaluasi liveness scheduler sebagai jalur terpisah. |
| BE-03 | P1 | Fleet list menghitung halaman, hitungan status, provider, summary, open alert, dan data activity dalam render Livewire. List melakukan polling 30 detik, jadi beban baca berulang bertambah seiring operator membuka beberapa tab. | Ukur query count/durasi; gabungkan agregasi dan cache hitungan yang aman, kurangi query provider/activity yang tidak berubah, dan hindari refresh tabel penuh bila hanya summary yang perlu diperbarui. |
| BE-04 | P1 | Filter pencarian memakai `LIKE '%term%'` terhadap beberapa kolom. B-tree biasa tidak membantu pola substring ini; pada jumlah server besar pencarian dapat menjadi scan penuh. | Untuk target sekarang biarkan sederhana; sebelum melewati skala yang ditentukan, pilih prefix search, FTS yang tersedia di DB, atau search service. Tambahkan paginasi dan batas panjang input tetap dipertahankan. |
| BE-05 | P1 | Beberapa keputusan agregasi dan penyimpanan bersifat khusus SQLite, sementara docs menyebut PostgreSQL rehearsal. Portabilitas nyata perlu diverifikasi pada seluruh siklus migrasi, ingest, rollup, alert, retention, dan antrean. | Jadikan rehearsal Postgres sebagai gate kapasitas/migrasi, bukan klaim kompatibilitas dari konfigurasi saja. Dokumentasikan hasil dan perbedaan perilaku driver sebelum cutover. |
| BE-06 | P2 | Command rollup/prune mencetak jumlah kerja, tetapi belum ada ringkasan durasi, backlog tertinggal, atau status akhir yang mudah dibaca mesin. Dashboard hanya menampilkan waktu rollup terakhir; tidak menunjukkan bucket yang tertinggal. | Keluarkan log JSON dengan run id, jumlah row/bucket, durasi, rentang waktu, exit status; dashboard/alert hanya menampilkan lag yang actionable. |
| BE-07 | P2 | Retensi dan rollup adalah proses batch terjadwal. Bila pekerjaan gagal berulang, operator perlu menghubungkan cron/scheduler log secara manual. | Tambahkan indikator scheduler terakhir sukses dan umur job tertua; pertimbangkan dead-man alert dari pemantau eksternal agar kegagalan scheduler tidak bergantung pada scheduler itu sendiri. |

## Temuan frontend

### Yang sudah baik

- Blade/Livewire tetap server-rendered; Chart.js dimuat dinamis dan seri diminta paralel.
- Filter, sort, paginasi dan query string tersedia pada daftar server; polling hanya aktif ketika terlihat.
- Banyak form sudah memiliki label, deskripsi error, state aria, dan status toast; layout mendukung layar kecil serta tema gelap.
- Chart memisahkan gap data (null) dari nol dan menampilkan waktu WIB.

### Peluang peningkatan

| ID | Prioritas | Temuan dan dampak | Arah perbaikan |
|---|---|---|---|
| FE-01 | P1 | Pada `charts.js`, kegagalan fetch atau inisialisasi hanya ditulis ke console (`data-chartError`). Pengguna dapat melihat area grafik kosong tanpa mengetahui apakah data belum ada, sedang dimuat, atau request gagal. | Tambahkan state loading/error yang terlihat, pesan retry, dan tombol retry yang tidak memuat ulang seluruh halaman. Bedakan kosong valid dari kegagalan API. |
| FE-02 | P1 | Navigasi tab server membentuk ulang komponen halaman; halaman detail membaca sample terbaru pada setiap render, sedangkan grafik menggunakan request terpisah dan cache 15 detik. Potensi tampilan sementara berbeda saat data masuk bersamaan. | Tentukan perilaku freshness per tab; gunakan indikator “updated”/waktu sampel dan refresh terarah untuk overview. Pertahankan cache pendek pada seri bila sesuai. |
| FE-03 | P2 | Sorting kolom tersedia, tetapi affordance urutan aktif/arah sort tidak tampak jelas pada tombol header di tabel. Ini membuat operator sulit memahami hasil urutan. | Tambahkan `aria-sort` pada header aktif dan ikon/teks arah sort yang terlihat serta konsisten. |
| FE-04 | P2 | Grafik diberi `role="img"` dan label ringkas; nilai aktual dan titik ekstrem tidak tersedia sebagai alternatif data yang mudah dibaca tanpa grafik. | Sediakan ringkasan teks/tabel ringkas yang bisa diakses pembaca layar dan pengguna yang butuh nilai numerik. |
| FE-05 | P2 | Error chart menggunakan `console.warn`, dan tombol retry/monitoring state belum tampak pada markup yang ditinjau. Browser console bukan saluran yang dapat diandalkan operator NOC. | Satukan state komponen dan pastikan error tidak membocorkan detail internal; kirim telemetri frontend terbatas bila memang dibutuhkan. |
| FE-06 | P3 | Sebagian navigasi memakai URL literal (`/servers/...`, `/alerts`) sementara endpoint lain memakai named route. Perubahan prefix/domain dapat membuat tautan tidak konsisten. | Gunakan route helper konsisten untuk navigasi server-rendered; tinjau path hasil deploy dengan subdirectory bila target deployment memerlukannya. |

## Backlog task

### Tahap 1 — Visibilitas dan pengalaman operasi

- [x] **NOC-101 — Tambahkan log ingest terstruktur.** Rekam status code, durasi, ukuran request, request ID terbatas, dan server ID (bila token tervalidasi); tidak mencatat token/payload. Agregasi p95 belum dibuat sesuai keputusan tahap ukur.
- [x] **NOC-102 — Tambahkan heartbeat scheduler dan status maintenance.** Catat heartbeat scheduler, waktu sukses terakhir rollup/prune, umur data, dan status kegagalan terakhir. Ambang tertunda: heartbeat 120 dtk, rollup 600 dtk, prune 26 jam.
- [x] **NOC-103 — Perlihatkan state grafik.** Grafik menampilkan loading, empty, error, dan retry per chart; detail exception hanya masuk console.
- [x] **NOC-104 — Perjelas freshness halaman detail.** Tampilkan timestamp sampel sumber dalam zona WIB di dekat chart.

### Tahap 2 — Beban query dan kapasitas

- [ ] **NOC-201 — Profilkan render Fleet.** Ukur query count/waktu untuk 10, 100, dan target pertumbuhan server; identifikasi query poll paling mahal. **Selesai bila:** baseline dicatat dan ada batas regresi yang disepakati.
- [x] **NOC-202 — Kurangi query berulang Fleet.** Cache tab counts (10 dtk), activity/open alerts (10 dtk), activity server names (60 dtk), dan daftar provider (60 dtk). Data panel operasional dan baris fleet tetap mengambil data segar saat render.
- [ ] **NOC-203 — Tentukan strategi search fleet.** Evaluasi kebutuhan substring vs prefix dan opsi FTS pada DB target. **Selesai bila:** pencarian memenuhi latensi target di dataset representatif dan rencana fallback tertulis.
- [ ] **NOC-204 — Ukur biaya alert pada ingest.** Bandingkan latensi ingest normal dan dengan banyak rule aktif. **Selesai bila:** hasil p95/p99 terdokumentasi; bila biaya di atas budget, desain job alert idempoten disiapkan.
- [ ] **NOC-205 — Buat kapasitas trigger PostgreSQL.** Tetapkan threshold operasional dari metrik ingest, ukuran DB, lock, server count dan volume sample; lakukan rehearsal Postgres pada backup salinan. **Selesai bila:** kriteria migrasi dan hasil rehearsal disetujui sebelum threshold terlampaui.

### Tahap 3 — Kualitas antarmuka dan release

- [x] **NOC-301 — Tingkatkan semantik sort tabel.** Header sort aktif memiliki `aria-sort`; panah arah terlihat dan disembunyikan dari pembaca layar.
- [ ] **NOC-302 — Tambahkan alternatif data grafik.** Sajikan ringkasan numerik yang bisa diakses selain canvas. **Selesai bila:** metrik dan rentang waktu dapat dipahami tanpa interaksi hover.
- [x] **NOC-303 — Ganti path literal dengan named route.** Link internal di navigasi, dashboard, fleet, alerts, dan detail server sekarang memakai route helper.
- [ ] **NOC-304 — Tambahkan pemeriksaan release operasional.** Jalankan dependency audit, acceptance/load check pada database scratch, pemeriksaan aset dan alur backup/restore sebelum rilis. **Selesai bila:** hasil memiliki catatan dan tidak menggunakan database produksi.

## Urutan yang disarankan

Mulai dari NOC-101, NOC-102, dan NOC-103. Instrumentasi memberi baseline untuk memutuskan apakah NOC-202/NOC-204 perlu optimasi segera. Setelah itu kerjakan NOC-201 dan NOC-205 sebelum pertumbuhan fleet mendekati batas SQLite. Perbaikan aksesibilitas tabel/grafik dapat berjalan paralel dengan pekerjaan kapasitas.

## Batasan review

Temuan performa adalah risiko berdasarkan pola implementasi, bukan hasil benchmark. Ukuran dampak perlu dikonfirmasi dengan dataset dan konfigurasi deployment sebenarnya. Review ini juga tidak memverifikasi tampilan di browser, aksesibilitas dengan assistive technology, hasil `composer audit`, atau keadaan server produksi.
