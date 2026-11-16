import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Shell } from '@/components/shell';
import { ActionLink, Notice, RowLink } from '@/components/ui';
import { isActive, useAction, useLive } from '@/lib/live';
import type { CompanionProgress } from '@/types';

interface Invitation {
  id: number;
  status: 'pending' | 'accepted' | 'revoked' | 'expired';
  label: string;
  companion: string | null;
  sentAt: string | null;
  acceptedAt: string | null;
}

/** Patient side: invite family members to one visit, see who accepted, revoke any time. */
export function CompanionAccess({ invitations }: { invitations: Invitation[] }) {
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
  const link = flash.shareLink;
  return (
    <Shell title="Akses pendamping" subtitle="KELOLA AKSES KUNJUNGAN" back="/kunjungan">
      <h2>Biar keluarga ikut tenang.</h2>
      <p className="muted">Undang keluarga memantau kunjungan ini, bukan informasi medis Anda.</p>
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
          <li>✓ Lokasi dan tahapan pelayanan</li>
          <li>✓ Proses penyiapan obat</li>
        </ul>
        <hr />
        <p className="muted">
          Tidak termasuk diagnosis, hasil pemeriksaan, detail resep, nama lengkap, alamat, atau
          nomor check-in Anda.
        </p>
      </section>
      <section className="warning-card">
        <h3>Satu undangan, satu pendamping</h3>
        <p>
          Pendamping menerima undangan dengan akun HyTrack-nya sendiri. Setelah diterima, tautan
          tidak bisa dipakai orang lain. Akses berakhir saat kunjungan selesai atau pada hari
          layanan pukul 23.59.
        </p>
      </section>
      {link && (
        <section className="card stack" data-testid="share-link">
          <h3>Tautan undangan</h3>
          <p className="small share-link">{link}</p>
          <p className="small muted">
            Kirim ke satu anggota keluarga. Demi keamanan, tautan ini hanya ditampilkan satu kali.
          </p>
          <button
            className="button secondary"
            onClick={() => navigator.clipboard?.writeText(link).then(() => setCopied(true))}
          >
            {copied ? 'Tautan disalin' : 'Salin tautan'}
          </button>
          {'share' in navigator && (
            <button
              className="button secondary"
              onClick={() =>
                navigator
                  .share({ title: 'Undangan pendamping HyTrack', url: link })
                  .catch(() => undefined)
              }
            >
              Bagikan tautan
            </button>
          )}
        </section>
      )}
      <button className="button" disabled={busy} onClick={() => post('/pendamping')}>
        {invitations.length ? 'Undang pendamping lain' : 'Undang pendamping'}
      </button>
      {invitations.length > 0 && (
        <section className="stack-list" aria-label="Undangan pendamping">
          <h3>Undangan untuk kunjungan ini</h3>
          {invitations.map((item) => {
            const live = item.status === 'pending' || item.status === 'accepted';
            return (
              <article className={`card invitation ${item.status}`} key={item.id}>
                <div>
                  <strong>{item.companion ?? 'Belum diterima'}</strong>
                  <p className="small muted">
                    {item.label}
                    {item.status === 'accepted' && item.acceptedAt
                      ? ` · sejak ${item.acceptedAt}`
                      : item.sentAt
                        ? ` · dikirim ${item.sentAt}`
                        : ''}
                  </p>
                </div>
                {live && (
                  <button
                    className="text-button danger"
                    disabled={busy}
                    onClick={() => destroy(`/pendamping/${item.id}`)}
                  >
                    Cabut akses
                  </button>
                )}
              </article>
            );
          })}
        </section>
      )}
    </Shell>
  );
}

/** The invitation link. Guests sign in first; the companion then accepts with their account. */
export function CompanionInvite({
  token,
  invite,
  canAccept,
}: {
  token: string;
  invite: { patientName: string; clinic: string; date: string } | null;
  canAccept: boolean;
}) {
  const { auth } = usePage().props;
  const { busy, post } = useAction();
  return (
    <Shell title="Undangan pendamping" subtitle="AKSES PENDAMPING" bare>
      {!invite ? (
        <>
          <Notice tone="warning">
            Undangan tidak berlaku, sudah dipakai, atau telah berakhir.
          </Notice>
          <p className="muted">Minta pasien mengirim undangan baru bila masih diperlukan.</p>
          {auth && <ActionLink href="/">Ke beranda</ActionLink>}
        </>
      ) : (
        <>
          <h2>
            {invite.patientName} mengundang Anda
            <br />
            mendampingi kunjungannya.
          </h2>
          <section className="card">
            <p className="eyebrow">KUNJUNGAN KELUARGA</p>
            <h3>Poli {invite.clinic}</h3>
            <p className="muted">{invite.date}</p>
            <hr />
            <ul className="checklist">
              <li>✓ Nomor antrean dan status kunjungan</li>
              <li>✓ Lokasi dan tahapan pelayanan</li>
              <li>✓ Perkiraan waktu tunggu</li>
            </ul>
            <p className="small muted">Hanya pantauan · informasi medis tetap privat.</p>
          </section>
          {canAccept ? (
            <button className="button" disabled={busy} onClick={() => post(`/pantauan/${token}`)}>
              Terima undangan
            </button>
          ) : auth ? (
            <Notice tone="info">
              Undangan ini untuk pendamping. Masuk dengan akun pasien milik pendamping untuk
              menerimanya.
            </Notice>
          ) : (
            <>
              <p className="muted">Masuk atau buat akun HyTrack untuk menerima undangan ini.</p>
              <ActionLink href="/masuk">Masuk untuk menerima</ActionLink>
              <ActionLink href="/daftar" secondary>
                Buat akun
              </ActionLink>
            </>
          )}
        </>
      )}
    </Shell>
  );
}

/** Everyone this account accompanies right now, each through their own invitation. */
export function CompanionList({
  patients,
}: {
  patients: { id: number; name: string; detail: string }[];
}) {
  return (
    <Shell title="Pantauan keluarga" subtitle="AKSES PENDAMPING" back="/">
      <h2>Kunjungan yang Anda dampingi</h2>
      {patients.length ? (
        <div className="card">
          {patients.map((item) => (
            <RowLink key={item.id} href={`/pendampingan/${item.id}`} detail={item.detail}>
              {item.name}
            </RowLink>
          ))}
        </div>
      ) : (
        <section className="card empty">
          <h3>Belum ada pantauan aktif</h3>
          <p className="muted">
            Buka tautan undangan dari pasien untuk mulai mendampingi. Akses berakhir otomatis saat
            kunjungan selesai.
          </p>
        </section>
      )}
    </Shell>
  );
}

const stageStatus: Record<string, string[]> = {
  booked: ['Menunggu', 'kedatangan'],
  waiting: ['Menunggu', 'giliran'],
  serving: ['Sedang', 'diperiksa'],
  laboratory: ['Di', 'laboratorium'],
  pharmacy: ['Di', 'farmasi'],
};
function headline(visit: CompanionProgress) {
  switch (visit.stage) {
    case 'waiting':
      return `${visit.ahead} pasien lagi${visit.waitRange ? ` · sekitar ${visit.waitRange} menit` : ''}`;
    case 'serving':
      return 'Sedang di ruang pemeriksaan dokter';
    case 'laboratory':
      return 'Menjalani pemeriksaan laboratorium';
    case 'pharmacy':
      return `Obat: ${visit.pharmacy?.label ?? 'menunggu resep'}`;
    default:
      return 'Belum tiba di meja check-in';
  }
}

/** Pantauan pendamping (Figma 27:303): live progress of one accompanied visit. */
export function CompanionDashboard({
  shareId,
  progress: visit,
  ended,
}: {
  shareId: number;
  progress: CompanionProgress | null;
  ended: string | null;
}) {
  const live = useLive();
  if (!visit)
    return (
      <Shell title="Pantauan pendamping" subtitle="AKSES PENDAMPING" back="/pendampingan" bare>
        <Notice tone="warning">Akses pendamping {ended?.toLowerCase()}.</Notice>
        <p className="muted">
          Kunjungan sudah selesai atau pasien mencabut akses. Minta undangan baru bila masih perlu
          mendampingi.
        </p>
        <ActionLink href="/pendampingan">Kembali ke pantauan keluarga</ActionLink>
      </Shell>
    );
  const [status, statusLine] = stageStatus[visit.stage] ?? ['', ''];
  return (
    <Shell
      title="Pantauan pendamping"
      subtitle="AKSES PENDAMPING"
      back="/pendampingan"
      bare
      className="inter-shell companion-shell"
      action={
        visit.phone && (
          <a className="button" href={`tel:+${visit.phone}`}>
            Hubungi pasien
          </a>
        )
      }
    >
      <div className="monitor-row">
        <div>
          <p className="eyebrow">KUNJUNGAN KELUARGA</p>
          <h2>{visit.patientName}</h2>
        </div>
        <p className={`live ${live.online ? '' : 'offline'}`} role="status">
          {live.online ? `●  Live · ${live.syncedAt}` : '○  Terputus'}
        </p>
      </div>
      <section className="monitor-hero">
        <p className="eyebrow">
          {visit.checkedInAt ? `SUDAH CHECK-IN · ${visit.checkedInAt}` : 'BELUM CHECK-IN'}
        </p>
        <div className="monitor-ticket">
          <strong>{visit.ticket}</strong>
          <span>
            {status}
            <br />
            {statusLine}
          </span>
        </div>
        <p className="monitor-headline">{headline(visit)}</p>
        {visit.serving && <p className="small">Sedang dilayani: {visit.serving}</p>}
        {visit.stage === 'pharmacy' && visit.pharmacy && (
          <p className="small">Nomor ambil obat: {visit.pharmacy.pickupNumber}</p>
        )}
      </section>
      <section className="monitor-card">
        <p className="eyebrow">LOKASI SAAT INI</p>
        <h3>{visit.place}</h3>
        {visit.placeDetail && <p>{visit.placeDetail}</p>}
      </section>
      <section className="monitor-card timeline">
        <h3>Alur kunjungan</h3>
        <ol>
          {visit.timeline.map((step) => (
            <li key={step.label} className={step.current ? 'current' : undefined}>
              <span aria-hidden="true">{step.current ? '●' : '✓'}</span>
              <span>{step.label}</span>
              <small>{step.current ? 'Saat ini' : step.time}</small>
            </li>
          ))}
        </ol>
        <p className="next">Berikutnya: {visit.next}</p>
      </section>
      <section className="monitor-privacy">
        <p>
          <strong>Hanya pantauan · informasi medis tetap privat.</strong>
        </p>
        <p>Akses berakhir saat kunjungan selesai atau {visit.endsAt}.</p>
      </section>
      <section className="tip-card">
        <strong>Tetap dekat dengan pasien</strong>
        <p>Ikuti juga panggilan petugas di rumah sakit.</p>
      </section>
      <button
        className="text-button"
        onClick={() => {
          if (
            window.confirm(
              'Berhenti memantau kunjungan ini? Anda perlu undangan baru untuk kembali.',
            )
          )
            router.delete(`/pendampingan/${shareId}`);
        }}
      >
        Berhenti memantau
      </button>
    </Shell>
  );
}
