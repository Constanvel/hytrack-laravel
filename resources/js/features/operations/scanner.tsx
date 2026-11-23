import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { postJson } from '@/lib/live';
import type { ScannedTicket } from '@/types';

type Phase =
  | 'starting'
  | 'scanning'
  | 'verifying'
  | 'found'
  | 'confirming'
  | 'done'
  | 'invalid'
  | 'off'
  | 'blocked';

const NO_HTTPS = 'Kamera hanya dapat dipakai lewat HTTPS. Ketik nomor check-in di bawah.';
const hasCamera = () => !!navigator.mediaDevices?.getUserMedia;
function cameraProblem(error: unknown) {
  const name = error instanceof DOMException ? error.name : '';
  return name === 'NotAllowedError'
    ? 'Izin kamera ditolak. Izinkan kamera untuk situs ini di pengaturan browser, atau ketik nomor check-in di bawah.'
    : name === 'NotFoundError' || name === 'OverconstrainedError'
      ? 'Kamera tidak ditemukan. Hubungkan kamera, atau ketik nomor check-in di bawah.'
      : name === 'NotReadableError'
        ? 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi itu lalu coba lagi.'
        : 'Kamera tidak dapat dibuka. Coba lagi atau ketik nomor check-in di bawah.';
}

/**
 * Scan QR kedatangan (Figma 27:360). The camera only reads the code; Laravel decides whether
 * the ticket can be checked in (POST /petugas/scan) and records it after staff confirm
 * (POST /petugas/check-in). Scanning pauses from the first read until staff finish.
 */
export function QrScanner({ desk }: { desk: string }) {
  const video = useRef<HTMLVideoElement>(null);
  const [phase, setPhase] = useState<Phase>(() => (hasCamera() ? 'starting' : 'blocked'));
  const [problem, setProblem] = useState(() => (hasCamera() ? '' : NO_HTTPS));
  const [cameras, setCameras] = useState<MediaDeviceInfo[]>([]);
  const [camera, setCamera] = useState(0);
  /** Changing it (re)opens the camera; null keeps it closed. */
  const [session, setSession] = useState<number | null>(() => (hasCamera() ? 0 : null));
  const [code, setCode] = useState('');
  const [ticket, setTicket] = useState<ScannedTicket | null>(null);
  const [done, setDone] = useState('');
  const [typed, setTyped] = useState('');
  // The camera opens asynchronously; a ticket typed in meanwhile must not be overwritten.
  const phaseNow = useRef(phase);
  useEffect(() => {
    phaseNow.current = phase;
  }, [phase]);

  const fail = useCallback((message: string) => {
    setProblem(message);
    setTicket(null);
    setPhase('invalid');
  }, []);

  /** Step 1: Laravel identifies the ticket without changing anything. */
  const verify = useCallback(
    async (value: string) => {
      video.current?.pause();
      setCode(value);
      setTicket(null);
      setPhase('verifying');
      const result = await postJson<{ ticket: ScannedTicket }>('/petugas/scan', { code: value });
      if (!result.ok) return fail(result.message);
      setTicket(result.data.ticket);
      setPhase('found');
    },
    [fail],
  );

  // Opens the chosen camera for each session and releases it when the session ends.
  useEffect(() => {
    if (session === null) return;
    let cancelled = false;
    let media: MediaStream | null = null;
    const deviceId = cameras[camera]?.deviceId;
    navigator.mediaDevices
      .getUserMedia({
        video: deviceId ? { deviceId: { exact: deviceId } } : { facingMode: 'environment' },
        audio: false,
      })
      .then(async (opened) => {
        media = opened;
        if (cancelled) return opened.getTracks().forEach((track) => track.stop());
        // Labels and ids are only available once permission is granted.
        const devices = await navigator.mediaDevices.enumerateDevices();
        setCameras(devices.filter((device) => device.kind === 'videoinput'));
        video.current!.srcObject = opened;
        await video.current!.play();
        if (!cancelled && phaseNow.current === 'starting') setPhase('scanning');
      })
      .catch((error) => {
        if (cancelled || phaseNow.current !== 'starting') return;
        setProblem(cameraProblem(error));
        setPhase('blocked');
      });
    return () => {
      cancelled = true;
      media?.getTracks().forEach((track) => track.stop());
    };
    // The camera list is read, not watched: it is filled in by this effect.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, camera]);

  // While scanning, reads a frame about five times a second until a QR is found.
  useEffect(() => {
    if (phase !== 'scanning') return;
    const element = video.current!;
    element.play().catch(() => undefined);
    const canvas = document.createElement('canvas');
    let timer = 0;
    let stopped = false;
    // Loaded on demand so the decoder is not part of every patient's download.
    import('jsqr').then(({ default: jsQR }) => {
      if (stopped) return;
      const read = () => {
        if (element.readyState >= element.HAVE_ENOUGH_DATA && element.videoWidth) {
          const scale = Math.min(1, 640 / element.videoWidth);
          canvas.width = Math.round(element.videoWidth * scale);
          canvas.height = Math.round(element.videoHeight * scale);
          const context = canvas.getContext('2d', { willReadFrequently: true })!;
          context.drawImage(element, 0, 0, canvas.width, canvas.height);
          const frame = context.getImageData(0, 0, canvas.width, canvas.height);
          const found = jsQR(frame.data, frame.width, frame.height, {
            inversionAttempts: 'dontInvert',
          });
          if (found?.data) return verify(found.data);
        }
        timer = window.setTimeout(read, 200);
      };
      read();
    });
    return () => {
      stopped = true;
      window.clearTimeout(timer);
    };
  }, [phase, verify]);

  function rescan() {
    setTicket(null);
    setProblem('');
    if (session === null || phase === 'blocked') {
      setPhase('starting');
      setSession((session ?? 0) + 1);
    } else setPhase('scanning');
  }
  /** Step 2: record the arrival. */
  async function confirm() {
    setPhase('confirming');
    const result = await postJson<{ message: string }>('/petugas/check-in', { code });
    if (!result.ok) return fail(result.message);
    setDone(result.data.message);
    setPhase('done');
    // Refresh the dashboard lists and counters with the new arrival.
    router.reload();
  }
  function search(event: FormEvent) {
    event.preventDefault();
    if (typed.trim()) verify(typed.trim());
  }
  const live = !['starting', 'off', 'blocked'].includes(phase);

  return (
    <div className="scan-page">
      <div className="scan-title">
        <div>
          <h1>Scan QR kedatangan</h1>
          <p>Pindai tiket pasien, cocokkan data, lalu konfirmasi check-in.</p>
        </div>
        <span className="scan-mode">{desk.toUpperCase().replace('LANTAI', 'LT.')}</span>
      </div>
      <div className="scan-columns">
        <section className="scan-card scanner" aria-label="Pemindai QR">
          <div className="scan-camera-row">
            <h2>{cameras[camera]?.label || 'Kamera meja check-in'}</h2>
            <button
              className="scan-link"
              disabled={cameras.length < 2 || phase === 'verifying'}
              onClick={() => {
                setTicket(null);
                setPhase('starting');
                setCamera((camera + 1) % cameras.length);
                setSession((session ?? 0) + 1);
              }}
            >
              Ganti kamera
            </button>
          </div>
          <div className="scan-preview">
            <video ref={video} muted playsInline aria-hidden="true" hidden={!live} />
            <p className="scan-preview-label">PRATINJAU PEMINDAIAN</p>
            {live && <span className="scan-frame" aria-hidden="true" />}
            <p className="scan-preview-caption">
              {phase === 'starting'
                ? 'Membuka kamera…'
                : phase === 'scanning'
                  ? 'Arahkan QR tiket pasien ke dalam bingkai'
                  : phase === 'verifying'
                    ? 'Memeriksa tiket…'
                    : phase === 'found' || phase === 'confirming'
                      ? 'QR terdeteksi · periksa data di sebelah kanan'
                      : phase === 'off'
                        ? 'Kamera ditutup'
                        : phase === 'blocked'
                          ? 'Kamera tidak tersedia'
                          : ''}
            </p>
          </div>
          <div
            className={`scan-status ${phase === 'invalid' || phase === 'blocked' ? 'problem' : ''}`}
            role="status"
            aria-live="polite"
          >
            {phase === 'found' || phase === 'confirming' ? (
              <>
                <strong>✓ QR berhasil dibaca</strong>
                <p>Pemindaian dijeda hingga konfirmasi selesai.</p>
              </>
            ) : phase === 'done' ? (
              <>
                <strong>✓ {done}</strong>
                <p>Pasien masuk antrean dokter. Pindai pasien berikutnya.</p>
              </>
            ) : phase === 'invalid' ? (
              <>
                <strong>QR tidak dapat dipakai</strong>
                <p>{problem}</p>
              </>
            ) : phase === 'blocked' ? (
              <>
                <strong>Kamera tidak tersedia</strong>
                <p>{problem}</p>
              </>
            ) : phase === 'verifying' ? (
              <>
                <strong>Memeriksa tiket…</strong>
                <p>Data dicocokkan dengan antrean hari ini.</p>
              </>
            ) : phase === 'off' ? (
              <>
                <strong>Kamera ditutup</strong>
                <p>Buka kamera lagi dengan Pindai ulang, atau ketik nomor check-in.</p>
              </>
            ) : (
              <>
                <strong>Siap memindai</strong>
                <p>Tiket dibaca otomatis saat QR terlihat jelas.</p>
              </>
            )}
          </div>
          <div className="scan-controls">
            <button className="scan-button" disabled={phase === 'verifying'} onClick={rescan}>
              {phase === 'off' || phase === 'blocked' ? 'Buka kamera' : 'Pindai ulang'}
            </button>
            <button
              className="scan-button"
              disabled={['starting', 'off', 'blocked'].includes(phase)}
              onClick={() => {
                setSession(null);
                setPhase('off');
              }}
            >
              Tutup kamera
            </button>
          </div>
        </section>
        <section className="scan-card result" aria-label="Hasil pemindaian" aria-live="polite">
          <p className="scan-eyebrow">HASIL PEMINDAIAN</p>
          {ticket ? (
            <>
              <h2>{ticket.name}</h2>
              <div className="scan-ticket">
                <p className="scan-eyebrow">NOMOR ANTREAN</p>
                <strong>{ticket.ticket}</strong>
                <span>{ticket.code}</span>
              </div>
              <dl>
                <div>
                  <dt>Poliklinik</dt>
                  <dd>{ticket.clinic}</dd>
                </div>
                <div>
                  <dt>Dokter dan jadwal</dt>
                  <dd>
                    {ticket.doctor}
                    <br />
                    {ticket.schedule}
                  </dd>
                </div>
                <div>
                  <dt>Tanggal kunjungan</dt>
                  <dd>{ticket.date.replace(/^[^,]+, /, '')}</dd>
                </div>
              </dl>
              {phase === 'done' ? (
                <>
                  <p className="scan-arrival done">Sudah check-in · tercatat sekali</p>
                  <button className="scan-confirm" onClick={rescan}>
                    Pindai pasien berikutnya
                  </button>
                </>
              ) : ticket.state === 'checked_in' ? (
                <>
                  <p className="scan-arrival done" role="alert">
                    Sudah check-in pukul {ticket.checkedInAt}
                    {ticket.checkedInBy ? ` · dikonfirmasi ${ticket.checkedInBy}` : ''}
                  </p>
                  <p className="scan-note">Kehadiran sudah tercatat. Tidak ada yang diubah.</p>
                  <button className="scan-confirm" onClick={rescan}>
                    Pindai pasien berikutnya
                  </button>
                </>
              ) : ticket.state === 'no_show' ? (
                <>
                  <p className="scan-arrival" role="alert">
                    Tidak hadir · tiket tidak dapat dipakai lagi
                  </p>
                  <p className="scan-note">Arahkan pasien ke petugas pendaftaran.</p>
                  <button className="scan-confirm" onClick={rescan}>
                    Pindai pasien berikutnya
                  </button>
                </>
              ) : (
                <>
                  <p className="scan-arrival">
                    Belum check-in{ticket.deadline ? ` · batas ${ticket.deadline}` : ''}
                  </p>
                  <p className="scan-note">
                    Cocokkan nama dan tujuan poli dengan pasien sebelum konfirmasi.
                  </p>
                  <button
                    className="scan-confirm"
                    disabled={phase === 'confirming'}
                    onClick={confirm}
                  >
                    {phase === 'confirming' ? 'Mencatat…' : 'Konfirmasi check-in'}
                  </button>
                  <button className="scan-link center" onClick={rescan}>
                    Batalkan pemindaian
                  </button>
                </>
              )}
            </>
          ) : (
            <p className="scan-empty">
              Data pasien tampil di sini setelah QR terbaca. Tidak ada yang dicatat sebelum Anda
              menekan Konfirmasi check-in.
            </p>
          )}
        </section>
      </div>
      <form className="scan-card scan-manual" onSubmit={search}>
        <div>
          <h2>QR sulit terbaca?</h2>
          <p>Masukkan nomor check-in pada tiket pasien.</p>
        </div>
        <label>
          <span className="sr-only">Nomor check-in</span>
          <input
            value={typed}
            onChange={(e) => setTyped(e.target.value)}
            placeholder="Contoh: HT-000027"
            autoComplete="off"
            autoCapitalize="characters"
          />
        </label>
        <button className="scan-button" disabled={!typed.trim() || phase === 'verifying'}>
          Cari tiket
        </button>
      </form>
      <p className="scan-footnote">
        Check-in hanya dicatat setelah konfirmasi. Tiket yang sudah check-in tidak akan dicatat dua
        kali.
      </p>
    </div>
  );
}
