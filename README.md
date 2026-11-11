# HyTrack

Aplikasi antrean rawat jalan rumah sakit: pasien mengambil antrean, check-in, memantau giliran, laboratorium dan farmasi; petugas menjalankan antrean; manajemen melihat ringkasan layanan.

Versi ini adalah migrasi dari demo frontend Next.js (`Projek-KIK`) ke **Laravel 13 + Inertia.js + React 19**. Komponen, CSS, dan asset lama dipakai ulang; data, aturan antrean, autentikasi, dan otorisasi kini berada di server.

> **Status: demo dengan backend nyata.** Data disimpan di database dan aturan antrean dijalankan server, tetapi aplikasi **tidak terhubung ke sistem rumah sakit (SIMRS)**, tidak ada push realtime, QR masih gambar contoh, dan Hya bukan AI. Lihat [Status fitur](#status-fitur).

## Stack

| Lapisan | Sebelum                        | Sesudah                                                  |
| ------- | ------------------------------ | -------------------------------------------------------- |
| Routing | Next.js App Router (catch-all) | Laravel routes (`routes/web.php`)                        |
| Data    | Reducer React + sessionStorage | Eloquent + migration sesuai ERD HyTrack (SQLite / MySQL) |
| Logika  | `features/demo/model.ts`       | `app/Services` (QueueService, WaitEstimator, …)          |
| Auth    | Tidak ada                      | Session Laravel, role pasien/petugas/manajemen           |
| UI      | React 19, CSS semantik         | React 19 yang sama lewat Inertia, CSS yang sama          |
| Build   | Next.js                        | Vite 8 + laravel-vite-plugin                             |
| Tes     | Vitest, Playwright             | PHPUnit, Vitest, Playwright                              |

## Kebutuhan

- PHP 8.3+ dengan ekstensi `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`, `fileinfo`, `zip` (dan `pdo_mysql` bila memakai MySQL)
- Composer 2
- Node.js 22.13+ atau 24 LTS, npm

> **Windows (PHP dari winget):** `php.ini` bawaan belum mengaktifkan SQLite. Buka file yang ditunjukkan `php --ini`, lalu hapus `;` di depan `extension=pdo_sqlite`, `extension=sqlite3`, dan `extension=zip`. Tanpa ini muncul galat `could not find driver`.

## Menjalankan

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed        # membuat database/database.sqlite berisi data demo
npm ci
npm run build                     # atau `npm run dev` saat mengubah frontend
php artisan serve
```

Buka http://localhost:8000. Untuk pengembangan, jalankan `php artisan serve` dan `npm run dev` bersamaan (atau `composer dev`).

Data demo dibuat relatif terhadap **hari ini** (WIB). Jalankan `php artisan migrate:fresh --seed` untuk mengulang skenario, misalnya keesokan harinya.

### Akun demo

| Peran     | Nama pengguna | Halaman awal |
| --------- | ------------- | ------------ |
| Pasien    | `siti`        | `/`          |
| Petugas   | `dina`        | `/petugas`   |
| Manajemen | `manajer`     | `/manajemen` |

Kata sandi: `hytrack-demo` (`HYTRACK_DEMO_PASSWORD`). Daftar akun hanya tampil di halaman masuk saat `HYTRACK_DEMO_CONTROLS=true`.

### Mencoba alur

1. Masuk sebagai `siti` → **Ambil antrean** → **Penyakit Dalam** → dr. Rina → tiket **A-027** (3 pasien di depan, perkiraan 15–25 menit).
2. Buka **Tampilkan QR check-in**. Di jendela lain (mode samaran) masuk sebagai `dina`, ketik nomor check-in `HT-…` di **Konfirmasi kedatangan**. Layar pasien ikut berubah (maks. 15 detik, atau muat ulang).
3. Petugas memanggil, menandai tidak hadir (hanya setelah panggilan ulang), menyelesaikan pemeriksaan, laboratorium, dan farmasi. Pasien melihat setiap tahap.
4. **Bagikan ke pendamping** membuat tautan sekali tampil untuk keluarga tanpa akun; dapat dicabut.
5. Dengan kontrol demo aktif, pasien juga dapat mensimulasikan tindakan petugas dari layar kunjungan.
6. Masuk sebagai `manajer` untuk ringkasan per tanggal/poliklinik dan ekspor CSV agregat.

## Pengaturan

| Variabel                 | Bawaan             | Fungsi                                             |
| ------------------------ | ------------------ | -------------------------------------------------- |
| `HYTRACK_DEMO_CONTROLS`  | `false`            | Tombol simulasi pasien + daftar akun demo di login |
| `HYTRACK_DEMO_PASSWORD`  | `hytrack-demo`     | Kata sandi akun yang dibuat seeder                 |
| `HYTRACK_HOSPITAL`       | `RS Harapan Sehat` | Nama rumah sakit                                   |
| `HYTRACK_TIMEZONE`       | `Asia/Jakarta`     | Zona waktu layanan (database menyimpan UTC)        |
| `HYTRACK_DAILY_QUOTA`    | `30`               | Kuota tiket per dokter per hari                    |
| `HYTRACK_TRAVEL_MINUTES` | `25`               | Perkiraan perjalanan untuk "Berangkat sebelum"     |
| `HYTRACK_POLL_SECONDS`   | `15`               | Interval pembaruan halaman antrean                 |

Aturan lain (jendela booking 3 hari, check-in 15 menit sebelum praktik, target tunggu 30 menit, lokasi ruangan) ada di `config/hytrack.php`.

**MySQL:** buat database, isi `DB_CONNECTION=mysql`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` di `.env`, lalu `php artisan migrate --seed`. Skema ditulis kompatibel MySQL tetapi **baru diuji dengan SQLite**.

## Tes

```sh
vendor/bin/phpunit          # 34 tes fitur: aturan antrean, akses peran, privasi, alur pasien/petugas/manajemen
vendor/bin/pint --test      # gaya kode PHP
npm run lint
npm run typecheck
npm run format:check
npm test                    # Vitest: geometri asset
npx playwright install chromium
npm run test:e2e            # 24 tes browser: alur, responsif 320–1440px, axe, panduan, posisi Hya, CLS
```

`npm run test:e2e` membutuhkan `npm run build` lebih dulu. Playwright menyiapkan database terpisah `database/e2e.sqlite` dengan kontrol demo aktif, lalu memulihkannya sebelum setiap tes. Server PHP bawaan memproses satu permintaan sekaligus, jadi tes berjalan berurutan (±5 menit).

## Status fitur

| Fitur                  | Status          | Keterangan                                                                                               |
| ---------------------- | --------------- | -------------------------------------------------------------------------------------------------------- |
| Live Queue Tracking    | Berfungsi       | Data dari database, diperbarui dengan **polling** tiap 15 detik. Bukan WebSocket/push.                   |
| Smart Wait Time        | Berfungsi       | Rata-rata durasi pemeriksaan dokter hari itu × posisi + keterlambatan dokter (bawaan 5 menit/pasien).    |
| Time to Go             | Sebagian        | Batas check-in dari jadwal dokter; waktu berangkat memakai perkiraan perjalanan tetap, tanpa lokasi.     |
| Patient Journey        | Berfungsi       | Empat tahap (check-in, dokter, laboratorium, farmasi) di `patient_journeys`.                             |
| Smart Notification     | Sebagian        | Notifikasi di aplikasi (lonceng + daftar). Tanpa SMS, WhatsApp, email, atau push.                        |
| QR Check-in            | Sebagian        | Petugas mengonfirmasi dengan nomor check-in `HT-xxxxxx`. Gambar QR masih contoh dan belum bisa dipindai. |
| Pharmacy Tracker       | Berfungsi       | Petugas memajukan status resep; pasien melihat nomor ambil `F-xxx`.                                      |
| Family Companion       | Berfungsi       | Tautan acak, hanya hash SHA-256 yang disimpan; berakhir 23.59 hari layanan, saat dicabut, atau selesai.  |
| Live Control Dashboard | Berfungsi       | Check-in, panggil, panggil ulang, tidak hadir, lanjut laboratorium/farmasi, riwayat aktivitas.           |
| Service Insights       | Berfungsi       | Agregat per tanggal/poliklinik, grafik per jam, ekspor CSV tanpa identitas pasien.                       |
| Hya (chat bubble)      | Berbasis aturan | Menjawab dari antrean pasien yang sebenarnya; bukan AI, tidak menjawab pertanyaan medis.                 |
| Panduan interaktif     | Berfungsi       | Disimpan di localStorage tanpa data pasien.                                                              |
| Indikator koneksi      | Berfungsi       | Status online/offline browser dan waktu sinkron terakhir dari server.                                    |
| Petunjuk ruangan       | Teks statis     | Tanpa denah atau navigasi peta.                                                                          |
| Kontrol demo           | Demo            | Hanya bila `HYTRACK_DEMO_CONTROLS=true`.                                                                 |

**Belum ada:** pendaftaran pasien mandiri, reset kata sandi, integrasi SIMRS/BPJS, pemindai QR, notifikasi luar aplikasi, push realtime, audit log lengkap (riwayat petugas hanya menampilkan pembaruan terakhir tiap tahap).

## Arsitektur

```text
routes/web.php                 Rute per peran (middleware auth + role)
app/Http/Controllers/          Auth, Patient, Visit, Companion, Hya, Staff, Management
app/Http/Middleware/           EnsureRole, HandleInertiaRequests (props bersama, data kunjungan lazy)
app/Services/                  QueueService (aturan antrean, transaksi + lock), WaitEstimator,
                               HyaResponder, ServiceInsights
app/Support/                   VisitView (bentuk data untuk pasien/pendamping), HospitalTime
app/Models, app/Enums          Model Eloquent dan status sesuai ERD
database/migrations, seeders   Skema ERD HyTrack dan data demo
resources/js/pages.ts          Daftar halaman Inertia (pengganti route switch Next.js)
resources/js/features/         Layar React dari proyek lama
resources/css/                 CSS dan token desain lama
tests/Feature, unit, e2e       PHPUnit, Vitest, Playwright
```

Keputusan utama:

- **Inertia + React** agar layar lama dipakai hampir apa adanya; `next/link` → `Link` Inertia, `next/image` → `<img>`.
- **Skema mengikuti ERD** (`docs/database` di proyek lama): nama tabel/kolom, `password_hash`, FK komposit `queues(doctor_id, polyclinic_id)`, dan `UNIQUE(queue_id, stage_order)`. Tambahan: `UNIQUE(doctor_id, service_date, queue_number)` agar nomor tidak ganda, dan semua FK `restrict on delete`.
- **Privasi:** pasien hanya melihat kunjungannya sendiri, nomor telepon disamarkan, pendamping hanya melihat nama singkat dan progres, ekspor manajemen hanya agregat, token pendamping tidak pernah disimpan mentah.
- **Tanpa N+1:** relasi dimuat dengan `with()`, hitungan memakai query grup.

## Produksi

Bukan untuk data pasien nyata tanpa tinjauan keamanan dan integrasi rumah sakit. Minimal: `APP_ENV=production`, `APP_DEBUG=false`, `HYTRACK_DEMO_CONTROLS=false`, jangan jalankan seeder demo, buat akun dengan kata sandi kuat, gunakan HTTPS, dan MySQL/PostgreSQL.
