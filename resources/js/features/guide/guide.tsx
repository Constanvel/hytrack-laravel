import { useSyncExternalStore } from 'react';

export const guideSteps = {
  pasien: {
    title: 'Pastikan nama pasien benar',
    text: 'Nama yang ditandai adalah pasien yang akan berobat. Jika sudah benar, tekan tombol Ambil antrean.',
  },
  poli: {
    title: 'Pilih poliklinik tujuan',
    text: 'Ketuk nama poliklinik yang ingin Anda tuju. Anda juga bisa mengetik namanya di kolom cari.',
  },
  dokter: {
    title: 'Pilih tanggal dan dokter',
    text: 'Pilih tanggal, ketuk nama dokter, lalu tekan Ambil antrean. Nomor antrean baru keluar setelah tombol itu ditekan.',
  },
  tiket: {
    title: 'Perhatikan waktu berangkat',
    text: 'Kotak kuning memberi tahu kapan sebaiknya Anda berangkat. Setibanya di rumah sakit, tekan Tampilkan QR check-in.',
  },
  checkin: {
    title: 'Tunjukkan QR ke petugas',
    text: 'Bawa layar ini ke meja check-in. Jika QR sulit dipindai, tekan Nomor check-in manual.',
  },
  antrean: {
    title: 'Tunggu giliran Anda',
    text: 'Angka besar adalah nomor Anda. Di sini terlihat berapa pasien lagi sebelum Anda dan di ruang mana Anda menunggu.',
  },
  layanan: {
    title: 'Ikuti layanan berikutnya',
    text: 'Setelah diperiksa dokter, HyTrack menunjukkan tujuan berikutnya, misalnya laboratorium atau farmasi. Ikuti langkah di halaman ini.',
  },
};
export type GuideStep = keyof typeof guideSteps;
const order = Object.keys(guideSteps) as GuideStep[];

// Guide preferences only; never touches the demo visit state.
type Prefs = { off: boolean; seen: GuideStep[] };
const KEY = 'hytrack-guide';
const hidden: Prefs = { off: true, seen: [] };
const listeners = new Set<() => void>();
let cache: Prefs | null = null;
function read(): Prefs {
  if (cache) return cache;
  try {
    cache = { off: false, seen: [], ...JSON.parse(localStorage.getItem(KEY) ?? '{}') };
  } catch {
    cache = { off: false, seen: [] };
  }
  return cache!;
}
function write(next: Prefs) {
  cache = next;
  try {
    localStorage.setItem(KEY, JSON.stringify(next));
  } catch {}
  listeners.forEach((listener) => listener());
}
function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}
export function useGuide(step?: GuideStep) {
  const prefs = useSyncExternalStore(subscribe, read, () => hidden);
  return {
    visible: !!step && !prefs.off && !prefs.seen.includes(step),
    off: prefs.off,
    dismiss: () => step && write({ ...prefs, seen: [...prefs.seen, step] }),
    skip: () => write({ ...prefs, off: true }),
    restart: () => write({ off: false, seen: [] }),
  };
}
export function GuideHint({ step }: { step: GuideStep }) {
  const { visible, dismiss, skip } = useGuide(step);
  if (!visible) return null;
  const index = order.indexOf(step);
  return (
    <aside className="guide-hint" aria-labelledby="guide-title">
      <p className="eyebrow">
        PANDUAN · LANGKAH {index + 1} DARI {order.length}
      </p>
      <div className="guide-progress" aria-hidden="true">
        {order.map((item, i) => (
          <span key={item} className={i <= index ? 'done' : ''} />
        ))}
      </div>
      <h2 id="guide-title">{guideSteps[step].title}</h2>
      <p>{guideSteps[step].text}</p>
      <div className="guide-actions">
        <button className="button" onClick={dismiss}>
          Mengerti
        </button>
        <button className="text-button" onClick={skip}>
          Lewati panduan
        </button>
      </div>
    </aside>
  );
}
export function GuideRestart() {
  const { off, restart } = useGuide();
  return (
    <button className="text-button" onClick={restart}>
      {off ? 'Tampilkan panduan penggunaan' : 'Ulangi panduan dari awal'}
    </button>
  );
}
