import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Shell, HyaHelp } from '@/components/shell';
import { ActionLink, Notice, RowLink, Steps, TicketQr } from '@/components/ui';
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
  const {
    props: { visit },
    flash,
  } = usePage();
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
  if (visit.stage === 'completed') return <Completed visit={visit} />;
  if (visit.stage === 'cancelled')
    return (
      <Shell title="Kunjungan" subtitle="RAWAT JALAN">
        <Notice tone="warning">
          Booking {visit.ticket} untuk {visit.dateLabel} dibatalkan petugas.
          {visit.cancelReason ? ` Alasan: ${visit.cancelReason}.` : ''}
        </Notice>
        <section className="card empty">
          <h2>Booking dibatalkan</h2>
          <p className="muted">
            Kuota Anda dikembalikan. Ambil antrean baru atau hubungi petugas di meja check-in.
          </p>
          <ActionLink href="/poliklinik">Ambil antrean baru</ActionLink>
        </section>
        <RowLink href="/riwayat">Riwayat kunjungan</RowLink>
        <HyaHelp />
      </Shell>
    );
  if (visit.stage === 'no_show')
    return (
      <Shell title="Kunjungan" subtitle="RAWAT JALAN">
        <Notice tone="warning">
          {visit.checkedInAt
            ? `Nomor ${visit.ticket} ditandai tidak hadir setelah dipanggil ulang.`
            : `Nomor ${visit.ticket} ditandai tidak hadir karena belum check-in hingga batas ${visit.checkInBy}.`}
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
      {/* Right after booking the flash already says so; one confirmation is enough. */}
      {!flash.status && <Notice>✓ Antrean berhasil diambil</Notice>}
      <div className="connection-row" role="status">
        <strong>{live.online ? '● Terhubung' : '○ Koneksi terputus'}</strong>
        <span>
          {live.online ? 'Diperbarui' : 'Data terakhir'} {live.syncedAt}
        </span>
      </div>
      <section className="card marked ticket-card">
        <p className="eyebrow">NOMOR ANTREAN ANDA</p>
        <div className="ticket-number">{visit.ticket}</div>
        {/* Figma 43:2 frame 04: the plus badge beside the number and a pulse line below it. */}
        <span className="ticket-badge" aria-hidden="true">
          +
        </span>
        <img
          className="ticket-pulse"
          src="/assets/ticket-pulse.svg"
          alt=""
          width={137}
          height={53}
        />
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
      <Notice tone="warning">
        Status: Belum check-in
        {visit.isToday ? ` · batas ${visit.checkInBy}` : ` · check-in dibuka ${visit.dateLabel}`}
      </Notice>
      <div className="metrics ticket-metrics">
        <div>
          <strong>{visit.ahead} pasien</strong>
          <small>sedang menunggu</small>
        </div>
        <div>
          <strong>{visit.waitRange ?? '—'} mnt</strong>
          <small>setelah check-in</small>
        </div>
      </div>
      <p className="small muted">
        Sedang dilayani: <strong>{visit.serving ?? '—'}</strong>
      </p>
      <div data-guide="">
        <ActionLink href="/check-in">Tampilkan QR check-in</ActionLink>
      </div>
      <p className="small muted">
        Gedung A, lantai 2 · Meja check-in
        <br />
        Estimasi berubah mengikuti pelayanan.
      </p>
      {/* Family can follow from booking on, not only once the patient is in the queue. */}
      <RowLink href="/pendamping">Bagikan ke pendamping</RowLink>
      <HyaHelp />
      <RowLink href="/riwayat">Riwayat kunjungan</RowLink>
      <button
        className="text-button"
        disabled={busy}
        onClick={() => {
          if (window.confirm(`Batalkan antrean ${visit.ticket}? Kuota akan dilepas.`))
            destroy('/kunjungan');
        }}
      >
        Batalkan kunjungan
      </button>
    </Shell>
  );
}
export function CheckIn() {
  const { visit, hospital } = usePage().props;
  const [manual, setManual] = useState(false);
  const manualRef = useRef<HTMLDivElement>(null);
  const live = useLive();
  useEffect(() => {
    if (manual) manualRef.current?.scrollIntoView({ block: 'nearest' });
  }, [manual]);
  if (!visit || ['completed', 'no_show', 'cancelled'].includes(visit.stage)) return <Visit />;
  const booked = visit.stage === 'booked';
  return (
    <Shell
      title="Check-in kedatangan"
      subtitle={hospital.toUpperCase()}
      back="/kunjungan"
      guide="checkin"
      action={
        <button
          className="button secondary"
          aria-expanded={manual}
          aria-controls="manual-check-in"
          onClick={() => setManual(!manual)}
        >
          Nomor check-in manual
        </button>
      }
    >
      <h2>
        Tunjukkan QR ini
        <br />
        kepada petugas
      </h2>
      <p className="muted">Meja check-in · Gedung A, lantai 2</p>
      <section className="card marked qr-card" data-guide="">
        <h3>
          {visit.ticket} · {visit.patientName}
        </h3>
        <TicketQr
          value={visit.checkInQr}
          label={`QR check-in ${visit.ticket}; nomor check-in ${visit.checkInCode}`}
        />
        <p className="small muted">{visit.checkInCode} · Nomor check-in</p>
      </section>
      <div className="connection-row" role="status">
        <strong>{live.online ? '● Terhubung' : '○ Koneksi terputus'}</strong>
        <span>
          {live.online ? 'Diperbarui' : 'Data terakhir'} {live.syncedAt}
        </span>
      </div>
      <Notice tone={booked ? 'warning' : 'green'}>
        {!booked
          ? `✓ Sudah check-in${visit.checkedInAt ? ` pukul ${visit.checkedInAt}` : ''}`
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
        QR hanya berlaku untuk kunjungan ini pada tanggal layanannya. Bila sulit terbaca, sebutkan
        nomor check-in kepada petugas.
      </p>
      <div id="manual-check-in" ref={manualRef}>
        {manual && (
          <Notice role="status">
            Nomor check-in: {visit.checkInCode}. Sebutkan kepada petugas.
          </Notice>
        )}
      </div>
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
    <Shell title="Antrean saya" subtitle="RAWAT JALAN · HARI INI" back="/" queue guide="antrean">
      <div className="connection-row" role="status">
        <strong>{live.online ? '● Terhubung langsung' : '○ Koneksi terputus'}</strong>
        <span>
          {live.online ? 'Diperbarui' : 'Data terakhir'} {live.syncedAt}
        </span>
      </div>
      <section className="hero queue-hero" data-guide="">
        <p className="eyebrow">
          {serving
            ? 'GILIRAN ANDA'
            : `SUDAH CHECK-IN${visit.checkedInAt ? ` · ${visit.checkedInAt}` : ''}`}
        </p>
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
      <section className="card marked queue-location">
        <p className="eyebrow">{serving ? 'MASUK KE SINI' : 'TUNGGU DI SINI'}</p>
        <h2>Poli {visit.clinic}</h2>
        <p className="muted">{building ? `${room} · ${building}` : room}</p>
        <ActionLink href="/petunjuk-ruangan" secondary>
          Lihat petunjuk ruangan
        </ActionLink>
      </section>
      {/* Figma says "saat 2 pasien lagi"; the server warns once the patient is next (up_next). */}
      <p className="muted queue-reminder">
        Tetap di dekat ruang. Kami beri tahu saat giliran Anda berikutnya; dengarkan juga panggilan
        petugas.
      </p>
      <div className="queue-share">
        <RowLink href="/pendamping">Bagikan ke pendamping</RowLink>
      </div>
      <Notice tone="warning">Saat sinyal putus, ikuti layar antrean rumah sakit.</Notice>
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
      <Notice>✓ Pemeriksaan dokter selesai{visit.examinedAt && ` · ${visit.examinedAt}`}</Notice>
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
        current={2}
        items={[
          { title: 'Gunakan lift di sebelah kanan', detail: `Dari ${room}, ikuti tanda lift.` },
          { title: 'Turun ke lantai 1', detail: 'Keluar lift, lalu belok kiri.' },
          { title: 'Lapor di meja laboratorium', detail: `Tunjukkan tiket ${visit.ticket}.` },
        ]}
      />
      <section className="lab-wait">
        <h3>Perkiraan antrean 10–20 menit</h3>
        <p className="muted">
          Dimulai setelah lapor di laboratorium.
          <br />
          Permintaan pemeriksaan sudah dikirim dokter.
        </p>
      </section>
      <ActionLink href="/petunjuk-ruangan" secondary>
        Lihat denah &amp; rute aksesibel
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
  // A received prescription is being prepared: earlier steps ticked, the present one filled.
  const step = Math.max(
    1,
    ['received', 'preparing', 'checking', 'ready'].indexOf(order?.status ?? ''),
  );
  return (
    <Shell title="Status obat" subtitle="FARMASI RAWAT JALAN" back="/kunjungan" guide="layanan">
      <Notice>
        ●{' '}
        {visit.syncedAt
          ? `Pembaruan farmasi · ${visit.syncedAt}`
          : `Status farmasi · ${order?.label ?? 'Menunggu resep'}`}
      </Notice>
      <section className="hero">
        <p className="eyebrow">{ready ? 'OBAT SIAP DIAMBIL' : 'OBAT SEDANG DISIAPKAN'}</p>
        <h2>
          {ready
            ? 'Silakan ke loket F2'
            : order?.readyIn
              ? `${order.readyIn} menit`
              : (order?.label ?? 'Menunggu resep')}
        </h2>
        <p>
          {ready
            ? 'Tunjukkan nomor pengambilan Anda.'
            : order?.readyBetween
              ? `Perkiraan siap ${order.readyBetween}`
              : 'Tunggu sampai nomor Anda dipanggil.'}
        </p>
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
        done={step}
        current={step}
        items={[
          {
            title: 'Resep diterima',
            detail: `${order?.receivedAt ? `${order.receivedAt} · ` : ''}Resep dari dokter diterima.`,
          },
          { title: 'Obat disiapkan', detail: 'Sedang dikerjakan petugas farmasi.' },
          { title: 'Pemeriksaan akhir', detail: 'Obat diperiksa apoteker.' },
          {
            title: 'Siap diambil',
            detail: ready ? `Nomor ${pickup} dipanggil.` : 'Tunggu pemberitahuan sebelum ke loket.',
          },
        ]}
      />
      <section className="info-card">
        <h2>{ready ? 'Ambil obat di Farmasi' : 'Tunggu di area Farmasi'}</h2>
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

/** Kunjungan telah selesai (Figma 43:2 frame 10): what was done, then back to Beranda. */
function Completed({ visit }: { visit: VisitData }) {
  const medicine = visit.pharmacy;
  return (
    <Shell
      title="Kunjungan selesai"
      subtitle={`KUNJUNGAN ${visit.ticket}`}
      action={<ActionLink href="/">Kembali ke Beranda</ActionLink>}
    >
      <Notice>✓ Kunjungan telah diselesaikan</Notice>
      <section className="done-hero">
        <p className="done-mark" aria-hidden="true">
          ✓
        </p>
        <p className="done-title">Kunjungan selesai</p>
        <p>
          Terima kasih, {visit.patientName}.
          <br />
          {medicine && 'Obat sudah diterima. '}Semoga lekas sehat.
        </p>
      </section>
      <section className="done-summary">
        <h3>Ringkasan kunjungan</h3>
        <ul>
          <li>
            <strong>✓ Check-in kedatangan</strong>
            <span>
              {visit.ticket} · {visit.patientName}
            </span>
          </li>
          {visit.laboratoryDone && (
            <li>
              <strong>✓ Layanan berikutnya</strong>
              <span>Kunjungan laboratorium selesai</span>
            </li>
          )}
          {medicine && (
            <li>
              <strong>✓ Pengambilan obat</strong>
              <span>{medicine.pickupNumber} · Diterima di Loket F2</span>
            </li>
          )}
        </ul>
      </section>
      {medicine && (
        <section className="info-card done-note">
          <h2>Sebelum meninggalkan rumah sakit</h2>
          <p>Pastikan obat dan aturan pakai sudah dijelaskan oleh petugas farmasi.</p>
        </section>
      )}
      <p className="done-end">Kunjungan Anda telah selesai.</p>
    </Shell>
  );
}
