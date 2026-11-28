import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Dialog, type OrderRow } from './clinical';
import {
  DashboardTitle,
  EXAM,
  Pager,
  pageOf,
  SCAN,
  shortDate,
  Stat,
  SUPPORT,
} from './dashboard-shell';
import { Notice } from '@/components/ui';

// ── Alur pelayanan (Figma 47:2 frame 21, dialogs 47:2267–47:2325) ─────────────

export interface FlowProps {
  visits: {
    id: number;
    number: string;
    name: string;
    clinicId: number;
    stage: 'arrival' | 'clinic' | 'support' | 'pharmacy';
    note: string;
    late: boolean;
  }[];
  completed: Record<number, number>;
}
/** Stage, title, the service page it leads to and that button's label. */
const COLUMNS = [
  ['arrival', 'Verifikasi kedatangan', SCAN, 'Verifikasi kedatangan →'],
  ['clinic', 'Poli & dokter', EXAM, 'Pemeriksaan dokter →'],
  ['support', 'Penunjang', SUPPORT, 'Ringkasan penunjang →'],
  ['pharmacy', 'Farmasi', 'Farmasi', 'Kelola pemrosesan obat →'],
] as const;

export function ServiceFlow({
  clinics,
  flow,
  onSection,
}: {
  clinics: { id: number; name: string }[];
  flow: FlowProps;
  onSection: (section: string) => void;
}) {
  const { flash, props } = usePage();
  const [clinic, setClinic] = useState(0);
  const [summary, setSummary] = useState<number | null>(null);
  const visits = flow.visits.filter((visit) => !clinic || visit.clinicId === clinic);
  const late = visits.filter((visit) => visit.late);
  const completed = Object.entries(flow.completed)
    .filter(([id]) => !clinic || Number(id) === clinic)
    .reduce((sum, [, count]) => sum + count, 0);
  const rowsOf = (stage: string) => visits.filter((visit) => visit.stage === stage);
  const open = summary === null ? null : COLUMNS[summary];
  const openRows = open ? rowsOf(open[0]) : [];
  return (
    <>
      <DashboardTitle
        flat
        title="Alur pelayanan"
        subtitle="Pantau antrean, konfirmasi dokter, penunjang, dan obat hingga diserahkan."
        aside={<Notice>Data contoh · {props.now}</Notice>}
      />
      <div className="dashboard-filters flat flow-filters">
        <label>
          <span className="sr-only">Filter poliklinik</span>
          <select value={clinic} onChange={(e) => setClinic(Number(e.target.value))}>
            <option value={0}>Semua poliklinik</option>
            {clinics.map((item) => (
              <option key={item.id} value={item.id}>
                {item.name}
              </option>
            ))}
          </select>
        </label>
        <span className="filter-box">Hari ini · {shortDate(props.today)}</span>
        <p>Pembaruan langsung · semua unit</p>
      </div>
      <div className="stat-grid three flat split">
        <Stat
          label="Dalam pelayanan"
          value={String(visits.length)}
          detail="Pasien di seluruh tahap"
        />
        <Stat
          label="Selesai hari ini"
          value={String(completed)}
          detail="Sudah menyelesaikan layanan"
        />
        <Stat
          label="Perlu perhatian"
          value={String(late.length)}
          tone={late.length ? 'warm' : undefined}
          detail="Waktu tunggu di atas target"
        />
      </div>
      <div className="flow-board">
        {COLUMNS.map(([stage, title, target, action], index) => {
          const rows = rowsOf(stage);
          return (
            <section className="flow-column" key={stage} aria-label={title}>
              <span className="flow-step" aria-hidden="true">
                {String(index + 1).padStart(2, '0')}
              </span>
              <p className="flow-count">{rows.length} pasien</p>
              <button
                type="button"
                className="flow-open"
                aria-label={`Ringkasan tahap ${title}`}
                onClick={() => setSummary(index)}
              >
                →
              </button>
              <h3>{title}</h3>
              <ul>
                {rows.slice(0, 2).map((visit) => (
                  <li key={visit.id} className={visit.late ? 'late' : ''}>
                    <button type="button" onClick={() => setSummary(index)}>
                      <strong>{visit.number}</strong>
                      <span>{visit.name}</span>
                      <small>{visit.note}</small>
                      <i aria-hidden="true">↗</i>
                    </button>
                  </li>
                ))}
              </ul>
              {!rows.length && <p className="flow-empty">Tidak ada pasien.</p>}
              <button type="button" className="flow-action" onClick={() => onSection(target)}>
                {action}
              </button>
            </section>
          );
        })}
      </div>
      {late.length > 0 && (
        <section className="follow-up">
          <div>
            <h3>{late.length} pasien membutuhkan tindak lanjut</h3>
            <p>
              Periksa waktu tunggu dan koordinasikan dengan unit terkait sebelum memindahkan pasien.
            </p>
          </div>
          <button className="button white" onClick={() => setSummary(1)}>
            Lihat pasien
          </button>
        </section>
      )}
      <p className="dashboard-footnote" role="status">
        {flash.status ??
          'Status diperbarui oleh masing-masing unit. Setiap perpindahan tercatat di riwayat aktivitas.'}
      </p>
      {open && summary !== null && (
        <Dialog
          className="flow-summary"
          eyebrow={`TAHAP ${String(summary + 1).padStart(2, '0')} / 04`}
          title={open[1]}
          onClose={() => setSummary(null)}
        >
          <p className="cl-muted">
            {openRows.length} pasien · {openRows.length} pasien ditampilkan
          </p>
          <ul className="flow-summary-list">
            {openRows.map((visit) => (
              <li key={visit.id}>
                <strong>{visit.number}</strong>
                <span>{visit.name}</span>
                <small>{visit.note}</small>
              </li>
            ))}
          </ul>
          {!openRows.length && <p className="cl-empty">Tidak ada pasien di tahap ini.</p>}
          <p className="cl-muted small">Status diperbarui oleh masing-masing unit.</p>
          <div className="cl-dialog-actions">
            <button type="button" className="cl-button mint" onClick={() => setSummary(null)}>
              Kembali ke alur
            </button>
            <button
              type="button"
              className="cl-button primary"
              onClick={() => {
                setSummary(null);
                onSection(open[2]);
              }}
            >
              Buka layanan →
            </button>
          </div>
        </Dialog>
      )}
    </>
  );
}

// ── Farmasi (Figma 47:2 frame 22) ─────────────────────────────────────────────

export interface PharmacyProps {
  pharmacy: OrderRow[];
  pharmacyStats: { done: number; average: number | null };
}
/** The short status names of frame 22. */
const SHORT: Record<OrderRow['status'], string> = {
  received: 'Belum diproses',
  preparing: 'Diproses',
  ready: 'Siap diambil',
  completed: 'Diserahkan',
  returned: 'Dikembalikan',
};
/** Row action and the side panel's button per status. */
const ACTION: Record<OrderRow['status'], [string, string]> = {
  received: ['Mulai proses', 'Verifikasi & mulai proses'],
  preparing: ['Detail', 'Konfirmasi obat siap diambil'],
  ready: ['Panggil', 'Konfirmasi obat diserahkan'],
  completed: ['Detail', 'Lihat detail resep'],
  returned: ['Detail', 'Lihat detail resep'],
};

export function PharmacyDesk({
  pharmacy,
  pharmacyStats,
  onOpen,
}: PharmacyProps & { onOpen: (id: number) => void }) {
  const { flash, props } = usePage();
  const [status, setStatus] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [picked, setPicked] = useState<number | null>(null);
  // Handed-over prescriptions leave the working list; the "Diserahkan" filter shows them.
  const active = pharmacy.filter((order) => order.status !== 'completed');
  const rows = (status ? pharmacy : active).filter(
    (order) =>
      (!status || order.status === status) &&
      `${order.pickup} ${order.number} ${order.name}`.toLowerCase().includes(query.toLowerCase()),
  );
  const shown = pageOf(rows, page, 6);
  const order = rows.find((item) => item.id === picked) ?? rows[0];
  const count = (...statuses: string[]) =>
    pharmacy.filter((item) => statuses.includes(item.status)).length;
  return (
    <>
      <DashboardTitle
        flat
        title="Farmasi"
        subtitle="Verifikasi resep, siapkan obat, dan kelola penyerahan kepada pasien."
        aside={<Notice>Data contoh · {props.now}</Notice>}
      />
      <div className="dashboard-filters flat pharmacy-filters">
        <label>
          <span className="sr-only">Filter status resep</span>
          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
          >
            <option value="">Semua status</option>
            {Object.entries(SHORT).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </label>
        <label>
          <span className="sr-only">Cari nomor resep atau pasien</span>
          <input
            placeholder="Cari nomor resep atau pasien"
            value={query}
            onChange={(e) => {
              setQuery(e.target.value);
              setPage(1);
            }}
          />
        </label>
        <span className="filter-box">Hari ini · {shortDate(props.today)}</span>
      </div>
      <div className="stat-grid three flat">
        <Stat
          label="Antrean resep"
          value={String(active.length)}
          detail={`${count('received')} belum diproses · ${count('preparing')} diproses · ${count('ready')} siap`}
        />
        <Stat
          label="Sudah diserahkan"
          value={String(pharmacyStats.done)}
          detail="Resep selesai hari ini"
        />
        <Stat
          label="Rata-rata penyiapan"
          value={`${pharmacyStats.average ?? 0} mnt`}
          detail="Target layanan ≤ 15 menit"
        />
      </div>
      <div className="staff-columns pharmacy-columns">
        <section className="card flat-card">
          <div className="table-header">
            <h3>Daftar resep</h3>
            <span>{active.length} resep aktif</span>
          </div>
          <div className="table-scroll">
            <table className="grid-table pharmacy-table">
              <thead>
                <tr>
                  <th>Nomor</th>
                  <th>Pasien</th>
                  <th>Masuk</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                {shown.rows.map((item) => (
                  <tr
                    key={item.id}
                    className={item.id === order?.id ? 'next-row' : ''}
                    onClick={() => setPicked(item.id)}
                  >
                    <td>
                      <strong>{item.pickup}</strong>
                    </td>
                    <td>{item.name}</td>
                    <td>{item.receivedAt ?? '—'}</td>
                    <td>{SHORT[item.status]}</td>
                    <td>
                      <button
                        className="text-button"
                        onClick={(e) => {
                          e.stopPropagation();
                          onOpen(item.id);
                        }}
                      >
                        {ACTION[item.status][0]}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {!rows.length && (
            <p className="empty muted" role="status">
              {pharmacy.length
                ? 'Tidak ada resep yang cocok.'
                : 'Tidak ada resep yang sedang diproses.'}
            </p>
          )}
          <div className="table-footer plain">
            <span>
              {rows.length
                ? `Menampilkan ${shown.from}–${Math.min(shown.to, rows.length)} dari ${rows.length} resep${status ? '' : ' aktif'}`
                : 'Menampilkan 0 resep'}
            </span>
            <Pager page={shown.current} pages={shown.pages} onPage={setPage} />
          </div>
        </section>
        <div className="stack pharmacy-side">
          <section className="card flat-card prescription">
            {order ? (
              <>
                <p className="eyebrow">
                  RESEP DIPILIH ·{' '}
                  {(order.status === 'preparing'
                    ? 'Sedang diproses'
                    : SHORT[order.status]
                  ).toUpperCase()}
                </p>
                <h2>{order.pickup}</h2>
                <h3>{order.name}</h3>
                <p className="muted">
                  {order.record ? `No. RM ${order.record} · ` : ''}
                  {order.clinic}
                </p>
                <p className="muted">
                  Masuk {order.receivedAt ?? '—'}
                  {order.handledBy ? ` · Ditangani ${order.handledBy}` : ''}
                </p>
                <div className="handover-check">
                  <strong>
                    {order.status === 'received'
                      ? `${order.itemCount} item obat · belum diproses`
                      : order.status === 'returned'
                        ? `${order.itemCount} item obat · dikembalikan ke dokter`
                        : `${order.itemCount} item obat · diproses oleh ${order.handledBy ?? 'farmasi'}`}
                  </strong>
                  <small>
                    {order.status === 'received'
                      ? 'Verifikasi resep dokter sebelum memproses.'
                      : order.status === 'returned'
                        ? (order.returnReason ?? 'Menunggu dokter meninjau resep.')
                        : 'Resep dokter sudah diverifikasi.'}
                  </small>
                </div>
                <button className="button solid" onClick={() => onOpen(order.id)}>
                  {ACTION[order.status][1]}
                </button>
              </>
            ) : (
              <>
                <p className="eyebrow">RESEP DIPILIH</p>
                <h3>Belum ada resep aktif</h3>
                <p className="muted">Resep baru muncul setelah dokter mengonfirmasi pemeriksaan.</p>
              </>
            )}
          </section>
          <section className="handover-guide">
            <h3>Sebelum menyerahkan obat</h3>
            <p>Cocokkan dua identitas pasien dan berikan penjelasan penggunaan sesuai resep.</p>
            <small>Penyerahan dicatat atas nama petugas.</small>
          </section>
        </div>
      </div>
      <p className="dashboard-footnote" role="status">
        {flash.status ?? 'Perubahan status resep tersimpan di riwayat aktivitas.'}
      </p>
    </>
  );
}

// ── Riwayat aktivitas (Figma 43:2 frame 23) ───────────────────────────────────

export interface Activity {
  key: string;
  time: string;
  activity: string;
  patient: string;
  staff: string | null;
  unit: string;
  result: string;
}
const HEAD = ['Waktu', 'Aktivitas', 'Pasien / nomor', 'Petugas', 'Unit', 'Hasil'] as const;

/** One CSV cell, quoted, with spreadsheet formulas neutralised. */
const cell = (value: string) => `"${value.replace(/^[=+\-@]/, "'$&").replace(/"/g, '""')}"`;

export function ActivityLog({ history }: { history: Activity[] }) {
  const { props } = usePage();
  const [unit, setUnit] = useState('');
  const [staff, setStaff] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const units = [...new Set(history.map((row) => row.unit))];
  const people = [...new Set(history.flatMap((row) => (row.staff ? [row.staff] : [])))];
  const rows = history.filter(
    (row) =>
      (!unit || row.unit === unit) &&
      (!staff || row.staff === staff) &&
      row.patient.toLowerCase().includes(query.toLowerCase()),
  );
  const shown = pageOf(rows, page, 8);
  const reset = (set: (value: string) => void) => (value: string) => {
    set(value);
    setPage(1);
  };
  function download() {
    const lines = [
      HEAD,
      ...rows.map((row) => [
        row.time,
        row.activity,
        row.patient,
        row.staff ?? '',
        row.unit,
        row.result,
      ]),
    ];
    const csv = `\u{FEFF}${lines.map((line) => line.map(cell).join(',')).join('\r\n')}\r\n`;
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    link.download = 'hytrack-riwayat-aktivitas.csv';
    link.click();
    URL.revokeObjectURL(link.href);
  }
  return (
    <>
      <DashboardTitle
        flat
        title="Riwayat aktivitas"
        subtitle="Telusuri perubahan status dan tindakan petugas di seluruh layanan."
        aside={
          <button className="button solid export" disabled={!rows.length} onClick={download}>
            Ekspor riwayat
          </button>
        }
      />
      <div className="dashboard-filters flat activity-filters">
        <span className="filter-box">Hari ini · {shortDate(props.today)}</span>
        <label>
          <span className="sr-only">Filter unit</span>
          <select value={unit} onChange={(e) => reset(setUnit)(e.target.value)}>
            <option value="">Semua unit</option>
            {units.map((item) => (
              <option key={item}>{item}</option>
            ))}
          </select>
        </label>
        <label>
          <span className="sr-only">Filter petugas</span>
          <select value={staff} onChange={(e) => reset(setStaff)(e.target.value)}>
            <option value="">Semua petugas</option>
            {people.map((item) => (
              <option key={item}>{item}</option>
            ))}
          </select>
        </label>
        <label>
          <span className="sr-only">Cari pasien atau nomor</span>
          <input
            placeholder="Cari pasien atau nomor"
            value={query}
            onChange={(e) => reset(setQuery)(e.target.value)}
          />
        </label>
      </div>
      <div className="stat-grid three flat">
        <Stat
          label="Aktivitas hari ini"
          value={String(history.length)}
          detail="Seluruh tindakan tercatat"
        />
        <Stat
          label="Petugas aktif"
          value={String(people.length)}
          detail={`Di ${units.length} unit pelayanan`}
        />
        <Stat
          label="Pembaruan terakhir"
          value={history[0]?.time ?? '—'}
          detail="Data contoh · diperbarui langsung"
        />
      </div>
      <section className="card flat-card activity-log">
        <div className="table-header">
          <h3>Catatan aktivitas</h3>
          <span>Terbaru lebih dulu</span>
        </div>
        <div className="table-scroll">
          <table className="grid-table activity-table">
            <thead>
              <tr>
                {HEAD.map((label) => (
                  <th key={label}>{label}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {shown.rows.map((row) => (
                <tr key={row.key}>
                  <td>
                    <strong>{row.time}</strong>
                  </td>
                  <td>{row.activity}</td>
                  <td>{row.patient}</td>
                  <td>{row.staff ?? '—'}</td>
                  <td>{row.unit}</td>
                  <td className="result">{row.result}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!rows.length && (
          <p className="empty muted" role="status">
            {history.length ? 'Tidak ada aktivitas yang cocok.' : 'Belum ada aktivitas hari ini.'}
          </p>
        )}
        <div className="table-footer plain">
          <span>
            {rows.length
              ? `Menampilkan ${shown.from}–${Math.min(shown.to, rows.length)} dari ${rows.length} aktivitas`
              : 'Menampilkan 0 aktivitas'}
          </span>
          <Pager page={shown.current} pages={shown.pages} onPage={setPage} />
        </div>
      </section>
      <p className="activity-note">
        Jejak aktivitas tersimpan otomatis. Catatan tidak dapat diubah atau dihapus oleh petugas.
      </p>
    </>
  );
}
