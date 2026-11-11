import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Shell } from '@/components/shell';
import { ActionLink, Icon, Notice, RowLink } from '@/components/ui';
import { isActive, useAction } from '@/lib/live';

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
    <Shell title="Pilih poliklinik" subtitle="KUNJUNGAN BARU · 1 / 2" back="/" guide="poli">
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
  days: { date: string; day: string; weekday: string; label: string }[];
  doctors: Doctor[];
}) {
  const { visit } = usePage().props;
  const [date, setDate] = useState(days[0].date);
  const [doctorId, setDoctorId] = useState(doctors[0]?.id);
  const { busy, post } = useAction();
  const day = days.find((item) => item.date === date)!;
  const doctor = doctors.find((item) => item.id === doctorId);
  if (isActive(visit))
    return (
      <Shell title="Dokter & jadwal" back="/poliklinik">
        <Notice tone="warning">Anda sudah memiliki satu kunjungan aktif.</Notice>
        <ActionLink href="/kunjungan">Buka kunjungan aktif</ActionLink>
      </Shell>
    );
  return (
    <Shell
      title="Dokter & jadwal"
      subtitle="KUNJUNGAN BARU · 2 / 2"
      back="/poliklinik"
      guide={doctors.length ? 'dokter' : undefined}
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
          <div className="date-grid" role="group" aria-label="Tanggal kunjungan">
            {days.map((item) => (
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
          <div className="stack" role="group" aria-label="Pilih dokter">
            {doctors.map((item) => {
              const selected = doctorId === item.id;
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
                  <p className="muted">
                    {selected && (
                      <>
                        {clinic.name}
                        <br />
                      </>
                    )}
                    {day.weekday}, {day.label} · {item.time}
                  </p>
                  {selected && (
                    <>
                      <hr />
                      <div className="metrics">
                        <span>
                          <small>Perkiraan tunggu</small>
                          <strong>{item.days[date].wait} menit</strong>
                        </span>
                        <span>
                          <small>Sisa kuota</small>
                          <strong>{item.days[date].quota} pasien</strong>
                        </span>
                      </div>
                      <Notice tone={item.delay ? 'warning' : 'green'}>
                        {item.delay
                          ? `Dokter terlambat ±${item.delay.minutes} menit${item.delay.reason ? ` · ${item.delay.reason}` : ''}`
                          : `Jadwal dipilih · ${item.time}`}
                      </Notice>
                    </>
                  )}
                </button>
              );
            })}
          </div>
          <section className="warning-card">
            <strong>Antrean belum diambil</strong>
            <p className="small">
              Nomor diterbitkan setelah Anda menekan Ambil antrean. Maks. 1 kunjungan aktif.
            </p>
          </section>
          <button
            className="button"
            data-guide=""
            disabled={busy || !doctor || doctor.days[date].quota < 1}
            onClick={() => post('/kunjungan', { doctor_id: doctor!.id, date })}
          >
            {doctor && doctor.days[date].quota < 1 ? 'Kuota penuh' : 'Ambil antrean'}{' '}
            <Icon name="arrow" className="on-dark" />
          </button>
        </>
      )}
    </Shell>
  );
}
