import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Bookings, BOOKINGS, visitDashboard, type BookingProps } from './bookings';
import { ClinicAdmin, CLINICS, type ClinicRow } from './clinic-admin';
import {
  DoctorExam,
  PharmacyDetail,
  SupportDesk,
  type ExamRow,
  type Me,
  type SupportRow,
} from './clinical';
import {
  DashboardShell,
  DashboardTitle,
  EXAM,
  Pager,
  pageOf,
  SCAN,
  Stat,
  SUPPORT,
} from './dashboard-shell';
import { QrScanner } from './scanner';
import {
  ActivityLog,
  PharmacyDesk,
  ServiceFlow,
  type Activity,
  type FlowProps,
  type PharmacyProps,
} from './staff-sections';
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
  stats: { waiting: number; examined: number; notCheckedIn: number; registered: number };
  queue: QueuePatient[];
  current:
    | (QueuePatient & {
        startedAt: string | null;
        minutes: number | null;
        calledCount: number;
        confirmed: boolean;
      })
    | null;
  me: Me;
  examinations: ExamRow[];
  support: SupportRow[];
  supportUnits: Record<string, string>;
  flow: FlowProps;
  history: Activity[];
  checkInDesk: string;
  clinicAdmin: ClinicRow[];
}
/** Where each room starts (Figma 47:2 frames 31, 33, 34). */
const HOME = { desk: 'Antrean poli', doctor: EXAM, support: SUPPORT, pharmacy: 'Farmasi' };

/** Figma 43:2 frame 11: six patients per page. */
const PAGE = 6;

/** "A-027 check-in dikonfirmasi oleh Dina" from a history row. */
const sentence = (row: Activity) =>
  `${row.time} · ${row.patient.split(' · ')[1]} ${row.activity.charAt(0).toLowerCase()}${row.activity.slice(1)}${row.staff ? ` oleh ${row.staff}` : ''}`;

export function Staff({
  clinics,
  clinicId,
  doctor,
  stats,
  queue,
  current,
  flow,
  pharmacy,
  pharmacyStats,
  history,
  checkInDesk,
  clinicAdmin,
  me,
  examinations,
  support,
  supportUnits,
  ...bookingProps
}: StaffProps & PharmacyProps & Omit<BookingProps, 'clinics'>) {
  const { flash, props } = usePage();
  const [section, setSection] = useState<string>(HOME[me.room]);
  const [examPick, setExamPick] = useState<number | null>(null);
  const [orderPick, setOrderPick] = useState<number | null>(null);
  const order = pharmacy.find((item) => item.id === orderPick);
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [detail, setDetail] = useState<string | null>(null);
  const { busy, post, send, error, clearError } = useAction();
  const go = (next: string) => {
    clearError();
    setSection(next);
  };
  const clinic = clinics.find((item) => item.id === clinicId);
  const filtered = queue.filter((patient) =>
    `${patient.name} ${patient.number}`.toLowerCase().includes(query.toLowerCase()),
  );
  const shown = pageOf(filtered, page, PAGE);
  const filter = (poli: number, dokter?: number) =>
    visitDashboard({ poli, dokter: dokter ?? null });
  const sample = <Notice>Data contoh · {props.now}</Notice>;
  const callNext = () => post(`/petugas/dokter/${doctor!.id}/panggil`);
  return (
    <DashboardShell
      room={me.room}
      section={section}
      onSection={(next) => {
        setOrderPick(null);
        go(next);
      }}
    >
      {section === SCAN ? (
        <QrScanner desk={checkInDesk} />
      ) : section === BOOKINGS ? (
        <>
          <DashboardTitle
            title={BOOKINGS}
            subtitle="Buat, cari, perbarui, dan batalkan booking pasien. Semua perubahan tersimpan di server."
            aside={sample}
          />
          <Bookings clinics={clinics} {...bookingProps} />
        </>
      ) : section === CLINICS ? (
        <>
          <DashboardTitle
            title={CLINICS}
            subtitle="Tambah, ubah, dan hapus data poliklinik yang dipilih pasien saat booking."
            aside={sample}
          />
          <ClinicAdmin clinics={clinicAdmin} />
        </>
      ) : section === EXAM ? (
        <DoctorExam
          me={me}
          rows={examinations}
          supportUnits={supportUnits}
          picked={examPick}
          onPick={setExamPick}
          busy={busy}
          error={error}
          send={send}
          onSection={go}
          onOrder={(id) => {
            setOrderPick(id);
            go('Farmasi');
          }}
        />
      ) : section === SUPPORT ? (
        <SupportDesk
          me={me}
          rows={support}
          units={supportUnits}
          busy={busy}
          error={error}
          send={send}
        />
      ) : section === 'Alur pelayanan' ? (
        <ServiceFlow clinics={clinics} flow={flow} onSection={go} />
      ) : section === 'Farmasi' && order ? (
        <PharmacyDetail
          order={order}
          me={me}
          busy={busy}
          error={error}
          send={send}
          onBack={() => {
            clearError();
            setOrderPick(null);
          }}
          onHistory={() => {
            setOrderPick(null);
            go('Riwayat aktivitas');
          }}
        />
      ) : section === 'Farmasi' ? (
        <PharmacyDesk
          pharmacy={pharmacy}
          pharmacyStats={pharmacyStats}
          onOpen={(id) => {
            clearError();
            setOrderPick(id);
          }}
        />
      ) : section === 'Riwayat aktivitas' ? (
        <ActivityLog history={history} />
      ) : (
        <>
          <DashboardTitle
            title="Antrean poli"
            subtitle="Kelola panggilan dan bantu pasien melanjutkan pelayanan."
            aside={sample}
          />
          <div className="dashboard-filters queue-filters">
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
            <p>{doctor ? `${doctor.room ?? 'Ruang belum diatur'} · ${doctor.time}` : '—'}</p>
          </div>
          {!doctor ? (
            <section className="card empty">
              <h2>Belum ada dokter di poliklinik ini</h2>
              <p className="muted">Pilih poliklinik lain untuk mengelola antrean.</p>
            </section>
          ) : (
            <>
              <div className="stat-grid three">
                <Stat
                  className="mint"
                  label="Menunggu"
                  value={String(stats.waiting)}
                  detail="Pasien sudah check-in"
                />
                <Stat
                  label="Selesai"
                  value={String(stats.examined)}
                  detail={`Dari ${stats.registered} pasien terdaftar`}
                />
                <Stat
                  className="slate"
                  label="Belum check-in"
                  value={String(stats.notCheckedIn)}
                  detail="Periksa kedatangan pasien"
                />
              </div>
              <div className="staff-columns">
                <section className="card queue-table">
                  <div className="table-header">
                    <h3>Daftar antrean</h3>
                    <span>
                      {queue.length} menunggu ·
                      <label>
                        <span className="sr-only">Cari pasien</span>
                        <input
                          placeholder="Cari pasien"
                          value={query}
                          onChange={(e) => {
                            setQuery(e.target.value);
                            setPage(1);
                          }}
                        />
                      </label>
                    </span>
                  </div>
                  <div className="table-scroll">
                    <table className="grid-table">
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
                        {shown.rows.map((patient) => {
                          const next = patient.id === queue[0].id;
                          return (
                            <tr key={patient.id} className={next ? 'next-row' : ''}>
                              <td>
                                <strong>{patient.number}</strong>
                              </td>
                              <td>{patient.name}</td>
                              <td>{patient.checkIn}</td>
                              <td>{next ? 'Berikutnya' : 'Menunggu'}</td>
                              <td>
                                {next ? (
                                  <button
                                    className="text-button"
                                    disabled={!!current || busy}
                                    onClick={callNext}
                                  >
                                    Panggil
                                  </button>
                                ) : (
                                  <button
                                    className="text-button"
                                    onClick={() =>
                                      setDetail(detail === patient.number ? null : patient.number)
                                    }
                                    aria-expanded={detail === patient.number}
                                  >
                                    Detail
                                  </button>
                                )}
                              </td>
                            </tr>
                          );
                        })}
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
                      {filtered.length
                        ? `Menampilkan ${shown.from}–${Math.min(shown.to, filtered.length)} dari ${filtered.length} pasien`
                        : 'Menampilkan 0 pasien'}
                    </span>
                    <Pager page={shown.current} pages={shown.pages} onPage={setPage} />
                  </div>
                </section>
                <div className="stack queue-controls">
                  <section className="hero serving">
                    <p className="eyebrow">
                      {current
                        ? `SEDANG DIPERIKSA · MULAI ${current.startedAt}`
                        : 'SEDANG DIPERIKSA'}
                    </p>
                    <h2>{current?.number ?? '—'}</h2>
                    <h3>{current?.name ?? 'Belum ada pasien'}</h3>
                    <p className="small">
                      {current
                        ? `${current.minutes ?? 0} mnt · ${current.confirmed ? 'sudah dikonfirmasi dokter' : 'menunggu konfirmasi dokter'}`
                        : 'Panggil pasien berikutnya'}
                    </p>
                    {/* Figma 47:2 frame 11: only the doctor confirms; the desk opens that screen. */}
                    <button
                      className="button white"
                      disabled={!current}
                      onClick={() => {
                        setExamPick(current!.id);
                        go(EXAM);
                      }}
                    >
                      Lihat pemeriksaan dokter
                    </button>
                  </section>
                  <section className="card next-call">
                    <h3>Berikutnya {queue[0]?.number ?? '—'}</h3>
                    <p className="muted small">Pastikan ruang siap sebelum memanggil.</p>
                    <button
                      className="button"
                      disabled={!!current || !queue.length || busy}
                      onClick={callNext}
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
                  <Notice tone="warning">
                    Hanya dokter mengonfirmasi pemeriksaan &amp; resep. Tidak hadir: tandai setelah
                    panggilan ulang.
                  </Notice>
                </div>
              </div>
              <div className="dashboard-foot">
                <p className="dashboard-footnote" role="status">
                  {flash.status
                    ? `✓ ${flash.status}`
                    : history[0]
                      ? `✓ ${sentence(history[0])}`
                      : 'Belum ada aktivitas hari ini.'}
                </p>
                <button className="text-button" onClick={() => setSection('Riwayat aktivitas')}>
                  Lihat riwayat aktivitas →
                </button>
              </div>
            </>
          )}
        </>
      )}
    </DashboardShell>
  );
}
