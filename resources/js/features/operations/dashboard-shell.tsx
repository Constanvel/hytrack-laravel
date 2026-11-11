import { Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Notice } from '@/components/ui';
import { useLive } from '@/lib/live';

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
  const { auth, hospital, today } = props;
  const live = useLive();
  const items = management
    ? ['Ringkasan layanan', 'Waktu tunggu', 'Kinerja poliklinik', 'Laporan']
    : ['Antrean poli', 'Alur pelayanan', 'Farmasi', 'Riwayat aktivitas'];
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
          <strong>{hospital}</strong>
          <p>
            {auth?.name}
            <br />
            {auth?.unit ?? (management ? 'Akses data agregat' : 'Petugas')}
          </p>
          <button className="button secondary" onClick={() => router.post('/keluar')}>
            Keluar
          </button>
        </div>
      </aside>
      <div className="dashboard-body">
        <header className="dashboard-topbar">
          <span>{management ? 'Analitik / Ringkasan layanan' : 'Pelayanan / Rawat jalan'}</span>
          <strong role="status">
            {live.online ? `● Terhubung · Diperbarui ${live.syncedAt}` : '○ Koneksi terputus'}
          </strong>
          <span>{today}</span>
        </header>
        <main id="main" className="dashboard-main">
          {flash.error && (
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
