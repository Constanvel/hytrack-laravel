import { useState, type InputHTMLAttributes, type ReactNode } from 'react';

/** Shared frame of the sign-in and sign-up screens (Figma 00a / 00b). */
export function AuthPage({
  title,
  intro,
  compact = false,
  children,
}: {
  title: string;
  intro: ReactNode;
  /** Sign-up has more fields, so the design tightens the top padding and section gap. */
  compact?: boolean;
  children: ReactNode;
}) {
  return (
    <div className={`auth-page ${compact ? 'auth-compact' : ''}`}>
      <main id="main" className="auth-main">
        <header className="auth-identity">
          <div className="auth-brand">
            <img src="/assets/logo-hytrack.png" alt="" width={72} height={54} />
            <div>
              <p className="auth-brand-name">HyTrack</p>
              <p className="auth-brand-tagline">TEMAN PERJALANAN BEROBAT</p>
            </div>
          </div>
          <div className="auth-intro">
            <h1>{title}</h1>
            <p>{intro}</p>
          </div>
        </header>
        {children}
      </main>
    </div>
  );
}

type InputProps = InputHTMLAttributes<HTMLInputElement> & {
  label: string;
  name: string;
  error?: string;
  icon?: 'phone';
  children?: ReactNode;
};

export function AuthInput({ label, name, error, icon, children, ...input }: InputProps) {
  const id = `auth-${name}`;
  return (
    <div className="auth-field">
      <label htmlFor={id}>{label}</label>
      <span className="auth-input">
        {icon && <img src={`/assets/${icon}.png`} alt="" width={31} height={20} />}
        <input
          id={id}
          name={name}
          aria-invalid={!!error}
          aria-describedby={error ? `${id}-error` : undefined}
          {...input}
        />
        {children}
      </span>
      {error && (
        <p id={`${id}-error`} className="field-error" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}

export function PasswordInput(props: Omit<InputProps, 'type' | 'icon' | 'children'>) {
  const [visible, setVisible] = useState(false);
  return (
    <AuthInput {...props} type={visible ? 'text' : 'password'}>
      <button
        type="button"
        className="auth-eye"
        aria-label={visible ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'}
        aria-pressed={visible}
        onClick={() => setVisible((shown) => !shown)}
      >
        <img src="/assets/eye.png" alt="" width={31} height={20} />
      </button>
    </AuthInput>
  );
}

/** There is no self-service reset or public help page yet: account help happens at the hospital. */
export const ACCOUNT_HELP =
  'Hubungi petugas pendaftaran rumah sakit untuk bantuan akun, termasuk mengatur ulang kata sandi.';
