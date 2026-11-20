import { Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Notice } from '@/components/ui';
import { ACCOUNT_HELP, AuthInput, AuthPage, PasswordInput } from './auth-form';

// Prototype mode (/prototipe) signs in as one of these sample accounts; no password is checked.
const PROTOTYPE_ROLES = [
  ['Pasien', '0812-5550-0842'],
  ['Pendamping', '0812-5550-0843'],
  ['Petugas', 'dina'],
  ['Manajemen', 'manajer'],
] as const;

type Role = 'patient' | 'companion';
const ROLES: { value: Role; label: string; detail: string; icon: string }[] = [
  {
    value: 'patient',
    label: 'Pasien',
    detail: 'Berobat untuk\ndiri sendiri',
    icon: '/assets/role-patient.svg',
  },
  {
    value: 'companion',
    label: 'Pendamping',
    detail: 'Membantu kunjungan\nkeluarga atau pasien',
    icon: '/assets/role-companion.svg',
  },
];

export function Login({
  demoAccounts,
  prototype = false,
}: {
  demoAccounts: string | null;
  prototype?: boolean;
}) {
  // "Masuk sebagai" decides where a patient account lands: its own visit or the companion view.
  const form = useForm<{ username: string; password: string; as: Role }>({
    username: '',
    password: '',
    as: 'patient',
  });
  const [help, setHelp] = useState<'recovery' | 'contact' | null>(null);
  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/masuk', { onFinish: () => form.reset('password') });
  }
  return (
    <AuthPage
      className="auth-login"
      title="Selamat datang di Hytrack"
      intro={
        <>
          Masuk untuk melanjutkan kunjungan
          <br />
          dan memantau antrean Anda.
        </>
      }
    >
      <form className="auth-form" onSubmit={submit} noValidate>
        <fieldset className="auth-roles">
          <legend>Masuk sebagai</legend>
          <div>
            {ROLES.map((role) => (
              <label key={role.value} className="auth-role">
                <input
                  type="radio"
                  name="as"
                  className="sr-only"
                  value={role.value}
                  checked={form.data.as === role.value}
                  onChange={() => form.setData('as', role.value)}
                />
                <img src={role.icon} alt="" width={28} height={28} />
                <span className="auth-radio" aria-hidden="true" />
                <strong>{role.label}</strong>
                <small>{role.detail}</small>
              </label>
            ))}
          </div>
        </fieldset>
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
      <div className="auth-prototype">
        <label className="auth-switch-toggle">
          <input
            type="checkbox"
            role="switch"
            checked={prototype}
            onChange={() =>
              window.location.assign(prototype ? '/masuk' : '/prototipe/masuk?reset=1')
            }
          />
          <span>
            <strong>Mode prototype</strong>
            <small>Coba alur aplikasi dengan data contoh, tanpa database.</small>
          </span>
        </label>
        {prototype && (
          <div className="auth-prototype-roles" role="group" aria-label="Masuk sebagai">
            {PROTOTYPE_ROLES.map(([label, username]) => (
              <button
                key={label}
                type="button"
                onClick={() =>
                  router.post('/masuk', {
                    username,
                    password: 'prototype',
                    as: label === 'Pendamping' ? 'companion' : 'patient',
                  })
                }
              >
                {label}
              </button>
            ))}
          </div>
        )}
      </div>
    </AuthPage>
  );
}
