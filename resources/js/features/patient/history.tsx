import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Shell } from '@/components/shell';
import { ActionLink } from '@/components/ui';

interface HistoryItem {
  id: number;
  number: string;
  clinic: string;
  doctor: string;
  date: string;
  /** "28 Oktober 2026" and the check-in time (else the session start) for the card heading. */
  day: string;
  clock: string;
  time: string;
  status: string;
  statusLabel: string;
  checkInAt: string | null;
  cancelReason: string | null;
}

/** Riwayat kunjungan (Figma 31:3): every ticket of this account, read from the database. */
export function History({ items }: { items: HistoryItem[] }) {
  const { today, auth } = usePage().props;
  const [open, setOpen] = useState<number | null>(null);
  const done = items.filter((item) => item.status === 'completed').length;
  return (
    <Shell
      title="Riwayat kunjungan"
      subtitle={today.toUpperCase()}
      back="/akun"
      bare
      className="inter-shell history-shell"
    >
      {!items.length ? (
        <section className="card empty">
          <h2>Belum ada kunjungan</h2>
          <p className="muted">Tiket yang Anda ambil akan tercatat di sini.</p>
          <ActionLink href="/poliklinik">Ambil antrean</ActionLink>
        </section>
      ) : (
        <>
          <section className="history-lead">
            <h2>
              {done === items.length
                ? `${done} kunjungan selesai`
                : `${items.length} kunjungan tercatat`}
            </h2>
            {auth?.name && <p>Riwayat pemeriksaan {auth.name}</p>}
          </section>
          <ul className="history-list" aria-label="Daftar kunjungan">
            {items.map((item) => {
              const expanded = open === item.id;
              return (
                <li key={item.id} className="history-card">
                  <div className="history-top">
                    <p>
                      {item.day} · {item.clock}
                    </p>
                    <span className={`history-status ${item.status}`}>{item.statusLabel}</span>
                  </div>
                  <h3>{item.clinic}</h3>
                  <p className="history-doctor">{item.doctor}</p>
                  <div className="history-foot">
                    <p>No. antrean {item.number}</p>
                    <button
                      type="button"
                      aria-expanded={expanded}
                      aria-controls={`history-detail-${item.id}`}
                      onClick={() => setOpen(expanded ? null : item.id)}
                    >
                      {expanded ? 'Tutup detail' : 'Lihat detail'}
                    </button>
                  </div>
                  {expanded && (
                    <dl id={`history-detail-${item.id}`} className="history-detail">
                      <div>
                        <dt>Tanggal</dt>
                        <dd>{item.date}</dd>
                      </div>
                      <div>
                        <dt>Jadwal dokter</dt>
                        <dd>{item.time}</dd>
                      </div>
                      <div>
                        <dt>Check-in</dt>
                        <dd>{item.checkInAt ?? 'Tidak tercatat'}</dd>
                      </div>
                      {item.cancelReason && (
                        <div>
                          <dt>Alasan pembatalan</dt>
                          <dd>{item.cancelReason}</dd>
                        </div>
                      )}
                    </dl>
                  )}
                </li>
              );
            })}
          </ul>
          <ActionLink href="/poliklinik">Daftar kunjungan baru</ActionLink>
          <p className="history-note">Menampilkan semua riwayat kunjungan Anda.</p>
        </>
      )}
    </Shell>
  );
}
