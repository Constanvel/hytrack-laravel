import { Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
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

/** Status pill on the queue card. */
const stageLabel: Record<string, string> = {
  booked: 'Belum check-in',
  waiting: 'Menunggu',
  serving: 'Diperiksa',
  laboratory: 'Laboratorium',
  pharmacy: 'Farmasi',
};

/** Shared frame of the companion screens: mint atmosphere, logo and the "Pendamping" badge. */
function CompanionPage({ children }: { children: ReactNode }) {
  const { flash } = usePage();
  return (
    <div className="companion-page">
      <main id="main" className="companion-main">
        <header className="companion-header">
          <Link href="/" className="lockup" aria-label="HyTrack, ke beranda">
            <img src="/assets/logo-hytrack.png" alt="" width={72} height={54} />
            <span>
              <span className="lockup-name">HyTrack</span>
              <span className="lockup-tagline">TEMAN PERJALANAN BEROBAT</span>
            </span>
          </Link>
          <span className="companion-badge">Pendamping</span>
        </header>
        {flash.error && (
          <Notice tone="warning" role="alert">
            {flash.error}
          </Notice>
        )}
        {flash.status && <Notice role="status">{flash.status}</Notice>}
        {children}
      </main>
    </div>
  );
}

/** Antrean pasien (Figma 31:136): the queue of one accompanied patient, view only. */
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
  const [refreshing, setRefreshing] = useState(false);
  if (!visit)
    return (
      <CompanionPage>
        <section className="companion-intro">
          <h1>Antrean pasien</h1>
          <p>Akses pendamping {ended?.toLowerCase()}.</p>
        </section>
        <section className="companion-limit">
          <h2>Pantauan berakhir</h2>
          <p>
            Kunjungan sudah selesai atau pasien mencabut akses. Minta undangan baru bila masih perlu
            mendampingi.
          </p>
        </section>
        <ActionLink href="/pendampingan">Kembali ke pantauan keluarga</ActionLink>
      </CompanionPage>
    );
  const inLine = visit.stage === 'booked' || visit.stage === 'waiting';
  const estimate = inLine && visit.waitRange ? `±${visit.waitRange.split('–')[0]} mnt` : '—';
  const clock = live.syncedAt.slice(0, 5);
  return (
    <CompanionPage>
      <section className="companion-intro">
        <h1>Antrean pasien</h1>
        <p>Pantau giliran pasien yang Anda dampingi.</p>
      </section>
      <section className="companion-patient" aria-label="Pasien yang didampingi">
        <p className="eyebrow">PASIEN YANG DIDAMPINGI</p>
        <h2>{visit.patientName}</h2>
        <p>Keluarga · Pasien terhubung</p>
      </section>
      <section className="companion-queue" aria-labelledby="companion-queue-title">
        <div className="companion-queue-head">
          <p id="companion-queue-title">Nomor antrean pasien</p>
          <span className="companion-status">{stageLabel[visit.stage] ?? 'Menunggu'}</span>
        </div>
        <strong className="companion-ticket">{visit.ticket}</strong>
        <p className="companion-clinic">Poli {visit.clinic}</p>
        <dl className="companion-metrics">
          <div>
            <dt>Dipanggil</dt>
            <dd>{visit.serving ?? '—'}</dd>
          </div>
          <div>
            <dt>Di depan Anda</dt>
            <dd>{inLine ? `${visit.ahead} pasien` : '—'}</dd>
          </div>
          <div>
            <dt>Estimasi</dt>
            <dd>{estimate}</dd>
          </div>
        </dl>
        <button
          type="button"
          className="companion-refresh"
          disabled={refreshing}
          onClick={() =>
            router.reload({
              onStart: () => setRefreshing(true),
              onFinish: () => setRefreshing(false),
            })
          }
        >
          {refreshing ? 'Memperbarui…' : 'Perbarui antrean'}
        </button>
      </section>
      <section className="companion-info">
        <h2>Informasi kunjungan</h2>
        <p>
          {visit.day} · {visit.schedule}
        </p>
        <p className="place">{visit.placeDetail ?? visit.place}</p>
      </section>
      <section className="companion-limit">
        <h2>Akses pendamping · Hanya lihat</h2>
        <p>
          Anda hanya dapat memantau antrean pasien.
          <br />
          Pendaftaran antrean dilakukan oleh pasien.
        </p>
      </section>
      <p className="companion-updated" role="status">
        {live.online ? `Diperbarui pukul ${clock}` : `Koneksi terputus · data pukul ${clock}`}
      </p>
      <button
        type="button"
        className="companion-stop"
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
    </CompanionPage>
  );
}
