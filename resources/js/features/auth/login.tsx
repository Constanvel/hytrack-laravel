import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Shell } from '@/components/shell';
import { Notice } from '@/components/ui';

export function Login({ demoAccounts }: { demoAccounts: string | null }) {
  const { hospital } = usePage().props;
  const form = useForm({ username: '', password: '' });
  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/masuk', { onFinish: () => form.reset('password') });
  }
  return (
    <Shell title="HyTrack" subtitle={hospital} bare>
      <section className="intro">
        <h2>Berobat lebih tenang.</h2>
        <p>Masuk untuk memantau antrean Anda.</p>
      </section>
      <form className="card stack" onSubmit={submit} noValidate>
        <label className="field">
          Nama pengguna
          <span className="search-field">
            <input
              name="username"
              autoComplete="username"
              autoCapitalize="none"
              value={form.data.username}
              onChange={(e) => form.setData('username', e.target.value)}
              aria-invalid={!!form.errors.username}
              aria-describedby={form.errors.username ? 'login-error' : undefined}
            />
          </span>
        </label>
        <label className="field">
          Kata sandi
          <span className="search-field">
            <input
              name="password"
              type="password"
              autoComplete="current-password"
              value={form.data.password}
              onChange={(e) => form.setData('password', e.target.value)}
              aria-invalid={!!form.errors.password}
            />
          </span>
        </label>
        {(form.errors.username || form.errors.password) && (
          <p id="login-error" className="field-error" role="alert">
            {form.errors.username ?? form.errors.password}
          </p>
        )}
        <button className="button" disabled={form.processing}>
          {form.processing ? 'Memeriksa…' : 'Masuk'}
        </button>
      </form>
      {demoAccounts && <Notice tone="info">{demoAccounts}</Notice>}
      <p className="small muted">
        Akun dibuat oleh rumah sakit. Hubungi petugas pendaftaran bila belum memilikinya.
      </p>
    </Shell>
  );
}
