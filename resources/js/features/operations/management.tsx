import { router } from '@inertiajs/react';
import { useState } from 'react';
import { DashboardShell } from './dashboard-shell';
import { Stat } from './staff';
import { Notice } from '@/components/ui';

interface Report {
  registered: number;
  completed: number;
  active: number;
  notArrived: number;
  averageWait: number | null;
  withinTarget: number | null;
  onTime: number | null;
  target: number;
  hourly: { hour: string; wait: number }[];
  clinics: { clinic: string; complete: number; wait: number | null }[];
  stages: { label: string; minutes: number | null }[];
}
const value = (number: number | null, unit: string) => (number === null ? '—' : `${number}${unit}`);
export function Management({
  dates,
  clinics,
  date,
  clinicId,
  report,
}: {
  dates: { value: string; label: string }[];
  clinics: { id: number; name: string }[];
  date: string;
  clinicId: number | null;
  report: Report;
}) {
  const [section, setSection] = useState('Ringkasan layanan');
  const [status, setStatus] = useState('');
  const selected = clinics.find((clinic) => clinic.id === clinicId);
  const exportUrl = `/manajemen/ekspor?tanggal=${date}${clinicId ? `&poli=${clinicId}` : ''}`;
  const filter = (next: { tanggal: string; poli?: number }) =>
    router.get('/manajemen', next, { preserveState: true, preserveScroll: true });
  // The slowest clinic is the one that needs attention.
  const attention = report.clinics.find((row) => row.wait !== null);
  const scale = Math.max(60, ...report.hourly.map((row) => row.wait));
  return (
    <DashboardShell management section={section} onSection={setSection}>
      <div className="dashboard-title">
        <div>
          <h1>{section}</h1>
          <p className="muted">
            Dari kedatangan hingga obat: lihat di mana pasien paling lama menunggu.
          </p>
        </div>
        <Notice>Data agregat</Notice>
      </div>
      <div className="dashboard-filters">
        <label>
          <span className="sr-only">Tanggal laporan</span>
          <select
            value={date}
            onChange={(e) =>
              filter({ tanggal: e.target.value, ...(clinicId ? { poli: clinicId } : {}) })
            }
          >
            {dates.map((item) => (
              <option key={item.value} value={item.value}>
                {item.label}
              </option>
            ))}
          </select>
        </label>
        <label>
          <span className="sr-only">Filter poliklinik</span>
          <select
            value={clinicId ?? ''}
            onChange={(e) =>
              filter({
                tanggal: date,
                ...(e.target.value ? { poli: Number(e.target.value) } : {}),
              })
            }
          >
            <option value="">Semua poliklinik</option>
            {clinics.map((clinic) => (
              <option key={clinic.id} value={clinic.id}>
                {clinic.name}
              </option>
            ))}
          </select>
        </label>
        {report.registered ? (
          <a
            className="button"
            href={exportUrl}
            onClick={() => setStatus('Laporan agregat diunduh dalam format CSV.')}
          >
            ↓ Ekspor laporan
          </a>
        ) : (
          <button className="button" disabled>
            ↓ Ekspor laporan
          </button>
        )}
      </div>
      {!report.registered ? (
        <section className="card empty">
          <h2>Belum ada data</h2>
          <p className="muted">
            Tidak ada kunjungan{selected ? ` ${selected.name}` : ''} pada tanggal ini. Pilih tanggal
            atau poliklinik lain.
          </p>
        </section>
      ) : (
        <>
          <div className="stat-grid four">
            <Stat
              label="Pasien terdaftar"
              value={String(report.registered)}
              detail={`${report.completed} selesai · ${report.active} aktif · ${report.notArrived} belum tiba`}
            />
            <Stat
              label="Rata-rata tunggu"
              value={value(report.averageWait, ' mnt')}
              detail={`Target layanan ${report.target} menit`}
            />
            <Stat
              label="Sesuai target tunggu"
              value={value(report.withinTarget, '%')}
              detail="Target layanan: minimal 85%"
            />
            <Stat
              label="Check-in tepat waktu"
              value={value(report.onTime, '%')}
              detail="Dari pasien yang sudah tiba"
            />
          </div>
          {(section === 'Ringkasan layanan' || section === 'Waktu tunggu') && (
            <div className="management-columns">
              <section className="card">
                <div className="table-header">
                  <h3>Waktu tunggu per jam</h3>
                  <small className="muted">Menit · Check-in → dokter</small>
                </div>
                {!report.hourly.length ? (
                  <Notice tone="info">
                    Belum ada pasien yang mulai diperiksa pada tanggal ini.
                  </Notice>
                ) : (
                  <>
                    <figure
                      className="wait-chart"
                      aria-label="Rata-rata waktu tunggu per jam check-in dalam menit"
                    >
                      <div className="chart-grid">
                        <span>{scale}</span>
                        <span>{scale / 2}</span>
                        <span>0</span>
                      </div>
                      <div className="chart-bars">
                        {report.hourly.map((row) => (
                          <div className="chart-column" key={row.hour}>
                            <span>{row.wait}</span>
                            <div
                              className={`chart-bar ${row.wait > report.target ? 'above' : ''}`}
                              style={{ height: `${(row.wait / scale) * 160}px` }}
                            />
                            <small>{row.hour}</small>
                          </div>
                        ))}
                      </div>
                      <figcaption>
                        <span>■ ≤{report.target} menit</span>
                        <span>■ &gt;{report.target} menit</span>
                        <span>Target {report.target} menit</span>
                      </figcaption>
                    </figure>
                    <details>
                      <summary>Lihat data grafik</summary>
                      <table>
                        <thead>
                          <tr>
                            <th>Jam</th>
                            <th>Menit</th>
                          </tr>
                        </thead>
                        <tbody>
                          {report.hourly.map((row) => (
                            <tr key={row.hour}>
                              <td>{row.hour}</td>
                              <td>{row.wait}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </details>
                  </>
                )}
              </section>
              {attention && (
                <section className="warning-card attention">
                  <p className="eyebrow">
                    {attention.wait! > report.target ? 'PERLU PERHATIAN' : 'TUNGGU TERLAMA'}
                  </p>
                  <h3>{attention.clinic}</h3>
                  <strong>{attention.wait} menit</strong>
                  <p>Rata-rata tunggu dokter. Target layanan {report.target} menit.</p>
                  <hr />
                  <p>Tinjau kapasitas layanan sebelum menambah kuota.</p>
                </section>
              )}
            </div>
          )}
          {(section === 'Ringkasan layanan' ||
            section === 'Kinerja poliklinik' ||
            section === 'Laporan') && (
            <div className="management-columns">
              <section className="card">
                <h3>Kinerja poliklinik</h3>
                <div className="table-scroll">
                  <table>
                    <thead>
                      <tr>
                        <th>Poliklinik</th>
                        <th>Selesai</th>
                        <th>Rata-rata tunggu</th>
                        <th>Target</th>
                      </tr>
                    </thead>
                    <tbody>
                      {report.clinics.map((row) => (
                        <tr key={row.clinic}>
                          <td>
                            <strong>{row.clinic}</strong>
                          </td>
                          <td>{row.complete}</td>
                          <td>{value(row.wait, ' menit')}</td>
                          <td
                            className={
                              row.wait !== null && row.wait > report.target
                                ? 'warning-text'
                                : 'primary-text'
                            }
                          >
                            {row.wait === null
                              ? '—'
                              : row.wait > report.target
                                ? 'Di atas'
                                : 'Sesuai'}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
              <section className="card stack">
                <h3>Tahap pelayanan</h3>
                {report.stages.map((stage) => (
                  <div className="profile-row" key={stage.label}>
                    <span className="muted">{stage.label}</span>
                    <strong>{value(stage.minutes, ' mnt')}</strong>
                  </div>
                ))}
                <p className="small muted">
                  Rata-rata waktu antar tahap pada data yang difilter, bukan durasi tindakan medis.
                </p>
              </section>
            </div>
          )}
          {section === 'Laporan' && (
            <Notice tone="info">
              Ekspor berisi data agregat yang sedang difilter. Tidak memuat identitas pasien.
            </Notice>
          )}
          <p className="status-line" role="status">
            {status}
          </p>
          <p className="dashboard-footnote">
            Definisi: waktu tunggu dokter dihitung sejak check-in hingga mulai dilayani. Tampilan
            manajemen tidak memuat identitas pasien.
          </p>
        </>
      )}
    </DashboardShell>
  );
}
