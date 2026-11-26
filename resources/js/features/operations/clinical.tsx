import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { DashboardTitle } from './dashboard-shell';
import { Notice } from '@/components/ui';
import type { Payload } from '@/lib/live';

/**
 * Figma 47:2 frames 31–37: the doctor's examination and prescription, support services
 * and the pharmacy detail. All data comes from StaffController / ClinicalBoard; what a
 * viewer may read (notes, prescription items, results) is decided on the server.
 */

export type Send = (method: 'post', url: string, data: Payload, onSuccess?: () => void) => void;

export interface Me {
  name: string;
  unit: string | null;
  room: 'desk' | 'doctor' | 'support' | 'pharmacy';
  doctorId: number | null;
}
export interface Medicine {
  name: string;
  form: string | null;
  quantity: string;
  dosage: string;
  timing: string | null;
}
interface ResultFile {
  name: string;
  url: string;
}
export interface ExamRow {
  id: number;
  number: string;
  name: string;
  record: string | null;
  clinic: string;
  room: string | null;
  doctor: string;
  state: 'serving' | 'review' | 'returned' | 'support' | 'confirmed';
  startedAt: string | null;
  minutes: number | null;
  confirmedAt: string | null;
  confirmedStamp: string | null;
  mine: boolean;
  open: boolean;
  notes: string | null;
  allergy: string | null;
  pharmacyNote: string | null;
  items: Medicine[];
  itemCount: number;
  support: {
    id: number;
    number: string;
    unitLabel: string;
    test: string;
    status: 'waiting' | 'processing' | 'done';
    statusLabel: string;
    completedAt: string | null;
    note: string | null;
    file: ResultFile | null;
  } | null;
  order: {
    id: number;
    pickup: string;
    status: string;
    statusLabel: string;
    returnReason: string | null;
  } | null;
  trail: { time: string; title: string; detail: string | null }[];
}
export interface SupportRow {
  id: number;
  number: string;
  queueId: number;
  ticket: string;
  name: string;
  unit: string;
  unitLabel: string;
  test: string;
  status: 'waiting' | 'processing' | 'done';
  statusLabel: string;
  listLabel: string;
  requestedAt: string | null;
  completedAt: string | null;
  doctor: string;
  note: string | null;
  file: ResultFile | null;
  by: string | null;
  byUnit: string | null;
  updatedAt: string | null;
  canEdit: boolean;
}
export interface OrderRow {
  id: number;
  pickup: string;
  number: string;
  name: string;
  clinic: string;
  record: string | null;
  receivedAt: string | null;
  status: 'received' | 'preparing' | 'ready' | 'completed' | 'returned';
  statusLabel: string;
  handledBy: string | null;
  doctor: string;
  confirmedAt: string | null;
  sentAt: string | null;
  itemCount: number;
  items: Medicine[];
  allergy: string | null;
  doctorNote: string | null;
  verifiedAt: string | null;
  readyAt: string | null;
  calledAt: string | null;
  completedAt: string | null;
  returnedAt: string | null;
  returnReason: string | null;
  checks: string[];
  checklist: { key: string; label: string }[];
  counter: string;
  canAct: boolean;
}

// ── Shared pieces ─────────────────────────────────────────────────────────────

const STEPS = ['Kedatangan', 'Poli & dokter', 'Penunjang*', 'Farmasi', 'Selesai'];

/** "Tahapan pelayanan": five 220px tabs, the current one in mint. */
export function ServiceSteps({ current }: { current: number }) {
  return (
    <ol className="cl-steps" aria-label="Tahapan pelayanan">
      {STEPS.map((step, index) => (
        <li key={step} aria-current={index === current ? 'step' : undefined}>
          {index + 1}&nbsp;&nbsp;{step}
        </li>
      ))}
    </ol>
  );
}

function ClinicalTitle({
  title,
  subtitle,
  aside,
}: {
  title: string;
  subtitle: string;
  aside?: ReactNode;
}) {
  const { props } = usePage();
  return (
    <DashboardTitle
      className="cl-title"
      title={title}
      subtitle={subtitle}
      aside={
        <div className="cl-title-aside">
          {aside}
          <span className="cl-pill">Data contoh · {props.now}</span>
        </div>
      }
    />
  );
}

/** "Identitas dan status pasien" (88px card). */
function PatientCard({
  number,
  name,
  detail,
  status,
  tone = 'warm',
}: {
  number: string;
  name: string;
  detail: string;
  status: string;
  tone?: 'warm' | 'mint';
}) {
  return (
    <section className="cl-patient" aria-label="Pasien">
      <strong>{number}</strong>
      <div>
        <h2>{name}</h2>
        <p>{detail}</p>
      </div>
      <span className={`cl-status ${tone}`} role="status">
        {status}
      </span>
    </section>
  );
}

/** "Aksi halaman": footnote, secondary (mint) and primary buttons. */
/** Frames 31 and 33 use 188/366px buttons (`wide`); the others 230/324px. */
function ActionRow({
  note,
  wide,
  children,
}: {
  note: string;
  wide?: boolean;
  children: ReactNode;
}) {
  return (
    <div className={`cl-actions${wide ? ' wide' : ''}`}>
      <p>{note}</p>
      {children}
    </div>
  );
}

/** A modal on the native <dialog> element; Escape and the × close it. */
export function Dialog({
  title,
  eyebrow,
  onClose,
  children,
  className = '',
}: {
  title: string;
  eyebrow?: string;
  onClose: () => void;
  children: ReactNode;
  className?: string;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const dialog = ref.current;
    if (dialog && !dialog.open) dialog.showModal();
  }, []);
  return (
    <dialog ref={ref} className={`cl-dialog ${className}`} aria-label={title} onClose={onClose}>
      <span className="cl-dialog-accent" aria-hidden="true" />
      {eyebrow && <p className="cl-dialog-eyebrow">{eyebrow}</p>}
      <h2>{title}</h2>
      <button type="button" className="cl-dialog-close" aria-label="Tutup" onClick={onClose}>
        ×
      </button>
      {children}
    </dialog>
  );
}

function MedicineRows({ items, onEdit }: { items: Medicine[]; onEdit?: (index: number) => void }) {
  return (
    <>
      <div className="cl-med-head" aria-hidden="true">
        <span>Nama obat &amp; sediaan</span>
        <span>Jumlah</span>
        <span>Aturan pakai</span>
      </div>
      <ul className="cl-meds">
        {items.map((item, index) => (
          <li key={index}>
            <span>
              <strong>{item.name}</strong>
              <small>{item.form || '—'}</small>
            </span>
            <span className="cl-qty">{item.quantity}</span>
            <span>
              <b>{item.dosage}</b>
              <small>{item.timing || '—'}</small>
            </span>
            {onEdit && (
              <button
                type="button"
                className="cl-med-edit"
                aria-label={`Ubah ${item.name}`}
                onClick={() => onEdit(index)}
              >
                Ubah
              </button>
            )}
          </li>
        ))}
      </ul>
    </>
  );
}

const detailOf = (row: { record: string | null; clinic: string; room?: string | null }) =>
  [row.record ? `No. RM ${row.record}` : null, row.clinic, row.room].filter(Boolean).join(' · ');

// ── Pemeriksaan dokter (frames 31, 32) ────────────────────────────────────────

const EXAM_STATE: Record<ExamRow['state'], string> = {
  serving: 'Sedang diperiksa',
  review: 'Hasil penunjang · menunggu dokter',
  returned: 'Resep dikembalikan farmasi',
  support: 'Menunggu hasil penunjang',
  confirmed: 'Sudah diperiksa',
};

export function DoctorExam({
  me,
  rows,
  supportUnits,
  picked,
  onPick,
  busy,
  error,
  send,
  onSection,
  onOrder,
}: {
  me: Me;
  rows: ExamRow[];
  supportUnits: Record<string, string>;
  picked: number | null;
  onPick: (id: number) => void;
  busy: boolean;
  error: string;
  send: Send;
  onSection: (section: string) => void;
  onOrder: (id: number) => void;
}) {
  if (me.room !== 'doctor') return <ExamOverview rows={rows} />;
  const row = rows.find((item) => item.id === picked) ?? rows[0];
  const switcher =
    row && rows.length > 1 ? (
      <label className="cl-switch">
        <span className="sr-only">Pilih pasien</span>
        <select value={row.id} onChange={(e) => onPick(Number(e.target.value))}>
          {rows.map((item) => (
            <option key={item.id} value={item.id}>
              {item.number} · {item.name} · {EXAM_STATE[item.state]}
            </option>
          ))}
        </select>
      </label>
    ) : null;
  if (!row)
    return (
      <>
        <ClinicalTitle
          title="Pemeriksaan & resep"
          subtitle="Catat hasil pemeriksaan, tentukan tindak lanjut, dan tulis resep pasien."
        />
        <ServiceSteps current={1} />
        <section className="card empty">
          <h2>Belum ada pasien di ruang dokter</h2>
          <p className="muted">
            Panggil pasien berikutnya dari Antrean poli untuk memulai pemeriksaan.
          </p>
          <button className="button solid" onClick={() => onSection('Antrean poli')}>
            Buka antrean poli
          </button>
        </section>
      </>
    );
  return row.state === 'confirmed' ? (
    <ExamConfirmed row={row} switcher={switcher} onSection={onSection} onOrder={onOrder} />
  ) : (
    <ExamForm
      key={row.id}
      row={row}
      switcher={switcher}
      supportUnits={supportUnits}
      busy={busy}
      error={error}
      // Stay on this patient after saving: confirmed rows move down the list (frame 32).
      send={(...args) => {
        onPick(row.id);
        send(...args);
      }}
    />
  );
}

/** Other rooms see who is with a doctor, never the notes or prescription. */
function ExamOverview({ rows }: { rows: ExamRow[] }) {
  return (
    <>
      <ClinicalTitle
        title="Pemeriksaan dokter"
        subtitle="Pantau pasien yang sedang diperiksa dan menunggu konfirmasi dokter."
      />
      <ServiceSteps current={1} />
      <section className="card flat-card">
        <div className="table-header">
          <h3>Pasien di ruang dokter</h3>
          <span>{rows.length} pasien</span>
        </div>
        <div className="table-scroll">
          <table className="grid-table">
            <thead>
              <tr>
                <th>Nomor</th>
                <th>Pasien</th>
                <th>Dokter</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.id}>
                  <td>
                    <strong>{row.number}</strong>
                  </td>
                  <td>{row.name}</td>
                  <td>{row.doctor}</td>
                  <td>
                    {EXAM_STATE[row.state]}
                    {row.state === 'serving' && row.minutes !== null ? ` · ${row.minutes} mnt` : ''}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!rows.length && <p className="empty muted">Tidak ada pasien yang sedang diperiksa.</p>}
      </section>
      <Notice>
        Hanya dokter pemeriksa yang dapat membaca catatan, mengisi resep, dan mengonfirmasi
        pemeriksaan.
      </Notice>
    </>
  );
}

const EMPTY_MEDICINE: Medicine = { name: '', form: '', quantity: '', dosage: '', timing: '' };

function ExamForm({
  row,
  switcher,
  supportUnits,
  busy,
  error,
  send,
}: {
  row: ExamRow;
  switcher: ReactNode;
  supportUnits: Record<string, string>;
  busy: boolean;
  error: string;
  send: Send;
}) {
  const [notes, setNotes] = useState(row.notes ?? '');
  const [items, setItems] = useState<Medicine[]>(row.items);
  const [allergy, setAllergy] = useState(row.allergy ?? '');
  const [pharmacyNote, setPharmacyNote] = useState(row.pharmacyNote ?? '');
  const [examined, setExamined] = useState(row.state !== 'serving');
  const [withMedicine, setWithMedicine] = useState(true);
  const [editing, setEditing] = useState<{ index: number; item: Medicine } | null>(null);
  const [dialog, setDialog] = useState<'refer' | 'confirm' | null>(null);
  const [unit, setUnit] = useState(Object.keys(supportUnits)[0] ?? '');
  const [test, setTest] = useState('');
  const sending = withMedicine ? items : [];
  const locked = !row.open;
  const exam = (extra: Payload = {}): Payload => ({
    notes,
    allergy,
    pharmacy_note: pharmacyNote,
    items: sending.map((item) => ({ ...item })),
    ...extra,
  });
  // A second referral is possible once the first result is back.
  const canRefer = row.state === 'serving' || row.state === 'review';
  const status =
    row.state === 'serving'
      ? `Sedang diperiksa${row.minutes !== null ? ` · ${row.minutes} mnt` : ''}`
      : row.state === 'support'
        ? `${row.support?.unitLabel} · ${row.support?.statusLabel.toLowerCase()}`
        : EXAM_STATE[row.state];
  function saveMedicine() {
    if (!editing) return;
    const next = [...items];
    if (editing.index < 0) next.push(editing.item);
    else next[editing.index] = editing.item;
    setItems(next);
    setEditing(null);
  }
  return (
    <>
      <ClinicalTitle
        title="Pemeriksaan & resep"
        subtitle="Catat hasil pemeriksaan, tentukan tindak lanjut, dan tulis resep pasien."
        aside={switcher}
      />
      {/* Frame 31 has no steps bar; the card keeps its place below the atmosphere. */}
      <PatientCard number={row.number} name={row.name} detail={detailOf(row)} status={status} />
      {error && (
        <Notice tone="warning" role="alert">
          {error}
        </Notice>
      )}
      {row.state === 'review' && row.support && (
        <Notice role="status">
          Hasil {row.support.number} · {row.support.test} dari {row.support.unitLabel} sudah dikirim{' '}
          {row.support.completedAt}.{' '}
          {row.support.file && (
            <a href={row.support.file.url} className="cl-link">
              Unduh {row.support.file.name}
            </a>
          )}
          {row.support.note ? ` Catatan petugas: ${row.support.note}` : ''}
        </Notice>
      )}
      {row.state === 'returned' && row.order && (
        <Notice tone="warning" role="status">
          Resep {row.order.pickup} dikembalikan farmasi: {row.order.returnReason}. Perbaiki resep
          lalu konfirmasi ulang.
        </Notice>
      )}
      {row.state === 'support' && row.support && (
        <Notice role="status">
          Pasien sedang di {row.support.unitLabel} ({row.support.number} · {row.support.test}).
          Konfirmasi dapat dilakukan setelah hasil dikirim kembali.
        </Notice>
      )}
      <div className="cl-columns">
        <div className="cl-stack">
          <section className="cl-card cl-notes">
            <h3>Catatan pemeriksaan</h3>
            <label htmlFor="exam-notes">Keluhan dan hasil pemeriksaan · data contoh</label>
            <textarea
              id="exam-notes"
              value={notes}
              maxLength={2000}
              disabled={locked}
              placeholder="Tuliskan keluhan dan hasil pemeriksaan pasien."
              onChange={(e) => setNotes(e.target.value)}
            />
          </section>
          <section className="cl-card cl-recipe">
            <div className="cl-card-head">
              <h3>Resep obat</h3>
              <button
                type="button"
                className="cl-button mint"
                disabled={locked || items.length >= 20}
                onClick={() => setEditing({ index: -1, item: { ...EMPTY_MEDICINE } })}
              >
                + Tambah obat
              </button>
            </div>
            {items.length ? (
              <MedicineRows
                items={items}
                onEdit={
                  locked ? undefined : (index) => setEditing({ index, item: { ...items[index] } })
                }
              />
            ) : (
              <p className="cl-empty">Belum ada obat. Tambahkan obat bila pasien perlu resep.</p>
            )}
            <p className="cl-foot">
              Alergi: {allergy || 'tidak dilaporkan'} · konfirmasi ulang sebelum resep dikirim.
            </p>
          </section>
        </div>
        <aside className="cl-card cl-panel">
          <h3>Konfirmasi dokter</h3>
          <fieldset className="cl-radios" disabled={locked}>
            <legend>Pemeriksaan pasien sudah selesai?</legend>
            {[
              [true, 'Sudah diperiksa'],
              [false, 'Belum diperiksa'],
            ].map(([value, label]) => (
              <label key={String(value)}>
                <input
                  type="radio"
                  name="examined"
                  checked={examined === value}
                  onChange={() => setExamined(value as boolean)}
                />
                <span aria-hidden="true">{examined === value ? '●' : '○'}</span>
                {label}
              </label>
            ))}
          </fieldset>
          <label className="cl-label" htmlFor="exam-next">
            Tindak lanjut
          </label>
          <select
            id="exam-next"
            className="cl-select"
            value={withMedicine && items.length ? 'pharmacy' : 'home'}
            disabled={locked || !items.length}
            onChange={(e) => setWithMedicine(e.target.value === 'pharmacy')}
          >
            {items.length > 0 && (
              <option value="pharmacy">Farmasi · kirim {items.length} item obat</option>
            )}
            <option value="home">Selesai · tanpa resep</option>
          </select>
          <p className="cl-question">Perlu pemeriksaan tambahan?</p>
          <button
            type="button"
            className="cl-button mint wide"
            disabled={locked || !canRefer || busy}
            onClick={() => setDialog('refer')}
          >
            Buat permintaan penunjang
          </button>
          <p className="cl-muted">
            Resep dikirim setelah dokter mengonfirmasi.
            <br />
            Petugas poli tidak dapat mengesahkan resep.
          </p>
          <p className="cl-signature">Dokter: {row.doctor}</p>
        </aside>
      </div>
      <ActionRow wide note="*Penunjang bila dirujuk · resep contoh, bukan instruksi klinis.">
        <button
          type="button"
          className="cl-button mint"
          disabled={locked || busy}
          onClick={() => send('post', `/petugas/pemeriksaan/${row.id}`, exam())}
        >
          Simpan draf
        </button>
        <button
          type="button"
          className="cl-button primary"
          disabled={locked || busy || !examined || !notes.trim()}
          title={!notes.trim() ? 'Tuliskan catatan pemeriksaan terlebih dahulu.' : undefined}
          onClick={() => setDialog('confirm')}
        >
          {sending.length ? 'Konfirmasi & kirim resep' : 'Konfirmasi pemeriksaan'}
        </button>
      </ActionRow>

      {editing && (
        <Dialog
          title={editing.index < 0 ? 'Tambah obat' : 'Ubah obat'}
          eyebrow="RESEP OBAT"
          onClose={() => setEditing(null)}
        >
          <form
            className="cl-form"
            onSubmit={(e) => {
              e.preventDefault();
              saveMedicine();
            }}
          >
            {(
              [
                ['name', 'Nama obat', 'Contoh: Paracetamol 500 mg', 100, true],
                ['form', 'Sediaan', 'Contoh: Tablet · oral', 60, false],
                ['quantity', 'Jumlah', 'Contoh: 10 tablet', 40, true],
                ['dosage', 'Aturan pakai', 'Contoh: 3 × 1 tablet', 100, true],
                ['timing', 'Waktu minum', 'Contoh: Sesudah makan', 60, false],
              ] as const
            ).map(([key, label, placeholder, max, required]) => (
              <label key={key}>
                {label}
                <input
                  value={editing.item[key] ?? ''}
                  placeholder={placeholder}
                  maxLength={max}
                  required={required}
                  onChange={(e) =>
                    setEditing({ ...editing, item: { ...editing.item, [key]: e.target.value } })
                  }
                />
              </label>
            ))}
            <div className="cl-dialog-actions">
              {editing.index >= 0 ? (
                <button
                  type="button"
                  className="cl-button mint"
                  onClick={() => {
                    setItems(items.filter((_, index) => index !== editing.index));
                    setEditing(null);
                  }}
                >
                  Hapus obat
                </button>
              ) : (
                <button type="button" className="cl-button mint" onClick={() => setEditing(null)}>
                  Batal
                </button>
              )}
              <button className="cl-button primary">Simpan obat</button>
            </div>
          </form>
        </Dialog>
      )}

      {dialog === 'refer' && (
        <Dialog
          title="Permintaan penunjang"
          eyebrow="PEMERIKSAAN TAMBAHAN"
          onClose={() => setDialog(null)}
        >
          <form
            className="cl-form"
            onSubmit={(e) => {
              e.preventDefault();
              send('post', `/petugas/pemeriksaan/${row.id}/penunjang`, exam({ unit, test }), () =>
                setDialog(null),
              );
            }}
          >
            <label>
              Unit penunjang
              <select value={unit} onChange={(e) => setUnit(e.target.value)}>
                {Object.entries(supportUnits).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Jenis pemeriksaan
              <input
                value={test}
                required
                minLength={3}
                maxLength={100}
                placeholder="Contoh: Darah lengkap"
                onChange={(e) => setTest(e.target.value)}
              />
            </label>
            <p className="cl-muted">
              Catatan dan resep tersimpan sebagai draf. Hasil penunjang kembali kepada Anda untuk
              ditinjau sebelum pemeriksaan dikonfirmasi.
            </p>
            {error && (
              <p className="field-error" role="alert">
                {error}
              </p>
            )}
            <div className="cl-dialog-actions">
              <button type="button" className="cl-button mint" onClick={() => setDialog(null)}>
                Batal
              </button>
              <button className="cl-button primary" disabled={busy}>
                Kirim permintaan
              </button>
            </div>
          </form>
        </Dialog>
      )}

      {dialog === 'confirm' && (
        <Dialog
          title="Konfirmasi pemeriksaan"
          eyebrow={`${row.number} · ${row.name}`}
          onClose={() => setDialog(null)}
        >
          <form
            className="cl-form"
            onSubmit={(e) => {
              e.preventDefault();
              send(
                'post',
                `/petugas/pemeriksaan/${row.id}/konfirmasi`,
                exam({ examined: '1' }),
                () => setDialog(null),
              );
            }}
          >
            <p className="cl-muted">
              {sending.length
                ? `${sending.length} item obat dikirim ke farmasi setelah konfirmasi.`
                : 'Tanpa resep: kunjungan selesai setelah konfirmasi.'}
            </p>
            <label>
              Alergi obat
              <input
                value={allergy}
                maxLength={120}
                placeholder="Kosongkan bila tidak dilaporkan"
                onChange={(e) => setAllergy(e.target.value)}
              />
            </label>
            {sending.length > 0 && (
              <label>
                Catatan untuk farmasi
                <textarea
                  value={pharmacyNote}
                  maxLength={255}
                  placeholder="Contoh: berikan edukasi penggunaan obat sesuai resep."
                  onChange={(e) => setPharmacyNote(e.target.value)}
                />
              </label>
            )}
            {error && (
              <p className="field-error" role="alert">
                {error}
              </p>
            )}
            <div className="cl-dialog-actions">
              <button type="button" className="cl-button mint" onClick={() => setDialog(null)}>
                Periksa lagi
              </button>
              <button className="cl-button primary" disabled={busy}>
                {sending.length ? 'Konfirmasi & kirim resep' : 'Konfirmasi'}
              </button>
            </div>
          </form>
        </Dialog>
      )}
    </>
  );
}

function ExamConfirmed({
  row,
  switcher,
  onSection,
  onOrder,
}: {
  row: ExamRow;
  switcher: ReactNode;
  onSection: (section: string) => void;
  onOrder: (id: number) => void;
}) {
  const order = row.order;
  return (
    <>
      <ClinicalTitle
        title="Pemeriksaan selesai"
        subtitle={
          order
            ? 'Konfirmasi dokter tercatat dan resep diteruskan ke petugas farmasi.'
            : 'Konfirmasi dokter tercatat. Kunjungan selesai tanpa resep.'
        }
        aside={switcher}
      />
      <ServiceSteps current={1} />
      <PatientCard
        number={row.number}
        name={row.name}
        detail={detailOf(row)}
        status={`Sudah diperiksa · ${row.confirmedAt}`}
        tone="mint"
      />
      <div className="cl-columns">
        <div className="cl-stack">
          <section className="cl-card cl-confirmed">
            <h3>Pemeriksaan berhasil dikonfirmasi</h3>
            <p className="cl-strong">
              {row.doctor} · {row.confirmedStamp}
            </p>
            <p>
              {row.name} {order ? 'diarahkan ke farmasi' : 'menyelesaikan kunjungan'}.{' '}
              {row.support
                ? `Hasil ${row.support.unitLabel.toLowerCase()} (${row.support.number}) sudah ditinjau.`
                : 'Tidak ada rujukan penunjang.'}
            </p>
          </section>
          <section className="cl-card cl-sent">
            <h3>{order ? `Resep ${order.pickup} dikirim ke farmasi` : 'Tanpa resep'}</h3>
            <p className="cl-muted">
              {row.itemCount} item obat · resep final dari {row.doctor}
            </p>
            <ul className="cl-sent-list">
              {row.items.map((item, index) => (
                <li key={index}>
                  <strong>{item.name}</strong>
                  <small>
                    {[item.quantity, item.dosage, item.timing?.toLowerCase()]
                      .filter(Boolean)
                      .join(' · ')}
                  </small>
                </li>
              ))}
            </ul>
            {order && (
              <p className="cl-banner">
                {order.status === 'received'
                  ? 'Resep diterima · obat belum diproses oleh farmasi'
                  : `Farmasi: ${order.statusLabel.toLowerCase()}`}
              </p>
            )}
          </section>
        </div>
        <aside className="cl-card cl-panel">
          <h3>Jejak pelayanan pasien</h3>
          <Trail rows={row.trail} />
          {order && (
            <div className="cl-next">
              <strong>
                {order.status === 'received'
                  ? 'Berikutnya: verifikasi resep'
                  : `Farmasi: ${order.statusLabel}`}
              </strong>
              <small>Petugas farmasi memulai proses obat.</small>
            </div>
          )}
        </aside>
      </div>
      <ActionRow note="*Penunjang hanya jika dirujuk dokter · data contoh.">
        <button type="button" className="cl-button mint" onClick={() => onSection('Antrean poli')}>
          Lihat antrean poli
        </button>
        {order && (
          <button type="button" className="cl-button primary" onClick={() => onOrder(order.id)}>
            Lihat resep {order.pickup}
          </button>
        )}
      </ActionRow>
    </>
  );
}

function Trail({ rows }: { rows: ExamRow['trail'] }) {
  return (
    <ol className="cl-trail">
      {rows.slice(-4).map((row, index) => (
        <li key={`${row.time}-${row.title}`}>
          <b>{String(index + 1).padStart(2, '0')}</b>
          <span>
            <strong>
              {row.time} · {row.title}
            </strong>
            <small>{row.detail}</small>
          </span>
        </li>
      ))}
    </ol>
  );
}

// ── Pelayanan penunjang (frame 33) ────────────────────────────────────────────

const SUPPORT_STATUS = [
  ['waiting', 'Menunggu'],
  ['processing', 'Diproses'],
  ['done', 'Selesai'],
] as const;

export function SupportDesk({
  me,
  rows,
  units,
  busy,
  error,
  send,
}: {
  me: Me;
  rows: SupportRow[];
  units: Record<string, string>;
  busy: boolean;
  error: string;
  send: Send;
}) {
  const [unit, setUnit] = useState('');
  const [status, setStatus] = useState('');
  const [query, setQuery] = useState('');
  const [picked, setPicked] = useState<number | null>(null);
  const shown = rows.filter(
    (row) =>
      (!unit || row.unit === unit) &&
      (!status || row.status === status) &&
      `${row.ticket} ${row.name} ${row.number}`.toLowerCase().includes(query.toLowerCase()),
  );
  const active = rows.filter((row) => row.status !== 'done').length;
  const row = shown.find((item) => item.id === picked) ?? shown[0];
  return (
    <>
      <ClinicalTitle
        title="Pelayanan penunjang"
        subtitle="Kelola permintaan pemeriksaan dan kirim hasil kembali kepada dokter."
      />
      <ServiceSteps current={2} />
      {row ? (
        <PatientCard
          number={row.ticket}
          name={row.name}
          detail={`${row.unitLabel} · permintaan dari dokter · diterima ${row.requestedAt}`}
          status={
            row.status === 'done'
              ? `Selesai · hasil terkirim ${row.completedAt}`
              : row.status === 'processing'
                ? 'Diproses · menunggu hasil'
                : 'Menunggu pemeriksaan'
          }
          tone={row.status === 'done' ? 'mint' : 'warm'}
        />
      ) : (
        <section className="cl-patient empty-patient">
          <p>Belum ada permintaan penunjang hari ini.</p>
        </section>
      )}
      {error && (
        <Notice tone="warning" role="alert">
          {error}
        </Notice>
      )}
      <div className="cl-columns">
        <div className="cl-stack">
          <section className="cl-card cl-support-list">
            <div className="cl-card-head">
              <h3>Antrean penunjang</h3>
              <span className="cl-muted">{active} pasien aktif</span>
            </div>
            <div className="cl-filters">
              <label>
                <span className="sr-only">Filter unit</span>
                <select value={unit} onChange={(e) => setUnit(e.target.value)}>
                  <option value="">Semua unit</option>
                  {Object.entries(units).map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                <span className="sr-only">Filter status</span>
                <select value={status} onChange={(e) => setStatus(e.target.value)}>
                  <option value="">Semua status</option>
                  {SUPPORT_STATUS.map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                <span className="sr-only">Cari pasien atau nomor</span>
                <input
                  placeholder="Cari pasien atau nomor"
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                />
              </label>
            </div>
            <div className="cl-support-head" aria-hidden="true">
              <span>Pasien</span>
              <span>Unit / pemeriksaan</span>
              <span>Status</span>
            </div>
            <ul className="cl-support-rows">
              {shown.map((item) => (
                <li key={item.id}>
                  <button
                    type="button"
                    aria-pressed={item.id === row?.id}
                    onClick={() => setPicked(item.id)}
                  >
                    <span>
                      {item.ticket} · {item.name}
                    </span>
                    <span>
                      {item.unitLabel} · {item.test.toLowerCase()}
                    </span>
                    <span>{item.listLabel}</span>
                  </button>
                </li>
              ))}
            </ul>
            {!shown.length && (
              <p className="cl-empty" role="status">
                {rows.length
                  ? 'Tidak ada permintaan yang cocok.'
                  : 'Belum ada permintaan penunjang.'}
              </p>
            )}
          </section>
          {row && (
            <section className="cl-card cl-request">
              <h3>Permintaan pemeriksaan</h3>
              <p className="cl-strong">
                {row.test} · {row.unitLabel}
              </p>
              <p className="cl-muted">
                Nomor permintaan {row.number} · {row.name} · {row.ticket} · {row.doctor}
              </p>
              <p className="cl-banner">
                Penunjang selesai → dokter meninjau hasil → tindak lanjut pasien
              </p>
            </section>
          )}
        </div>
        {row ? (
          <SupportPanel key={row.id} row={row} me={me} busy={busy} send={send} />
        ) : (
          <aside className="cl-card cl-panel">
            <h3>Konfirmasi petugas</h3>
            <p className="cl-muted">Pilih permintaan untuk memperbarui statusnya.</p>
          </aside>
        )}
      </div>
    </>
  );
}

function SupportPanel({
  row,
  me,
  busy,
  send,
}: {
  row: SupportRow;
  me: Me;
  busy: boolean;
  send: Send;
}) {
  const [status, setStatus] = useState<SupportRow['status']>(row.status);
  const [note, setNote] = useState(row.note ?? '');
  const [file, setFile] = useState<File | null>(null);
  const input = useRef<HTMLInputElement>(null);
  const locked = !row.canEdit;
  const payload = (): Payload => ({ note, ...(file ? { result: file } : {}) });
  const clear = () => {
    setFile(null);
    if (input.current) input.current.value = '';
  };
  const complete = () => send('post', `/petugas/penunjang/${row.id}/selesai`, payload(), clear);
  return (
    <>
      <aside className="cl-card cl-panel">
        <h3>Konfirmasi petugas</h3>
        <fieldset className="cl-segments" disabled={locked}>
          <legend>Status pemeriksaan penunjang</legend>
          {SUPPORT_STATUS.map(([value, label]) => (
            <label key={value}>
              <input
                type="radio"
                name="support-status"
                className="sr-only"
                checked={status === value}
                onChange={() => setStatus(value)}
              />
              {label}
            </label>
          ))}
        </fieldset>
        <p className="cl-label">Hasil pemeriksaan</p>
        <label className={`cl-file ${locked ? 'locked' : ''}`}>
          <input
            ref={input}
            type="file"
            className="sr-only"
            accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
            disabled={locked}
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          />
          <strong>{file?.name ?? row.file?.name ?? 'Lampirkan hasil pemeriksaan'}</strong>
          <small>
            {file
              ? 'Siap diunggah · simpan untuk melampirkan'
              : row.file
                ? row.status === 'done'
                  ? 'Terkirim ke dokter pengirim'
                  : 'Terlampir · menunggu konfirmasi petugas'
                : 'PDF, JPG, atau PNG · maksimal 5 MB'}
          </small>
        </label>
        {row.file && !file && (
          <a className="cl-link small" href={row.file.url}>
            Unduh hasil terlampir
          </a>
        )}
        <label className="cl-label" htmlFor="support-note">
          Catatan petugas
        </label>
        <textarea
          id="support-note"
          className="cl-note"
          value={note}
          maxLength={1000}
          disabled={locked}
          placeholder="Contoh: sampel diterima, hasil siap ditinjau dokter."
          onChange={(e) => setNote(e.target.value)}
        />
        <p className="cl-label">Tujuan berikutnya</p>
        <p className="cl-destination">Kembali ke dokter pengirim</p>
        <p className="cl-signature">
          {[
            row.by ?? me.name,
            row.byUnit ? `Petugas ${row.byUnit.toLowerCase()}` : null,
            row.updatedAt ? `${row.updatedAt} WIB` : null,
          ]
            .filter(Boolean)
            .join(' · ')}
        </p>
      </aside>
      <ActionRow wide note="*Penunjang bila dirujuk · hasil tidak langsung menjadi resep.">
        <button
          type="button"
          className="cl-button mint"
          disabled={locked || busy}
          onClick={() =>
            status === 'done'
              ? complete()
              : send('post', `/petugas/penunjang/${row.id}`, { status, ...payload() }, clear)
          }
        >
          Simpan status
        </button>
        <button
          type="button"
          className="cl-button primary"
          disabled={locked || busy}
          onClick={complete}
        >
          Konfirmasi selesai &amp; kirim hasil
        </button>
      </ActionRow>
      {locked && me.room !== 'support' && (
        <p className="cl-muted cl-right">
          Hanya petugas laboratorium atau radiologi yang dapat mengubah status.
        </p>
      )}
    </>
  );
}

// ── Detail resep farmasi (frames 34–37) ───────────────────────────────────────

const ORDER_SCREEN: Record<
  OrderRow['status'],
  { title: string; subtitle: string; step: number; panel: string; pill: string }
> = {
  received: {
    title: 'Detail resep',
    subtitle: 'Verifikasi resep dokter sebelum memulai penyiapan obat.',
    step: 3,
    panel: 'Konfirmasi farmasi',
    pill: 'Resep diterima',
  },
  preparing: {
    title: 'Penyiapan obat',
    subtitle: 'Konfirmasi obat sedang diproses dan periksa kelengkapannya.',
    step: 3,
    panel: 'Konfirmasi farmasi',
    pill: 'Sedang diproses',
  },
  ready: {
    title: 'Penyerahan obat',
    subtitle: 'Panggil pasien dan konfirmasi penyerahan setelah identitas sesuai.',
    step: 3,
    panel: 'Konfirmasi penyerahan',
    pill: 'Siap diambil',
  },
  completed: {
    title: 'Pelayanan selesai',
    subtitle: 'Obat sudah diserahkan dan seluruh tahapan kunjungan tercatat.',
    step: 4,
    panel: 'Pelayanan telah selesai',
    pill: 'Sudah diserahkan',
  },
  returned: {
    title: 'Detail resep',
    subtitle: 'Resep dikembalikan dan menunggu peninjauan dokter.',
    step: 3,
    panel: 'Resep dikembalikan',
    pill: 'Dikembalikan ke dokter',
  },
};

export function PharmacyDetail({
  order,
  me,
  busy,
  error,
  send,
  onBack,
  onHistory,
}: {
  order: OrderRow;
  me: Me;
  busy: boolean;
  error: string;
  send: Send;
  onBack: () => void;
  onHistory: () => void;
}) {
  const [checks, setChecks] = useState<string[]>(order.checks);
  const [returning, setReturning] = useState(false);
  const [reason, setReason] = useState('');
  // A new step brings a new checklist; keep it in step with the server.
  const step = order.status;
  const [seen, setSeen] = useState(step);
  if (seen !== step) {
    setSeen(step);
    setChecks(order.checks);
  }
  const screen = ORDER_SCREEN[order.status];
  const time = {
    received: order.receivedAt,
    preparing: order.verifiedAt,
    ready: order.readyAt,
    completed: order.completedAt,
    returned: order.returnedAt,
  }[order.status];
  const ticked = order.checklist.every((item) => checks.includes(item.key));
  const advance = () => send('post', `/petugas/farmasi/${order.id}/lanjut`, { checks });
  const act = order.canAct;
  return (
    <>
      <ClinicalTitle title={screen.title} subtitle={screen.subtitle} />
      <ServiceSteps current={screen.step} />
      <PatientCard
        number={order.pickup}
        name={order.name}
        detail={[
          order.record ? `No. RM ${order.record}` : null,
          `Antrean poli ${order.number}`,
          order.clinic,
        ]
          .filter(Boolean)
          .join(' · ')}
        status={`${screen.pill}${time ? ` · ${time}` : ''}`}
        tone={order.status === 'completed' || order.status === 'ready' ? 'mint' : 'warm'}
      />
      {error && (
        <Notice tone="warning" role="alert">
          {error}
        </Notice>
      )}
      <div className="cl-columns">
        <section className="cl-card cl-order">
          <h3>Resep dokter · {order.pickup}</h3>
          <p className="cl-sub">
            {order.doctor} · dikirim {order.sentAt ?? '—'}
          </p>
          <p className="cl-banner">
            Sudah diperiksa · konfirmasi dokter {order.confirmedAt ?? '—'} WIB · {order.itemCount}{' '}
            item obat
          </p>
          {act ? (
            <>
              <MedicineRows items={order.items} />
              <p className="cl-foot strong">
                Alergi: {order.allergy} · perlu dikonfirmasi ulang saat penyerahan.
              </p>
              {order.doctorNote && (
                <p className="cl-banner note">Catatan dokter: {order.doctorNote}</p>
              )}
            </>
          ) : (
            <p className="cl-empty">
              Rincian obat hanya dapat dilihat petugas farmasi dan dokter pemeriksa.
            </p>
          )}
        </section>
        <aside className="cl-card cl-panel">
          <h3>{screen.panel}</h3>
          <p
            className={`cl-state ${order.status === 'completed' || order.status === 'ready' ? 'mint' : 'warm'}`}
          >
            {order.status === 'received' ? order.statusLabel : screen.pill}
          </p>
          <p className="cl-desc">
            {order.status === 'received' &&
              `Resep diterima ${order.receivedAt} WIB. Penyiapan obat belum dimulai oleh petugas farmasi.`}
            {order.status === 'preparing' &&
              `Diverifikasi dan mulai disiapkan ${order.verifiedAt ?? '—'} WIB${order.handledBy ? ` oleh ${order.handledBy}` : ''}.`}
            {order.status === 'ready' &&
              `${order.itemCount} item obat selesai disiapkan ${order.readyAt} WIB. Obat belum diserahkan.${order.calledAt ? ` Pasien dipanggil ${order.calledAt}.` : ''}`}
            {order.status === 'completed' && (
              <>
                {order.name} · {order.itemCount} item obat
                <br />
                Diterima {order.completedAt} WIB di {order.counter}
              </>
            )}
            {order.status === 'returned' &&
              `Dikembalikan ${order.returnedAt} WIB. Alasan: ${order.returnReason}`}
          </p>
          {order.status === 'completed' ? (
            <ol className="cl-timeline">
              {(
                [
                  [order.receivedAt, 'Resep dokter diterima'],
                  [order.verifiedAt, 'Obat mulai diproses'],
                  [order.readyAt, 'Obat siap diambil'],
                  [order.completedAt, 'Obat diserahkan ke pasien'],
                ] as const
              )
                .filter(([at]) => at)
                .map(([at, label]) => (
                  <li key={label}>
                    <b>{at}</b>
                    {label}
                  </li>
                ))}
            </ol>
          ) : order.checklist.length ? (
            <fieldset className="cl-checks" disabled={!act}>
              <legend>
                {order.status === 'received'
                  ? 'Verifikasi sebelum memproses'
                  : order.status === 'preparing'
                    ? 'Pemeriksaan penyiapan obat'
                    : 'Periksa saat pasien datang'}
              </legend>
              {order.checklist.map((item) => (
                <label key={item.key}>
                  <input
                    type="checkbox"
                    checked={checks.includes(item.key)}
                    onChange={(e) =>
                      setChecks(
                        e.target.checked
                          ? [...checks, item.key]
                          : checks.filter((key) => key !== item.key),
                      )
                    }
                  />
                  <span aria-hidden="true">{checks.includes(item.key) ? '✓' : '○'}</span>
                  {item.label}
                </label>
              ))}
            </fieldset>
          ) : null}
          {/* Frame 36 warns in amber: hand over only once the patient is at the counter. */}
          <div className={`cl-next${order.status === 'ready' ? ' warm' : ''}`}>
            <strong>
              {
                {
                  received: 'Mulai proses setelah verifikasi',
                  preparing: 'Pastikan semua obat sudah siap',
                  ready: 'Konfirmasi setelah obat diterima',
                  completed: `Dikonfirmasi oleh ${order.handledBy ?? '—'}`,
                  returned: 'Menunggu dokter meninjau resep',
                }[order.status]
              }
            </strong>
            <small>
              {
                {
                  received: 'Status berikutnya: Sedang diproses',
                  preparing: 'Status berikutnya: Siap diambil',
                  ready: 'Jangan selesaikan saat pasien belum datang.',
                  completed: 'Tercatat di riwayat aktivitas pelayanan.',
                  returned: 'Resep kembali ke farmasi setelah dokter mengonfirmasi ulang.',
                }[order.status]
              }
            </small>
          </div>
          {order.status !== 'completed' && (
            <p className="cl-signature">
              Petugas: {order.handledBy ?? me.name}
              {order.status === 'ready' ? ` · ${order.counter}` : ''}
            </p>
          )}
        </aside>
      </div>
      <ActionRow note="*Penunjang bila dirujuk · resep contoh, bukan instruksi klinis.">
        {order.status === 'received' && (
          <>
            <button
              type="button"
              className="cl-button mint"
              disabled={!act || busy}
              onClick={() => setReturning(true)}
            >
              Kembalikan ke dokter
            </button>
            <button
              type="button"
              className="cl-button primary"
              disabled={!act || busy || !ticked}
              onClick={advance}
            >
              Verifikasi &amp; mulai proses
            </button>
          </>
        )}
        {order.status === 'preparing' && (
          <>
            <button
              type="button"
              className="cl-button mint"
              disabled={!act || busy}
              onClick={() => send('post', `/petugas/farmasi/${order.id}/cek`, { checks })}
            >
              Simpan proses
            </button>
            <button
              type="button"
              className="cl-button primary"
              disabled={!act || busy || !ticked}
              onClick={advance}
            >
              Konfirmasi obat siap diambil
            </button>
          </>
        )}
        {order.status === 'ready' && (
          <>
            <button
              type="button"
              className="cl-button mint"
              disabled={!act || busy}
              onClick={() => send('post', `/petugas/farmasi/${order.id}/panggil`, {})}
            >
              {order.calledAt ? 'Panggil ulang' : 'Panggil pasien'} {order.pickup}
            </button>
            <button
              type="button"
              className="cl-button primary"
              disabled={!act || busy || !ticked}
              onClick={advance}
            >
              Konfirmasi obat diserahkan
            </button>
          </>
        )}
        {(order.status === 'completed' || order.status === 'returned') && (
          <>
            <button type="button" className="cl-button mint" onClick={onHistory}>
              Lihat riwayat aktivitas
            </button>
            <button type="button" className="cl-button primary" onClick={onBack}>
              Kembali ke antrean farmasi
            </button>
          </>
        )}
      </ActionRow>
      {returning && (
        <Dialog
          title="Kembalikan ke dokter"
          eyebrow={`RESEP ${order.pickup}`}
          onClose={() => setReturning(false)}
        >
          <form
            className="cl-form"
            onSubmit={(e) => {
              e.preventDefault();
              send('post', `/petugas/farmasi/${order.id}/kembalikan`, { reason }, () =>
                setReturning(false),
              );
            }}
          >
            <label>
              Alasan
              <textarea
                value={reason}
                required
                minLength={3}
                maxLength={255}
                placeholder="Contoh: dosis perlu dikonfirmasi ulang."
                onChange={(e) => setReason(e.target.value)}
              />
            </label>
            <p className="cl-muted">Dokter pemeriksa meninjau resep lalu mengirimkannya kembali.</p>
            {error && (
              <p className="field-error" role="alert">
                {error}
              </p>
            )}
            <div className="cl-dialog-actions">
              <button type="button" className="cl-button mint" onClick={() => setReturning(false)}>
                Batal
              </button>
              <button className="cl-button primary" disabled={busy}>
                Kembalikan resep
              </button>
            </div>
          </form>
        </Dialog>
      )}
    </>
  );
}
