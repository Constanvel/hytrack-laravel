import { Link } from '@inertiajs/react';

const messages: Record<number, { title: string; text: string }> = {
  403: { title: 'Akses ditolak', text: 'Akun Anda tidak memiliki akses ke halaman ini.' },
  404: { title: 'Halaman tidak ditemukan', text: 'Periksa alamat atau kembali ke beranda.' },
  503: { title: 'HyTrack sedang dalam pemeliharaan', text: 'Silakan coba beberapa saat lagi.' },
};
export function ErrorPage({ status }: { status: number }) {
  const known = messages[status];
  return (
    <main id="main" className="error-page">
      {known && <p className="eyebrow">{status} · HYTRACK</p>}
      <h1>{known?.title ?? 'Halaman belum dapat dimuat'}</h1>
      <p className="muted">{known?.text ?? 'Coba lagi beberapa saat lagi.'}</p>
      {!known && (
        <button className="button" onClick={() => window.location.reload()}>
          Coba lagi
        </button>
      )}
      <Link className={`button ${known ? '' : 'secondary'}`} href="/">
        Kembali ke beranda
      </Link>
    </main>
  );
}
