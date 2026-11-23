import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { DashboardTitle, Pager, pageOf, shortDate, Stat } from './dashboard-shell';
import { Notice } from '@/components/ui';

type Post = (url: string) => void;

// ── Alur pelayanan (Figma 43:2 frame 21) ──────────────────────────────────────

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
const COLUMNS = [
  ['arrival', 'Verifikasi kedatangan'],
  ['clinic', 'Antrean poli'],
  ['support', 'Penunjang'],
  ['pharmacy', 'Farmasi'],
] as const;

export function ServiceFlow({
  clinics,
  flow,
  busy,
  post,
}: {
  clinics: { id: number; name: string }[];
  flow: FlowProps;
  busy: boolean;
  post: Post;
}) {
  const { flash, props } = usePage();
  const [clinic, setClinic] = useState(0);
  const [open, setOpen] = useState<string[]>([]);
  const visits = flow.visits.filter((visit) => !clinic || visit.clinicId === clinic);
  const late = visits.filter((visit) => visit.late);
  const completed = Object.entries(flow.completed)
    .filter(([id]) => !clinic || Number(id) === clinic)
    .reduce((sum, [, count]) => sum + count, 0);
  const toggle = (stage: string) =>
    setOpen(open.includes(stage) ? open.filter((item) => item !== stage) : [...open, stage]);
  return (
    <>
      <DashboardTitle
        flat
        title="Alur pelayanan"
        subtitle="Pantau perpindahan pasien dari kedatangan hingga layanan selesai."
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
        {COLUMNS.map(([stage, title]) => {
          const rows = visits.filter((visit) => visit.stage === stage);
          const expanded = open.includes(stage);
          return (
            <section className="flow-column" key={stage} aria-label={title}>
              <h3>{title}</h3>
              <p>{rows.length} pasien</p>
              <ul>
                {(expanded ? rows : rows.slice(0, 2)).map((visit) => (
                  <li key={visit.id} className={visit.late ? 'late' : ''}>
                    <strong>{visit.number}</strong>
                    <span>{visit.name}</span>
                    <small>{visit.note}</small>
                    {stage === 'support' && (
                      <button
                        className="text-button"
                        disabled={busy}
                        onClick={() => post(`/petugas/antrean/${visit.id}/selesai-laboratorium`)}
                      >
                        Selesai, lanjut ke farmasi
                      </button>
                    )}
                  </li>
                ))}
              </ul>
              {!rows.length && <p className="flow-empty">Tidak ada pasien.</p>}
              {rows.length > 2 && (
                <button
                  className="text-button"
                  aria-expanded={expanded}
                  onClick={() => toggle(stage)}
                >
                  {expanded ? 'Tampilkan lebih sedikit ↑' : 'Lihat semua pasien →'}
                </button>
              )}
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
          <button
            className="button white"
            onClick={() => setOpen((items) => [...new Set([...items, 'clinic'])])}
          >
            Lihat pasien
          </button>
        </section>
      )}
      <p className="dashboard-footnote" role="status">
        {flash.status ??
          'Status diperbarui oleh masing-masing unit. Setiap perpindahan tercatat di riwayat aktivitas.'}
      </p>
    </>
  );
}

// ── Farmasi (Figma 43:2 frame 22) ─────────────────────────────────────────────

export interface PharmacyProps {
  pharmacy: {
    id: number;
    pickup: string;
    number: string;
    name: string;
    clinic: string;
    record: string | null;
    receivedAt: string | null;
    status: 'received' | 'preparing' | 'checking' | 'ready';
    statusLabel: string;
    next: string | null;
    handledBy: string | null;
  }[];
  pharmacyStats: { done: number; average: number | null };
}
/** The short status names of frame 22. */
const SHORT = {
  received: 'Verifikasi',
  preparing: 'Disiapkan',
  checking: 'Pemeriksaan akhir',
  ready: 'Siap diambil',
};

export function PharmacyDesk({
  pharmacy,
  pharmacyStats,
  busy,
  post,
}: PharmacyProps & { busy: boolean; post: Post }) {
  const { flash, props } = usePage();
  const [status, setStatus] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [picked, setPicked] = useState<number | null>(null);
  const rows = pharmacy.filter(
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
          value={String(pharmacy.length)}
          detail={`${count('received')} verifikasi · ${count('preparing', 'checking')} disiapkan · ${count('ready')} siap`}
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
            <span>{pharmacy.length} resep aktif</span>
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
                  <tr key={item.id} className={item.id === order?.id ? 'next-row' : ''}>
                    <td>
                      <strong>{item.pickup}</strong>
                    </td>
                    <td>{item.name}</td>
                    <td>{item.receivedAt ?? '—'}</td>
                    <td>{SHORT[item.status]}</td>
                    <td>
                      <button
                        className="text-button"
                        aria-pressed={item.id === order?.id}
                        onClick={() => setPicked(item.id)}
                      >
                        {item.status === 'received'
                          ? 'Verifikasi'
                          : item.status === 'ready'
                            ? 'Panggil'
                            : 'Detail'}
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
                ? `Menampilkan ${shown.from}–${Math.min(shown.to, rows.length)} dari ${rows.length} resep aktif`
                : 'Menampilkan 0 resep'}
            </span>
            <Pager page={shown.current} pages={shown.pages} onPage={setPage} />
          </div>
        </section>
        <div className="stack pharmacy-side">
          <section className="card flat-card prescription">
            {order ? (
              <>
                <p className="eyebrow">RESEP DIPILIH · {SHORT[order.status].toUpperCase()}</p>
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
                      ? 'Resep menunggu verifikasi'
                      : 'Resep sudah diverifikasi'}
                  </strong>
                  <small>Pastikan obat, label, dan identitas sesuai.</small>
                </div>
                <button
                  className="button solid"
                  disabled={!order.next || busy}
                  onClick={() => post(`/petugas/farmasi/${order.id}/lanjut`)}
                >
                  Tandai {order.next?.toLowerCase()}
                </button>
              </>
            ) : (
              <>
                <p className="eyebrow">RESEP DIPILIH</p>
                <h3>Belum ada resep aktif</h3>
                <p className="muted">Resep baru muncul setelah pasien selesai di laboratorium.</p>
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
