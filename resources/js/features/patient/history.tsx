import { Shell } from '@/components/shell';
import { ActionLink, Notice } from '@/components/ui';

interface HistoryItem {
  id: number;
  number: string;
  clinic: string;
  doctor: string;
  date: string;
  time: string;
  status: string;
  statusLabel: string;
  checkInAt: string | null;
  cancelReason: string | null;
}

/** Riwayat kunjungan: every ticket of this account, read from the database. */
export function History({ items }: { items: HistoryItem[] }) {
  return (
    <Shell title="Riwayat kunjungan" subtitle="RAWAT JALAN" back="/akun">
      {!items.length ? (
        <section className="card empty">
          <h2>Belum ada kunjungan</h2>
          <p className="muted">Tiket yang Anda ambil akan tercatat di sini.</p>
          <ActionLink href="/poliklinik">Ambil antrean</ActionLink>
        </section>
      ) : (
        <ul className="history-list" aria-label="Daftar kunjungan">
          {items.map((item) => (
            <li key={item.id} className="card">
              <div className="profile-row">
                <strong className="history-number">{item.number}</strong>
                <span className={`history-status ${item.status}`}>{item.statusLabel}</span>
              </div>
              <p>
                <strong>{item.clinic}</strong>
                <br />
                <span className="muted small">
                  {item.doctor}
                  <br />
                  {item.date} · {item.time}
                </span>
              </p>
              {item.checkInAt && <Notice>Check-in tercatat {item.checkInAt}</Notice>}
              {item.cancelReason && (
                <Notice tone="warning">Dibatalkan · {item.cancelReason}</Notice>
              )}
            </li>
          ))}
        </ul>
      )}
    </Shell>
  );
}
