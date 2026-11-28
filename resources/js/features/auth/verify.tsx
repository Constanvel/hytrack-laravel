import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
  useEffect,
  useRef,
  useState,
  type ClipboardEvent,
  type FormEvent,
  type KeyboardEvent,
  type ReactNode,
} from 'react';
import { Notice } from '@/components/ui';
import { ACCOUNT_HELP } from './auth-form';

/** Sign-up steps 2 and 3 (Figma 32:286 "Kode OTP", 32:330 "Verifikasi berhasil"). */
function VerifyPage({
  step,
  top,
  chip,
  title,
  children,
}: {
  step: 2 | 3;
  top: ReactNode;
  chip: string;
  title: ReactNode;
  children: ReactNode;
}) {
  const [help, setHelp] = useState(false);
  return (
    <div className="auth-page verify-page">
      <main id="main" className="verify-main">
        <div className="verify-top">
          {top}
          <span className="verify-count">0{step} / 03</span>
        </div>
        <div
          className="verify-progress"
          role="progressbar"
          aria-label="Langkah pendaftaran"
          aria-valuemin={1}
          aria-valuemax={3}
          aria-valuenow={step}
        >
          {[1, 2, 3].map((n) => (
            <span key={n} className={n <= step ? 'done' : undefined} />
          ))}
        </div>
        <p className="verify-chip">{chip}</p>
        <h1>{title}</h1>
        {children}
        <div className="verify-help">
          <p>
            Ada kendala?{' '}
            <button type="button" className="auth-link" onClick={() => setHelp(true)}>
              Hubungi bantuan
            </button>
          </p>
          {help && <Notice role="status">{ACCOUNT_HELP}</Notice>}
        </div>
      </main>
    </div>
  );
}

export function RegisterOtp({
  phone,
  resendIn,
  demoCode,
  channel = 'SMS',
}: {
  phone: string;
  resendIn: number;
  expiresIn: number;
  demoCode: string | null;
  /** "WhatsApp" once the Wangsaf gateway is configured. */
  channel?: string;
}) {
  const page = usePage();
  const form = useForm({ code: '' });
  const [digits, setDigits] = useState(['', '', '', '', '', '']);
  const boxes = useRef<(HTMLInputElement | null)[]>([]);
  const [left, setLeft] = useState(resendIn);
  // Every visit (first load, resend) brings a fresh countdown from the server.
  const [visit, setVisit] = useState(page.props);
  if (visit !== page.props) {
    setVisit(page.props);
    setLeft(resendIn);
  }
  useEffect(() => {
    if (left <= 0) return;
    const timer = setTimeout(() => setLeft(left - 1), 1000);
    return () => clearTimeout(timer);
  }, [left]);

  /** Writes typed or pasted digits from box `index` on and moves focus past them. */
  function put(index: number, value: string) {
    const typed = value
      .replace(/\D/g, '')
      .slice(0, 6 - index)
      .split('');
    const next = [...digits];
    if (typed.length) typed.forEach((d, i) => (next[index + i] = d));
    else next[index] = '';
    setDigits(next);
    form.setData('code', next.join(''));
    if (typed.length) boxes.current[Math.min(index + typed.length, 5)]?.focus();
  }
  function key(index: number, event: KeyboardEvent<HTMLInputElement>) {
    // A digit always replaces the box and moves on, even when it is the same digit (no change event).
    if (/^\d$/.test(event.key)) {
      event.preventDefault();
      put(index, event.key);
      return;
    }
    if (event.key === 'Backspace' && !digits[index] && index > 0) {
      event.preventDefault();
      put(index - 1, '');
      boxes.current[index - 1]?.focus();
    }
    if (event.key === 'ArrowLeft') boxes.current[index - 1]?.focus();
    if (event.key === 'ArrowRight') boxes.current[index + 1]?.focus();
  }
  function paste(index: number, event: ClipboardEvent<HTMLInputElement>) {
    event.preventDefault();
    put(index, event.clipboardData.getData('text'));
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/daftar/verifikasi', { onError: () => boxes.current[0]?.focus() });
  }
  const error = form.errors.code ?? (page.props.errors as Record<string, string>).phone;
  const mm = String(Math.floor(left / 60)).padStart(2, '0');
  const ss = String(left % 60).padStart(2, '0');

  return (
    <VerifyPage
      step={2}
      top={
        <Link href="/daftar" className="verify-back">
          <span aria-hidden="true">←</span> Kembali
        </Link>
      }
      chip="VERIFIKASI NOMOR"
      title={
        <>
          Masukkan
          <br />
          kode OTP
        </>
      }
    >
      <p className="verify-lead">
        Kode 6 digit telah dikirim melalui {channel} ke
        <br />
        <strong>{phone}</strong>{' '}
        <Link href="/daftar" className="auth-link">
          Ubah nomor
        </Link>
      </p>
      {page.flash.status && <Notice role="status">{page.flash.status}</Notice>}
      {demoCode && (
        <Notice tone="info">Mode demo · belum ada SMS. Kode OTP Anda: {demoCode}</Notice>
      )}
      <form className="verify-form" onSubmit={submit} noValidate>
        <fieldset className="otp-boxes" aria-describedby={error ? 'otp-error' : undefined}>
          <legend className="sr-only">Kode OTP 6 digit</legend>
          {digits.map((digit, index) => (
            <input
              key={index}
              ref={(el) => {
                boxes.current[index] = el;
              }}
              className={digit ? 'filled' : undefined}
              aria-label={`Digit ${index + 1} dari 6`}
              aria-invalid={!!error}
              inputMode="numeric"
              autoComplete={index === 0 ? 'one-time-code' : 'off'}
              pattern="[0-9]*"
              maxLength={index === 0 ? 6 : 1}
              value={digit}
              autoFocus={index === 0}
              // Typing over a filled box replaces its digit.
              onFocus={(e) => e.target.select()}
              onChange={(e) => put(index, e.target.value)}
              onKeyDown={(e) => key(index, e)}
              onPaste={(e) => paste(index, e)}
            />
          ))}
        </fieldset>
        {error && (
          <p id="otp-error" className="field-error" role="alert">
            {error}
          </p>
        )}
        <p className="verify-resend" aria-live="polite">
          {left > 0 ? (
            <>
              Kirim ulang kode dalam <strong>{`${mm}:${ss}`}</strong>
            </>
          ) : (
            <button
              type="button"
              className="auth-link"
              onClick={() =>
                router.post('/daftar/verifikasi/kirim-ulang', {}, { preserveScroll: true })
              }
            >
              Kirim ulang kode
            </button>
          )}
        </p>
        <button className="auth-submit" disabled={form.processing || digits.some((d) => !d)}>
          {form.processing ? 'Memverifikasi…' : 'Verifikasi & lanjutkan'}
        </button>
      </form>
      <p className="verify-note">
        Kode berlaku selama 5 menit. Jangan bagikan kode ini kepada siapa pun, termasuk pihak kami.
      </p>
    </VerifyPage>
  );
}

export function RegisterVerified({ phone, next }: { phone: string; next: string }) {
  const { flash } = usePage();
  return (
    <VerifyPage
      step={3}
      top={<span className="verify-back">Akun terverifikasi</span>}
      chip="VERIFIKASI SELESAI"
      title={
        <>
          Nomor Anda
          <br />
          terverifikasi.
        </>
      }
    >
      <p className="verify-lead">
        Akun HyTrack Anda siap digunakan. Mulai atur kunjungan dan pantau antrean dengan lebih
        tenang.
      </p>
      {flash.status && <Notice role="status">{flash.status}</Notice>}
      <section className="verify-card">
        <p className="verify-chip-label">NOMOR PONSEL TERVERIFIKASI</p>
        <p className="verify-phone">{phone}</p>
        <p>Nomor ini terhubung dengan akun Anda.</p>
      </section>
      <Link href={next} className="auth-submit verify-continue">
        Lanjut ke beranda
      </Link>
      <p className="verify-note">
        Di beranda, Anda dapat memilih pasien
        <br />
        dan mulai mengambil antrean.
      </p>
    </VerifyPage>
  );
}
