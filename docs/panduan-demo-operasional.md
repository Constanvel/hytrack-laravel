# Panduan demo operasional HyTrack (pasien + petugas)

Mode operasional: `HYTRACK_DEMO_CONTROLS=false` di `.env`. Pasien tidak dapat mencatat
kehadiran sendiri; hanya petugas yang sudah login yang dapat check-in, memanggil, mengubah
tahap, membatalkan, dan mencatat "Tidak hadir".

## Menjalankan di Terminal Laragon

Jalankan satu per satu dari `D:\laragon\www\hytrack-laravel`:

```
cd D:\laragon\www\hytrack-laravel
composer install
npm install
npm run build
php artisan config:clear
php artisan migrate
php artisan serve
```

`php artisan migrate` hanya menambah kolom baru (aman untuk data lama). Jangan jalankan
`migrate:fresh` atau `db:wipe` pada `hytrack_laravel`. Seeder (`php artisan db:seed`) hanya
untuk database yang masih kosong.

Buka `http://localhost:8000`.

## Alur demo dengan dua browser

1. **Browser 1 (pasien)**, mis. Chrome: `/daftar` → isi nama, nomor ponsel, kata sandi,
   setujui syarat → Keluar (menu Akun) → Masuk lagi (format `08…` atau `+62…` sama saja).
2. Beranda → **+ Ambil antrean** → "Mau berobat ke mana?" → pilih poli → pilih tanggal dan
   dokter (lihat **Sisa kuota**) → **Ambil antrean** → tiket tampil → **Tampilkan QR check-in**.
3. **Browser 2 (petugas)**, mis. Edge atau jendela Incognito: masuk sebagai petugas (`dina`).
   Menu **Daftar booking** → booking baru tampil di "Booking terbaru" (juga untuk tanggal lain;
   pilih tanggal di filter).
4. Tombol **Scan QR** → izinkan kamera → arahkan ke QR di layar pasien → cocokkan nama,
   nomor antrean, poli, dan tanggal → **Konfirmasi hadir**.
5. Layar pasien berubah sendiri menjadi "Sudah check-in pukul …" paling lambat satu interval
   polling (`HYTRACK_POLL_SECONDS`, bawaan 15 detik). Indikator "● Terhubung · Diperbarui
   hh.mm.ss" / "○ Koneksi terputus" tampil di kedua layar.
6. Pindai QR yang sama lagi → "Sudah check-in pukul …" dengan waktu semula; tidak dicatat dua kali.
7. Keluar dari kedua akun, masuk lagi: tiket ada di **Riwayat kunjungan** (pasien) dan di
   **Daftar booking → Detail** (petugas), lengkap dengan waktu check-in dan nama petugas.

Kamera: browser hanya mengizinkan kamera di `http://localhost`/`127.0.0.1` atau HTTPS. Untuk
memindai dari ponsel/laptop lain, aktifkan SSL di Laragon (Menu → Apache → SSL → Enabled) dan
buka `https://hytrack-laravel.test`. Bila kamera gagal, ketik nomor check-in (`HT-000123`)
di kolom "QR sulit terbaca?".

## Pengujian otomatis (database terpisah)

```
php artisan test
php artisan test --testsuite=MySQL
npx playwright test -c playwright.operational.config.ts
```

- `php artisan test`: SQLite di memori, tidak menyentuh MySQL.
- `--testsuite=MySQL`: perebutan slot terakhir dan klik ganda dengan proses paralel, di
  database `hytrack_laravel_test` (dibuat otomatis).
- Playwright operasional: dua sesi browser + kamera palsu Chrome yang menayangkan QR pasien,
  juga di `hytrack_laravel_test`. Tambahkan `PW_CHANNEL=chrome` bila browser Playwright belum
  diunduh (PowerShell: `$env:PW_CHANNEL='chrome'`).

## Menelusuri satu booking di HeidiSQL

Buka sesi `127.0.0.1`, user `root`, database `hytrack_laravel`, tab **Query**, lalu ganti
nomor antrean dan tanggalnya:

```sql
SELECT q.queue_number    AS nomor_antrean,
       q.service_date    AS tanggal_layanan,
       p.full_name       AS pasien,
       pl.polyclinic_name AS poli,
       d.doctor_name     AS dokter,
       q.queue_status    AS status,
       CONVERT_TZ(q.booked_at,  '+00:00', '+07:00') AS dibooking_wib,
       COALESCE(sb.full_name, 'Pasien (aplikasi)')  AS dibooking_oleh,
       CONVERT_TZ(q.checkin_at, '+00:00', '+07:00') AS checkin_wib,
       sc.full_name      AS dikonfirmasi_oleh,
       q.checkin_method  AS metode_checkin,
       j.stage_status    AS tahap_checkin,
       CONVERT_TZ(q.cancelled_at, '+00:00', '+07:00') AS dibatalkan_wib,
       q.cancel_reason   AS alasan_batal
FROM queues q
JOIN patients p     ON p.patient_id = q.patient_id
JOIN polyclinics pl ON pl.polyclinic_id = q.polyclinic_id
JOIN doctors d      ON d.doctor_id = q.doctor_id
LEFT JOIN staff sb  ON sb.staff_id = q.booked_by_staff_id
LEFT JOIN staff sc  ON sc.staff_id = q.checkin_by_staff_id
LEFT JOIN patient_journeys j ON j.queue_id = q.queue_id AND j.stage_order = 1
WHERE q.queue_number = 'A-027'
  AND q.service_date = '2026-11-19';
```

Waktu disimpan dalam UTC; `CONVERT_TZ(..., '+07:00')` menampilkannya dalam WIB. Kata sandi
(`users.password_hash`) tidak diikutkan, dan token QR tidak disimpan di database (QR dibuat
dan ditandatangani ulang dari `queue_id` + tanggal layanan).
