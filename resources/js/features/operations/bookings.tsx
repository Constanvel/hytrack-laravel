import { router, usePage } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { Notice } from '@/components/ui';
import { useAction } from '@/lib/live';

export const BOOKINGS = 'Daftar booking';

interface Booking {
  id: number;
  number: string;
  code: string;
  name: string;
  phone: string | null;
  clinic: string;
  doctor: string;
  time: string;
  date: string;
  dateLabel: string;
  isToday: boolean;
  status: string;
  statusLabel: string;
  bookedAt: string | null;
  bookedBy: string | null;
  deadline: string | null;
  checkInClosed: boolean;
  checkInAt: string | null;
  checkInBy: string | null;
  checkInMethod: string | null;
  calledCount: number;
  noShowAt: string | null;
  noShowBy: string | null;
  cancelledAt: string | null;
  cancelledBy: string | null;
  cancelReason: string | null;
  stages: { name: string; status: string; at: string | null; by: string | null }[];
}
export interface BookingProps {
  clinics: { id: number; name: string }[];
  bookingFilters: { date: string; clinic: number | null; status: string; search: string };
  bookingTotal: number;
  bookings: Booking[];
  statuses: { value: string; label: string }[];
  recentBookings: {
    id: number;
    number: string;
    name: string;
    clinic: string;
    date: string;
    dateLabel: string;
    status: string;
    bookedAt: string | null;
  }[];
  bookingDates: { min: string; max: string };
}

/** Merges filters into the current dashboard URL so the other tabs keep theirs. */
export function visitDashboard(
  params: Record<string, string | number | null>,
  onSuccess?: () => void,
) {
  const query = Object.fromEntries(new URLSearchParams(window.location.search));
  for (const [key, value] of Object.entries(params))
    query[key] = value === null ? '' : String(value);
  router.get('/petugas', query, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onSuccess,
  });
}

/** CRUD for bookings: Create (walk-in), Read (list, search, detail), Update (stage), Delete (cancel). */
export function Bookings({
  clinics,
  bookingFilters: filters,
  bookingTotal,
  bookings,
  statuses,
  recentBookings,
  bookingDates,
}: BookingProps) {
  const [selected, setSelected] = useState<number | null>(null);
  const [search, setSearch] = useState(filters.search);
  const booking = bookings.find((item) => item.id === selected) ?? null;
  const apply = (params: Record<string, string | number | null>, then?: () => void) =>
    visitDashboard(params, then);
  function submitSearch(event: FormEvent) {
    event.preventDefault();
    apply({ cari: search.trim() });
  }
  return (
    <div className="booking-layout">
      <section className="card stack booking-list">
        <form className="booking-filters" onSubmit={submitSearch} aria-label="Filter booking">
          <label>
            <span>Tanggal layanan</span>
            <input
              type="date"
              value={filters.date}
              onChange={(e) => apply({ tanggal: e.target.value })}
            />
          </label>
          <label>
            <span>Poliklinik</span>
            <select
              value={filters.clinic ?? ''}
              onChange={(e) => apply({ bpoli: e.target.value || null })}
            >
              <option value="">Semua poli</option>
              {clinics.map((clinic) => (
                <option key={clinic.id} value={clinic.id}>
                  {clinic.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            <span>Status</span>
            <select value={filters.status} onChange={(e) => apply({ status: e.target.value })}>
              <option value="">Semua status</option>
              {statuses.map((status) => (
                <option key={status.value} value={status.value}>
                  {status.label}
                </option>
              ))}
            </select>
          </label>
          <label className="booking-search">
            <span>Cari nomor antrean</span>
            <input
              type="search"
              placeholder="A-004, HT-000027, atau nama"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </label>
          <div className="booking-filter-actions">
            <button className="button">Cari</button>
            <button
              type="button"
              className="text-button"
              onClick={() => apply({ tanggal: '' })}
              aria-pressed={filters.date === ''}
            >
              Semua tanggal
            </button>
          </div>
        </form>
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Nomor</th>
                <th>Pasien</th>
                <th>Poli · Dokter</th>
                <th>Tanggal</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {bookings.map((item) => (
                <tr key={item.id} className={item.id === selected ? 'next-row' : ''}>
                  <td>
                    <strong>{item.number}</strong>
                  </td>
                  <td>{item.name}</td>
                  <td>
                    {item.clinic}
                    <br />
                    <span className="muted">{item.doctor}</span>
                  </td>
                  <td>{item.dateLabel.replace(/^[^,]+, /, '')}</td>
                  <td>
                    <StatusPill status={item.status} label={item.statusLabel} />
                  </td>
                  <td>
                    <button
                      className="text-button"
                      aria-expanded={item.id === selected}
                      onClick={() => setSelected(item.id === selected ? null : item.id)}
                    >
                      Detail
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!bookings.length && (
          <p className="empty muted" role="status">
            Tidak ada booking untuk filter ini.
          </p>
        )}
        <div className="table-footer">
          <span>
            Menampilkan {bookings.length} dari {bookingTotal} booking
            {filters.date ? '' : ' · semua tanggal'}
          </span>
          <span>Urut tanggal, poli, nomor</span>
        </div>
      </section>
      <div className="stack">
        {booking ? (
          <BookingDetail key={booking.id} booking={booking} onClose={() => setSelected(null)} />
        ) : (
          <section className="card stack">
            <h3>Booking terbaru</h3>
            <p className="muted small">Termasuk booking untuk hari lain.</p>
            <ul className="recent-bookings">
              {recentBookings.map((item) => (
                <li key={item.id}>
                  <button
                    className="text-button"
                    onClick={() => {
                      setSearch(item.number);
                      apply(
                        { tanggal: item.date, cari: item.number, bpoli: null, status: '' },
                        () => setSelected(item.id),
                      );
                    }}
                  >
                    <strong>{item.number}</strong> · {item.name}
                  </button>
                  <small>
                    {item.clinic} · {item.dateLabel} · {item.status}
                    {item.bookedAt ? ` · dibuat ${item.bookedAt}` : ''}
                  </small>
                </li>
              ))}
            </ul>
          </section>
        )}
        <WalkInForm range={bookingDates} />
      </div>
    </div>
  );
}

function StatusPill({ status, label }: { status: string; label: string }) {
  return <span className={`status-pill ${status}`}>{label}</span>;
}

function BookingDetail({ booking, onClose }: { booking: Booking; onClose: () => void }) {
  const { flash } = usePage();
  const { busy, error, send } = useAction();
  // Keyed by booking id (see Bookings), so the cancel form starts closed for each booking.
  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState('');
  const act = (url: string, question?: string, data: Record<string, string> = {}) => {
    if (question && !window.confirm(question)) return;
    send('post', url, data);
  };
  const base = `/petugas/antrean/${booking.id}`;
  const cancellable =
    (booking.status === 'booked' || booking.status === 'waiting') &&
    (booking.isToday || !booking.checkInClosed);
  return (
    <section className="card stack booking-detail" aria-label={`Detail booking ${booking.number}`}>
      <div className="profile-row">
        <div>
          <p className="eyebrow">DETAIL BOOKING · {booking.code}</p>
          <h2>{booking.number}</h2>
        </div>
        <button className="text-button" onClick={onClose}>
          Tutup
        </button>
      </div>
      <StatusPill status={booking.status} label={booking.statusLabel} />
      <dl>
        <Row
          label="Pasien"
          value={`${booking.name}${booking.phone ? ` · ${booking.phone}` : ''}`}
        />
        <Row label="Poli · Dokter" value={`${booking.clinic} · ${booking.doctor}`} />
        <Row label="Tanggal" value={`${booking.dateLabel} · ${booking.time}`} />
        <Row
          label="Dibuat"
          value={booking.bookedAt ? `${booking.bookedAt} · ${booking.bookedBy}` : 'Data lama'}
        />
        <Row
          label="Kehadiran"
          value={
            booking.checkInAt
              ? `Hadir ${booking.checkInAt}${booking.checkInBy ? ` · dikonfirmasi ${booking.checkInBy}` : ''}${booking.checkInMethod ? ` · ${booking.checkInMethod}` : ''}`
              : booking.status === 'no_show'
                ? `Tidak hadir · ${booking.noShowAt ?? ''}${booking.noShowBy ? ` oleh ${booking.noShowBy}` : ''}`
                : booking.status === 'cancelled'
                  ? '—'
                  : `Belum check-in · batas ${booking.deadline ?? '—'}${booking.checkInClosed ? ' (terlewati)' : ''}`
          }
        />
        {booking.calledCount > 0 && <Row label="Dipanggil" value={`${booking.calledCount}×`} />}
        {booking.cancelledAt && (
          <Row
            label="Dibatalkan"
            value={`${booking.cancelledAt} · ${booking.cancelledBy}${booking.cancelReason ? ` · ${booking.cancelReason}` : ''}`}
          />
        )}
      </dl>
      <ol className="stage-list" aria-label="Tahap pelayanan">
        {booking.stages.map((stage) => (
          <li key={stage.name}>
            <strong>{stage.name}</strong> · {stage.status}
            {stage.at ? ` · ${stage.at}` : ''}
            {stage.by ? ` · ${stage.by}` : ''}
          </li>
        ))}
      </ol>
      {(error || flash.error) && (
        <Notice tone="warning" role="alert">
          {error || flash.error}
        </Notice>
      )}
      <div className="detail-actions">
        {booking.status === 'booked' && booking.isToday && (
          <button
            className="button"
            disabled={busy}
            onClick={() =>
              act(
                '/petugas/check-in',
                `Konfirmasi ${booking.name} (${booking.number}) hadir? Cocokkan identitas pasien terlebih dahulu.`,
                { code: booking.code },
              )
            }
          >
            Konfirmasi hadir
          </button>
        )}
        {booking.status === 'booked' && (
          <button
            className="button secondary"
            disabled={busy || !booking.checkInClosed}
            title={
              booking.checkInClosed
                ? undefined
                : `Tersedia setelah batas check-in ${booking.deadline}`
            }
            onClick={() =>
              act(
                `${base}/tidak-hadir`,
                `Tandai ${booking.number} tidak hadir? Tidak dapat diulang.`,
              )
            }
          >
            Tandai tidak hadir
          </button>
        )}
        {booking.status === 'waiting' && booking.isToday && (
          <button className="button" disabled={busy} onClick={() => act(`${base}/panggil`)}>
            Panggil
          </button>
        )}
        {booking.status === 'serving' && (
          <>
            <button
              className="button secondary"
              disabled={busy}
              onClick={() => act(`${base}/panggil-ulang`)}
            >
              Panggil ulang
            </button>
            <button
              className="button"
              disabled={busy}
              onClick={() => act(`${base}/selesai-periksa`)}
            >
              Selesai periksa, lanjut lab
            </button>
            <button
              className="button secondary"
              disabled={busy || booking.calledCount < 2}
              onClick={() =>
                act(
                  `${base}/tidak-hadir`,
                  `Tandai ${booking.number} tidak hadir setelah dipanggil?`,
                )
              }
            >
              Tidak hadir
            </button>
          </>
        )}
        {booking.status === 'laboratory' && (
          <button
            className="button"
            disabled={busy}
            onClick={() => act(`${base}/selesai-laboratorium`)}
          >
            Selesai lab, lanjut ke farmasi
          </button>
        )}
      </div>
      {booking.status === 'booked' && !booking.checkInClosed && (
        <p className="muted small">
          Status masih Belum check-in. Tidak hadir baru dapat dicatat setelah batas{' '}
          {booking.deadline}.
        </p>
      )}
      {cancellable &&
        (cancelling ? (
          <form
            className="cancel-form stack"
            onSubmit={(event) => {
              event.preventDefault();
              send('post', `${base}/batal`, { reason }, () => setCancelling(false));
            }}
          >
            <label>
              <span>Alasan pembatalan</span>
              <textarea
                value={reason}
                maxLength={255}
                required
                minLength={3}
                rows={2}
                onChange={(e) => setReason(e.target.value)}
                placeholder="Mis. pasien meminta jadwal ulang"
              />
            </label>
            <p className="muted small">
              Booking tidak dihapus: alasan, waktu, dan nama Anda dicatat. Kuota dokter dibuka
              kembali.
            </p>
            <div className="split-actions">
              <button className="button danger" disabled={busy || reason.trim().length < 3}>
                Ya, batalkan booking
              </button>
              <button
                type="button"
                className="button secondary"
                onClick={() => setCancelling(false)}
              >
                Tidak jadi
              </button>
            </div>
          </form>
        ) : (
          <button className="text-button danger-text" onClick={() => setCancelling(true)}>
            Batalkan booking…
          </button>
        ))}
    </section>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt>{label}</dt>
      <dd>{value}</dd>
    </div>
  );
}

interface ScheduleClinic {
  id: number;
  name: string;
  doctors: { id: number; name: string; time: string; quota: number }[];
}
/** Create: a registered patient who arrived at the desk, under the same quota and numbering. */
function WalkInForm({ range }: { range: { min: string; max: string } }) {
  const { busy, error, send } = useAction();
  const [phone, setPhone] = useState('');
  const [patient, setPatient] = useState<{
    name: string;
    phone: string | null;
    active: string | null;
  } | null>(null);
  const [lookup, setLookup] = useState('');
  const [date, setDate] = useState(range.min);
  const [schedule, setSchedule] = useState<ScheduleClinic[]>([]);
  const [scheduleError, setScheduleError] = useState('');
  const [clinicId, setClinicId] = useState<number | null>(null);
  const [doctorId, setDoctorId] = useState<number | null>(null);
  const [reload, setReload] = useState(0);
  const clinic = schedule.find((item) => item.id === clinicId) ?? schedule[0];
  const doctor = clinic?.doctors.find((item) => item.id === doctorId) ?? clinic?.doctors[0];

  // Doctors and remaining quota for the chosen date, from the server.
  useEffect(() => {
    let current = true;
    fetch(`/petugas/jadwal?tanggal=${encodeURIComponent(date)}`, {
      headers: { Accept: 'application/json' },
    })
      .then(async (response) => {
        if (!response.ok) throw new Error();
        const data = (await response.json()) as { open: boolean; clinics: ScheduleClinic[] };
        if (!current) return;
        setSchedule(data.clinics);
        setScheduleError(data.open ? '' : 'Tanggal ini di luar masa booking.');
      })
      .catch(() => current && setScheduleError('Jadwal tidak dapat dimuat. Periksa koneksi.'));
    return () => {
      current = false;
    };
  }, [date, reload]);

  async function find(event: FormEvent) {
    event.preventDefault();
    setPatient(null);
    setLookup('Mencari…');
    try {
      const response = await fetch(`/petugas/pasien?telepon=${encodeURIComponent(phone)}`, {
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok) return setLookup(data.message ?? 'Pasien tidak ditemukan.');
      setPatient(data.patient);
      setLookup('');
    } catch {
      setLookup('Koneksi ke server terputus. Coba lagi.');
    }
  }
  function book() {
    if (!clinic || !doctor) return;
    send(
      'post',
      '/petugas/booking',
      { phone, polyclinic_id: clinic.id, doctor_id: doctor.id, date },
      () => {
        setPatient(null);
        setPhone('');
        setReload(reload + 1);
      },
    );
  }
  return (
    <section className="card stack walk-in" aria-label="Buat booking pasien datang langsung">
      <h3>Buat booking · pasien datang langsung</h3>
      <form className="walk-in-find" onSubmit={find}>
        <label>
          <span>Nomor ponsel pasien terdaftar</span>
          <input
            inputMode="tel"
            value={phone}
            placeholder="0812-3456-7890"
            onChange={(e) => {
              setPhone(e.target.value);
              setPatient(null);
            }}
          />
        </label>
        <button className="button secondary" disabled={phone.trim().length < 8}>
          Cari pasien
        </button>
      </form>
      {lookup && (
        <p className="muted small" role="status">
          {lookup}
        </p>
      )}
      {patient && (
        <>
          <Notice tone={patient.active ? 'warning' : 'green'}>
            {patient.name}
            {patient.phone ? ` · ${patient.phone}` : ''}
            {patient.active ? ` · sudah punya kunjungan aktif ${patient.active}` : ''}
          </Notice>
          <label>
            <span>Tanggal kunjungan</span>
            <input
              type="date"
              min={range.min}
              max={range.max}
              value={date}
              onChange={(e) => e.target.value && setDate(e.target.value)}
            />
          </label>
          <label>
            <span>Poliklinik</span>
            <select
              value={clinic?.id ?? ''}
              onChange={(e) => {
                setClinicId(Number(e.target.value));
                setDoctorId(null);
              }}
            >
              {schedule.map((item) => (
                <option key={item.id} value={item.id}>
                  {item.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            <span>Dokter</span>
            <select
              value={doctor?.id ?? ''}
              disabled={!clinic?.doctors.length}
              onChange={(e) => setDoctorId(Number(e.target.value))}
            >
              {clinic?.doctors.map((item) => (
                <option key={item.id} value={item.id}>
                  {item.name} · {item.time} · sisa {item.quota}
                </option>
              ))}
            </select>
          </label>
          <p className="small">
            Sisa kuota: <strong>{doctor ? `${doctor.quota} pasien` : '—'}</strong>
          </p>
          {scheduleError && <Notice tone="warning">{scheduleError}</Notice>}
          {error && (
            <Notice tone="warning" role="alert">
              {error}
            </Notice>
          )}
          <button
            className="button"
            disabled={busy || !doctor || doctor.quota < 1 || !!scheduleError}
            onClick={book}
          >
            {doctor && doctor.quota < 1 ? 'Kuota penuh' : 'Ambil antrean'}
          </button>
        </>
      )}
    </section>
  );
}
