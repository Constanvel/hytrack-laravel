import { Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Avatar, Notice } from '@/components/ui';
import { BOOKINGS } from './bookings';
import { CLINICS } from './clinic-admin';
import { syncLabel, useLive } from '@/lib/live';

export const SCAN = 'Scan QR kedatangan';

export function DashboardShell({
  management = false,
  section,
  onSection,
  children,
}: {
  management?: boolean;
  section: string;
  onSection: (section: string) => void;
  children: ReactNode;
}) {
  const { props, flash } = usePage();
  const { auth, today } = props;
  const live = useLive();
  const items = management
    ? ['Ringkasan layanan', 'Waktu tunggu', 'Kinerja poliklinik', 'Laporan']
    : [SCAN, BOOKINGS, 'Antrean poli', 'Alur pelayanan', 'Farmasi', 'Riwayat aktivitas', CLINICS];
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
          <span>
            {management
              ? 'Analitik / Ringkasan layanan'
              : section === SCAN
                ? 'Pelayanan / Check-in kedatangan'
                : 'Pelayanan / Rawat jalan'}
          </span>
          <strong role="status" className={live.online ? undefined : 'offline'}>
            {syncLabel(live)}
          </strong>
          <span>{today}</span>
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
