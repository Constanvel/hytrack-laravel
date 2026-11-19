import { usePage } from '@inertiajs/react';
import { useLayoutEffect, useRef, useState } from 'react';
import { Shell } from '@/components/shell';
import { ActionLink, Icon, Notice, RowLink } from '@/components/ui';
import { isActive, syncLabel, useAction, useLive } from '@/lib/live';

interface Clinic {
  id: number;
  name: string;
  doctors: number;
  wait: string;
}
export function Clinics({ clinics }: { clinics: Clinic[] }) {
  const { hospital } = usePage().props;
  const [query, setQuery] = useState('');
  const filtered = clinics.filter((clinic) =>
    clinic.name.toLowerCase().includes(query.toLowerCase().trim()),
  );
  return (
    <Shell
      title="Pilih poliklinik"
      subtitle="KUNJUNGAN BARU · 1 / 2"
      back="/"
      guide="poli"
      decor="waves"
    >
      <h2>Mau berobat ke mana?</h2>
      <label className="search-field">
        <Icon name="search" />
        <input
          aria-label="Cari nama poliklinik"
          placeholder="Cari nama poliklinik"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
      </label>
      <Notice>{hospital} · Rawat jalan</Notice>
      <h3>Poliklinik tersedia hari ini</h3>
      <div className="clinic-list" data-guide="">
        {filtered.map((clinic) => (
          <RowLink
            key={clinic.id}
            href={`/dokter?poli=${clinic.id}`}
            detail={
              clinic.doctors
                ? `${clinic.doctors} dokter praktik · ${clinic.wait} menit`
                : 'Jadwal belum tersedia'
            }
          >
            {clinic.name}
          </RowLink>
        ))}
      </div>
      {!filtered.length && (
        <div className="card empty" role="status">
          <h3>Poliklinik tidak ditemukan</h3>
          <p className="muted">Coba kata pencarian lain.</p>
          <button className="text-button" onClick={() => setQuery('')}>
            Hapus pencarian
          </button>
        </div>
      )}
      <p className="small muted">Perkiraan tunggu berubah mengikuti pelayanan.</p>
    </Shell>
  );
}

interface Day {
  date: string;
  day: string;
  weekday: string;
  label: string;
  /** "Jumat, 4 Des 2026" */
  medium: string;
  /** "Desember 2026" */
  month: string;
}
interface Doctor {
  id: number;
  name: string;
  time: string;
  delay: { minutes: number; reason: string | null } | null;
  days: Record<string, { quota: number; wait: string }>;
}
export function DoctorSchedule({
  clinic,
  days,
  doctors,
}: {
  clinic: { id: number; name: string; room: string | null };
  /** Every bookable service date, today first. */
  days: Day[];
  doctors: Doctor[];
}) {
  const { visit } = usePage().props;
  const [date, setDate] = useState(days[0].date);
  const [doctorId, setDoctorId] = useState(doctors[0]?.id);
  const { busy, post, error } = useAction();
  // Sisa kuota comes from the server and is refreshed by polling.
  const live = useLive();
  const strip = useRef<HTMLDivElement>(null);
  const picker = useRef<HTMLInputElement>(null);
  const day = days.find((item) => item.date === date)!;
  const doctor = doctors.find((item) => item.id === doctorId);
  // The strip shows the selected day's month; the arrows jump to the first open day of the
  // previous / next month inside the booking window.
  const months = [...new Set(days.map((item) => item.month))];
  const month = months.indexOf(day.month);
  const jump = (to: number) => setDate(days.find((item) => item.month === months[to])!.date);

  // Bring the selected date into view when it comes from the calendar or another month.
  useLayoutEffect(() => {
    const row = strip.current;
    const chip = row?.querySelector<HTMLElement>('[aria-pressed="true"]');
    if (row && chip) row.scrollLeft = chip.offsetLeft - (row.clientWidth - chip.offsetWidth) / 2;
  }, [date]);

  if (isActive(visit))
    return (
      <Shell title="Dokter & jadwal" back="/poliklinik">
        <Notice tone="warning">Anda sudah memiliki satu kunjungan aktif.</Notice>
        <ActionLink href="/kunjungan">Buka kunjungan aktif</ActionLink>
      </Shell>
    );
  const full = !!doctor && doctor.days[date].quota < 1;
  return (
    <Shell
      title="Dokter & jadwal"
      subtitle="KUNJUNGAN BARU · 2 / 2"
      back="/poliklinik"
      guide={doctors.length ? 'dokter' : undefined}
      decor="waves"
      className="schedule-shell"
      action={
        doctors.length > 0 && (
          <button
            className="button"
            data-guide=""
            disabled={busy || !doctor || full}
            onClick={() =>
              post('/kunjungan', { doctor_id: doctor!.id, polyclinic_id: clinic.id, date })
            }
          >
            {full ? 'Kuota penuh' : 'Ambil antrean'} <Icon name="arrow" className="on-dark" />
          </button>
        )
      }
    >
      <Notice>
        {clinic.name}
        {doctors.length ? (clinic.room ? ` · ${clinic.room}` : '') : ' · Jadwal belum tersedia'}
      </Notice>
      <h2>Pilih dokter & jadwal</h2>
      {!doctors.length ? (
        <section className="card empty">
          <h3>Jadwal belum tersedia</h3>
          <p className="muted">Belum ada dokter yang praktik di poliklinik ini.</p>
          <ActionLink href="/poliklinik" secondary>
            Pilih poliklinik lain
          </ActionLink>
        </section>
      ) : (
        <>
          <section className="date-section" aria-label="Tanggal kunjungan">
            <div className="date-nav">
              <button
                className="date-arrow"
                aria-label="Bulan sebelumnya"
                disabled={month === 0}
                onClick={() => jump(month - 1)}
              >
                ‹
              </button>
              <p className="date-month" aria-live="polite">
                {day.month}
              </p>
              <button
                className="date-arrow"
                aria-label="Bulan berikutnya"
                disabled={month === months.length - 1}
                onClick={() => jump(month + 1)}
              >
                ›
              </button>
              <span className="date-calendar">
                <button
                  onClick={() => {
                    const input = picker.current!;
                    try {
                      input.showPicker();
                    } catch {
                      input.focus();
                      input.click();
                    }
                  }}
                >
                  Kalender lengkap
                </button>
                {/* The browser's own date picker, limited to the booking window. */}
                <input
                  ref={picker}
                  type="date"
                  tabIndex={-1}
                  aria-hidden="true"
                  aria-label="Tanggal kunjungan"
                  min={days[0].date}
                  max={days[days.length - 1].date}
                  value={date}
                  onChange={(e) => {
                    const picked = e.target.value;
                    if (days.some((item) => item.date === picked)) setDate(picked);
                  }}
                />
              </span>
            </div>
            <div className="date-strip" ref={strip} role="group" aria-label={day.month}>
              {days
                .filter((item) => item.month === day.month)
                .map((item) => (
                  <button
                    key={item.date}
                    aria-pressed={date === item.date}
                    onClick={() => setDate(item.date)}
                  >
                    {item.day}
                    <strong>{item.label}</strong>
                  </button>
                ))}
            </div>
            <p className="date-hint">Pilih bulan atau tanggal lain untuk booking lebih awal.</p>
          </section>
          <div className="doctor-list" role="group" aria-label="Pilih dokter">
            {doctors.map((item) => {
              const selected = doctorId === item.id;
              const { quota, wait } = item.days[date];
              // A reported delay is about today's practice only.
              const delay = date === days[0].date ? item.delay : null;
              return (
                <button
                  className="doctor-option"
                  aria-pressed={selected}
                  key={item.id}
                  onClick={() => setDoctorId(item.id)}
                >
                  <strong>
                    {item.name}
                    {selected && <Icon name="check" />}
                  </strong>
                  {selected ? (
                    <>
                      <span className="doctor-when">
                        {clinic.name}
                        <br />
                        {day.medium} · {item.time}
                      </span>
                      <hr />
                      <span className="metrics">
                        <span>
                          <small>Perkiraan tunggu</small>
                          <strong>{wait} menit</strong>
                        </span>
                        <span>
                          <small>Sisa kuota</small>
                          <strong>{quota} pasien</strong>
                        </span>
                      </span>
                      <Notice tone={delay ? 'warning' : 'green'}>
                        {delay
                          ? `Dokter terlambat ±${delay.minutes} menit${delay.reason ? ` · ${delay.reason}` : ''}`
                          : `Jadwal dipilih · ${item.time}`}
                      </Notice>
                    </>
                  ) : (
                    <span className="doctor-when">
                      {item.time} · {quota ? `${quota} kuota tersisa` : 'Kuota penuh'}
                    </span>
                  )}
                </button>
              );
            })}
          </div>
          {error && (
            <Notice tone="warning" role="alert">
              {error}
            </Notice>
          )}
          <section className="warning-card">
            <strong>Antrean belum diambil</strong>
            <p>Nomor diterbitkan setelah Anda menekan Ambil antrean. Maks. 1 kunjungan aktif.</p>
          </section>
          <p className="small muted" role="status">
            {syncLabel(live)}
          </p>
        </>
      )}
    </Shell>
  );
}
