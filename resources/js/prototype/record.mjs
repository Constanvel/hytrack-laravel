// Records the page props the prototype replays (npm run prototype:record).
// Runs the real app on a freshly seeded SQLite database, signs in as each demo role,
// walks one visit from booking to pickup and writes everything to fixtures.json.
// The prototype itself never talks to Laravel or a database.
import { spawn } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const port = 8040;
const base = `http://127.0.0.1:${port}`;
const out = resolve('resources/js/prototype/fixtures.json');
const password = 'hytrack-demo';
// Props every page receives; the prototype stores them once per user and step.
const SHARED = ['visit', 'unread', 'following', 'now', 'today'];

const server = spawn('node', ['tests/e2e/server.mjs'], {
  stdio: 'inherit',
  env: {
    ...process.env,
    APP_ENV: 'local',
    APP_URL: base,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: resolve('database/prototype.sqlite'),
    E2E_PORT: String(port),
    CACHE_STORE: 'array',
    BCRYPT_ROUNDS: '4',
    HYTRACK_DEMO_CONTROLS: 'true',
  },
});
const stop = () => server.kill();
process.on('exit', stop);

class Client {
  cookies = new Map();
  version = '';

  cookieHeader() {
    return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
  }

  remember(res) {
    for (const line of res.headers.getSetCookie()) {
      const [pair] = line.split(';');
      const at = pair.indexOf('=');
      this.cookies.set(pair.slice(0, at), pair.slice(at + 1));
    }
  }

  headers(json = true) {
    return {
      'X-Inertia': 'true',
      'X-Inertia-Version': this.version,
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': decodeURIComponent(this.cookies.get('XSRF-TOKEN') ?? ''),
      Accept: json ? 'text/html, application/xhtml+xml' : 'application/json',
      'Content-Type': 'application/json',
      Cookie: this.cookieHeader(),
    };
  }

  async boot() {
    const res = await fetch(`${base}/masuk`, { headers: { Cookie: this.cookieHeader() } });
    this.remember(res);
    const html = await res.text();
    const json =
      html.match(/<script[^>]*data-page="app"[^>]*>([^<]+)</)?.[1] ??
      html.match(/data-page="([^"]+)"/)?.[1];
    const page = JSON.parse(json.replace(/&quot;/g, '"').replace(/&amp;/g, '&'));
    this.version = page.version ?? '';
    return page;
  }

  /** An Inertia visit, following Laravel's redirects like the browser would. */
  async visit(method, path, data) {
    let res = await fetch(base + path, {
      method,
      headers: this.headers(),
      body: data ? JSON.stringify(data) : undefined,
      redirect: 'manual',
    });
    for (let hops = 0; res.status >= 300 && res.status < 400 && hops < 5; hops++) {
      this.remember(res);
      res = await fetch(new URL(res.headers.get('location'), base), {
        headers: this.headers(),
        redirect: 'manual',
      });
    }
    this.remember(res);
    if (res.status !== 200) throw new Error(`${method} ${path} → ${res.status}`);
    return res.json();
  }

  async json(path, data) {
    const res = await fetch(base + path, {
      method: 'POST',
      headers: this.headers(false),
      body: JSON.stringify(data),
    });
    this.remember(res);
    return res.json();
  }

  async text(path) {
    const res = await fetch(base + path, { headers: { Cookie: this.cookieHeader() } });
    return res.text();
  }

  async login(username) {
    await this.boot();
    return this.visit('POST', '/masuk', { username, password });
  }
}

async function ready() {
  for (let i = 0; i < 240; i++) {
    try {
      if ((await fetch(`${base}/masuk`)).ok) return;
    } catch {
      // still booting
    }
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error('Laravel did not start');
}

const strip = (page) => {
  const props = { ...page.props };
  for (const key of [...SHARED, 'auth', 'hospital', 'demo', 'pollSeconds', 'errors'])
    delete props[key];
  return { component: page.component, props };
};

await ready();
const guest = new Client();
const users = {
  siti: new Client(),
  ahmad: new Client(),
  dina: new Client(),
  manajer: new Client(),
};
const fixtures = { base: {}, users: {}, pages: {}, steps: [], flash: {}, hya: {}, csv: {} };

const first = await guest.boot();
fixtures.base = { hospital: first.props.hospital, pollSeconds: first.props.pollSeconds };
fixtures.pages['guest|/masuk'] = strip(first);
fixtures.pages['guest|/daftar'] = strip(await guest.visit('GET', '/daftar'));

for (const [name, login] of Object.entries({
  siti: '0812-5550-0842',
  ahmad: '0812-5550-0843',
  dina: 'dina',
  manajer: 'manajer',
})) {
  const page = await users[name].login(login);
  fixtures.users[name] = { auth: page.props.auth, home: page.url };
}
const { siti, ahmad, dina, manajer } = users;
const save = async (user, path, into = fixtures.pages) => {
  into[`${user}|${path}`] = strip(await users[user].visit('GET', path));
};

const schedule = await siti.visit('GET', '/dokter?poli=1');
const budi = schedule.props.doctors.find((d) => d.name.includes('Budi'));
const today = schedule.props.days[0].date;
let shareId = null;

async function snapshot() {
  const step = { shared: {}, pages: {} };
  for (const user of ['siti', 'ahmad']) {
    const home = await users[user].visit('GET', '/');
    step.shared[user] = Object.fromEntries(SHARED.map((key) => [key, home.props[key]]));
  }
  const pages = step.pages;
  for (const path of ['/notifikasi', '/pendamping']) await save('siti', path, pages);
  await save('ahmad', '/pendampingan', pages);
  if (shareId) await save('ahmad', `/pendampingan/${shareId}`, pages);
  await save('dina', '/petugas', pages);
  await save('dina', `/petugas?poli=1&dokter=${budi.id}`, pages);
  fixtures.steps.push(step);
}
async function askHya() {
  const replies = {};
  for (const question of [
    'Kapan giliran saya?',
    'Ke ruangan mana saya harus pergi?',
    'Bagaimana cara check-in?',
    'Bagaimana jika saya terlambat?',
  ]) {
    replies[question] = (await siti.json('/hya', { question })).reply;
  }
  fixtures.hya[fixtures.steps.length - 1] = replies;
}

// Step 0: signed in, no visit yet. Screens that only read shared props are recorded once.
await snapshot();
await askHya();
for (const path of [
  '/poliklinik',
  '/dokter?poli=1',
  '/dokter?poli=2',
  '/dokter?poli=3',
  '/dokter?poli=4',
  '/kunjungan',
  '/check-in',
  '/akun',
  '/hya',
  '/bantuan',
  '/bantuan/check-in',
  '/bantuan/antrean',
  '/petunjuk-ruangan',
  '/data-pasien',
  '/data-pasien/tambah',
]) {
  await save('siti', path);
}
for (const record of fixtures.pages['siti|/data-pasien'].props.patients) {
  await save('siti', `/data-pasien/${record.id}/ubah`);
}
for (const query of ['', '?poli=2', '?poli=3', '?poli=4']) await save('dina', `/petugas${query}`);
for (const query of ['', '?poli=1', '?poli=2', '?poli=3', '?poli=4']) {
  await save('manajer', `/manajemen${query}`);
  fixtures.csv[query] = await manajer.text(`/manajemen/ekspor${query}`);
}

// Step 1: booked with dr. Budi today.
fixtures.flash.book = (
  await siti.visit('POST', '/kunjungan', { doctor_id: budi.id, date: today })
).flash;
await snapshot();
await askHya();
const qr = fixtures.steps[1].shared.siti.visit.checkInQr;
fixtures.flash.scan = (await dina.visit('POST', '/petugas/scan', { code: qr })).flash;

// Step 2: companion invited. Step 3: Ahmad accepted.
const invited = await siti.visit('POST', '/pendamping');
fixtures.flash.invite = invited.flash;
const token = invited.flash.shareLink.split('/pantauan/')[1];
await snapshot();
const visitor = new Client();
await visitor.boot();
fixtures.pages['guest|/pantauan/*'] = strip(await visitor.visit('GET', `/pantauan/${token}`));
fixtures.pages['ahmad|/pantauan/*'] = strip(await ahmad.visit('GET', `/pantauan/${token}`));
const accepted = await ahmad.visit('POST', `/pantauan/${token}`);
fixtures.flash.accept = accepted.flash;
shareId = Number(accepted.url.split('/').pop());
fixtures.shareId = shareId;
await snapshot();

// Steps 4+: the visit moves on exactly as the demo controls move it.
fixtures.flash.simulate = {};
for (const action of ['check-in', 'examined', 'laboratory', 'medicine-ready', 'picked-up']) {
  const page = await siti.visit('POST', '/kunjungan/simulasi', { action });
  if (page.flash?.error) continue; // e.g. no laboratory ordered for this visit
  fixtures.flash.simulate[action] = page.flash;
  await snapshot();
  if (action === 'check-in') await askHya();
}

// Links shown to the user (the companion invite) point back into the prototype.
const json = JSON.stringify(fixtures).replaceAll(base, '__ORIGIN__');
writeFileSync(out, json);
console.log(
  `Recorded ${fixtures.steps.length} steps, ${Object.keys(fixtures.pages).length} pages → ${out}`,
);
stop();
process.exit(0);
