import { test, expect, type Browser, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { copyFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Ported from the Next.js app's tests/e2e/frontend.spec.ts. Data now comes from the
// seeded Laravel database (demo controls on) instead of the browser-side demo store.

// Every test starts from the freshly seeded snapshot written by server.mjs.
test.beforeEach(() =>
  copyFileSync(resolve('database/e2e-seed.sqlite'), resolve('database/e2e.sqlite')),
);

const password = process.env.HYTRACK_DEMO_PASSWORD ?? 'hytrack-demo';
const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());

// Staff and manager accounts sign in only at /masuk/internal, choosing their own role.
const INTERNAL: Record<string, 'Petugas' | 'Manajemen'> = {
  dina: 'Petugas',
  rina: 'Petugas',
  rani: 'Petugas',
  dinda: 'Petugas',
  manajer: 'Manajemen',
};
async function login(page: Page, username = 'siti', secret = password) {
  const role = INTERNAL[username];
  await page.goto(role ? '/masuk/internal' : '/masuk');
  if (role) await page.getByText(role, { exact: true }).click();
  await page.getByLabel(role ? 'ID pengguna atau email' : 'Nomor ponsel').fill(username);
  await page.getByLabel('Kata sandi', { exact: true }).fill(secret);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await expect(page).not.toHaveURL(/\/masuk(\/internal)?$/);
}
async function bookDoctor(page: Page, doctor: RegExp) {
  await page.goto('/poliklinik');
  await page.getByRole('button', { name: 'Lewati panduan' }).click();
  await page.getByRole('link', { name: /^Penyakit Dalam/ }).click();
  await page.getByRole('button', { name: doctor }).click();
  await page.getByRole('button', { name: 'Ambil antrean', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Tiket kunjungan' })).toBeVisible();
}
async function asStaff(browser: Browser, username: string) {
  const page = await (await browser.newContext()).newPage();
  await login(page, username);
  return page;
}

test('sign-in keeps each role on its own pages', async ({ page }) => {
  await page.goto('/kunjungan');
  await expect(page).toHaveURL(/\/masuk$/);
  await page.getByLabel('Nomor ponsel').fill('0812-5550-0842');
  await page.getByLabel('Kata sandi', { exact: true }).fill('salah');
  await page.getByRole('button', { name: 'Masuk' }).click();
  await expect(page.getByRole('alert')).toHaveText('Nomor ponsel atau kata sandi salah.');
  await login(page, 'dina');
  await expect(page).toHaveURL(/\/petugas$/);
  await page.goto('/kunjungan');
  await expect(page.getByRole('heading', { name: 'Akses ditolak' })).toBeVisible();
});

/** Sign-up step 2/3: a wrong code is refused, then the demo code (no SMS gateway) is typed. */
async function enterOtp(page: Page) {
  await expect(page).toHaveURL(/\/daftar\/verifikasi$/);
  await expect(page.getByRole('heading', { name: /Masukkan\s*kode OTP/ })).toBeVisible();
  const verify = page.getByRole('button', { name: 'Verifikasi & lanjutkan' });
  await expect(verify).toBeDisabled();
  const code = (await page.locator('.notice.info').textContent())!.match(/\d{6}/)![0];
  const wrong = code === '000000' ? '111111' : '000000';
  // Pasting fills all six boxes at once.
  await page.getByLabel('Digit 1 dari 6').focus();
  await page.evaluate((text) => {
    const data = new DataTransfer();
    data.setData('text', text);
    document.activeElement!.dispatchEvent(
      new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }),
    );
  }, wrong);
  await expect(page.getByLabel('Digit 6 dari 6')).toHaveValue(wrong[5]);
  await verify.click();
  await expect(page.locator('#otp-error')).toHaveText('Kode OTP salah. Periksa kembali SMS Anda.');
  // Typing moves from box to box.
  await page.getByLabel('Digit 1 dari 6').focus();
  await page.keyboard.type(code);
  await verify.click();
}

test('sign-up validates, toggles password visibility and signs the new patient in', async ({
  page,
}) => {
  await page.goto('/masuk');
  await page.getByRole('link', { name: 'Daftar sekarang' }).click();
  await expect(page).toHaveURL(/\/daftar$/);
  await page.getByRole('button', { name: 'Buat akun' }).click();
  await expect(page.locator('#auth-name-error')).toHaveText('Nama lengkap wajib diisi.');
  await expect(page.locator('#auth-terms-error')).toBeVisible();

  const secret = page.getByLabel('Kata sandi', { exact: true });
  await page.getByLabel('Nama lengkap').fill('Budi Santoso');
  await page.getByLabel('Nomor ponsel').fill('0813-2222-3333');
  await secret.fill('rahasia-123');
  await expect(secret).toHaveAttribute('type', 'password');
  await page.getByRole('button', { name: 'Tampilkan kata sandi' }).first().click();
  await expect(secret).toHaveAttribute('type', 'text');
  await expect(page.getByRole('button', { name: 'Sembunyikan kata sandi' })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  await page.getByLabel('Konfirmasi kata sandi').fill('rahasia-123');
  await page.getByRole('checkbox').check();
  await page.getByRole('button', { name: 'Buat akun' }).click();
  await enterOtp(page);
  await expect(page.getByRole('heading', { name: 'Nomor Anda terverifikasi.' })).toBeVisible();
  await expect(page.getByText('+62 813 •••• 3333')).toBeVisible();
  await page.getByRole('link', { name: 'Lanjut ke beranda' }).click();
  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole('heading', { name: 'Budi Santoso' })).toBeVisible();

  await page.goto('/akun');
  await page.getByRole('button', { name: 'Keluar' }).click();
  await login(page, '+62 813 2222 3333', 'rahasia-123');
  await expect(page.getByRole('heading', { name: 'Budi Santoso' })).toBeVisible();
});

test('sign-in and sign-up pages fit every width and pass accessibility checks', async ({
  page,
}) => {
  for (const width of [320, 412, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    for (const route of ['/masuk', '/daftar']) {
      await page.goto(route);
      await expect(page.locator('h1')).toHaveCount(1);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
        true,
      );
      const scan = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
        .analyze();
      expect(scan.violations, `${route} at ${width}px`).toEqual([]);
    }
  }
});

test('the splash covers only the first load and leaves without a fixed delay', async ({ page }) => {
  const response = await page.goto('/masuk');
  // In the first HTML, so it paints before the app bundle arrives.
  expect(await response!.text()).toContain('TEMAN PERJALANAN BEROBAT');
  await expect(page.locator('#splash')).toHaveCount(0, { timeout: 2000 });
  // Page changes are Inertia visits: the document never reloads, so it never returns.
  await page.getByRole('link', { name: 'Daftar sekarang' }).click();
  await expect(page).toHaveURL(/\/daftar$/);
  await expect(page.locator('#splash')).toHaveCount(0);
  // Reduced motion: removed at once, without the sweep.
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto('/masuk');
  await expect(page.locator('#splash')).toHaveCount(0);
});

test('patient flow, companion revocation and pharmacy completion', async ({ page }) => {
  await login(page);
  await bookDoctor(page, /dr. Rina/);
  await page.getByRole('link', { name: 'Tampilkan QR check-in' }).click();
  await page.getByRole('button', { name: 'Nomor check-in manual' }).click();
  await expect(page.getByText(/Nomor check-in: HT-\d{6}\./)).toBeVisible();
  await page.getByRole('button', { name: 'Simulasikan konfirmasi petugas' }).click();
  await page.getByRole('link', { name: 'Lihat antrean saya' }).click();
  await expect(page.getByRole('heading', { name: 'Menunggu giliran' })).toBeVisible();
  await expect(page.locator('.connection-row')).toContainText('Terhubung');

  // The invitation is accepted with the companion's own account and is single-use.
  await page.getByRole('link', { name: 'Bagikan ke pendamping' }).click();
  await page.getByRole('button', { name: 'Bagikan tautan aman' }).click();
  const link = (await page.locator('.share-link').textContent())!;
  const companion = await (await page.context().browser()!.newContext()).newPage();
  await companion.goto(link);
  await expect(companion.getByRole('link', { name: 'Masuk untuk menerima' })).toBeVisible();
  await login(companion, 'ahmad');
  await expect(companion).toHaveURL(/\/pantauan\//);
  await companion.getByRole('button', { name: 'Terima undangan' }).click();
  await expect(companion.getByRole('heading', { name: 'Siti A.' })).toBeVisible();
  await expect(companion.locator('.companion-status')).toHaveText('Menunggu');
  await expect(companion.locator('main')).toContainText('Di depan Anda');
  await expect(companion.locator('main')).not.toContainText('Aminah');
  const monitor = companion.url();
  await companion.goto(link);
  await expect(companion.getByText(/Undangan tidak berlaku/)).toBeVisible();

  await page.reload();
  await page.getByRole('button', { name: 'Cabut akses' }).click();
  await expect(page.getByText(/Dicabut/)).toBeVisible();
  await companion.goto(monitor);
  await expect(companion.getByText('Akses pendamping dicabut.')).toBeVisible();
  await companion.context().close();

  await page.getByRole('link', { name: 'Kembali' }).click();
  await page.getByRole('button', { name: 'Simulasikan pemeriksaan selesai' }).click();
  await page.getByRole('button', { name: 'Simulasikan lanjut ke farmasi' }).click();
  await page.getByRole('button', { name: 'Simulasikan obat siap' }).click();
  await expect(page.getByText('OBAT SIAP DIAMBIL')).toBeVisible();
  await page.getByRole('button', { name: 'Simulasikan pengambilan selesai' }).click();
  await expect(page.getByRole('heading', { name: 'Kunjungan selesai' })).toBeVisible();
});

test('staff check-in reaches the patient screen', async ({ page, browser }) => {
  await login(page);
  await bookDoctor(page, /dr. Rina/);
  await page.getByRole('link', { name: 'Tampilkan QR check-in' }).click();
  const code = (await page.locator('.qr-card .small').textContent())!.split(' ')[0];

  const staff = await asStaff(browser, 'dina');
  await staff.getByRole('button', { name: 'Scan QR kedatangan' }).click();
  await staff.getByPlaceholder('Contoh: HT-000027').fill(code);
  await staff.getByRole('button', { name: 'Cari tiket' }).click();
  await staff.getByRole('button', { name: 'Konfirmasi check-in' }).click();
  await expect(staff.locator('.scan-status')).toContainText('A-027 berhasil check-in pukul');

  await page.reload();
  await expect(page.getByText(/✓ Sudah check-in pukul/)).toBeVisible();
  await staff.context().close();
});

test('staff scanner identifies a ticket before confirming and rejects reuse', async ({
  page,
  browser,
}) => {
  await login(page);
  await bookDoctor(page, /dr. Rina/);
  await page.getByRole('link', { name: 'Tampilkan QR check-in' }).click();
  const code = (await page.locator('.qr-card .small').textContent())!.split(' ')[0];

  const staff = await asStaff(browser, 'dina');
  await staff.getByRole('button', { name: 'Scan QR kedatangan' }).click();
  const manual = staff.getByPlaceholder('Contoh: HT-000027');
  await manual.fill('HT1.1.20200101.00000000000000000000');
  await staff.getByRole('button', { name: 'Cari tiket' }).click();
  await expect(staff.locator('.scan-status')).toContainText('QR tidak valid');

  await manual.fill(code);
  await staff.getByRole('button', { name: 'Cari tiket' }).click();
  await expect(staff.locator('.scan-card.result')).toContainText('Siti Aminah');
  await page.reload();
  await expect(page.getByText(/Belum check-in/)).toBeVisible();

  await staff.getByRole('button', { name: 'Konfirmasi check-in' }).click();
  await expect(staff.locator('.scan-status')).toContainText('berhasil check-in');
  await manual.fill(code);
  await staff.getByRole('button', { name: 'Cari tiket' }).click();
  // A repeat scan shows the original check-in instead of recording it again.
  await expect(staff.locator('.scan-card.result')).toContainText('Sudah check-in pukul');
  await staff.context().close();
});

test('clinic search, missing schedules and booking guard', async ({ page }) => {
  await login(page);
  await page.goto('/poliklinik');
  await page.getByRole('textbox', { name: 'Cari nama poliklinik' }).fill('tidak ada');
  await expect(page.getByRole('heading', { name: 'Poliklinik tidak ditemukan' })).toBeVisible();
  await page.getByRole('button', { name: 'Hapus pencarian' }).click();
  await page.getByRole('link', { name: /^Saraf/ }).click();
  await expect(page.getByRole('heading', { name: 'Jadwal belum tersedia' })).toBeVisible();
  await bookDoctor(page, /dr. Rina/);
  await page.goto('/dokter');
  await expect(page.getByText('Anda sudah memiliki satu kunjungan aktif.')).toBeVisible();
});

test('Hya replies from the real queue and help search', async ({ page }) => {
  await login(page);
  await page.goto('/hya');
  await page.getByRole('button', { name: 'Kapan giliran saya?' }).click();
  await expect(page.getByRole('log')).toContainText('Belum ada kunjungan aktif');
  await page.getByRole('textbox', { name: 'Pertanyaan untuk Hya' }).fill('Apa dosis obat saya?');
  await page.getByRole('button', { name: 'Kirim pertanyaan' }).click();
  await expect(page.getByRole('log')).toContainText('tenaga kesehatan');
  await page.goto('/bantuan');
  await page.getByRole('textbox', { name: 'Cari topik bantuan' }).fill('QR');
  await expect(page.getByRole('link', { name: /^Check-in dengan QR/ })).toBeVisible();
});

test('staff guards, queue actions and history', async ({ page }) => {
  await login(page, 'dina');
  await expect(page.getByRole('button', { name: 'Tidak hadir', exact: true })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Panggil pasien berikutnya' })).toBeDisabled();
  await page.getByRole('button', { name: 'Panggil ulang', exact: true }).click();
  await page.getByRole('button', { name: 'Tidak hadir', exact: true }).click();
  await expect(page.locator('.dashboard-footnote')).toContainText('A-023 ditandai tidak hadir.');
  await page.getByRole('button', { name: 'Panggil pasien berikutnya' }).click();
  // Only the doctor confirms (Figma 47:2); the desk sees the visit's status.
  await page.getByRole('button', { name: 'Lihat pemeriksaan dokter' }).click();
  await expect(page.getByRole('heading', { name: 'Pasien di ruang dokter' })).toBeVisible();
  await page.getByRole('button', { name: 'Riwayat aktivitas', exact: true }).click();
  await expect(
    page
      .locator('.activity-table tr', { hasText: 'Pemeriksaan dimulai' })
      .filter({ hasText: 'A-024' }),
  ).toContainText('Dina');
});

test('management filtering, empty state and CSV download', async ({ page }) => {
  await login(page, 'manajer');
  const download = page.waitForEvent('download');
  await page.getByRole('link', { name: /Ekspor laporan/ }).click();
  expect((await download).suggestedFilename()).toBe(`hytrack-laporan-${today}.csv`);
  await page.getByRole('combobox', { name: 'Filter poliklinik' }).selectOption('Saraf');
  await expect(page.locator('.chart-empty')).toContainText('Belum ada data waktu tunggu');
  await expect(page.getByRole('button', { name: /Ekspor laporan/ })).toBeDisabled();
  await page.getByRole('navigation').getByRole('button', { name: 'Kinerja poliklinik' }).click();
  await expect(page.getByRole('heading', { name: 'Evaluasi menunggu data' })).toBeVisible();
});

const pages: [string, string][] = [
  ['siti', '/'],
  ['siti', '/poliklinik'],
  ['siti', '/dokter'],
  ['siti', '/hya'],
  ['siti', '/bantuan'],
  ['siti', '/akun'],
  ['siti', '/data-pasien'],
  ['siti', '/data-pasien/tambah'],
  ['dina', '/petugas'],
  ['rina', '/petugas'],
  ['rani', '/petugas'],
  ['dinda', '/petugas'],
  ['manajer', '/manajemen'],
];
async function visitAll(page: Page, check: (route: string) => Promise<void>) {
  let user = '';
  for (const [username, route] of pages) {
    if (username !== user) {
      await page.context().clearCookies();
      await login(page, (user = username));
    }
    await page.goto(route);
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('h1')).toBeVisible();
    await check(route);
  }
}

for (const width of [320, 375, 412, 768, 1024, 1440]) {
  test(`responsive routes have no overflow at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 960 });
    await visitAll(page, async (route) => {
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        route,
      ).toBe(true);
    });
  });
}

test('representative pages pass automated accessibility and runtime checks', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await visitAll(page, async (route) => {
    const scan = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
      .analyze();
    expect(scan.violations, route).toEqual([]);
  });
  expect(errors).toEqual([]);
});

test('internal sign-in checks the chosen role and passes accessibility checks', async ({
  page,
}) => {
  await page.goto('/masuk/internal');
  const scan = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(scan.violations).toEqual([]);
  await page.getByLabel('ID pengguna atau email').fill('rina');
  await page.getByLabel('Kata sandi', { exact: true }).fill(password);
  await page.getByText('Manajemen', { exact: true }).click();
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('Pilih peran Petugas');
  await page.getByText('Petugas', { exact: true }).click();
  await page.getByLabel('Kata sandi', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Pemeriksaan & resep' })).toBeVisible();
});

test('onboarding guide follows the flow, can be skipped and reopened without losing the visit', async ({
  page,
}) => {
  await login(page);
  const hint = page.locator('.guide-hint');
  await expect(hint).toContainText('LANGKAH 1 DARI 7');
  await expect(hint).toContainText('Pastikan nama pasien benar');
  await expect(page.locator('.guide-active [data-guide]')).toHaveCount(2);
  await page.getByRole('button', { name: 'Mengerti' }).click();
  await expect(hint).toHaveCount(0);
  await page.getByRole('link', { name: '+ Ambil antrean' }).click();
  await expect(hint).toContainText('Pilih poliklinik tujuan');
  await page.getByRole('link', { name: /^Penyakit Dalam/ }).click();
  await expect(hint).toContainText('LANGKAH 3 DARI 7');
  await page.getByRole('button', { name: 'Ambil antrean', exact: true }).click();
  await expect(hint).toContainText('Perhatikan waktu berangkat');
  await page.getByRole('button', { name: 'Lewati panduan' }).click();
  await expect(hint).toHaveCount(0);
  await page.getByRole('link', { name: 'Beranda', exact: true }).click();
  await expect(page.getByRole('link', { name: 'Lihat kunjungan' })).toBeVisible();
  await page.getByRole('button', { name: 'Tampilkan panduan penggunaan' }).click();
  await page.getByRole('link', { name: 'Kunjungan', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Tiket kunjungan' })).toBeVisible();
  await expect(hint).toContainText('Perhatikan waktu berangkat');
  await page.getByRole('link', { name: 'Tampilkan QR check-in' }).click();
  await expect(hint).toContainText('Tunjukkan QR ke petugas');
});

for (const [width, height] of [
  [375, 667],
  [768, 900],
  [1440, 900],
]) {
  test(`Hya bubble is fixed bottom-right above nav at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height });
    await login(page);
    for (const route of ['/', '/poliklinik', '/bantuan']) {
      await page.goto(route);
      await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
      // A Hya card with its own portrait takes the bubble's place.
      if (await page.locator('.hya-help:is(.with-avatar, .large)').count()) {
        await expect(page.locator('.hya-fab')).toBeHidden();
        continue;
      }
      const fab = await page.locator('.hya-fab').boundingBox();
      const nav = await page.locator('.bottom-nav').boundingBox();
      expect(fab && nav).toBeTruthy();
      // The plain card stops short of the bubble floating beside it (Figma 43:2 frame 04).
      if (await page.locator('.hya-help').count()) {
        const card = await page.locator('.hya-help').boundingBox();
        expect(card!.x + card!.width).toBeLessThanOrEqual(fab!.x);
      }
      expect(width - (fab!.x + fab!.width)).toBeLessThanOrEqual(20);
      expect(fab!.y + fab!.height).toBeLessThanOrEqual(nav!.y);
      expect(fab!.x).toBeGreaterThanOrEqual(0);
    }
  });
}

test('visit progress survives a refresh and the browser stores no patient identity', async ({
  page,
}) => {
  await login(page);
  await bookDoctor(page, /dr. Rina/);
  await page.reload();
  await expect(page.locator('.ticket-number')).toHaveText('A-027');
  await page.getByRole('link', { name: 'Tampilkan QR check-in' }).click();
  await page.getByRole('button', { name: 'Simulasikan konfirmasi petugas' }).click();
  await page.reload();
  await expect(page.getByText(/✓ Sudah check-in pukul/)).toBeVisible();
  await page.getByRole('link', { name: 'Lihat antrean saya' }).click();
  await page.getByRole('button', { name: 'Simulasikan pemeriksaan selesai' }).click();
  await page.getByRole('button', { name: 'Simulasikan lanjut ke farmasi' }).click();
  await page.getByRole('button', { name: 'Simulasikan obat siap' }).click();
  await page.reload();
  await expect(page.getByText('OBAT SIAP DIAMBIL')).toBeVisible();
  const stored = await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }));
  expect(stored).not.toMatch(/Siti|Aminah|0842|A-027/);
  await page.getByRole('button', { name: 'Simulasikan pengambilan selesai' }).click();
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Kunjungan selesai' })).toBeVisible();
});

test('ticket, departure time and queue follow the chosen doctor', async ({ page }) => {
  await login(page);
  await bookDoctor(page, /dr. Budi/);
  const main = page.locator('main');
  await expect(page.locator('.ticket-number')).toHaveText('B-014');
  await expect(main).toContainText('· 13.00–16.00');
  await expect(page.getByRole('heading', { name: 'Berangkat sebelum 12.20' })).toBeVisible();
  await expect(main).toContainText('Check-in paling lambat 12.45');
  await expect(main).not.toContainText('08.45');
  await page.getByRole('link', { name: 'Tampilkan QR check-in' }).click();
  await expect(main).toContainText('B-014 · Siti Aminah');
  await expect(main).toContainText('Belum check-in · Batas 12.45');
  await page.getByRole('button', { name: 'Simulasikan konfirmasi petugas' }).click();
  await page.getByRole('link', { name: 'Lihat antrean saya' }).click();
  await expect(page.locator('.queue-hero .ticket-number')).toHaveText('B-014');
  await expect(main).not.toContainText('A-02');
});

for (const width of [375, 768, 1440]) {
  test(`Hya bubble never covers a control at the end of check-in at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 740 });
    await login(page);
    await bookDoctor(page, /dr. Rina/);
    await page.getByRole('link', { name: 'Tampilkan QR check-in' }).click();
    await expect(page.locator('.qr-card')).toBeVisible();
    // Wherever the page is scrolled, the bubble never sits on the QR the desk has to scan.
    const onQr = () =>
      page.evaluate(() => {
        const bubble = document.querySelector('.hya-fab')!;
        const fab = bubble.getBoundingClientRect();
        const qr = document.querySelector('.ticket-qr')!.getBoundingClientRect();
        const apart =
          qr.right <= fab.left ||
          qr.left >= fab.right ||
          qr.bottom <= fab.top ||
          qr.top >= fab.bottom;
        return !apart && !bubble.classList.contains('is-tucked');
      });
    for (const offset of [0, 100, 200, 300]) {
      await page.evaluate((y) => window.scrollTo(0, y), offset);
      await expect.poll(onQr).toBe(false);
    }
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    // No room is reserved for the bubble: at the page end it tucks away over a control instead.
    const covered = () =>
      page.evaluate(() => {
        const bubble = document.querySelector('.hya-fab')!;
        if (bubble.classList.contains('is-tucked')) return [];
        const fab = bubble.getBoundingClientRect();
        return [...document.querySelectorAll('main button, main a, main input')]
          .filter((element) => {
            const box = element.getBoundingClientRect();
            return (
              box.right > fab.left &&
              box.left < fab.right &&
              box.bottom > fab.top &&
              box.top < fab.bottom
            );
          })
          .map((element) => element.textContent);
      });
    await expect.poll(covered).toEqual([]);
  });
}

test('the last section ends right above the bottom nav, with no band for the Hya bubble', async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 667 });
  await login(page);
  const gap = () =>
    page.evaluate(() => {
      const ends = [...document.querySelectorAll('#main > *')].map(
        (child) => child.getBoundingClientRect().bottom,
      );
      return document.querySelector('.bottom-nav')!.getBoundingClientRect().top - Math.max(...ends);
    });
  const fab = page.locator('.hya-fab');
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  // Only the page's own 28px bottom padding is left above the nav.
  expect(await gap()).toBeLessThanOrEqual(29);
  // Home ends with the Hya card; the bubble floats beside it (Figma 43:2 frame 04).
  await expect(fab).toBeVisible();
  const card = await page.locator('.hya-help').boundingBox();
  const bubble = await fab.boundingBox();
  expect(card!.x + card!.width).toBeLessThanOrEqual(bubble!.x);
});

test('first paint and refresh stay stable (CLS ≤ 0.1) with the guide shown', async ({ page }) => {
  await page.addInitScript(() => {
    const w = window as unknown as { cls: number };
    w.cls = 0;
    new PerformanceObserver((list) => {
      for (const entry of list.getEntries() as unknown as {
        value: number;
        hadRecentInput: boolean;
      }[])
        if (!entry.hadRecentInput) w.cls += entry.value;
    }).observe({ type: 'layout-shift', buffered: true });
  });
  const cls = () => page.evaluate(() => (window as unknown as { cls: number }).cls);
  await login(page);
  await expect(page.locator('.guide-hint')).toBeVisible();
  await page.waitForTimeout(1000);
  expect(await cls()).toBeLessThanOrEqual(0.1);
  await bookDoctor(page, /dr. Rina/);
  await page.reload();
  await expect(page.locator('.ticket-number')).toHaveText('A-027');
  await page.waitForTimeout(1000);
  expect(await cls()).toBeLessThanOrEqual(0.1);
});

// A delete is two requests, queued behind live polling on PHP's one-request-at-a-time server.
const ROUND_TRIP = { timeout: 20_000 };

/** Fails the test if any screen falls back to the browser's own confirm or alert. */
function noNativeDialogs(page: Page) {
  page.on('dialog', (dialog) => {
    void dialog.dismiss();
    throw new Error(`Native ${dialog.type()} dialog: ${dialog.message()}`);
  });
}

test('deleting a polyclinic is confirmed, survives errors and is sent once', async ({
  page,
  browser,
}) => {
  test.slow();
  noNativeDialogs(page);
  let sent = 0;
  page.on('request', (request) => {
    if (request.method() === 'DELETE') sent++;
  });
  await login(page, 'dina');
  await page.getByRole('button', { name: 'Data poliklinik' }).click();
  const row = page.locator('tr', { hasText: 'Ruang B-105' });
  const trigger = row.getByRole('button', { name: 'Hapus' });
  const dialog = page.getByRole('dialog', { name: 'Hapus poliklinik?' });
  const confirm = dialog.locator('.delete-confirm');
  const cancel = dialog.getByRole('button', { name: 'Batal' });

  // Editing the clinic that is about to go: its form must close with it.
  await row.getByRole('button', { name: 'Ubah' }).click();
  await trigger.click();
  await expect(dialog.locator('.delete-target')).toContainText('Anak');
  await expect(dialog.locator('.delete-target')).toContainText('Ruang B-105 · Gedung B, lantai 1');
  await expect(dialog.locator('.delete-target')).toContainText(/1 dokter · \d+ antrean terkait/);
  await expect(dialog.locator('.delete-note li')).toHaveCount(3);
  await expect(cancel).toBeFocused();
  await page.waitForTimeout(300); // let the opening animation finish before measuring
  const scan = await new AxeBuilder({ page }).include('.delete-dialog').analyze();
  expect(scan.violations).toEqual([]);

  for (const width of [320, 375, 768, 1440]) {
    const height = width < 400 ? 560 : 900;
    await page.setViewportSize({ width, height });
    const box = (await dialog.boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.y).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(width);
    expect(box.y + box.height).toBeLessThanOrEqual(height);
    expect(await dialog.evaluate((node) => node.scrollWidth <= node.clientWidth)).toBe(true);
    await page.screenshot({ path: `test-results/delete-polyclinic-${width}.png` });
  }

  // Tab stays inside; Esc and Batal change nothing and hand focus back.
  await page.keyboard.press('Tab');
  await expect(confirm).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(cancel).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.locator('.delete-dialog')).toHaveCount(0);
  await expect(trigger).toBeFocused();
  await trigger.click();
  await cancel.click();
  await expect(page.locator('.delete-dialog')).toHaveCount(0);
  await expect(row).toBeVisible();
  expect(sent).toBe(0);

  // A lost connection and a server error keep the dialog and the record.
  const route = '**/petugas/poliklinik/*';
  await trigger.click();
  await page.route(route, (request) => request.abort());
  await confirm.click();
  await expect(dialog.getByRole('alert')).toContainText('Koneksi ke server terputus');
  await expect(confirm).toBeEnabled();
  await page.unroute(route);
  await page.route(route, (request) =>
    request.fulfill({ status: 500, contentType: 'text/html', body: '<h1>boom</h1>' }),
  );
  await confirm.click();
  await expect(dialog.getByRole('alert')).toContainText('gagal diproses server');
  await expect(page.locator('dialog')).toHaveCount(1);
  await page.unroute(route);

  // While the request runs, nothing can send it again or close the dialog.
  let release!: () => void;
  const held = new Promise<void>((done) => (release = done));
  await page.route(route, async (request) => {
    await held;
    await request.continue();
  });
  sent = 0;
  await confirm.dblclick();
  await expect(confirm).toHaveText('Menghapus…');
  await expect(confirm).toBeDisabled();
  await expect(cancel).toBeDisabled();
  await page.screenshot({ path: 'test-results/delete-polyclinic-busy.png' });
  await confirm.click({ force: true });
  await cancel.click({ force: true });
  await page.keyboard.press('Escape');
  await page.keyboard.press('Escape');
  await expect(dialog).toBeVisible();
  release();
  await expect(page.locator('.delete-dialog')).toHaveCount(0, ROUND_TRIP);
  expect(sent).toBe(1);
  await expect(page.getByRole('status').filter({ hasText: 'Anak dihapus' })).toBeVisible();
  await expect(row).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Tambah poliklinik' })).toBeVisible();
  await expect(page.locator('main')).toBeFocused();
  await page.unroute(route);

  // Deleted by a colleague in the meantime: said so in the dialog, no error screen.
  const heart = page.locator('tr', { hasText: 'Ruang A-210' });
  await heart.getByRole('button', { name: 'Hapus' }).click();
  const colleague = await asStaff(browser, 'dina');
  await colleague.getByRole('button', { name: 'Data poliklinik' }).click();
  const theirs = colleague.locator('tr', { hasText: 'Ruang A-210' });
  await theirs.getByRole('button', { name: 'Hapus' }).click();
  await colleague.locator('.delete-confirm').click();
  await expect(colleague.getByText(/Poliklinik Jantung dihapus/)).toBeVisible(ROUND_TRIP);
  await colleague.context().close();
  await confirm.click();
  await expect(dialog.getByRole('alert')).toContainText('mungkin sudah dihapus', ROUND_TRIP);
  await cancel.click();
  await expect(page.getByRole('heading', { name: 'Data poliklinik' })).toBeVisible();
});

test('patient data, a visit and companion access are removed through the same dialog', async ({
  page,
  browser,
}) => {
  test.slow();
  noNativeDialogs(page);
  await login(page);

  await page.goto('/data-pasien/tambah');
  await page.getByLabel('Nama lengkap *').fill('Rafi Pratama');
  await page.getByLabel('Tanggal lahir *').fill('2015-04-02');
  await page.getByLabel('Jenis kelamin *').selectOption('male');
  await page.getByLabel('Nomor ponsel *').fill('0812 3456 7890');
  await page.getByLabel('Alamat lengkap *').fill('Jalan Melati 3');
  await page.getByLabel('Kota / kabupaten *').fill('Bandung');
  await page.getByRole('button', { name: 'Simpan data pasien' }).click();
  const record = page.locator('.patient-card', { hasText: 'Rafi Pratama' });
  await record.getByRole('button', { name: 'Hapus data pasien' }).click();
  const patient = page.getByRole('dialog', { name: 'Hapus data pasien?' });
  await expect(patient.locator('.delete-target')).toContainText('Rafi Pratama');
  await page.setViewportSize({ width: 375, height: 740 });
  await page.waitForTimeout(300);
  await page.screenshot({ path: 'test-results/delete-patient-375.png' });
  await patient.getByRole('button', { name: 'Batal' }).click();
  await expect(page.locator('.delete-dialog')).toHaveCount(0);
  await expect(record).toBeVisible();
  await record.getByRole('button', { name: 'Hapus data pasien' }).click();
  await patient.getByRole('button', { name: 'Hapus data' }).click();
  await expect(page.getByText('Data Rafi Pratama dihapus dari daftar Anda.')).toBeVisible(
    ROUND_TRIP,
  );
  await expect(record).toHaveCount(0);
  await expect(page.locator('.delete-dialog')).toHaveCount(0);

  // Companion: stop following a visit.
  await bookDoctor(page, /dr. Rina/);
  await page.getByRole('link', { name: 'Bagikan ke pendamping' }).click();
  await page.getByRole('button', { name: 'Bagikan tautan aman' }).click();
  const link = (await page.locator('.share-link').textContent())!;
  const companion = await (await browser.newContext()).newPage();
  noNativeDialogs(companion);
  await companion.goto(link);
  await login(companion, 'ahmad');
  await companion.getByRole('button', { name: 'Terima undangan' }).click();
  await companion.getByRole('button', { name: 'Berhenti memantau' }).click();
  const stop = companion.getByRole('dialog', { name: 'Berhenti memantau?' });
  await expect(stop.locator('.delete-target')).toContainText('Siti A.');
  await companion.waitForTimeout(300);
  await companion.screenshot({ path: 'test-results/delete-companion.png' });
  await stop.getByRole('button', { name: 'Batal' }).click();
  await expect(companion.locator('.delete-dialog')).toHaveCount(0);
  await expect(companion.locator('.companion-status')).toBeVisible();
  await companion.getByRole('button', { name: 'Berhenti memantau' }).click();
  await stop.getByRole('button', { name: 'Berhenti memantau' }).click();
  await expect(companion).toHaveURL(/\/pendampingan$/, ROUND_TRIP);
  await expect(companion.getByText('Anda berhenti memantau kunjungan ini.')).toBeVisible();
  await companion.context().close();

  // The visit itself: a second tab cancels first, so the dialog reports the server's refusal.
  await page.goto('/kunjungan');
  const open = page.getByRole('button', { name: 'Batalkan kunjungan' });
  const visit = page.getByRole('dialog', { name: 'Batalkan kunjungan?' });
  await open.click();
  await expect(visit.locator('.delete-target')).toContainText('A-027 · Penyakit Dalam');
  await page.waitForTimeout(300);
  await page.screenshot({ path: 'test-results/cancel-visit-375.png' });
  await visit.getByRole('button', { name: 'Kembali' }).click();
  await expect(page.locator('.delete-dialog')).toHaveCount(0);
  await open.click();
  const other = await page.context().newPage();
  await other.goto('/kunjungan');
  await other.getByRole('button', { name: 'Batalkan kunjungan' }).click();
  await other.getByRole('button', { name: 'Batalkan antrean' }).click();
  await expect(other.getByText('Kunjungan dibatalkan.')).toBeVisible(ROUND_TRIP);
  await other.close();
  await visit.getByRole('button', { name: 'Batalkan antrean' }).click();
  await expect(visit.getByRole('alert')).toBeVisible(ROUND_TRIP);
  await visit.getByRole('button', { name: 'Kembali' }).click();
  await expect(page.locator('.delete-dialog')).toHaveCount(0);
});
