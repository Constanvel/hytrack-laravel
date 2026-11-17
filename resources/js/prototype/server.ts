// Prototype mode: a pretend Laravel that answers Inertia visits from recorded fixtures.
// No database, no session, nothing leaves the browser. Re-record with `npm run prototype:record`.
import data from './fixtures.json';

type Props = Record<string, unknown>;
type Recorded = { component: string; props: Props };
type Flash = Record<string, unknown>;
type User = 'siti' | 'ahmad' | 'dina' | 'manajer';
type Step = { shared: Record<string, Props>; pages: Record<string, Recorded> };
const fixtures = data as unknown as {
  base: Props;
  users: Record<User, { auth: Props; home: string }>;
  pages: Record<string, Recorded>;
  steps: Step[];
  flash: { scan: Flash; invite: Flash };
  hya: Record<string, Record<string, string>>;
  csv: Record<string, string>;
  shareId: number;
};

export const PREFIX = '/prototipe';
export interface State {
  user: User | null;
  step: number;
}
export const initialState: State = { user: null, step: 0 };
export interface Page {
  component: string;
  props: Props;
  url: string;
  version: string;
  flash: Flash;
  clearHistory: boolean;
  encryptHistory: boolean;
  rescuedProps: string[];
  rememberedState: Record<string, unknown>;
}

const LAST = fixtures.steps.length - 1;
// Steps recorded by record.mjs: 0 no visit, 1 booked, 2 invite sent, 3 companion following, 4+ the visit moving on.
const BOOKED = 1;
const INVITED = 2;
const FOLLOWING = 3;
const CHECKED_IN = 4;
const SAVED = 'Mode prototype: perubahan hanya contoh dan tidak disimpan.';

/** Roles own their own area; everyone else is sent home, as Laravel's role middleware does. */
function owner(path: string): 'patient' | 'staff' | 'manager' | 'guest' | 'any' {
  if (path === '/masuk' || path === '/daftar') return 'guest';
  if (path.startsWith('/pantauan/')) return 'any';
  if (path.startsWith('/petugas')) return 'staff';
  if (path.startsWith('/manajemen')) return 'manager';
  return 'patient';
}

function lookup(state: State, path: string, query: URLSearchParams): Recorded | null {
  const user = state.user ?? 'guest';
  if (path === '/') return { component: 'Home', props: {} };
  if (path.startsWith('/pantauan/'))
    return fixtures.pages[`${user === 'guest' ? 'guest' : 'ahmad'}|/pantauan/*`];
  if (/^\/pendampingan\/\d+$/.test(path)) path = `/pendampingan/${fixtures.shareId}`;
  if (/^\/data-pasien\/\d+\/ubah$/.test(path)) {
    const key = Object.keys(fixtures.pages).find(
      (k) => k.startsWith('siti|/data-pasien/') && k.endsWith('/ubah'),
    );
    return key ? fixtures.pages[key] : null;
  }
  query.delete('tanggal'); // recorded for one day only
  const poli = query.get('poli');
  const candidates = [
    query.size ? `${path}?${query}` : path,
    poli && poli !== '1' ? `${path}?poli=${poli}` : path,
  ];
  const step = fixtures.steps[state.step].pages;
  for (const who of user === 'ahmad' ? ['ahmad', 'siti'] : [user]) {
    for (const key of candidates) {
      const hit = step[`${who}|${key}`] ?? fixtures.pages[`${who}|${key}`];
      if (hit) return hit;
    }
  }
  return null;
}

function render(state: State, url: string, flash: Flash = {}): Page {
  const parsed = new URL(url, 'http://prototype');
  const path = parsed.pathname.replace(/\/+$/, '') || '/';
  const area = owner(path);
  const user = state.user;
  if (!user && area !== 'guest' && area !== 'any') return render(state, '/masuk', flash);
  if (user) {
    const role = fixtures.users[user].auth.role as string;
    const allowed = area === 'any' || area === (role === 'patient' ? 'patient' : role);
    if (!allowed) return render(state, fixtures.users[user].home, flash);
  }
  const found = lookup(state, path, parsed.searchParams);
  const page = found ?? { component: 'ErrorPage', props: { status: 404 } };
  const shared =
    user === 'siti' || user === 'ahmad'
      ? fixtures.steps[state.step].shared[user]
      : { ...fixtures.steps[state.step].shared.siti, visit: null, unread: 0, following: 0 };
  const extra = page.component === 'Login' ? { demoAccounts: null, prototype: true } : {};
  return {
    component: page.component,
    props: {
      ...fixtures.base,
      demo: true,
      auth: user ? fixtures.users[user].auth : null,
      errors: {},
      ...shared,
      ...page.props,
      ...extra,
    },
    url: PREFIX + (path === '/' ? '' : path) + parsed.search,
    version: '',
    flash,
    clearHistory: false,
    encryptHistory: false,
    rescuedProps: [],
    rememberedState: {},
  };
}

/** Which account a sign-in becomes: staff and manager by username, Ahmad by his number, otherwise Siti. */
export function accountFor(username: string): User {
  const name = username.trim().toLowerCase();
  if (name === 'dina' || name === 'petugas') return 'dina';
  if (name === 'manajer' || name === 'manajemen') return 'manajer';
  if (name.replace(/\D/g, '').endsWith('0843') || name === 'ahmad') return 'ahmad';
  return 'siti';
}

/**
 * Handles one Inertia request. `path` has no /prototipe prefix; `from` is the page the visit
 * started on, used where Laravel would `return back()`.
 */
export function respond(
  state: State,
  method: string,
  path: string,
  body: Props = {},
  from = '/',
): { state: State; page: Page } {
  method = method.toUpperCase();
  const s = { ...state };
  const go = (url: string, flash: Flash = {}) => ({ state: s, page: render(s, url, flash) });
  const back = (flash: Flash = {}) => go(from, flash);
  const route = path.split('?')[0];
  if (method === 'GET') return go(path);

  if (route === '/masuk') {
    s.user = accountFor(String(body.username ?? ''));
    return go(fixtures.users[s.user].home);
  }
  if (route === '/daftar') {
    s.user = 'siti';
    return go('/', { status: 'Mode prototype: Anda masuk sebagai pasien contoh, Siti Aminah.' });
  }
  if (route === '/keluar') {
    s.user = null;
    return go('/masuk');
  }
  if (!s.user) return go('/masuk');

  if (route === '/kunjungan' && method === 'POST') {
    if (s.step !== 0 && s.step !== LAST)
      return go('/kunjungan', { error: 'Anda masih memiliki kunjungan aktif.' });
    s.step = BOOKED;
    // Only one booking was recorded; say so when the patient picked another schedule.
    const recorded = Object.keys(fixtures.steps[0].pages)
      .join()
      .match(/dokter=(\d+)/)?.[1];
    return go(
      '/kunjungan',
      String(body.doctor_id) === recorded
        ? {}
        : { status: 'Mode prototype: antrean contoh selalu memakai jadwal dr. Budi Santoso.' },
    );
  }
  if (route === '/kunjungan' && method === 'DELETE') {
    if (s.step > FOLLOWING)
      return back({ error: 'Kunjungan yang sudah check-in tidak dapat dibatalkan.' });
    s.step = 0;
    return back({ status: 'Kunjungan dibatalkan.' });
  }
  if (route === '/kunjungan/simulasi') {
    s.step = s.step === 0 ? 0 : Math.min(Math.max(s.step + 1, CHECKED_IN), LAST);
    return back();
  }
  if (route === '/pendamping' && method === 'POST') {
    if (s.step === 0 || s.step === LAST)
      return back({ error: 'Belum ada kunjungan aktif untuk dibagikan.' });
    if (s.step === BOOKED) s.step = INVITED;
    const origin = typeof location === 'undefined' ? '' : location.origin;
    const link = String(fixtures.flash.invite.shareLink).replace('__ORIGIN__', origin + PREFIX);
    return back({ ...fixtures.flash.invite, shareLink: link });
  }
  if (route.startsWith('/pendamping/') && method === 'DELETE') {
    if (s.step === INVITED || s.step === FOLLOWING) s.step = BOOKED;
    return back({ status: 'Akses pendamping dicabut.' });
  }
  if (route.startsWith('/pantauan/')) {
    if (s.step === INVITED) s.step = FOLLOWING;
    return go(`/pendampingan/${fixtures.shareId}`);
  }
  if (route.startsWith('/pendampingan/') && method === 'DELETE') {
    if (s.step === FOLLOWING) s.step = INVITED;
    return go('/pendampingan', { status: 'Anda berhenti memantau kunjungan ini.' });
  }
  if (route.startsWith('/data-pasien')) return go('/data-pasien', { status: SAVED });
  if (route === '/petugas/scan')
    return back(
      s.step > 0 && s.step < CHECKED_IN
        ? fixtures.flash.scan
        : { error: 'Mode prototype: QR contoh hanya berlaku sebelum check-in.' },
    );
  if (route.startsWith('/petugas')) {
    if (s.step === 0)
      return back({
        error: 'Mode prototype: belum ada pasien contoh. Ambil antrean sebagai Siti dulu.',
      });
    s.step = s.step < CHECKED_IN ? CHECKED_IN : Math.min(s.step + 1, LAST);
    return back({ status: 'Mode prototype: status kunjungan Siti diperbarui.' });
  }
  return back({ status: SAVED });
}

/** Hya answers from the closest recorded step at or before this one. */
export function hya(state: State, question: string): string {
  const recorded = Object.keys(fixtures.hya)
    .map(Number)
    .filter((n) => n <= state.step);
  const replies = fixtures.hya[String(Math.max(0, ...recorded))] ?? {};
  return replies[question] ?? 'Mode prototype: Hya hanya menjawab pertanyaan contoh di bawah.';
}

export function csv(query: string): string {
  const poli = new URLSearchParams(query).get('poli');
  return fixtures.csv[poli ? `?poli=${poli}` : ''] ?? fixtures.csv[''];
}
