import { test, expect, chromium, type Page } from '@playwright/test';
import { readFileSync, writeFileSync } from 'node:fs';

/*
 * The demonstration from two separate sessions, in operational mode (no demo controls):
 * the patient registers, signs out and in, takes a ticket; staff in another browser see the
 * booking, scan the QR the patient's screen shows with a (fake) camera, confirm the arrival,
 * and the patient's screen changes without a reload. Data lives in hytrack_laravel_test.
 */

const PATIENT_PASSWORD = 'pasien-rahasia-9';
const STAFF_PASSWORD = 'hytrack-demo'; // seeded demo staff account "dina"
const WIDTH = 640;
const HEIGHT = 480;

/** Patients (phone numbers) use /masuk; the staff account "dina" uses /masuk/internal. */
async function signIn(page: Page, username: string, password: string) {
  const staff = /[a-z]/i.test(username);
  await page.goto(staff ? '/masuk/internal' : '/masuk');
  if (staff) await page.getByText('Petugas', { exact: true }).click();
  await page.getByLabel(staff ? 'ID pengguna atau email' : 'Nomor ponsel').fill(username);
  await page.getByLabel('Kata sandi', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await expect(page).not.toHaveURL(/\/masuk(\/internal)?$/);
}

async function skipGuide(page: Page) {
  const skip = page.getByRole('button', { name: 'Lewati panduan' });
  if (await skip.isVisible().catch(() => false)) await skip.click();
}

/**
 * Draws the QR exactly as the patient's screen shows it and writes it as a Y4M video,
 * which Chromium plays as the camera (--use-file-for-fake-video-capture).
 */
async function qrAsCamera(page: Page, file: string) {
  const luma = await page.locator('svg.ticket-qr').evaluate(
    async (svg, size) => {
      const image = new Image();
      image.src =
        'data:image/svg+xml;charset=utf-8,' +
        encodeURIComponent(new XMLSerializer().serializeToString(svg));
      await image.decode();
      const canvas = document.createElement('canvas');
      canvas.width = size.width;
      canvas.height = size.height;
      const context = canvas.getContext('2d')!;
      context.fillStyle = '#fff';
      context.fillRect(0, 0, size.width, size.height);
      context.drawImage(image, (size.width - 360) / 2, (size.height - 360) / 2, 360, 360);
      const { data } = context.getImageData(0, 0, size.width, size.height);
      const y: number[] = [];
      for (let i = 0; i < data.length; i += 4)
        y.push(Math.round(0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2]));
      return y;
    },
    { width: WIDTH, height: HEIGHT },
  );
  const chroma = Buffer.alloc((WIDTH / 2) * (HEIGHT / 2) * 2, 128);
  const frame = Buffer.concat([Buffer.from('FRAME\n'), Buffer.from(luma), chroma]);
  const header = Buffer.from(`YUV4MPEG2 W${WIDTH} H${HEIGHT} F10:1 Ip A1:1 C420jpeg\n`);
  writeFileSync(file, Buffer.concat([header, ...Array(10).fill(frame)]));
}

test('patient books in one browser, staff scan and confirm in another', async ({
  browser,
}, info) => {
  const baseURL = info.project.use.baseURL!;
  const patient = await (await browser.newContext()).newPage();

  // 1. Register, sign out, sign in again with the 08 format of the same number.
  await patient.goto('/daftar');
  await patient.getByLabel('Nama lengkap').fill('Rina Wulandari');
  await patient.getByLabel('Nomor ponsel').fill('+62 813-2468-1357');
  await patient.getByLabel('Kata sandi', { exact: true }).fill(PATIENT_PASSWORD);
  await patient.getByLabel('Konfirmasi kata sandi').fill(PATIENT_PASSWORD);
  await patient.locator('.auth-consent input').check();
  await patient.getByRole('button', { name: 'Buat akun' }).click();
  // Sign-up step 2/3: outside demo mode the code goes to the log (no SMS gateway yet).
  await expect(patient).toHaveURL(/\/daftar\/verifikasi$/);
  await expect(patient.getByText('+62 813 •••• 1357')).toBeVisible();
  const code = [
    ...readFileSync('storage/logs/laravel.log', 'utf8').matchAll(
      /Kode OTP pendaftaran .*?"code":"(\d{6})"/g,
    ),
  ].pop()![1];
  await patient.getByLabel('Digit 1 dari 6').focus();
  await patient.keyboard.type(code);
  await patient.getByRole('button', { name: 'Verifikasi & lanjutkan' }).click();
  await patient.getByRole('link', { name: 'Lanjut ke beranda' }).click();
  await expect(patient).toHaveURL(new RegExp(`${baseURL}/?$`));
  await patient.goto('/akun');
  await patient.getByRole('button', { name: 'Keluar' }).click();
  await expect(patient).toHaveURL(/\/masuk$/);
  await signIn(patient, '0813-2468-1357', PATIENT_PASSWORD);

  // 2. "Mau berobat ke mana?" → doctor and date → "Ambil antrean".
  await patient.goto('/poliklinik');
  await skipGuide(patient);
  await expect(patient.getByRole('heading', { name: 'Mau berobat ke mana?' })).toBeVisible();
  await patient.getByRole('link', { name: /^Penyakit Dalam/ }).click();
  await skipGuide(patient);
  await patient.getByRole('button', { name: /dr. Rina/ }).click();
  await expect(patient.getByText('Sisa kuota')).toBeVisible();
  await patient.getByRole('button', { name: 'Ambil antrean', exact: true }).click();
  await expect(patient.getByRole('heading', { name: 'Tiket kunjungan' })).toBeVisible();
  const ticket = (await patient.locator('.ticket-number').textContent())!.trim();
  await skipGuide(patient);
  await patient.getByRole('link', { name: 'Tampilkan QR check-in' }).click();
  await skipGuide(patient);
  await expect(patient.getByText(/Belum check-in · Batas/)).toBeVisible();
  // Operational mode: no demo button that could record an arrival.
  await expect(patient.getByText(/Simulasikan/)).toHaveCount(0);

  // 3. Staff browser with a camera that sees the patient's QR.
  const video = info.outputPath('patient-qr.y4m');
  await qrAsCamera(patient, video);
  const staffBrowser = await chromium.launch({
    channel: info.project.use.channel,
    args: [
      '--use-fake-ui-for-media-stream',
      '--use-fake-device-for-media-stream',
      `--use-file-for-fake-video-capture=${video}`,
    ],
  });
  const staffContext = await staffBrowser.newContext({ baseURL, permissions: ['camera'] });
  const staff = await staffContext.newPage();
  await signIn(staff, 'dina', STAFF_PASSWORD);
  await expect(staff).toHaveURL(/\/petugas$/);

  // The new booking is listed.
  await staff.getByRole('button', { name: 'Daftar booking' }).click();
  const recent = staff.locator('.recent-bookings li').first();
  await expect(recent).toContainText(ticket);
  await expect(recent).toContainText('Rina Wulandari');
  await recent.getByRole('button').click();
  const detail = staff.locator('.booking-detail');
  await expect(detail).toContainText('Belum check-in');

  // 4. Scan QR → match → "Konfirmasi hadir".
  await staff.getByRole('button', { name: 'Scan QR kedatangan', exact: true }).click();
  const result = staff.locator('.scan-card.result');
  await expect(result).toContainText('Rina Wulandari', { timeout: 20000 });
  await expect(result).toContainText(ticket);
  await expect(result).toContainText('Penyakit Dalam');
  await staff.getByRole('button', { name: 'Konfirmasi check-in' }).click();
  await expect(staff.locator('.scan-status')).toContainText(`${ticket} berhasil check-in pukul`);

  // 5. The patient's screen changes on its own within one polling interval (3 s here).
  await expect(patient.getByText(/✓ Sudah check-in pukul/)).toBeVisible({ timeout: 8000 });

  // 6. The same QR again: "Sudah check-in" with the original time, nothing recorded.
  const checkedIn = (await patient.getByText(/✓ Sudah check-in pukul/).textContent())!.match(
    /\d{2}\.\d{2}/,
  )![0];
  await staff.getByRole('button', { name: 'Pindai pasien berikutnya' }).click();
  await expect(result).toContainText(`Sudah check-in pukul ${checkedIn}`, { timeout: 20000 });
  await expect(result).toContainText('dikonfirmasi Dina');

  // 7. A patient cannot open the staff dashboard.
  await patient.goto('/petugas');
  await expect(patient.getByRole('heading', { name: 'Akses ditolak' })).toBeVisible();

  // 8. Both sign out and back in: the ticket and the attendance are still there.
  await patient.goto('/akun');
  await patient.getByRole('button', { name: 'Keluar' }).click();
  await staff.getByRole('button', { name: 'Keluar' }).click();
  await expect(staff).toHaveURL(/\/masuk$/);

  await signIn(patient, '+62 813 2468 1357', PATIENT_PASSWORD);
  await patient.goto('/riwayat');
  const item = patient.locator('.history-list li').first();
  await expect(item).toContainText(ticket);
  await expect(item).toContainText('Sudah check-in');
  await item.getByRole('button', { name: 'Lihat detail' }).click();
  await expect(item.locator('.history-detail')).toContainText('Check-in');
  await expect(item.locator('.history-detail')).not.toContainText('Tidak tercatat');

  await signIn(staff, 'dina', STAFF_PASSWORD);
  await staff.getByRole('button', { name: 'Daftar booking' }).click();
  await staff.locator('.recent-bookings li').first().getByRole('button').click();
  await expect(detail).toContainText(`dikonfirmasi Dina · Pindai QR`);

  await staffBrowser.close();
});
