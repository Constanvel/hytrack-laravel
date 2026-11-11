import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Shell, HyaHelp } from '@/components/shell';
import { ActionLink, Icon, Notice, RowLink, Steps } from '@/components/ui';
import { splitRoom, useAction, useLive } from '@/lib/live';
import type { Visit as VisitData } from '@/types';

/** Staff normally move a visit forward; in demo mode the patient may simulate that. */
function Simulate({
  legend,
  action,
  children,
  secondary = false,
}: {
  legend: string;
  action: string;
  children: string;
  secondary?: boolean;
}) {
  const { demo } = usePage().props;
  const { busy, post } = useAction();
  if (!demo) return null;
  return (
    <fieldset className="demo-controls">
      <legend>{legend}</legend>
      <button
        className={`button ${secondary ? 'secondary' : ''}`}
        disabled={busy}
        onClick={() => post('/kunjungan/simulasi', { action })}
      >
        {children}
      </button>
    </fieldset>
  );
}

export function Visit() {
  const { visit } = usePage().props;
  const live = useLive();
  const { busy, destroy } = useAction();
  if (!visit)
    return (
      <Shell title="Kunjungan" subtitle="RAWAT JALAN">
        <section className="card empty">
          <h2>Belum ada kunjungan aktif</h2>
          <p className="muted">Pilih poliklinik dan jadwal dokter untuk memulai.</p>
          <ActionLink href="/poliklinik">Ambil antrean</ActionLink>
        </section>
        <HyaHelp />
      </Shell>
    );
  if (visit.stage === 'waiting' || visit.stage === 'serving')
    return <Queue visit={visit} live={live} />;
  if (visit.stage === 'laboratory') return <NextService visit={visit} />;
  if (visit.stage === 'pharmacy') return <Pharmacy visit={visit} />;
  if (visit.stage === 'completed')
    return (
      <Shell title="Kunjungan selesai">
        <Notice>Kunjungan selesai. Akses pendamping telah berakhir.</Notice>
        <section className="card empty">
          <h2>Terima kasih.</h2>
          <p>Semoga lekas sehat, {visit.patientName}.</p>
          <ActionLink href="/poliklinik">Mulai kunjungan baru</ActionLink>
        </section>
      </Shell>
    );
  if (visit.stage === 'no_show')
    return (
      <Shell title="Kunjungan" subtitle="RAWAT JALAN">
        <Notice tone="warning">
          Nomor {visit.ticket} ditandai tidak hadir setelah dipanggil ulang.
        </Notice>
        <section className="card empty">
          <h2>Anda melewatkan panggilan</h2>
          <p className="muted">Hubungi petugas di meja check-in atau ambil antrean baru.</p>
          <ActionLink href="/poliklinik">Ambil antrean baru</ActionLink>
        </section>
        <HyaHelp />
      </Shell>
    );
  return (
    <Shell title="Tiket kunjungan" subtitle={visit.dateLabel.toUpperCase()} back="/" guide="tiket">
      <Notice>✓ Antrean berhasil diambil</Notice>
      <section className="card ticket-card">
        <p className="eyebrow">NOMOR ANTREAN ANDA</p>
        <div className="ticket-number">{visit.ticket}</div>
        <h3>{visit.patientName}</h3>
        <hr />
        <h3>{visit.clinic}</h3>
        <p className="muted">
          {visit.doctor}
          <br />
          {visit.dateLabel} · {visit.time}
        </p>
      </section>
      <section className="warning-card" data-guide="">
        <h2 style={{ fontSize: 22 }}>Berangkat sebelum {visit.leaveBy}</h2>
        <p>
          Check-in paling lambat {visit.checkInBy}, {visit.leadMinutes} menit sebelum praktik
          dimulai. Perjalanan ±{visit.travelMinutes} menit adalah perkiraan umum; sesuaikan dengan
          perjalanan Anda.
        </p>
      </section>
      <div className="metrics">
        <div>
          <strong>{visit.ahead} pasien</strong>
          <small>sedang menunggu</small>
        </div>
        <div>
          <strong>{visit.waitRange ?? '—'} mnt</strong>
          <small>setelah check-in</small>
        </div>
      </div>
      <div data-guide="">
        <ActionLink href="/check-in">Tampilkan QR check-in</ActionLink>
      </div>
      <p className="small muted">
        Gedung A, lantai 2 · Meja check-in
        <br />
        Estimasi berubah mengikuti pelayanan.
      </p>
      <HyaHelp />
      <button className="text-button" disabled={busy} onClick={() => destroy('/kunjungan')}>
        Batalkan kunjungan
      </button>
    </Shell>
  );
}
export function CheckIn() {
  const { visit, hospital } = usePage().props;
  const [manual, setManual] = useState(false);
  useLive();
  if (!visit || visit.stage === 'completed' || visit.stage === 'no_show') return <Visit />;
  const booked = visit.stage === 'booked';
  return (
    <Shell
      title="Check-in kedatangan"
      subtitle={hospital.toUpperCase()}
      back="/kunjungan"
      guide="checkin"
    >
      <h2>
        Tunjukkan QR ini
        <br />
        kepada petugas
      </h2>
      <p className="muted">Meja check-in · Gedung A, lantai 2</p>
      <section className="card qr-card" data-guide="">
        <h3>
          {visit.ticket} · {visit.patientName}
        </h3>
        <img
          className="qr-example"
          src="/assets/qr-example.svg"
          width={232}
          height={232}
          alt="Gambar QR contoh; gunakan nomor check-in di bawahnya"
        />
        <p className="small muted">{visit.checkInCode} · Nomor check-in</p>
      </section>
      <Notice tone={booked ? 'warning' : 'green'}>
        {!booked
          ? 'Sudah check-in'
          : visit.isToday
            ? `Belum check-in · Batas ${visit.checkInBy}`
            : `Check-in dibuka pada ${visit.dateLabel}`}
      </Notice>
      <ol className="plain-list">
        <li>Naik ke lantai 2 melalui lift Gedung A.</li>
        <li>Tunjukkan layar ini ke petugas check-in.</li>
        <li>Status berubah setelah petugas mengonfirmasi.</li>
      </ol>
      <p className="muted">
        Gambar QR masih contoh dan belum dapat dipindai. Petugas mengonfirmasi kedatangan dengan
        nomor check-in.
      </p>
      <button
        className="button secondary"
        aria-expanded={manual}
        onClick={() => setManual(!manual)}
      >
        Nomor check-in manual
      </button>
      {manual && <Notice>Nomor check-in: {visit.checkInCode}. Sebutkan kepada petugas.</Notice>}
      {booked ? (
        <Simulate legend="Kontrol demo · menggantikan konfirmasi petugas" action="check-in">
          Simulasikan konfirmasi petugas
        </Simulate>
      ) : (
        <ActionLink href="/kunjungan">Lihat antrean saya</ActionLink>
      )}
    </Shell>
  );
}
function Queue({ visit, live }: { visit: VisitData; live: ReturnType<typeof useLive> }) {
  const serving = visit.stage === 'serving';
  const [room, building] = splitRoom(visit.room);
  return (
    <Shell title="Antrean saya" subtitle="RAWAT JALAN · HARI INI" queue guide="antrean">
      <div className="connection-row" role="status">
        <strong>{live.online ? '● Terhubung' : '○ Koneksi terputus'}</strong>
        <span>
          {live.online ? 'Diperbarui' : 'Data terakhir'} {live.syncedAt}
        </span>
      </div>
      <section className="hero queue-hero" data-guide="">
        <p className="eyebrow">{serving ? 'GILIRAN ANDA' : 'SUDAH CHECK-IN'}</p>
        <h2>{serving ? 'Silakan masuk' : 'Menunggu giliran'}</h2>
        <div className="ticket-number">{visit.ticket}</div>
        <p className="ahead">
          {serving ? `Nomor Anda dipanggil ke ${room}` : `${visit.ahead} pasien lagi sebelum Anda`}
        </p>
        <hr />
        <div className="metrics">
          <div>
            <strong>{serving ? 'Sekarang' : `${visit.waitRange ?? '—'} mnt`}</strong>
            <small>perkiraan tunggu</small>
          </div>
          <div>
            <strong>{serving ? visit.ticket : (visit.serving ?? '—')}</strong>
            <small>sedang dilayani</small>
          </div>
        </div>
      </section>
      <section className="queue-location">
        <p className="eyebrow">{serving ? 'MASUK KE SINI' : 'TUNGGU DI SINI'}</p>
        <h2>Poli {visit.clinic}</h2>
        <p className="muted">{building ? `${room} · ${building}` : room}</p>
        <ActionLink href="/petunjuk-ruangan" secondary>
          Lihat petunjuk ruangan <Icon name="arrow" />
        </ActionLink>
      </section>
      <p className="muted queue-reminder">
        Tetap di dekat ruang. Dengarkan juga
        <br />
        panggilan petugas rumah sakit.
      </p>
      <div className="queue-share">
        <RowLink href="/pendamping">Bagikan ke pendamping</RowLink>
      </div>
      <HyaHelp />
      <Simulate legend="Kontrol demo · menggantikan tindakan petugas" action="examined" secondary>
        Simulasikan pemeriksaan selesai
      </Simulate>
    </Shell>
  );
}
function NextService({ visit }: { visit: VisitData }) {
  const [room] = splitRoom(visit.room);
  return (
    <Shell
      title="Layanan berikutnya"
      subtitle={`KUNJUNGAN ${visit.ticket}`}
      back="/kunjungan"
      guide="layanan"
    >
      <Notice>✓ Pemeriksaan dokter selesai</Notice>
      <h2>
        Selanjutnya, ke
        <br />
        laboratorium
      </h2>
      <section className="hero">
        <p className="eyebrow">GEDUNG A</p>
        <div className="profile-row">
          <strong style={{ fontSize: 58 }}>L1</strong>
          <span style={{ fontSize: 56 }} aria-label="Turun">
            ↓
          </span>
        </div>
        <h3>Laboratorium · A-108</h3>
        <p>Turun 1 lantai · ±3 menit berjalan</p>
      </section>
      <Steps
        items={[
          { title: 'Gunakan lift di sebelah kanan', detail: `Dari ${room}, ikuti tanda lift.` },
          { title: 'Turun ke lantai 1', detail: 'Keluar lift, lalu belok kiri.' },
          { title: 'Lapor di meja laboratorium', detail: `Tunjukkan tiket ${visit.ticket}.` },
        ]}
      />
      <section>
        <h3>Lapor dulu di meja laboratorium</h3>
        <p className="muted small">Antrean laboratorium dimulai setelah Anda melapor.</p>
      </section>
      <ActionLink href="/petunjuk-ruangan" secondary>
        Lihat petunjuk ruangan
      </ActionLink>
      <Simulate legend="Kontrol demo · menggantikan tindakan petugas" action="laboratory">
        Simulasikan lanjut ke farmasi
      </Simulate>
    </Shell>
  );
}
function Pharmacy({ visit }: { visit: VisitData }) {
  const order = visit.pharmacy;
  const ready = order?.status === 'ready';
  const pickup = order?.pickupNumber ?? '—';
  return (
    <Shell title="Status obat" subtitle="FARMASI RAWAT JALAN" back="/kunjungan" guide="layanan">
      <Notice>● Status farmasi · {order?.label ?? 'Menunggu resep'}</Notice>
      <section className="hero">
        <p className="eyebrow">{ready ? 'OBAT SIAP DIAMBIL' : 'OBAT SEDANG DISIAPKAN'}</p>
        <h2>{ready ? 'Silakan ke loket F2' : (order?.label ?? 'Menunggu resep')}</h2>
        <p>{ready ? 'Tunjukkan nomor pengambilan Anda.' : 'Tunggu sampai nomor Anda dipanggil.'}</p>
        <hr />
        <div className="profile-row">
          <strong style={{ fontSize: 38 }}>{pickup}</strong>
          <p className="small">
            Nomor
            <br />
            pengambilan
          </p>
        </div>
      </section>
      <Steps
        items={[
          { title: 'Resep diterima', detail: 'Resep dari dokter diterima.' },
          { title: 'Obat disiapkan', detail: 'Sedang dikerjakan petugas farmasi.' },
          { title: 'Pemeriksaan akhir', detail: 'Obat diperiksa apoteker.' },
          {
            title: 'Siap diambil',
            detail: ready ? `Nomor ${pickup} dipanggil.` : 'Tunggu pemberitahuan sebelum ke loket.',
          },
        ]}
      />
      <section className="info-card">
        <h3>{ready ? 'Ambil obat di Farmasi' : 'Tunggu di area Farmasi'}</h3>
        <p>
          Gedung A, lantai 1 · Loket F2
          <br />
          Ambil obat setelah nomor {pickup} dipanggil.
        </p>
      </section>
      <p className="small muted">
        Perkiraan berubah jika ada obat racikan. Petugas akan memberi tahu bila ada kendala.
      </p>
      {!ready ? (
        <Simulate
          legend="Kontrol demo · menggantikan tindakan petugas"
          action="medicine-ready"
          secondary
        >
          Simulasikan obat siap
        </Simulate>
      ) : (
        <Simulate legend="Kontrol demo · menggantikan tindakan petugas" action="picked-up">
          Simulasikan pengambilan selesai
        </Simulate>
      )}
    </Shell>
  );
}
