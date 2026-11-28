import { usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { DeleteDialog } from '@/components/delete-dialog';
import { Notice } from '@/components/ui';
import { useAction } from '@/lib/live';

export const CLINICS = 'Data poliklinik';

export interface ClinicRow {
  id: number;
  name: string;
  room: string | null;
  doctors: number;
  queues: number;
}

/** CRUD for the polyclinics master table. Every change is validated and saved by the server. */
export function ClinicAdmin({ clinics }: { clinics: ClinicRow[] }) {
  const [editingId, setEditingId] = useState<number | null>(null);
  // Looked up each time, so the form closes once its clinic is deleted.
  const editing = clinics.find((clinic) => clinic.id === editingId);
  const [removing, setRemoving] = useState<ClinicRow | null>(null);
  const { flash } = usePage();
  return (
    <div className="booking-layout">
      <section className="card stack booking-list">
        {flash.status && (
          <Notice tone="green" role="status">
            {flash.status}
          </Notice>
        )}
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Nama poliklinik</th>
                <th>Lokasi ruangan</th>
                <th>Dokter</th>
                <th>Antrean</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {clinics.map((clinic) => (
                <ClinicRowView
                  key={clinic.id}
                  clinic={clinic}
                  selected={editing?.id === clinic.id}
                  onEdit={() => setEditingId(editingId === clinic.id ? null : clinic.id)}
                  onRemove={() => setRemoving(clinic)}
                />
              ))}
            </tbody>
          </table>
        </div>
        {!clinics.length && (
          <p className="empty muted" role="status">
            Belum ada poliklinik. Tambahkan lewat formulir.
          </p>
        )}
        <div className="table-footer">
          <span>{clinics.length} poliklinik</span>
          <span>
            Poliklinik yang dihapus hilang dari daftar dan booking; riwayat antreannya tetap.
          </span>
        </div>
      </section>
      <div className="stack">
        {editing ? (
          <ClinicForm key={editing.id} clinic={editing} onDone={() => setEditingId(null)} />
        ) : (
          <ClinicForm key="new" />
        )}
      </div>
      {removing && (
        <DeleteDialog
          url={`/petugas/poliklinik/${removing.id}`}
          title="Hapus poliklinik?"
          description="Poliklinik berikut akan dihapus dari daftar."
          name={removing.name}
          details={[
            removing.room ?? 'Lokasi ruangan belum diatur',
            `${removing.doctors} dokter · ${removing.queues} antrean terkait`,
          ]}
          note={[
            'Poliklinik tidak lagi muncul di daftar dan pilihan booking.',
            'Data dokter dan riwayat antrean terkait tetap tersimpan.',
            'Antrean yang masih berjalan tetap dapat diselesaikan.',
          ]}
          onClose={() => setRemoving(null)}
        />
      )}
    </div>
  );
}

function ClinicRowView({
  clinic,
  selected,
  onEdit,
  onRemove,
}: {
  clinic: ClinicRow;
  selected: boolean;
  onEdit: () => void;
  onRemove: () => void;
}) {
  return (
    <tr className={selected ? 'next-row' : ''}>
      <td>
        <strong>{clinic.name}</strong>
      </td>
      <td>{clinic.room ?? <span className="muted">Belum diatur</span>}</td>
      <td>{clinic.doctors}</td>
      <td>{clinic.queues}</td>
      <td className="row-actions">
        <button className="text-button" aria-pressed={selected} onClick={onEdit}>
          Ubah
        </button>
        <button className="text-button danger" onClick={onRemove}>
          Hapus
        </button>
      </td>
    </tr>
  );
}

function ClinicForm({ clinic, onDone }: { clinic?: ClinicRow; onDone?: () => void }) {
  const { busy, error, send } = useAction();
  const [name, setName] = useState(clinic?.name ?? '');
  const [room, setRoom] = useState(clinic?.room ?? '');
  function submit(event: FormEvent) {
    event.preventDefault();
    const data = { name, room };
    if (clinic) send('put', `/petugas/poliklinik/${clinic.id}`, data, onDone);
    else
      send('post', '/petugas/poliklinik', data, () => {
        setName('');
        setRoom('');
      });
  }
  return (
    <section
      className="card stack walk-in"
      aria-label={clinic ? 'Ubah poliklinik' : 'Tambah poliklinik'}
    >
      <h3>{clinic ? `Ubah ${clinic.name}` : 'Tambah poliklinik'}</h3>
      <form className="stack" onSubmit={submit}>
        <label>
          <span>Nama poliklinik</span>
          <input
            required
            minLength={3}
            maxLength={100}
            value={name}
            placeholder="Contoh: Mata"
            onChange={(e) => setName(e.target.value)}
          />
        </label>
        <label>
          <span>Lokasi ruangan (opsional)</span>
          <input
            maxLength={100}
            value={room}
            placeholder="Ruang B-201 · Gedung B, lantai 2"
            onChange={(e) => setRoom(e.target.value)}
          />
        </label>
        {error && (
          <Notice tone="warning" role="alert">
            {error}
          </Notice>
        )}
        <div className="booking-filter-actions">
          <button className="button" disabled={busy}>
            {clinic ? 'Simpan perubahan' : 'Tambah poliklinik'}
          </button>
          {clinic && (
            <button type="button" className="text-button" onClick={onDone}>
              Batal
            </button>
          )}
        </div>
      </form>
    </section>
  );
}
