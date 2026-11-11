import { Link, router, usePage } from '@inertiajs/react';
import { Shell, HyaHelp } from '@/components/shell';
import { ActionLink, Icon } from '@/components/ui';
import { GuideRestart } from '@/features/guide/guide';
import { isActive, useAction } from '@/lib/live';

export function Home() {
  const { visit, auth, hospital } = usePage().props;
  const active = isActive(visit);
  return (
    <Shell title="HyTrack" subtitle={hospital} guide={active ? undefined : 'pasien'}>
      <section className="intro">
        <h2>Berobat lebih tenang.</h2>
        <p>Pantau antrean. Datang tepat waktu.</p>
      </section>
      <section className="hero">
        <p className="eyebrow">RAWAT JALAN</p>
        <h2>
          {active ? (
            <>
              Kunjungan Anda
              <br />
              {visit.ticket}
            </>
          ) : (
            <>
              Belum ada
              <br />
              kunjungan aktif
            </>
          )}
        </h2>
        <p>
          {active
            ? `${visit.clinic} · ${visit.doctor}`
            : 'Ambil antrean untuk poliklinik dan dokter pilihan Anda.'}
        </p>
        <Link
          className="button white"
          href={active ? '/kunjungan' : '/poliklinik'}
          data-guide={active ? undefined : ''}
        >
          {active ? 'Lihat kunjungan' : '+ Ambil antrean'}
        </Link>
      </section>
      <section className="profile-row" data-guide={active ? undefined : ''}>
        <div>
          <p className="eyebrow">PASIEN TERPILIH</p>
          <h3>{auth?.name}</h3>
          {auth?.phone && <p className="muted">Telp {auth.phone}</p>}
        </div>
        <Link className="text-button" href="/akun">
          Akun
        </Link>
      </section>
      <hr />
      <section>
        <h3>Sebelum ke rumah sakit</h3>
        <ol className="plain-list">
          <li>Siapkan kartu identitas dan rujukan.</li>
          <li>Pilih poliklinik dan jadwal dokter.</li>
          <li>Check-in saat tiba di rumah sakit.</li>
        </ol>
        <GuideRestart />
      </section>
      <Link className="inline-link" href="/bantuan">
        <Icon name="heart" />
        Butuh bantuan? Hubungi petugas.
      </Link>
      <HyaHelp />
    </Shell>
  );
}
export function Account() {
  const { visit, auth, demo } = usePage().props;
  const { busy, destroy } = useAction();
  return (
    <Shell title="Akun" subtitle="Profil dan bantuan">
      <section className="card">
        <p className="eyebrow">AKUN PASIEN</p>
        <h2>{auth?.name}</h2>
        {auth?.phone && <p className="muted">Telp {auth.phone}</p>}
        <p>Satu akun untuk satu profil pasien. Hubungi petugas untuk memperbarui data Anda.</p>
      </section>
      <ActionLink href="/bantuan" secondary>
        Bantuan & panduan kunjungan
      </ActionLink>
      <ActionLink href="/hya" secondary>
        Tanya Hya
      </ActionLink>
      {demo && isActive(visit) && (
        <fieldset className="demo-controls">
          <legend>Kontrol demo</legend>
          <button className="button danger" disabled={busy} onClick={() => destroy('/kunjungan')}>
            Reset data kunjungan demo
          </button>
        </fieldset>
      )}
      <button className="button secondary" onClick={() => router.post('/keluar')}>
        Keluar
      </button>
    </Shell>
  );
}
