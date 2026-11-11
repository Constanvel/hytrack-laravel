import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Shell } from '@/components/shell';
import { ActionLink, Notice } from '@/components/ui';
import { isActive, splitRoom, useAction, useLive } from '@/lib/live';
import type { VisitProgress } from '@/types';

export function CompanionAccess() {
  const { props, flash } = usePage();
  const { visit, hospital } = props;
  const { busy, post, destroy } = useAction();
  const [copied, setCopied] = useState(false);
  if (!isActive(visit))
    return (
      <Shell title="Akses pendamping" back="/kunjungan">
        <Notice tone="warning">Tidak ada kunjungan aktif untuk dibagikan.</Notice>
        <ActionLink href="/kunjungan">Buka kunjungan</ActionLink>
      </Shell>
    );
  return (
    <Shell title="Akses pendamping" subtitle="KELOLA AKSES KUNJUNGAN" back="/kunjungan">
      <h2>Biar keluarga ikut tenang.</h2>
      <p className="muted">Bagikan perkembangan kunjungan, bukan informasi medis Anda.</p>
      <section className="hero">
        <p className="eyebrow">KUNJUNGAN ANDA</p>
        <h2 style={{ fontSize: 23 }}>
          {visit.patientName} · {visit.ticket}
        </h2>
        <p className="small">
          {visit.dateLabel} · {hospital}
        </p>
      </section>
      <section className="card">
        <h3>Yang bisa dilihat pendamping</h3>
        <ul className="checklist">
          <li>✓ Status dan nomor antrean</li>
          <li>✓ Perkiraan waktu tunggu</li>
          <li>✓ Lokasi layanan berikutnya</li>
          <li>✓ Proses penyiapan obat</li>
        </ul>
        <hr />
        <p className="muted">
          Tidak termasuk diagnosis, hasil pemeriksaan, detail resep, nama lengkap, atau kontak Anda.
        </p>
      </section>
      <section className="warning-card">
        <h3>Akses otomatis berakhir</h3>
        <p>Saat kunjungan selesai atau pada hari layanan pukul 23.59, mana yang lebih dulu.</p>
      </section>
      <p className="muted">
        Hanya bagikan kepada orang tepercaya. Siapa pun yang memegang tautan dapat melihat pantauan
        sampai Anda mencabutnya.
      </p>
      {flash.shareLink && (
        <section className="card stack" data-testid="share-link">
          <h3>Tautan pendamping</h3>
          <p className="small share-link">{flash.shareLink}</p>
          <p className="small muted">
            Salin sekarang. Demi keamanan, tautan ini hanya ditampilkan satu kali.
          </p>
          <button
            className="button secondary"
            onClick={() =>
              navigator.clipboard?.writeText(flash.shareLink!).then(() => setCopied(true))
            }
          >
            {copied ? 'Tautan disalin' : 'Salin tautan'}
          </button>
          <a className="button secondary" href={flash.shareLink}>
            Buka pantauan pendamping
          </a>
        </section>
      )}
      {!visit.companionActive ? (
        <button className="button" disabled={busy} onClick={() => post('/pendamping')}>
          Aktifkan akses pendamping
        </button>
      ) : (
        <>
          {!flash.shareLink && (
            <>
              <Notice tone="info">
                Akses pendamping aktif. Tautan lama tidak dapat ditampilkan lagi; buat tautan baru
                bila hilang.
              </Notice>
              <button
                className="button secondary"
                disabled={busy}
                onClick={() => post('/pendamping')}
              >
                Buat tautan baru
              </button>
            </>
          )}
          <button className="button danger" disabled={busy} onClick={() => destroy('/pendamping')}>
            Cabut akses pendamping
          </button>
        </>
      )}
    </Shell>
  );
}

const stageText: Record<string, [string, (share: VisitProgress) => string]> = {
  booked: ['BELUM CHECK-IN', () => 'Menunggu kedatangan'],
  waiting: [
    'MENUNGGU GILIRAN',
    (share) => `${share.ahead} pasien lagi${share.waitRange ? ` · ${share.waitRange} menit` : ''}`,
  ],
  serving: ['GILIRAN DIPANGGIL', () => 'Sedang menuju ruang pemeriksaan'],
  laboratory: ['LABORATORIUM', () => 'Menuju atau sedang di laboratorium'],
  pharmacy: ['FARMASI', (share) => `Obat: ${share.pharmacy?.label ?? 'menunggu resep'}`],
};
/** Public page behind the companion link: read-only and limited. */
export function CompanionView({ share, token }: { share: VisitProgress | null; token: string }) {
  const { hospital } = usePage().props;
  const live = useLive();
  if (!share)
    return (
      <Shell title="Pantauan keluarga" subtitle="AKSES PENDAMPING" bare>
        <Notice tone="warning">Akses pendamping tidak aktif atau telah berakhir.</Notice>
        <p className="muted">Minta tautan baru kepada pasien bila masih diperlukan.</p>
      </Shell>
    );
  const [eyebrow, headline] = stageText[share.stage];
  const [room, building] =
    share.stage === 'pharmacy'
      ? ['Loket F2', 'Gedung A, lantai 1']
      : share.stage === 'laboratory'
        ? ['Ruang A-108', 'Gedung A, lantai 1']
        : splitRoom(share.room);
  return (
    <Shell title="Pantauan keluarga" subtitle="AKSES PENDAMPING" bare>
      <Notice tone="info">
        Akses terbatas · Hanya pantauan ·{' '}
        {live.online ? `Diperbarui ${live.syncedAt}` : 'Koneksi terputus'}
      </Notice>
      <p className="eyebrow">KUNJUNGAN KELUARGA</p>
      <h2>{share.patientName}</h2>
      <section className="hero">
        <p className="eyebrow">{eyebrow}</p>
        <div className="ticket-number">{share.ticket}</div>
        <h3>{headline(share)}</h3>
        <p className="small">Poli {share.clinic} · Estimasi dapat berubah.</p>
      </section>
      <section className="card">
        <p className="eyebrow">LOKASI SAAT INI</p>
        <h2>{room}</h2>
        <p className="muted">{[building, hospital].filter(Boolean).join(' · ')}</p>
      </section>
      <p className="small muted">
        Hanya pantauan. Antrean tidak dapat diubah; informasi medis tetap privat.
      </p>
      <button className="text-button" onClick={() => router.delete(`/pantauan/${token}`)}>
        Berhenti memantau
      </button>
    </Shell>
  );
}
