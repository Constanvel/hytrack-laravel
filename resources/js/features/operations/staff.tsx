import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { DashboardShell } from './dashboard-shell';
import { Notice } from '@/components/ui';
import { useAction } from '@/lib/live';

interface QueuePatient {
  id: number;
  number: string;
  name: string;
  checkIn: string | null;
}
interface StaffProps {
  clinics: { id: number; name: string; doctors: { id: number; name: string }[] }[];
  clinicId: number | null;
  doctor: { id: number; room: string | null; time: string } | null;
  stats: { waiting: number; examined: number; notCheckedIn: number };
  queue: QueuePatient[];
  current: (QueuePatient & { startedAt: string | null; calledCount: number }) | null;
  laboratory: (QueuePatient & { clinic: string })[];
  pharmacy: {
    id: number;
    pickup: string;
    number: string;
    name: string;
    status: string;
    next: string | null;
  }[];
  history: string[];
}
export function Staff({
  clinics,
  clinicId,
  doctor,
  stats,
  queue,
  current,
  laboratory,
  pharmacy,
  history,
}: StaffProps) {
  const { flash, props } = usePage();
  const [section, setSection] = useState('Antrean poli');
  const [query, setQuery] = useState('');
  const [detail, setDetail] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const { busy, post } = useAction();
  const clinic = clinics.find((item) => item.id === clinicId);
  const filtered = queue.filter((patient) =>
    `${patient.name} ${patient.number}`.toLowerCase().includes(query.toLowerCase()),
  );
  const filter = (poli: number, dokter?: number) =>
    router.get('/petugas', dokter ? { poli, dokter } : { poli }, {
      preserveState: true,
      preserveScroll: true,
    });
  function checkIn(event: FormEvent) {
    event.preventDefault();
    post('/petugas/check-in', { code });
    setCode('');
  }
  return (
    <DashboardShell section={section} onSection={setSection}>
      <div className="dashboard-title">
        <div>
          <h1>{section}</h1>
          <p className="muted">Kelola panggilan dan bantu pasien melanjutkan pelayanan.</p>
        </div>
        <Notice>Data hari ini · {props.now}</Notice>
      </div>
      {section === 'Antrean poli' ? (
        <>
          <div className="dashboard-filters">
            <label>
              <span className="sr-only">Poliklinik</span>
              <select value={clinicId ?? ''} onChange={(e) => filter(Number(e.target.value))}>
                {clinics.map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.name}
                  </option>
                ))}
              </select>
            </label>
            <label>
              <span className="sr-only">Dokter</span>
              <select
                value={doctor?.id ?? ''}
                disabled={!clinic?.doctors.length}
                onChange={(e) => filter(clinic!.id, Number(e.target.value))}
              >
                {clinic?.doctors.map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.name}
                  </option>
                ))}
              </select>
            </label>
            <p className="muted small">
              {doctor ? `${doctor.room ?? 'Ruang belum diatur'} · ${doctor.time}` : '—'}
            </p>
          </div>
          {!doctor ? (
            <section className="card empty">
              <h2>Belum ada dokter di poliklinik ini</h2>
              <p className="muted">Pilih poliklinik lain untuk mengelola antrean.</p>
            </section>
          ) : (
            <>
              <div className="stat-grid three">
                <Stat label="Menunggu" value={String(stats.waiting)} detail="Sudah check-in" />
                <Stat
                  label="Selesai"
                  value={String(stats.examined)}
                  detail="Pemeriksaan dokter selesai"
                />
                <Stat
                  label="Belum check-in"
                  value={String(stats.notCheckedIn)}
                  detail="Periksa kedatangan pasien"
                />
              </div>
              <div className="staff-columns">
                <section className="card queue-table">
                  <div className="table-header">
                    <h3>Daftar antrean</h3>
                    <label>
                      <span className="sr-only">Cari pasien</span>
                      <input
                        placeholder="Cari pasien"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                      />
                    </label>
                  </div>
                  <div className="table-scroll">
                    <table>
                      <thead>
                        <tr>
                          <th>Nomor</th>
                          <th>Pasien</th>
                          <th>Check-in</th>
                          <th>Status</th>
                          <th>Aksi</th>
                        </tr>
                      </thead>
                      <tbody>
                        {filtered.map((patient) => (
                          <tr
                            key={patient.id}
                            className={patient.id === queue[0].id ? 'next-row' : ''}
                          >
                            <td>
                              <strong>{patient.number}</strong>
                            </td>
                            <td>{patient.name}</td>
                            <td>{patient.checkIn}</td>
                            <td>{patient.id === queue[0].id ? 'Berikutnya' : 'Menunggu'}</td>
                            <td>
                              <button
                                className="text-button"
                                onClick={() =>
                                  setDetail(detail === patient.number ? null : patient.number)
                                }
                                aria-expanded={detail === patient.number}
                              >
                                Detail
                              </button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  {!filtered.length && (
                    <p className="empty" role="status">
                      {queue.length ? 'Tidak ada pasien yang cocok.' : 'Belum ada pasien menunggu.'}
                    </p>
                  )}
                  {detail && (
                    <Notice>
                      Nomor {detail} · Dashboard petugas hanya memuat data antrean, tanpa diagnosis
                      atau rekam medis.
                    </Notice>
                  )}
                  <div className="table-footer">
                    <span>
                      Menampilkan {filtered.length} dari {queue.length} pasien menunggu
                    </span>
                    <span>Urut waktu check-in</span>
                  </div>
                </section>
                <div className="stack">
                  <section className="hero">
                    <p className="eyebrow">SEDANG DILAYANI</p>
                    <h2 style={{ fontSize: 44 }}>{current?.number ?? '—'}</h2>
                    <h3>{current?.name ?? 'Belum ada pasien'}</h3>
                    <p className="small">
                      {current ? `Mulai ${current.startedAt}` : 'Panggil pasien berikutnya'}
                    </p>
                    <button
                      className="button white"
                      disabled={!current || busy}
                      onClick={() => post(`/petugas/antrean/${current!.id}/selesai-periksa`)}
                    >
                      Selesai & lanjutkan layanan
                    </button>
                  </section>
                  <section className="card stack">
                    <h3>Berikutnya {queue[0]?.number ?? '—'}</h3>
                    <p className="muted small">
                      Selesaikan pasien aktif sebelum memanggil berikutnya.
                    </p>
                    <button
                      className="button"
                      disabled={!!current || !queue.length || busy}
                      onClick={() => post(`/petugas/dokter/${doctor.id}/panggil`)}
                    >
                      Panggil pasien berikutnya
                    </button>
                    <div className="split-actions">
                      <button
                        className="button secondary"
                        disabled={!current || busy}
                        onClick={() => post(`/petugas/antrean/${current!.id}/panggil-ulang`)}
                      >
                        Panggil ulang
                      </button>
                      <button
                        className="button secondary"
                        disabled={!current || current.calledCount < 2 || busy}
                        onClick={() => post(`/petugas/antrean/${current!.id}/tidak-hadir`)}
                      >
                        Tidak hadir
                      </button>
                    </div>
                  </section>
                  <form className="card stack" onSubmit={checkIn}>
                    <h3>Konfirmasi kedatangan</h3>
                    <label className="search-field">
                      <span className="sr-only">Nomor check-in pasien</span>
                      <input
                        placeholder="Nomor check-in, mis. HT-000027"
                        value={code}
                        maxLength={20}
                        onChange={(e) => setCode(e.target.value)}
                      />
                    </label>
                    <button className="button secondary" disabled={!code.trim() || busy}>
                      Konfirmasi check-in
                    </button>
                  </form>
                  <Notice tone="warning">
                    Tandai tidak hadir hanya setelah panggilan ulang. Setiap perubahan tersimpan dan
                    terlihat oleh pasien.
                  </Notice>
                </div>
              </div>
              <div className="dashboard-footnote" role="status">
                {flash.status ?? history[0] ?? 'Belum ada aktivitas hari ini.'}
              </div>
            </>
          )}
        </>
      ) : section === 'Riwayat aktivitas' ? (
        <section className="card stack">
          <h2>Riwayat aktivitas hari ini</h2>
          {history.map((item, index) => (
            <p key={`${index}-${item}`}>{item}</p>
          ))}
          {!history.length && <p className="muted">Belum ada aktivitas hari ini.</p>}
          <p className="muted small">
            Menampilkan pembaruan terakhir tiap tahap pelayanan; bukan audit log lengkap.
          </p>
        </section>
      ) : section === 'Alur pelayanan' ? (
        <section className="card stack">
          <h2>Lanjutkan alur pelayanan</h2>
          <p className="muted">Pasien yang sedang menuju atau berada di laboratorium.</p>
          <FlowTable
            head={['Nomor', 'Pasien', 'Poliklinik', 'Aksi']}
            empty="Tidak ada pasien di laboratorium."
            rows={laboratory.map((patient) => ({
              key: patient.id,
              cells: [patient.number, patient.name, patient.clinic],
              action: 'Selesai, lanjut ke farmasi',
              run: () => post(`/petugas/antrean/${patient.id}/selesai-laboratorium`),
            }))}
            busy={busy}
          />
          <p className="dashboard-footnote" role="status">
            {flash.status}
          </p>
        </section>
      ) : (
        <section className="card stack">
          <h2>Pantau penyiapan obat</h2>
          <p className="muted">
            Status di bawah tampil di ponsel pasien. Hanya progres, tanpa rincian obat.
          </p>
          <FlowTable
            head={['Ambil', 'Antrean', 'Pasien', 'Status', 'Aksi']}
            empty="Tidak ada resep yang sedang diproses."
            rows={pharmacy.map((order) => ({
              key: order.id,
              cells: [order.pickup, order.number, order.name, order.status],
              action: order.next ?? '',
              run: () => post(`/petugas/farmasi/${order.id}/lanjut`),
            }))}
            busy={busy}
          />
          <p className="dashboard-footnote" role="status">
            {flash.status}
          </p>
        </section>
      )}
    </DashboardShell>
  );
}
function FlowTable({
  head,
  rows,
  empty,
  busy,
}: {
  head: string[];
  rows: { key: number; cells: string[]; action: string; run: () => void }[];
  empty: string;
  busy: boolean;
}) {
  if (!rows.length)
    return (
      <p className="empty muted" role="status">
        {empty}
      </p>
    );
  return (
    <div className="table-scroll">
      <table>
        <thead>
          <tr>
            {head.map((label) => (
              <th key={label}>{label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.key}>
              {row.cells.map((cell, index) => (
                <td key={index}>{index === 0 ? <strong>{cell}</strong> : cell}</td>
              ))}
              <td>
                <button className="text-button" disabled={busy} onClick={row.run}>
                  {row.action}
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
export function Stat({ label, value, detail }: { label: string; value: string; detail: string }) {
  return (
    <section className="stat-card">
      <p>{label}</p>
      <strong>{value}</strong>
      <small>{detail}</small>
    </section>
  );
}
