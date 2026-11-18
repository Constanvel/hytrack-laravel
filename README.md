# HyTrack

Aplikasi antrean rawat jalan rumah sakit: pasien mengambil antrean, check-in, memantau giliran, laboratorium dan farmasi; petugas menjalankan antrean; manajemen melihat ringkasan layanan.

Stack: **Laravel 13 + Inertia.js + React 19**, Vite 8.

> **Status: backend sedang dibangun ulang.** Kode backend lama (controller, model, migrasi HyTrack, service, tes fitur) sudah dihapus. Yang tersisa: kerangka Laravel, seluruh layar React, dan **mode prototype** di `/prototipe` yang berjalan di browser tanpa database. Backend lama masih bisa dilihat di riwayat git, commit `be01fc6`.

## Isi repo sekarang

```text
resources/js/features/       Layar React (pasien, pendamping, petugas, manajemen)
resources/js/pages.ts        Daftar nama halaman Inertia yang dipanggil backend
resources/js/types.ts        Bentuk props yang diharapkan setiap layar
resources/js/prototype/      Mode prototype: data contoh (fixtures.json) + backend tiruan di browser
resources/css/               CSS dan token desain
routes/web.php               Kosong; `/` sementara diarahkan ke prototype
app/Http/Middleware/         HandleInertiaRequests: tempat props bersama (lihat komentarnya)
database/migrations          Hanya tabel bawaan Laravel (users, sessions, cache, jobs)
```

## Menjalankan dengan Laragon (Windows)

1. Clone ke `C:\laragon\www\hytrack-laravel`, lalu buka **Terminal** Laragon di folder itu.
2. Buat database `hytrack` (Laragon → **Database**, atau HeidiSQL).
3. Jalankan:

```sh
composer install
copy .env.example .env
php artisan key:generate
npm ci
npm run build
```

4. Di `.env` ganti bagian database:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hytrack
DB_USERNAME=root
DB_PASSWORD=
APP_URL=http://hytrack-laravel.test
```

5. `php artisan migrate`, lalu **Reload** Laragon dan buka http://hytrack-laravel.test. Saat mengubah frontend, jalankan `npm run dev`.

## Membangun backend baru

Frontend sudah jadi; backend cukup merender halaman Inertia dengan props yang benar:

```php
Route::get('/kunjungan', fn () => Inertia::render('Visit'));
```

- Nama komponen ada di `resources/js/pages.ts`; bentuk props ada di `resources/js/types.ts`.
- Contoh props nyata per halaman dan per tahap kunjungan ada di `resources/js/prototype/fixtures.json` (direkam dari backend lama).
- Props bersama untuk semua halaman (`hospital`, `auth`, `visit`, dst.) diisi di `HandleInertiaRequests::share()`.
- Pesan ke pengguna memakai flash Inertia: `Inertia::flash('status', '...')` atau `'error'`.
- Halaman login (`/masuk`) belum ada, jadi tautan "Keluar dari prototype" akan 404 sampai rute itu dibuat.

## Mode prototype

Buka `/prototipe/masuk`, pilih peran (Pasien, Pendamping, Petugas, Manajemen), lalu coba alurnya: ambil antrean, undang pendamping, check-in oleh petugas, simulasi sampai obat diambil, dan laporan manajemen. Semua data ada di browser (sessionStorage); tidak ada yang dikirim ke server. "Ulang dari awal" mengembalikan data contoh.

## Tes

```sh
vendor/bin/phpunit          # rute prototype tanpa database
vendor/bin/pint --test      # gaya kode PHP
npm run lint
npm run typecheck
npm run format:check
npm test                    # Vitest: asset dan alur mode prototype
```

## Deploy

`vercel.json` dan `api/index.php` menjalankan Laravel di Vercel (runtime PHP komunitas). Tanpa backend, production hanya menampilkan prototype.
