import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Notice } from '@/components/ui';
import { ACCOUNT_HELP, AuthInput, AuthPage, PasswordInput } from './auth-form';

export function Register() {
  const form = useForm({
    name: '',
    phone: '',
    password: '',
    password_confirmation: '',
    terms: false,
  });
  const [help, setHelp] = useState(false);
  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/daftar', { onFinish: () => form.reset('password', 'password_confirmation') });
  }
  return (
    <AuthPage
      title="Mulai berobat lebih tenang."
      intro="Buat akun untuk mengatur kunjungan Anda."
      compact
    >
      <form className="auth-form" onSubmit={submit} noValidate>
        <div className="auth-fields">
          <AuthInput
            label="Nama lengkap"
            name="name"
            placeholder="Sesuai identitas Anda"
            autoComplete="name"
            required
            maxLength={100}
            value={form.data.name}
            onChange={(e) => form.setData('name', e.target.value)}
            error={form.errors.name}
          />
          <AuthInput
            label="Nomor ponsel"
            name="phone"
            type="tel"
            icon="phone"
            placeholder="+62 123-4567-8910"
            autoComplete="tel"
            required
            value={form.data.phone}
            onChange={(e) => form.setData('phone', e.target.value)}
            error={form.errors.phone}
          />
          <PasswordInput
            label="Kata sandi"
            name="password"
            placeholder="Minimal 8 karakter"
            autoComplete="new-password"
            required
            minLength={8}
            value={form.data.password}
            onChange={(e) => form.setData('password', e.target.value)}
            error={form.errors.password}
          />
          <PasswordInput
            label="Konfirmasi kata sandi"
            name="password_confirmation"
            placeholder="Ulangi kata sandi"
            autoComplete="new-password"
            required
            value={form.data.password_confirmation}
            onChange={(e) => form.setData('password_confirmation', e.target.value)}
            error={form.errors.password_confirmation}
          />
        </div>
        <div className="auth-field">
          <label className="auth-consent">
            <input
              type="checkbox"
              name="terms"
              checked={form.data.terms}
              onChange={(e) => form.setData('terms', e.target.checked)}
              aria-invalid={!!form.errors.terms}
              aria-describedby={form.errors.terms ? 'auth-terms-error' : undefined}
            />
            <span>
              Saya menyetujui <strong>Syarat &amp; Ketentuan</strong>
              <br />
              dan <strong>Kebijakan Privasi</strong> HyTrack.
            </span>
          </label>
          {form.errors.terms && (
            <p id="auth-terms-error" className="field-error" role="alert">
              {form.errors.terms}
            </p>
          )}
        </div>
        <div className="auth-actions">
          <button className="auth-submit" disabled={form.processing}>
            {form.processing ? 'Membuat akun…' : 'Buat akun'}
          </button>
          <p className="auth-switch">
            <span>Sudah punya akun?</span>
            <Link href="/masuk">Masuk</Link>
          </p>
        </div>
      </form>
      <section className="auth-help">
        <button type="button" className="auth-link auth-contact" onClick={() => setHelp(true)}>
          Butuh bantuan? Hubungi petugas.
        </button>
        {help && <Notice role="status">{ACCOUNT_HELP}</Notice>}
      </section>
    </AuthPage>
  );
}
