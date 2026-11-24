import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { DashboardShell, DashboardTitle, Stat } from './dashboard-shell';
import { Notice } from '@/components/ui';

interface Hour {
  hour: string;
  wait: number;
}
interface ClinicRow {
  clinic: string;
  complete: number;
  wait: number | null;
}
interface Report {
  registered: number;
  completed: number;
  active: number;
  notArrived: number;
  averageWait: number | null;
  withinTarget: number | null;
  onTime: number | null;
  target: number;
  updatedAt: string | null;
  hourly: Hour[];
  clinics: ClinicRow[];
  stages: { label: string; minutes: number | null; hourly: Hour[] }[];
}
interface Props {
  dates: { value: string; label: string }[];
  clinics: { id: number; name: string }[];
  date: string;
  clinicId: number | null;
  report: Report;
}

/** Figma 43:2 frames 12 and 24 always show 07.00–15.00. */
const HOURS = ['07', '08', '09', '10', '11', '12', '13', '14', '15'].map((h) => `${h}.00`);
const nextHour = (hour: string) => `${String(Number(hour.slice(0, 2)) + 1).padStart(2, '0')}.00`;
/** Slowest hour of a stage. */
const peakOf = (hourly: Hour[]) =>
  hourly.reduce<Hour | null>((top, row) => (!top || row.wait > top.wait ? row : top), null);
/** "+18 mnt" / "−2 mnt" against the target. */
const gap = (wait: number, target: number) =>
  wait > target ? `+${wait - target} mnt` : wait < target ? `−${target - wait} mnt` : '0 mnt';
/** "A", "A dan B", "A, B dan C". */
const names = (list: string[]) =>
  list.length < 2 ? (list[0] ?? '') : `${list.slice(0, -1).join(', ')} dan ${list.at(-1)}`;

export function Management(props: Props) {
  const { report, date, dates } = props;
  const [section, setSection] = useState('Ringkasan layanan');
  const empty = !report.registered;
  const today = date === dates[0]?.value ? 'hari ini' : 'pada tanggal ini';
  const status = report.updatedAt
    ? `Pembaruan terakhir ${report.updatedAt} WIB`
    : 'Belum ada pembaruan';
  const page = { ...props, empty, today };
  return (
    <DashboardShell management section={section} onSection={setSection} status={status}>
      {section === 'Waktu tunggu' ? (
        <Waits {...page} />
      ) : section === 'Kinerja poliklinik' ? (
        <Performance {...page} />
      ) : section === 'Laporan' ? (
        <Reports {...page} />
      ) : (
        <Summary {...page} />
      )}
    </DashboardShell>
  );
}

type Page = Props & { empty: boolean; today: string };

const exportUrl = ({ date, clinicId }: { date: string; clinicId: number | null }) =>
  `/manajemen/ekspor?tanggal=${date}${clinicId ? `&poli=${clinicId}` : ''}`;
const visit = (tanggal: string, poli: number | null, options = {}) =>
  router.get('/manajemen', poli ? { tanggal, poli } : { tanggal }, {
    preserveState: true,
    preserveScroll: true,
    ...options,
  });
const minutes = (value: number | null, empty: boolean) =>
  value === null ? (empty ? '0 mnt' : '—') : `${value} mnt`;
const percent = (value: number | null, empty: boolean) =>
  value === null ? (empty ? '0%' : '—') : `${value}%`;

function Pill({ empty }: { empty: boolean }) {
  return <Notice>{empty ? 'Belum ada data' : 'Data contoh'}</Notice>;
}

/** Date and clinic selects; frames 24–25 add a third select and a pale export button. */
function Filters({
  dates,
  clinics,
  date,
  clinicId,
  empty,
  flat = false,
  children,
}: Page & { flat?: boolean; children?: ReactNode }) {
  return (
    <div className={`dashboard-filters insight-filters ${flat ? 'flat' : ''}`}>
      <label>
        <span className="sr-only">Tanggal laporan</span>
        <select value={date} onChange={(e) => visit(e.target.value, clinicId)}>
          {dates.map((item, index) => (
            <option key={item.value} value={item.value}>
              {index ? item.label : `Hari ini · ${item.label}`}
            </option>
          ))}
        </select>
      </label>
      <label>
        <span className="sr-only">Filter poliklinik</span>
        <select
          value={clinicId ?? ''}
          onChange={(e) => visit(date, e.target.value ? Number(e.target.value) : null)}
        >
          <option value="">Semua poliklinik</option>
          {clinics.map((clinic) => (
            <option key={clinic.id} value={clinic.id}>
              {clinic.name}
            </option>
          ))}
        </select>
      </label>
      {children}
      {flat ? (
        empty ? (
          <button className="export-pale" disabled>
            Ekspor data
          </button>
        ) : (
          <a className="export-pale" href={exportUrl({ date, clinicId })}>
            Ekspor data
          </a>
        )
      ) : empty ? (
        <button className="button" disabled>
          ↓&nbsp; Ekspor laporan
        </button>
      ) : (
        <a className="button" href={exportUrl({ date, clinicId })}>
          ↓&nbsp; Ekspor laporan
        </a>
      )}
    </div>
  );
}

/** "Waktu tunggu per jam" (frames 12, 24, 27, 28). */
function WaitChart({ hourly, target, label }: { hourly: Hour[]; target: number; label: string }) {
  const hours = [...new Set([...HOURS, ...hourly.map((row) => row.hour)])].sort();
  const wait = new Map(hourly.map((row) => [row.hour, row.wait]));
  return (
    <section className="panel chart-panel">
      <div className="chart-head">
        <h3>Waktu tunggu per jam</h3>
        <small>Menit · {label}</small>
      </div>
      <figure
        className="wait-chart"
        aria-label={`Rata-rata waktu tunggu per jam dalam menit, ${label}`}
      >
        <span className="axis top">60</span>
        <span className="axis mid">30</span>
        <span className="axis low">0</span>
        <div
          className="chart-bars"
          style={{ gridTemplateColumns: `repeat(${hours.length}, minmax(0, 69px))` }}
        >
          {hours.map((hour) => {
            const value = wait.get(hour);
            return (
              <div className="chart-column" key={hour}>
                {value !== undefined && (
                  <>
                    <span>{value}</span>
                    <i
                      className={value > target ? 'above' : ''}
                      style={{ height: `${Math.min(value, 60) * 2.6}px` }}
                    />
                  </>
                )}
                <small>{hour}</small>
              </div>
            );
          })}
        </div>
        {!hourly.length && (
          <div className="chart-empty" role="status">
            <strong>Belum ada data waktu tunggu</strong>
            <p>Grafik akan terisi setelah pasien mulai dilayani.</p>
          </div>
        )}
        <figcaption>
          <span>■ ≤{target} menit</span>
          <span>
            <b>■</b> &gt;{target} menit
          </span>
          <span>— Target {target} menit</span>
        </figcaption>
      </figure>
      {hourly.length > 0 && (
        <table className="sr-only">
          <caption>Data grafik</caption>
          <thead>
            <tr>
              <th>Jam</th>
              <th>Menit</th>
            </tr>
          </thead>
          <tbody>
            {hourly.map((row) => (
              <tr key={row.hour}>
                <td>{row.hour}</td>
                <td>{row.wait}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

/** "Perlu perhatian": the slowest clinic, or what is still missing. */
function Attention({ report, today, idle }: { report: Report; today: string; idle: string[] }) {
  const slowest = report.clinics.find((row) => row.wait !== null);
  const peak = peakOf(report.hourly);
  if (!slowest || slowest.wait === null) {
    const [title, value, text, note] = idle;
    return (
      <section className="attention-panel idle">
        <p className="eyebrow">MENUNGGU DATA LAYANAN</p>
        <h3>{title}</h3>
        <strong>{value}</strong>
        <p>{text}</p>
        <hr />
        <p className="note">{note}</p>
      </section>
    );
  }
  const above = slowest.wait > report.target;
  const hours = peak ? ` pada jam ${peak.hour}–${nextHour(peak.hour)}` : '';
  return (
    <section className="attention-panel">
      <p className="eyebrow">{above ? 'PERLU PERHATIAN' : 'TUNGGU TERLAMA'}</p>
      <h3>{slowest.clinic}</h3>
      <strong>{slowest.wait} menit</strong>
      <p>
        Rata-rata tunggu tertinggi {today}.
        <br />
        {above
          ? `${slowest.wait - report.target} menit di atas target.`
          : `Masih sesuai target ${report.target} menit.`}
      </p>
      <hr />
      <p className="note">
        {above
          ? `Tinjau kapasitas layanan${hours} sebelum menambah kuota.`
          : `Pertahankan kapasitas layanan${hours}.`}
      </p>
    </section>
  );
}

/** Frame 12 / 27. */
function Summary(page: Page) {
  const { report, empty } = page;
  const over = report.averageWait === null ? null : report.averageWait - report.target;
  return (
    <>
      <DashboardTitle
        className="summary"
        title="Ringkasan layanan"
        subtitle="Dari kedatangan hingga obat: lihat di mana pasien paling lama menunggu."
        aside={<Pill empty={empty} />}
      />
      <Filters {...page} />
      <div className="stat-grid four">
        <Stat
          className="mint"
          label="Pasien terdaftar"
          value={String(report.registered)}
          detail={`${report.completed} selesai · ${report.active} aktif · ${report.notArrived} belum tiba`}
        />
        <Stat
          label="Rata-rata tunggu"
          value={minutes(report.averageWait, empty)}
          tone={over !== null && over > 0 ? 'warm' : undefined}
          detail={
            over === null
              ? 'Belum ada data waktu tunggu'
              : over > 0
                ? `${over} menit di atas target ${report.target} menit`
                : `Sesuai target ${report.target} menit`
          }
        />
        <Stat
          className="slate"
          label="Sesuai target tunggu"
          value={percent(report.withinTarget, empty)}
          detail={
            report.withinTarget === null
              ? 'Belum dinilai · target ≥85%'
              : 'Target layanan: minimal 85%'
          }
        />
        <Stat
          className="olive"
          label="Check-in tepat waktu"
          tone="good"
          value={percent(report.onTime, empty)}
          detail={
            report.onTime === null ? 'Belum ada pasien check-in' : 'Dari pasien yang sudah tiba'
          }
        />
      </div>
      <div className="insight-row">
        <WaitChart hourly={report.hourly} target={report.target} label="Check-in → dokter" />
        <Attention
          report={report}
          today={page.today}
          idle={[
            'Belum ada pasien',
            `${report.registered} pasien`,
            'Belum ada aktivitas layanan pada periode yang dipilih.',
            'Ringkasan akan diperbarui setelah pendaftaran dan pelayanan tercatat.',
          ]}
        />
      </div>
      <div className="insight-row">
        <section className="panel clinic-panel">
          <h3>Kinerja poliklinik</h3>
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
                  <td>{row.clinic}</td>
                  <td>{row.complete}</td>
                  <td>{row.wait === null ? '—' : `${row.wait} menit`}</td>
                  <td
                    className={
                      row.wait !== null && row.wait > report.target
                        ? 'warning-text'
                        : 'primary-text'
                    }
                  >
                    {row.wait === null ? '—' : row.wait > report.target ? 'Di atas' : 'Sesuai'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {!report.clinics.length && (
            <div className="panel-empty">
              <strong>Belum ada data poliklinik</strong>
              <p>Data muncul setelah ada aktivitas pelayanan.</p>
            </div>
          )}
        </section>
        <section className="panel stage-panel">
          <h3>Tahap pelayanan</h3>
          {report.stages.map((stage) => (
            <p key={stage.label}>
              <span>{stage.label}</span>
              <strong>{minutes(stage.minutes, empty)}</strong>
            </p>
          ))}
          <small>
            {empty
              ? 'Belum ada data pada setiap tahap pelayanan.'
              : 'Rata-rata tunggu antar tahap, bukan durasi tindakan medis.'}
          </small>
        </section>
      </div>
      <p className="dashboard-footnote small">
        Definisi: waktu tunggu dokter dihitung sejak check-in hingga mulai dilayani. Tampilan
        manajemen tidak memuat identitas pasien.
      </p>
    </>
  );
}

const STAGE_NOTES = [
  ['Target ≤30 menit', 'Belum ada pasien yang mulai dilayani.'],
  [
    'Waktu antar tahap',
    'Dihitung untuk pasien yang melanjutkan ke laboratorium.',
    'Belum ada pasien menuju laboratorium.',
  ],
  [
    'Waktu penyiapan farmasi',
    'Dihitung dari resep diterima hingga obat siap diambil.',
    'Belum ada resep yang diproses.',
  ],
];

/** Frame 24 / 28. */
function Waits(page: Page) {
  const { report, empty } = page;
  const [index, setIndex] = useState(0);
  const stage = report.stages[index];
  const peak = peakOf(stage.hourly);
  const over = stage.minutes === null ? null : stage.minutes - report.target;
  const firstPeak = peakOf(report.stages[0].hourly);
  return (
    <>
      <DashboardTitle
        flat
        className="compact"
        title="Waktu tunggu"
        subtitle="Telusuri jam sibuk dan waktu tunggu pada setiap tahap pelayanan."
        aside={<Pill empty={empty} />}
      />
      <Filters {...page} flat>
        <label>
          <span className="sr-only">Tahap pelayanan</span>
          <select value={index} onChange={(e) => setIndex(Number(e.target.value))}>
            {report.stages.map((item, i) => (
              <option key={item.label} value={i}>
                {item.label}
              </option>
            ))}
          </select>
        </label>
      </Filters>
      <div className="stat-grid four flat metrics">
        <Stat
          label="Rata-rata tunggu"
          value={minutes(stage.minutes, empty)}
          tone={index === 0 && over !== null && over > 0 ? 'warm' : undefined}
          detail={
            over === null
              ? 'Belum ada data waktu tunggu'
              : index
                ? stage.label
                : over > 0
                  ? `${over} menit di atas target ${report.target} menit`
                  : `Sesuai target ${report.target} menit`
          }
        />
        <Stat
          label="Sesuai target tunggu"
          value={percent(report.withinTarget, empty)}
          detail={
            report.withinTarget === null
              ? 'Belum dinilai · target ≥85%'
              : 'Target layanan minimal 85%'
          }
        />
        <Stat
          label="Jam tunggu tertinggi"
          value={peak?.hour ?? '—'}
          detail={peak ? `Rata-rata tunggu ${peak.wait} menit` : 'Belum ada jam layanan tercatat'}
        />
        <Stat
          label="Pasien selesai"
          value={String(report.completed)}
          detail={`Dari ${report.registered} pasien terdaftar`}
        />
      </div>
      <div className="insight-row">
        <WaitChart hourly={stage.hourly} target={report.target} label={stage.label} />
        <Attention
          report={report}
          today={page.today}
          idle={[
            'Belum ada antrean',
            '0 menit',
            'Waktu tunggu belum dapat dihitung karena belum ada pelayanan.',
            'Analisis jam sibuk tersedia setelah aktivitas pelayanan tercatat.',
          ]}
        />
      </div>
      <div className="stage-cards">
        {report.stages.map((item, i) => {
          const warm = i === 0 && item.minutes !== null && item.minutes > report.target;
          const [kind, note, idle] = STAGE_NOTES[i];
          return (
            <section key={item.label} className={`stage-card ${warm ? 'warm' : i ? 'teal' : ''}`}>
              <h3>
                0{i + 1} · {item.label}
              </h3>
              <strong>{minutes(item.minutes, empty)}</strong>
              <small>{i === 0 ? `Target ≤${report.target} menit` : kind}</small>
              <p>
                {item.minutes === null
                  ? (idle ?? note)
                  : i === 0
                    ? warm && firstPeak
                      ? `Prioritaskan peninjauan kapasitas pada jam ${firstPeak.hour}–${nextHour(firstPeak.hour)}.`
                      : 'Waktu tunggu dokter masih sesuai target.'
                    : note}
              </p>
            </section>
          );
        })}
      </div>
      <p className="dashboard-footnote spaced">
        Waktu tunggu antar tahap, bukan durasi tindakan medis. Data agregat tidak memuat identitas
        pasien.
      </p>
    </>
  );
}

const SORTS = {
  wait: 'Urutkan: tunggu tertinggi',
  complete: 'Urutkan: selesai terbanyak',
  name: 'Urutkan: nama poliklinik',
} as const;

/** Frame 25 / 29. */
function Performance(page: Page) {
  const { report, empty, today, clinicId } = page;
  const [sort, setSort] = useState<keyof typeof SORTS>('wait');
  const [focus, setFocus] = useState<string | null>(null);
  const rows = [...report.clinics].sort((a, b) =>
    sort === 'name'
      ? a.clinic.localeCompare(b.clinic)
      : sort === 'complete'
        ? b.complete - a.complete
        : (b.wait ?? -1) - (a.wait ?? -1),
  );
  const above = report.clinics.filter((row) => row.wait !== null && row.wait > report.target);
  const share = (row: ClinicRow) =>
    report.completed ? Math.round((row.complete / report.completed) * 100) : 0;
  const focused = rows.find((row) => row.clinic === focus) ?? rows[0];
  const slowest = report.clinics.find((row) => row.wait !== null);
  const peak = peakOf(report.hourly);
  return (
    <>
      <DashboardTitle
        flat
        className="compact"
        title="Kinerja poliklinik"
        subtitle="Bandingkan volume layanan dan pencapaian waktu tunggu setiap poliklinik."
        aside={<Pill empty={empty} />}
      />
      <Filters {...page} flat>
        <label>
          <span className="sr-only">Urutan poliklinik</span>
          <select value={sort} onChange={(e) => setSort(e.target.value as keyof typeof SORTS)}>
            {Object.entries(SORTS).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>
        </label>
      </Filters>
      <div className="stat-grid four flat metrics">
        <Stat
          label={empty ? 'Poliklinik dengan layanan' : 'Poliklinik dipantau'}
          value={String(report.clinics.length)}
          detail={
            empty
              ? 'Belum ada aktivitas poliklinik'
              : clinicId
                ? 'Poliklinik terpilih'
                : 'Seluruh poliklinik rawat jalan'
          }
        />
        <Stat
          label="Pasien selesai"
          value={String(report.completed)}
          detail={`Total layanan selesai ${today}`}
        />
        <Stat
          label="Di atas target tunggu"
          value={`${above.length} poli`}
          tone={above.length ? 'warm' : undefined}
          detail={
            empty
              ? 'Belum dapat dievaluasi'
              : above.length
                ? names(above.map((row) => row.clinic))
                : 'Seluruh poliklinik sesuai target'
          }
        />
        <Stat
          label="Rata-rata tunggu"
          value={minutes(report.averageWait, empty)}
          detail={
            report.averageWait === null
              ? `Belum ada data · target ≤${report.target} menit`
              : `Target maksimal ${report.target} menit`
          }
        />
      </div>
      <section className="panel compare-panel">
        <div className="chart-head">
          <h3>Perbandingan poliklinik</h3>
          <small>Target waktu tunggu ≤{report.target} menit</small>
        </div>
        <table className="compare-table">
          <thead>
            <tr>
              <th>Poliklinik</th>
              <th>Selesai</th>
              <th>Porsi layanan selesai</th>
              <th>Rata-rata tunggu</th>
              <th>Selisih target</th>
              <th>Status</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const late = row.wait !== null && row.wait > report.target;
              const tone = late ? 'warning-text' : 'primary-text';
              return (
                <tr key={row.clinic} className={row === focused ? 'selected' : ''}>
                  <td>{row.clinic}</td>
                  <td>{row.complete}</td>
                  <td>
                    <span className="share">
                      <i style={{ width: `${share(row)}%` }} />
                    </span>
                    <small>{share(row)}%</small>
                  </td>
                  <td>{row.wait === null ? '—' : `${row.wait} mnt`}</td>
                  <td className={tone}>{row.wait === null ? '—' : gap(row.wait, report.target)}</td>
                  <td className={tone}>{row.wait === null ? '—' : late ? 'Di atas' : 'Sesuai'}</td>
                  <td>
                    <button
                      className="text-button"
                      aria-pressed={row === focused}
                      onClick={() => setFocus(row.clinic)}
                    >
                      Detail
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
        {!rows.length && (
          <div className="panel-empty tall">
            <strong>Belum ada data poliklinik</strong>
            <p>
              Belum ada pasien yang dilayani pada periode ini.
              <br />
              Data perbandingan akan muncul setelah pelayanan tercatat.
            </p>
          </div>
        )}
        <small className="panel-foot">
          {report.completed
            ? `Porsi dihitung dari ${report.completed} layanan selesai · tidak termasuk pasien aktif atau belum tiba.`
            : 'Belum ada layanan selesai pada periode yang dipilih.'}
        </small>
      </section>
      <div className="focus-row">
        <section className="focus-card">
          <p className="eyebrow">
            {focused ? `FOKUS · ${focused.clinic.toUpperCase()}` : 'RINGKASAN LAYANAN'}
          </p>
          <strong>{focused?.complete ?? 0} pasien selesai</strong>
          <p>
            {focused
              ? `${share(focused)}% dari seluruh layanan selesai ${today}.`
              : 'Belum ada kontribusi layanan untuk dihitung.'}
          </p>
        </section>
        {slowest && slowest.wait !== null && slowest.wait > report.target ? (
          <section className="focus-card warm">
            <h3>Prioritas evaluasi kapasitas</h3>
            <p>
              {slowest.clinic} memiliki rata-rata tunggu {slowest.wait} menit. Tinjau kapasitas
              layanan{peak ? ` pada jam ${peak.hour}–${nextHour(peak.hour)}` : ''} sebelum menambah
              kuota.
            </p>
          </section>
        ) : (
          <section className="focus-card">
            <h3>{slowest ? 'Seluruh poliklinik sesuai target' : 'Evaluasi menunggu data'}</h3>
            <p>
              {slowest
                ? `Rata-rata tunggu tertinggi ${slowest.clinic} ${slowest.wait} menit, masih dalam target ${report.target} menit.`
                : 'Perbandingan dan rekomendasi akan tersedia setelah ada aktivitas pelayanan. Belum ada poliklinik yang perlu diprioritaskan.'}
            </p>
          </section>
        )}
      </div>
      <p className="dashboard-footnote spaced">
        Perbandingan menggunakan periode yang sama. Data agregat tidak memuat identitas pasien.
      </p>
    </>
  );
}

const KINDS = {
  summary: ['Ringkasan layanan', 'Ringkasan'],
  waits: ['Waktu tunggu', 'Waktu tunggu'],
  clinics: ['Kinerja poliklinik', 'Kinerja poli'],
} as const;
type Kind = keyof typeof KINDS;
interface Download {
  kind: Kind;
  date: string;
  label: string;
  clinicId: number | null;
  format: 'PDF' | 'CSV';
  at: string;
}
const HISTORY = 'hytrack.manajemen.unduhan';
const readHistory = (): Download[] => {
  try {
    return JSON.parse(localStorage.getItem(HISTORY) ?? '[]') as Download[];
  } catch {
    return [];
  }
};

/** Frame 26 / 30. PDF prints the preview; CSV uses the existing export. */
function Reports(page: Page) {
  const { report, empty, date, dates, clinics, clinicId } = page;
  const [kind, setKind] = useState<Kind>('summary');
  const [format, setFormat] = useState<Download['format']>('PDF');
  const [history, setHistory] = useState(readHistory);
  const label = dates.find((item) => item.value === date)?.label ?? date;
  const clinic = clinics.find((item) => item.id === clinicId)?.name ?? 'Semua poliklinik';
  const longDate = new Date(`${date}T00:00`).toLocaleDateString('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });
  const record = (entry: Omit<Download, 'at'>) => {
    const now = new Date();
    const at = `${now.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })} · ${now
      .toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
      .replace(':', '.')}`;
    const next = [{ ...entry, at }, ...history].slice(0, 20);
    setHistory(next);
    try {
      localStorage.setItem(HISTORY, JSON.stringify(next));
    } catch {
      // History is a convenience; the download itself already happened.
    }
  };
  const current = { kind, date, label, clinicId, format };
  /** Re-download a history row: CSV links straight to the export, PDF prints its filters. */
  const again = (row: Download) => {
    if (row.date === date && row.clinicId === clinicId) window.print();
    else visit(row.date, row.clinicId, { onSuccess: () => setTimeout(() => window.print(), 300) });
  };
  return (
    <>
      <DashboardTitle
        flat
        className="report"
        title="Laporan"
        subtitle="Susun laporan agregat untuk evaluasi dan dokumentasi layanan."
        aside={<Pill empty={empty} />}
      />
      <div className="report-layout">
        <section className="panel report-form">
          <h3>Buat laporan</h3>
          <p className="intro">Pilih cakupan data yang ingin diunduh.</p>
          <label>
            Jenis laporan
            <select value={kind} onChange={(e) => setKind(e.target.value as Kind)}>
              {Object.entries(KINDS).map(([key, [name]]) => (
                <option key={key} value={key}>
                  {name}
                </option>
              ))}
            </select>
          </label>
          <label>
            Periode
            <select value={date} onChange={(e) => visit(e.target.value, clinicId)}>
              {dates.map((item) => (
                <option key={item.value} value={item.value}>
                  {item.label} – {item.label}
                </option>
              ))}
            </select>
          </label>
          <label>
            Poliklinik
            <select
              value={clinicId ?? ''}
              onChange={(e) => visit(date, e.target.value ? Number(e.target.value) : null)}
            >
              <option value="">Semua poliklinik</option>
              {clinics.map((item) => (
                <option key={item.id} value={item.id}>
                  {item.name}
                </option>
              ))}
            </select>
          </label>
          <p className="field-label" id="report-format">
            Format unduhan
          </p>
          <div className="format" role="group" aria-labelledby="report-format">
            {(['PDF', 'CSV'] as const).map((item) => (
              <button key={item} aria-pressed={format === item} onClick={() => setFormat(item)}>
                {format === item ? `✓ ${item}` : item}
              </button>
            ))}
          </div>
          <div className="privacy">
            <strong>Hanya data agregat</strong>
            <p>Laporan tidak memuat nama, nomor rekam medis, atau identitas pasien.</p>
          </div>
          {empty ? (
            <button className="download" disabled>
              Unduh laporan {format}
            </button>
          ) : format === 'CSV' ? (
            <a className="download" href={exportUrl(page)} onClick={() => record(current)}>
              Unduh laporan CSV
            </a>
          ) : (
            <button
              className="download"
              onClick={() => {
                record(current);
                window.print();
              }}
            >
              Unduh laporan PDF
            </button>
          )}
          <small>
            {report.updatedAt
              ? `Data terakhir diperbarui pukul ${report.updatedAt} WIB.`
              : 'Unduhan tersedia setelah ada data layanan.'}
          </small>
        </section>
        <div className="report-side">
          <section className="panel report-preview">
            <h3>Pratinjau · {KINDS[kind][0]}</h3>
            <p className="intro">
              {longDate} · {clinic}
            </p>
            <div className="preview-boxes">
              <p>
                <span>Terdaftar</span>
                <strong>{report.registered}</strong>
              </p>
              <p>
                <span>Selesai</span>
                <strong>{report.completed}</strong>
              </p>
              <p>
                <span>Rata-rata tunggu</span>
                <strong>{minutes(report.averageWait, empty)}</strong>
              </p>
            </div>
            <table>
              <thead>
                <tr>
                  <th>Poliklinik</th>
                  <th>Selesai</th>
                  <th>Tunggu</th>
                  <th>Target</th>
                </tr>
              </thead>
              <tbody>
                {report.clinics.map((row) => (
                  <tr key={row.clinic}>
                    <td>{row.clinic}</td>
                    <td>{row.complete}</td>
                    <td>{row.wait === null ? '—' : `${row.wait} mnt`}</td>
                    <td>
                      {row.wait === null ? '—' : row.wait > report.target ? 'Di atas' : 'Sesuai'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {!report.clinics.length && (
              <div className="panel-empty">
                <strong>Belum ada data laporan</strong>
                <p>
                  Tidak ada aktivitas layanan pada periode ini.
                  <br />
                  Coba pilih periode lain atau tunggu pelayanan tercatat.
                </p>
              </div>
            )}
            <small className="panel-foot">
              {report.withinTarget === null && report.onTime === null
                ? 'Belum ada data · pencapaian target belum dapat dinilai.'
                : `Sesuai target tunggu ${percent(report.withinTarget, empty)} · Check-in tepat waktu ${percent(report.onTime, empty)}`}
            </small>
          </section>
          <section className="panel report-history">
            <h3>Riwayat unduhan</h3>
            <table>
              <thead>
                <tr>
                  <th>Laporan / periode</th>
                  <th>Format</th>
                  <th>Dibuat</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                {history.slice(0, 3).map((row, i) => (
                  <tr key={`${row.at}-${i}`}>
                    <td>
                      {KINDS[row.kind][1]} · {row.label}
                    </td>
                    <td>{row.format}</td>
                    <td>{row.at}</td>
                    <td>
                      {row.format === 'CSV' ? (
                        <a className="text-button" href={exportUrl(row)}>
                          Unduh
                        </a>
                      ) : (
                        <button className="text-button" onClick={() => again(row)}>
                          Unduh
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {!history.length && (
              <div className="panel-empty">
                <strong>Belum ada riwayat unduhan</strong>
                <p>Laporan yang sudah dibuat akan tampil di sini.</p>
              </div>
            )}
            <small className="panel-foot">
              {history.length
                ? `Menampilkan ${Math.min(history.length, 3)} unduhan terakhir · tersimpan di perangkat ini`
                : '0 unduhan · belum ada laporan yang dibuat'}
            </small>
          </section>
        </div>
      </div>
      <p className="dashboard-footnote spaced">
        Angka pada pratinjau mengikuti pilihan periode dan poliklinik. Laporan ini untuk evaluasi
        layanan, bukan catatan klinis.
      </p>
    </>
  );
}
