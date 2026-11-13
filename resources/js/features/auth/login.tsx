import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Notice } from '@/components/ui';
import { ACCOUNT_HELP, AuthInput, AuthPage, PasswordInput } from './auth-form';

export function Login({ demoAccounts }: { demoAccounts: string | null }) {
  const form = useForm({ username: '', password: '' });
  const [help, setHelp] = useState<'recovery' | 'contact' | null>(null);
  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/masuk', { onFinish: () => form.reset('password') });
  }
  return (
    <AuthPage
      title="Selamat datang kembali."
      intro={
        <>
          Masuk untuk melanjutkan kunjungan
          <br />
          dan memantau antrean Anda.
        </>
      }
    >
      <form className="auth-form" onSubmit={submit} noValidate>
        <div className="auth-fields">
          {/* Patients type their phone number; staff and managers still use their username. */}
          <AuthInput
            label="Nomor ponsel"
            name="username"
            icon="phone"
            placeholder="+62 123-4567-8910"
            autoComplete="username"
            autoCapitalize="none"
            required
            value={form.data.username}
            onChange={(e) => form.setData('username', e.target.value)}
            error={form.errors.username}
          />
          <PasswordInput
            label="Kata sandi"
            name="password"
            placeholder="Masukkan kata sandi"
            autoComplete="current-password"
            required
            value={form.data.password}
            onChange={(e) => form.setData('password', e.target.value)}
            error={form.errors.password}
          />
        </div>
        <div className="auth-recovery">
          <button type="button" className="auth-link" onClick={() => setHelp('recovery')}>
            Lupa kata sandi?
          </button>
        </div>
        {help === 'recovery' && <Notice role="status">{ACCOUNT_HELP}</Notice>}
        <div className="auth-actions">
          <button className="auth-submit" disabled={form.processing}>
            {form.processing ? 'Memeriksa…' : 'Masuk'}
          </button>
          <p className="auth-switch">
            <span>Belum punya akun?</span>
            <Link href="/daftar">Daftar sekarang</Link>
          </p>
        </div>
      </form>
      <section className="auth-help">
        <div className="auth-benefit">
          <h2>Satu akun, perjalanan lebih tenang.</h2>
          <p>
            Ambil antrean, pantau kunjungan, dan
            <br />
            dampingi keluarga dalam satu aplikasi.
          </p>
        </div>
        <button type="button" className="auth-link auth-contact" onClick={() => setHelp('contact')}>
          Butuh bantuan? Hubungi petugas.
        </button>
        {help === 'contact' && <Notice role="status">{ACCOUNT_HELP}</Notice>}
        {demoAccounts && <Notice tone="info">{demoAccounts}</Notice>}
      </section>
    </AuthPage>
  );
}
