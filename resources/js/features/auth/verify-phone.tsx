import { router, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { Notice } from '@/components/ui';
import { ACCOUNT_HELP, AuthInput, AuthPage } from './auth-form';

/** Sign-up step 2: the 6-digit code Wangsaf sent to the patient's WhatsApp. */
export function VerifyPhone({
  phone,
  minutesValid,
  resendIn,
}: {
  phone: string;
  minutesValid: number;
  resendIn: number;
}) {
  const form = useForm({ code: '' });
  const [wait, setWait] = useState(resendIn);
  const [resending, setResending] = useState(false);
  const [help, setHelp] = useState(false);
  useEffect(() => {
    if (wait <= 0) return;
    const timer = setTimeout(() => setWait((seconds) => seconds - 1), 1000);
    return () => clearTimeout(timer);
  }, [wait]);
  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/daftar/verifikasi', { onError: () => form.reset('code') });
  }
  function resend() {
    setResending(true);
    form.clearErrors();
    router.post(
      '/daftar/verifikasi/kirim-ulang',
      {},
      {
        // The new code restarts the countdown the server reports.
        onSuccess: (page) => setWait(Number(page.props.resendIn) || 0),
        onError: (errors) => form.setError('code', errors.code ?? 'Kode gagal dikirim.'),
        onFinish: () => setResending(false),
      },
    );
  }
  return (
    <AuthPage
      title="Cek WhatsApp Anda."
      intro={
        <>
          Kode 6 angka sudah dikirim ke WhatsApp <strong>{phone}</strong>. Kode berlaku{' '}
          {minutesValid} menit.
        </>
      }
    >
      <form className="auth-form" onSubmit={submit} noValidate>
        <div className="auth-fields">
          <AuthInput
            label="Kode verifikasi"
            name="code"
            inputMode="numeric"
            autoComplete="one-time-code"
            placeholder="6 angka"
            maxLength={6}
            required
            autoFocus
            value={form.data.code}
            onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, '').slice(0, 6))}
            error={form.errors.code}
          />
        </div>
        <div className="auth-actions">
          <button className="auth-submit" disabled={form.processing || form.data.code.length !== 6}>
            {form.processing ? 'Memeriksa kode…' : 'Verifikasi & buat akun'}
          </button>
          <button
            type="button"
            className="auth-link"
            disabled={wait > 0 || resending}
            onClick={resend}
          >
            {resending
              ? 'Mengirim kode…'
              : wait > 0
                ? `Kirim ulang kode dalam ${wait} detik`
                : 'Kirim ulang kode'}
          </button>
          <p className="auth-switch">
            <span>Nomor salah?</span>
            <button
              type="button"
              className="auth-link"
              onClick={() => router.post('/daftar/verifikasi/batal')}
            >
              Ganti nomor
            </button>
          </p>
        </div>
      </form>
      <Notice tone="warning">
        Jangan berikan kode ini kepada siapa pun, termasuk petugas rumah sakit.
      </Notice>
      <section className="auth-help">
        <button type="button" className="auth-link auth-contact" onClick={() => setHelp(true)}>
          Kode tidak masuk? Hubungi petugas.
        </button>
        {help && <Notice role="status">{ACCOUNT_HELP}</Notice>}
      </section>
    </AuthPage>
  );
}
