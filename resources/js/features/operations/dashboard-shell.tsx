import { Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Avatar, Notice } from '@/components/ui';
import { BOOKINGS } from './bookings';
import { CLINICS } from './clinic-admin';
import { syncLabel, useLive } from '@/lib/live';

export const SCAN = 'Scan QR kedatangan';

/** Breadcrumb under "Pelayanan" (Figma 43:2 frames 11, 18, 21–23). */
const crumb = (section: string) =>
  section === SCAN ? 'Check-in kedatangan' : section === 'Antrean poli' ? 'Rawat jalan' : section;

/** "Rabu, 4 November 2026" → "4 Nov 2026" (Figma 43:2 filters). */
export const shortDate = (today?: string) => {
  const [day, month, year] = (today ?? '').replace(/^[^,]+,\s*/, '').split(' ');
  return month ? `${day} ${month.slice(0, 3)} ${year}` : '';
};

export function DashboardShell({
  management = false,
  section,
  onSection,
  status,
  children,
}: {
  management?: boolean;
  section: string;
  onSection: (section: string) => void;
  /** Replaces the connection line (management: "Pembaruan terakhir …"). */
  status?: string;
  children: ReactNode;
}) {
  const { props, flash } = usePage();
  const { auth, today } = props;
  const live = useLive();
  // Figma 43:2 frames 18 and 21–23 list five staff sections; booking and clinic
  // data are existing PRD features without a frame, so they follow those five.
  const items = management
    ? ['Ringkasan layanan', 'Waktu tunggu', 'Kinerja poliklinik', 'Laporan']
    : [SCAN, 'Antrean poli', 'Alur pelayanan', 'Farmasi', 'Riwayat aktivitas', BOOKINGS, CLINICS];
  return (
    <div className="dashboard">
      <aside className="dashboard-sidebar">
        <Link className="brand" href="/">
          HyTrack
        </Link>
        <p className="sidebar-label">{management ? 'MANAJEMEN LAYANAN' : 'RUANG PETUGAS'}</p>
        <nav aria-label={management ? 'Navigasi manajemen' : 'Navigasi petugas'}>
          {items.map((item) => (
            <button
              key={item}
              aria-current={item === section ? 'page' : undefined}
              onClick={() => onSection(item)}
            >
              {item}
            </button>
          ))}
        </nav>
        <div className="sidebar-footer">
          <Avatar />
          <div className="sidebar-account">
            <strong>{auth?.name}</strong>
            <button onClick={() => router.post('/keluar')}>Keluar</button>
          </div>
        </div>
      </aside>
      <div className="dashboard-body">
        <header className="dashboard-topbar">
          <span>{management ? `Analitik / ${section}` : `Pelayanan / ${crumb(section)}`}</span>
          <strong role="status" className={live.online ? undefined : 'offline'}>
            {status ??
              (live.online ? `● Terhubung  ·  ${live.syncedAt.slice(0, 5)} WIB` : syncLabel(live))}
          </strong>
          {/* Figma 43:2: the date without the weekday. */}
          <span>{today?.replace(/^[^,]+,\s*/, '')}</span>
        </header>
        <main id="main" className="dashboard-main">
          {/* The scanner shows its own errors next to the camera. */}
          {flash.error && section !== SCAN && (
            <Notice tone="warning" role="alert">
              {flash.error}
            </Notice>
          )}
          {children}
        </main>
      </div>
    </div>
  );
}

/** Page title with its subtitle and a pill or button on the right. */
export function DashboardTitle({
  title,
  subtitle,
  aside,
  flat = false,
}: {
  title: string;
  subtitle: string;
  aside?: ReactNode;
  /** Frames 21–26: a 46px pill and a tighter subtitle. */
  flat?: boolean;
}) {
  return (
    <div className={`dashboard-title ${flat ? 'flat' : ''}`}>
      <div>
        <h1>{title}</h1>
        <p className="muted">{subtitle}</p>
      </div>
      {aside}
    </div>
  );
}

/** Metric card. Class names pick the accent bar (mint, gold, slate, olive) and layout. */
export function Stat({
  label,
  value,
  detail,
  className = '',
  tone,
}: {
  label: string;
  value: string;
  detail: string;
  className?: string;
  tone?: 'warm' | 'good';
}) {
  return (
    <section className={`stat-card ${className}`}>
      <p>{label}</p>
      <strong className={tone}>{value}</strong>
      <small>{detail}</small>
    </section>
  );
}

/** Rows per page and the slice for the current page. */
export function pageOf<T>(rows: T[], page: number, size: number) {
  const pages = Math.max(1, Math.ceil(rows.length / size));
  const current = Math.min(page, pages);
  const start = (current - 1) * size;
  return {
    pages,
    current,
    rows: rows.slice(start, start + size),
    from: start + 1,
    to: start + size,
  };
}

/** "←  1  2  3  …  16  →" (Figma 43:2 tables). */
export function Pager({
  page,
  pages,
  onPage,
}: {
  page: number;
  pages: number;
  onPage: (page: number) => void;
}) {
  if (pages < 2) return null;
  // 0 marks a gap.
  const shown =
    pages <= 5
      ? [...Array(pages).keys()].map((i) => i + 1)
      : page <= 3
        ? [1, 2, 3, 0, pages]
        : page >= pages - 2
          ? [1, 0, pages - 2, pages - 1, pages]
          : [1, 0, page, 0, pages];
  return (
    <nav className="pager" aria-label="Halaman">
      <button
        aria-label="Halaman sebelumnya"
        disabled={page === 1}
        onClick={() => onPage(page - 1)}
      >
        ←
      </button>
      {shown.map((item, index) =>
        item ? (
          <button
            key={item}
            aria-current={item === page ? 'page' : undefined}
            onClick={() => onPage(item)}
          >
            {item}
          </button>
        ) : (
          <span key={`gap-${index}`}>…</span>
        ),
      )}
      <button
        aria-label="Halaman berikutnya"
        disabled={page === pages}
        onClick={() => onPage(page + 1)}
      >
        →
      </button>
    </nav>
  );
}
