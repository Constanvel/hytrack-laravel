import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { Shell, HyaHelp } from '@/components/shell';
import { ActionLink, Avatar, Icon, Notice, RowLink, Steps } from '@/components/ui';
import { GuideRestart } from '@/features/guide/guide';
const topics = [
  {
    title: 'Antrean & jadwal dokter',
    detail: 'Nomor, perkiraan tunggu, dan perubahan jadwal',
    href: '/bantuan/antrean',
  },
  {
    title: 'Check-in dengan QR',
    detail: 'Cara konfirmasi kedatangan di rumah sakit',
    href: '/bantuan/check-in',
  },
  {
    title: 'Arah layanan & pengambilan obat',
    detail: 'Temukan ruang tujuan dan loket farmasi',
    href: '/petunjuk-ruangan',
  },
  {
    title: 'Akses pendamping',
    detail: 'Bagikan, pantau, dan cabut akses keluarga',
    href: '/pendamping',
  },
];
export function Help() {
  const [query, setQuery] = useState('');
  const filtered = topics.filter((topic) =>
    `${topic.title} ${topic.detail}`.toLowerCase().includes(query.toLowerCase().trim()),
  );
  return (
    <Shell title="Bantuan" subtitle="Panduan kunjungan Anda">
      <Link href="/hya" className="support-card">
        <div className="profile-row">
          <Avatar />
          <div>
            <h2>Tanya Hya</h2>
            <p className="small muted">Asisten digital HyTrack</p>
          </div>
        </div>
        <p>Bantu cek antrean dan arah layanan.</p>
        <div className="row-link">
          <strong>Mulai percakapan</strong>
          <Icon name="arrow" />
        </div>
      </Link>
      <label className="search-field">
        <Icon name="search" />
        <input
          aria-label="Cari topik bantuan"
          placeholder="Cari topik bantuan"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
      </label>
      <GuideRestart />
      <h3>Panduan yang sering dicari</h3>
      <div className="faq-list">
        {filtered.map((topic) => (
          <RowLink key={topic.title} href={topic.href} detail={topic.detail}>
            {topic.title}
          </RowLink>
        ))}
        {!filtered.length && (
          <p role="status" className="muted">
            Topik tidak ditemukan. Coba kata lain atau tanyakan kepada Hya.
          </p>
        )}
      </div>
      <section className="card">
        <h3>Butuh bantuan petugas?</h3>
        <p className="muted">
          Datangi meja check-in rumah sakit.
          <br />
          Siapkan nomor antrean Anda.
        </p>
      </section>
    </Shell>
  );
}
export function CheckInGuide() {
  return (
    <Shell title="Panduan check-in" subtitle="BANTUAN · KEDATANGAN" back="/bantuan">
      <section className="intro">
        <h2>Cara check-in dengan QR</h2>
        <p>Tunjukkan QR kunjungan Anda kepada petugas ketika tiba di rumah sakit.</p>
      </section>
      <Steps
        items={[
          {
            title: 'Buka tiket kunjungan',
            detail: 'Pilih kunjungan aktif lalu tekan “Tampilkan QR check-in”.',
          },
          {
            title: 'Tunjukkan QR ke petugas',
            detail: 'Gunakan QR dari tiket Anda. Petugas akan memindainya.',
          },
          {
            title: 'Periksa status kehadiran',
            detail: 'Status menjadi “Sudah check-in” setelah konfirmasi diterima.',
          },
        ]}
      />
      <section className="warning-card">
        <strong>QR sulit dipindai?</strong>
        <p className="small">Tunjukkan nomor check-in manual atau minta bantuan petugas.</p>
      </section>
      <ActionLink href="/kunjungan">
        Buka kunjungan aktif <Icon name="arrow" />
      </ActionLink>
      <HyaHelp />
    </Shell>
  );
}
export function QueueGuide() {
  return (
    <Shell title="Antrean & jadwal" subtitle="BANTUAN · RAWAT JALAN" back="/bantuan">
      <h2>Ikuti kunjungan Anda</h2>
      <details open>
        <summary>Bagaimana mengambil antrean?</summary>
        <p>
          Pilih poliklinik, dokter, dan jadwal. Tekan Ambil antrean untuk menerbitkan tiket.
          Maksimal satu kunjungan aktif.
        </p>
      </details>
      <details>
        <summary>Apakah waktu tunggu pasti?</summary>
        <p>
          Tidak. Estimasi berubah mengikuti pelayanan. Dengarkan juga panggilan petugas rumah sakit.
        </p>
      </details>
      <details>
        <summary>Bagaimana jika saya terlambat?</summary>
        <p>
          Hubungi petugas di meja check-in. Perubahan jadwal dan kebijakan keterlambatan harus
          dikonfirmasi kepada rumah sakit.
        </p>
      </details>
      <ActionLink href="/kunjungan">Buka kunjungan</ActionLink>
      <HyaHelp />
    </Shell>
  );
}
export function Directions() {
  return (
    <Shell title="Petunjuk ruangan" subtitle="GEDUNG A · RAWAT JALAN" back="/kunjungan">
      <Notice tone="warning">
        Denah rumah sakit belum tersedia. Rute berikut berupa petunjuk teks; konfirmasikan kepada
        petugas.
      </Notice>
      <h2>Poli Penyakit Dalam</h2>
      <section className="card">
        <h3>Ruang A-204</h3>
        <p className="muted">Gedung A, lantai 2</p>
        <p>
          Naik ke lantai 2 melalui lift Gedung A. Ikuti tanda menuju Poli Penyakit Dalam dan meja
          check-in.
        </p>
      </section>
      <h3>Setelah pemeriksaan</h3>
      <Steps
        items={[
          {
            title: 'Laboratorium · A-108',
            detail:
              'Dari ruang A-204, gunakan lift di sebelah kanan. Turun ke lantai 1, keluar lift lalu belok kiri.',
          },
          {
            title: 'Farmasi · Loket F2',
            detail: 'Gedung A, lantai 1. Ambil obat setelah nomor pengambilan dipanggil.',
          },
        ]}
      />
      <Notice tone="info">
        Butuh rute aksesibel? Minta pendampingan petugas. Denah, akses lift, dan kondisi rute belum
        diverifikasi.
      </Notice>
      <ActionLink href="/bantuan" secondary>
        Bantuan kunjungan
      </ActionLink>
    </Shell>
  );
}
interface NotificationItem {
  id: number;
  message: string;
  action: string | null;
  time: string;
  unread: boolean;
}
export function Notifications({ items }: { items: NotificationItem[] }) {
  return (
    <Shell title="Notifikasi" back="/">
      {!items.length ? (
        <section className="card empty">
          <h2>Belum ada notifikasi</h2>
          <p className="muted">
            Pemberitahuan muncul saat antrean Anda berubah, misalnya ketika nomor Anda dipanggil.
          </p>
          <ActionLink href="/kunjungan" secondary>
            Buka kunjungan
          </ActionLink>
        </section>
      ) : (
        <>
          <ul className="notification-list">
            {items.map((item) => (
              <li key={item.id} className={`card ${item.unread ? 'unread' : ''}`}>
                <p className="eyebrow">
                  {item.time}
                  {item.unread && ' · BARU'}
                </p>
                <strong>{item.message}</strong>
                {item.action && <p className="small muted">{item.action}</p>}
              </li>
            ))}
          </ul>
          <p className="small muted">
            Notifikasi hanya tampil di aplikasi; SMS, WhatsApp, dan push belum tersedia.
          </p>
          <ActionLink href="/kunjungan" secondary>
            Buka kunjungan
          </ActionLink>
        </>
      )}
    </Shell>
  );
}
