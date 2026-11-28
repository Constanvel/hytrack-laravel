import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

const FAILED = 'Permintaan gagal diproses server. Belum ada konfirmasi; coba lagi.';
const HTTP_ERRORS: Record<number, string> = {
  403: 'Akun Anda tidak memiliki akses untuk tindakan ini.',
  404: 'Data tidak ditemukan, mungkin sudah dihapus. Tutup lalu muat ulang halaman.',
};

/**
 * The confirmation for every delete (Figma 31:273). A native modal <dialog>, so focus stays
 * inside and Esc cancels. It sends the DELETE itself and stays open until the server accepts.
 */
export function DeleteDialog({
  url,
  title,
  description,
  name,
  details,
  note,
  confirmLabel = 'Hapus data',
  busyLabel = 'Menghapus…',
  cancelLabel = 'Batal',
  onClose,
}: {
  url: string;
  title: string;
  description: string;
  /** The record about to go, and the lines under it on its card. */
  name: string;
  details: string[];
  /** What happens next; a list when there is more than one effect. */
  note: string | string[];
  confirmLabel?: string;
  busyLabel?: string;
  cancelLabel?: string;
  onClose: () => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  const cancel = useRef<HTMLButtonElement>(null);
  // A ref, not state: a second click can arrive before React renders the disabled button.
  const locked = useRef(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  useEffect(() => {
    if (!dialog.current?.open) dialog.current?.showModal();
    cancel.current?.focus();
  }, []);

  /** Plays the closing animation; the dialog's close event then calls onClose. */
  async function close() {
    locked.current = true;
    const node = dialog.current;
    if (!node) return onClose();
    node.classList.add('closing');
    // Empty when the visitor asks for reduced motion, so this resolves at once. The timer
    // covers a page whose frames are throttled: closing must never wait on a repaint.
    await Promise.race([
      Promise.allSettled(node.getAnimations().map((animation) => animation.finished)),
      new Promise((resolve) => setTimeout(resolve, 300)),
    ]);
    node.close(); // the browser returns focus to the button that opened the dialog
    // A deleted record takes that button with it: continue from the top of the page.
    if (document.activeElement === document.body || node.contains(document.activeElement)) {
      const main = document.getElementById('main');
      main?.setAttribute('tabindex', '-1');
      main?.focus({ preventScroll: true });
    }
  }
  function dismiss() {
    if (!locked.current) void close();
  }
  function remove() {
    if (locked.current) return;
    locked.current = true;
    let done = false;
    const fail = (message: string) => {
      setError(message);
      return false;
    };
    router.delete(url, {
      preserveScroll: true,
      onStart: () => {
        setBusy(true);
        setError('');
      },
      onSuccess: (page) => {
        // A broken business rule redirects back with flash.error: nothing was deleted.
        if (page.flash.error) return void fail(String(page.flash.error));
        done = true;
        void close();
      },
      onError: (errors) => void fail(Object.values(errors)[0] ?? FAILED),
      // Returning false keeps this page, instead of swapping in an error screen.
      onHttpException: (response) => fail(HTTP_ERRORS[response.status] ?? FAILED),
      onNetworkError: () =>
        fail('Koneksi ke server terputus. Penghapusan belum terkonfirmasi; coba lagi.'),
      onFinish: () => {
        if (done) return;
        locked.current = false;
        setBusy(false);
      },
    });
  }
  const effects = Array.isArray(note) ? note : [note];
  // On <body>, so no page style can reach in and every screen shows the same dialog.
  return createPortal(
    <dialog
      ref={dialog}
      className="delete-dialog"
      aria-labelledby="delete-title"
      aria-describedby="delete-detail delete-note"
      onKeyDown={(event) => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        dismiss();
      }}
      // Other ways of cancelling, such as the Android back gesture.
      onCancel={(event) => {
        event.preventDefault();
        dismiss();
      }}
      onClose={onClose}
    >
      <span className="delete-mark" aria-hidden="true">
        <img src="/assets/trash.svg" alt="" width={28} height={28} />
      </span>
      <h2 id="delete-title">{title}</h2>
      <p id="delete-detail">{description}</p>
      <div className="delete-target">
        <strong>{name}</strong>
        {details.map((line) => (
          <span key={line}>{line}</span>
        ))}
      </div>
      <ul id="delete-note" className={effects.length > 1 ? 'delete-note list' : 'delete-note'}>
        {effects.map((effect) => (
          <li key={effect}>{effect}</li>
        ))}
      </ul>
      {error && (
        <p className="field-error" role="alert">
          {error}
        </p>
      )}
      {/* aria-disabled keeps focus on the button, so Esc and Tab stay inside while busy. */}
      <div className="delete-actions">
        <button
          ref={cancel}
          type="button"
          className="delete-cancel"
          aria-disabled={busy}
          onClick={dismiss}
        >
          {cancelLabel}
        </button>
        <button type="button" className="delete-confirm" aria-disabled={busy} onClick={remove}>
          {busy ? busyLabel : confirmLabel}
        </button>
      </div>
    </dialog>,
    document.body,
  );
}
