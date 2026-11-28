import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Notice } from '@/components/ui';
import { AuthInput, PasswordInput } from './auth-form';

type Role = 'staff' | 'manager';
const ROLES: { value: Role; label: string; detail: string; icon: string }[] = [
  {
    value: 'staff',
    label: 'Petugas',
    detail: 'Kelola antrean dan\npelayanan pasien',
    icon: '/assets/role-staff.svg',
  },
  {
    value: 'manager',
    label: 'Manajemen',
    detail: 'Pantau analitik dan\nkinerja pelayanan',
    icon: '/assets/role-manager.svg',
  },
];
// Prototype mode sample accounts, moved here from the patient sign-in.
const PROTOTYPE_ROLES = [
  ['Petugas', 'dina', 'staff'],
  ['Manajemen', 'manajer', 'manager'],
] as const;
const BENEFITS = [
  'Pantau antrean secara real-time',
  'Koordinasikan setiap tahap pelayanan',
  'Catat aktivitas dengan lebih teratur',
];

/**
 * Figma 47:2 "Login · Petugas dan Manajemen" (47:2345). The chosen role must match the
 * account (AuthController::storeInternal). There is no self-service reset: accounts are
 * issued and reset by the hospital's administrator.
 */
export function InternalLogin({
  demoAccounts,
  prototype = false,
}: {
  demoAccounts: string | null;
  prototype?: boolean;
}) {
  const form = useForm<{ as: Role; username: string; password: string }>({
    as: 'staff',
    username: '',
    password: '',
  });
  const [help, setHelp] = useState<'recovery' | 'access' | null>(null);
  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/masuk/internal', { onFinish: () => form.reset('password') });
  }
  return (
    <div className="internal-login">
      <aside className="internal-identity">
        <div className="internal-lockup">
          <span>
            <img src="/assets/logo-hytrack.png" alt="" width={72} height={54} />
          </span>
          <div>
            <p className="lockup-name">HyTrack</p>
            <p className="lockup-tagline">TEMAN PERJALANAN BEROBAT</p>
          </div>
        </div>
        <p className="internal-eyebrow">RUANG PETUGAS &amp; MANAJEMEN</p>
        <h2>
          Pelayanan terhubung,
          <br />
          pasien lebih tenang.
        </h2>
        <p className="internal-lead">
          Kelola perjalanan pasien dari kedatangan hingga pelayanan selesai dalam satu ruang kerja.
        </p>
        <ol className="internal-benefits">
          {BENEFITS.map((benefit, index) => (
            <li key={benefit}>
              <span>{String(index + 1).padStart(2, '0')}</span>
              {benefit}
            </li>
          ))}
        </ol>
        <p className="internal-note">Akses khusus petugas dan manajemen yang terdaftar.</p>
      </aside>
      <main id="main" className="internal-main">
        <div className="internal-panel">
          <span className="internal-badge">Akses internal</span>
          <h1>Masuk ke HyTrack</h1>
          <p className="internal-intro">
            Selamat datang kembali. Pilih peran dan masuk
            <br />
            untuk mengakses ruang kerja Anda.
          </p>
          <form className="auth-form internal-form" onSubmit={submit} noValidate>
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
              {form.errors.as && (
                <p className="field-error" role="alert">
                  {form.errors.as}
                </p>
              )}
            </fieldset>
            <div className="auth-fields">
              <AuthInput
                label="ID pengguna atau email"
                name="username"
                placeholder="Masukkan ID pengguna atau email"
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
            {help && (
              <Notice role="status">
                {help === 'recovery'
                  ? 'Kata sandi akun internal diatur ulang oleh administrator rumah sakit. Hubungi administrator dengan menyebutkan ID pengguna Anda.'
                  : 'Akun petugas dan manajemen dibuat oleh administrator rumah sakit. Hubungi administrator untuk mendapatkan akses.'}
              </Notice>
            )}
            <button className="auth-submit" disabled={form.processing}>
              {form.processing ? 'Memeriksa…' : 'Masuk'}
            </button>
            <p className="internal-access">
              Belum memiliki akses?{' '}
              <button type="button" className="auth-link plain" onClick={() => setHelp('access')}>
                Hubungi administrator
              </button>
            </p>
            {demoAccounts && <Notice tone="info">{demoAccounts}</Notice>}
          </form>
        </div>
        <p className="internal-foot">Gunakan akun Anda sendiri dan jaga kerahasiaan kata sandi.</p>
        {/* Prototype mode (/prototipe): sample staff and manager accounts, no password checked. */}
        <div className="auth-prototype">
          <label className="auth-switch-toggle">
            <input
              type="checkbox"
              role="switch"
              checked={prototype}
              onChange={() =>
                window.location.assign(
                  prototype ? '/masuk/internal' : '/prototipe/masuk/internal?reset=1',
                )
              }
            />
            <span>
              <strong>Mode prototype</strong>
              <small>Coba ruang kerja petugas dan manajemen dengan data contoh.</small>
            </span>
          </label>
          {prototype && (
            <div className="auth-prototype-roles" role="group" aria-label="Masuk sebagai">
              {PROTOTYPE_ROLES.map(([label, username, as]) => (
                <button
                  key={label}
                  type="button"
                  onClick={() =>
                    router.post('/masuk/internal', { as, username, password: 'prototype' })
                  }
                >
                  {label}
                </button>
              ))}
            </div>
          )}
        </div>
      </main>
    </div>
  );
}
