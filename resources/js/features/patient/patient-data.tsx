import { Link, router, useForm } from '@inertiajs/react';
import {
  cloneElement,
  useEffect,
  useRef,
  useState,
  type FormEvent,
  type InputHTMLAttributes,
  type ReactElement,
  type ReactNode,
} from 'react';
import { Shell } from '@/components/shell';

export interface PatientRecord {
  id: number;
  self: boolean;
  name: string;
  recordNumber: string | null;
  birthDate: string | null;
  birthLabel: string | null;
  age: number | null;
  gender: 'female' | 'male' | null;
  phone: string | null;
  address: string | null;
  city: string | null;
  postalCode: string | null;
}
const genders = { female: 'Perempuan', male: 'Laki-laki' };

/** Data pasien (Figma 27:61): the account's own record, then the family it manages. */
export function PatientData({ patients }: { patients: PatientRecord[] }) {
  const [removing, setRemoving] = useState<PatientRecord | null>(null);
  return (
    <Shell
      title="Data pasien"
      subtitle="PROFIL DAN DATA PASIEN"
      back="/akun"
      className="inter-shell data-shell"
      action={
        <Link className="button" href="/data-pasien/tambah">
          Tambah pasien
        </Link>
      }
    >
      <section className="lead">
        <h2>
          Data tepat, berobat
          <br />
          lebih mudah.
        </h2>
        <p>Kelola identitas dan kontak pasien untuk kebutuhan kunjungan.</p>
      </section>
      {patients.map((patient) => (
        <article className="patient-card" key={patient.id}>
          <header>
            <p className="eyebrow">{patient.self ? 'DIRI SENDIRI' : 'PASIEN YANG DIDAMPINGI'}</p>
            <h3>{patient.name}</h3>
            {patient.recordNumber && <p className="record">No. RM {patient.recordNumber}</p>}
          </header>
          <hr />
          <dl>
            <Detail label="Tanggal lahir · umur">
              {patient.birthLabel && `${patient.birthLabel} · ${patient.age} tahun`}
            </Detail>
            <Detail label="Jenis kelamin">{patient.gender && genders[patient.gender]}</Detail>
            <Detail label="Nomor ponsel">{patient.phone}</Detail>
            <Detail label="Alamat domisili">
              {patient.address && (
                <>
                  {patient.address}
                  <br />
                  {[patient.city, patient.postalCode].filter(Boolean).join(' · ')}
                </>
              )}
            </Detail>
          </dl>
          <Link className="button outline" href={`/data-pasien/${patient.id}/ubah`}>
            Ubah data pasien
          </Link>
          {/* The account's own record is the account itself; only family records can go. */}
          {!patient.self && (
            <button
              type="button"
              className="text-button danger"
              onClick={() => setRemoving(patient)}
            >
              Hapus data pasien
            </button>
          )}
        </article>
      ))}
      {removing && <DeleteDialog patient={removing} onClose={() => setRemoving(null)} />}
      <section className="tip-card">
        <strong>Mengurus kunjungan keluarga?</strong>
        <p>
          Tambahkan data pasien yang Anda dampingi. Pastikan pasien menyetujui penggunaan datanya.
        </p>
      </section>
    </Shell>
  );
}
/** Konfirmasi hapus (Figma 31:273): a modal dialog, so focus stays inside and Esc cancels. */
function DeleteDialog({ patient, onClose }: { patient: PatientRecord; onClose: () => void }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  useEffect(() => {
    dialog.current?.showModal();
  }, []);
  function remove() {
    router.delete(`/data-pasien/${patient.id}`, {
      preserveScroll: true,
      onStart: () => {
        setBusy(true);
        setError('');
      },
      onFinish: () => setBusy(false),
      onSuccess: onClose,
      onError: (errors) => setError(Object.values(errors)[0] ?? 'Data pasien gagal dihapus.'),
      onNetworkError: () => {
        setError('Koneksi ke server terputus. Data belum dihapus; coba lagi.');
        return false;
      },
    });
  }
  return (
    <dialog
      ref={dialog}
      className="delete-dialog"
      aria-labelledby="delete-title"
      aria-describedby="delete-detail"
      // Esc closes the native dialog; keep React's state in step, but never mid-request.
      onCancel={(event) => {
        event.preventDefault();
        if (!busy) onClose();
      }}
    >
      <span className="delete-mark" aria-hidden="true">
        <img src="/assets/trash.svg" alt="" width={28} height={28} />
      </span>
      <h2 id="delete-title">Hapus data pasien?</h2>
      <p id="delete-detail">
        Data pasien berikut akan dihapus dari daftar Anda. Tindakan ini tidak dapat dibatalkan.
      </p>
      <div className="delete-target">
        <strong>{patient.name}</strong>
        <span>{patient.recordNumber ? `No. RM ${patient.recordNumber}` : 'Belum ada No. RM'}</span>
      </div>
      <p className="delete-note">Pastikan data pasien yang dipilih sudah benar.</p>
      {error && (
        <p className="field-error" role="alert">
          {error}
        </p>
      )}
      <div className="delete-actions">
        <button
          type="button"
          className="button outline"
          disabled={busy}
          onClick={onClose}
          autoFocus
        >
          Batal
        </button>
        <button type="button" className="button danger" disabled={busy} onClick={remove}>
          {busy ? 'Menghapus…' : 'Hapus data'}
        </button>
      </div>
    </dialog>
  );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt>{label}</dt>
      <dd className={children ? undefined : 'empty'}>{children || 'Belum diisi'}</dd>
    </div>
  );
}

/** Whole years between a Y-m-d birth date and today. */
function age(birthDate: string) {
  const [year, month, day] = birthDate.split('-').map(Number);
  const now = new Date();
  const years = now.getFullYear() - year;
  return now.getMonth() + 1 < month || (now.getMonth() + 1 === month && now.getDate() < day)
    ? years - 1
    : years;
}
function localDate(date = new Date()) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** Tambah / ubah data pasien (Figma 27:110 and 27:179). */
export function PatientForm({ patient }: { patient: PatientRecord | null }) {
  const form = useForm({
    name: patient?.name ?? '',
    birth_date: patient?.birthDate ?? '',
    gender: patient?.gender ?? '',
    phone: patient?.phone ?? '',
    address: patient?.address ?? '',
    city: patient?.city ?? '',
    postal_code: patient?.postalCode ?? '',
  });
  const { data, errors, setData } = form;
  function submit(event: FormEvent) {
    event.preventDefault();
    if (patient) form.put(`/data-pasien/${patient.id}`);
    else form.post('/data-pasien');
  }
  const years = data.birth_date && data.birth_date <= localDate() ? age(data.birth_date) : null;
  return (
    <Shell
      title={patient ? 'Ubah data pasien' : 'Tambah data pasien'}
      subtitle="PROFIL DAN DATA PASIEN"
      back="/data-pasien"
      bare
      className="inter-shell form-shell"
    >
      <form className="patient-form" onSubmit={submit} noValidate>
        <section className="lead">
          <h2>{patient ? 'Perbarui data pasien' : 'Lengkapi data pasien'}</h2>
          <p>
            {patient
              ? 'Pastikan informasi terbaru sesuai dengan identitas pasien.'
              : 'Tambahkan data diri atau pasien yang Anda dampingi.'}
          </p>
          <p className="required-note">* Wajib diisi</p>
        </section>
        <fieldset>
          <legend>Identitas pasien</legend>
          <Field label="Nama lengkap *" name="name" error={errors.name}>
            <input
              value={data.name}
              onChange={(e) => setData('name', e.target.value)}
              placeholder="Nama sesuai identitas"
              autoComplete="name"
              maxLength={100}
              required
            />
          </Field>
          <Field
            label="Tanggal lahir *"
            name="birth_date"
            error={errors.birth_date}
            hint={
              years !== null
                ? `${years} tahun · dihitung otomatis dari tanggal lahir.`
                : 'Umur dihitung otomatis. Tanggal tidak boleh di masa depan.'
            }
          >
            <input
              type="date"
              value={data.birth_date}
              onChange={(e) => setData('birth_date', e.target.value)}
              min="1900-01-01"
              max={localDate()}
              autoComplete="bday"
              required
            />
          </Field>
          <Field label="Jenis kelamin *" name="gender" error={errors.gender}>
            <select
              value={data.gender}
              onChange={(e) => setData('gender', e.target.value)}
              className={data.gender ? undefined : 'placeholder'}
              required
            >
              <option value="" disabled>
                Pilih jenis kelamin
              </option>
              <option value="female">Perempuan</option>
              <option value="male">Laki-laki</option>
            </select>
          </Field>
        </fieldset>
        <fieldset>
          <legend>Kontak yang bisa dihubungi</legend>
          <Field
            label="Nomor ponsel *"
            name="phone"
            error={errors.phone}
            hint="Gunakan nomor aktif dengan awalan 08 atau +62."
          >
            <input
              type="tel"
              inputMode="tel"
              value={data.phone}
              onChange={(e) => setData('phone', e.target.value)}
              placeholder="Contoh: 0812 3456 7890"
              autoComplete="tel"
              required
            />
          </Field>
        </fieldset>
        <fieldset>
          <legend>Alamat domisili</legend>
          <Field label="Alamat lengkap *" name="address" error={errors.address}>
            <textarea
              value={data.address}
              onChange={(e) => setData('address', e.target.value)}
              placeholder={'Nama jalan, nomor rumah, RT/RW,\nkelurahan dan kecamatan'}
              autoComplete="street-address"
              maxLength={255}
              rows={3}
              required
            />
          </Field>
          <div className="field-row">
            <Field label="Kota / kabupaten *" name="city" error={errors.city}>
              <input
                value={data.city}
                onChange={(e) => setData('city', e.target.value)}
                placeholder="Contoh: Bandung"
                autoComplete="address-level2"
                maxLength={100}
                required
              />
            </Field>
            <Field label="Kode pos (opsional)" name="postal_code" error={errors.postal_code}>
              <input
                inputMode="numeric"
                value={data.postal_code}
                onChange={(e) => setData('postal_code', e.target.value.replace(/\D/g, ''))}
                placeholder="5 digit"
                autoComplete="postal-code"
                maxLength={5}
              />
            </Field>
          </div>
        </fieldset>
        <section className="tip-card">
          <strong>Pastikan data sudah benar</strong>
          <p>
            Data dipakai untuk identifikasi dan komunikasi kunjungan. Jika mengisi untuk orang lain,
            pastikan Anda mendapat persetujuannya.
          </p>
        </section>
        <div className="form-actions">
          <button className="button" disabled={form.processing}>
            {form.processing ? 'Menyimpan…' : patient ? 'Simpan perubahan' : 'Simpan data pasien'}
          </button>
          <Link className="button ghost" href="/data-pasien">
            Batal
          </Link>
        </div>
      </form>
    </Shell>
  );
}

/** Label, control, then the hint or the server's error, wired for screen readers. */
function Field({
  label,
  name,
  error,
  hint,
  children,
}: {
  label: string;
  name: string;
  error?: string;
  hint?: string;
  children: ReactElement<InputHTMLAttributes<HTMLElement>>;
}) {
  const id = `field-${name}`;
  const note = error ?? hint;
  return (
    <div className="form-field">
      <label htmlFor={id}>{label}</label>
      {cloneElement(children, {
        id,
        name,
        'aria-invalid': error ? true : undefined,
        'aria-describedby': note ? `${id}-note` : undefined,
      })}
      {note && (
        <p id={`${id}-note`} className={error ? 'field-error' : 'field-hint'}>
          {note}
        </p>
      )}
    </div>
  );
}
