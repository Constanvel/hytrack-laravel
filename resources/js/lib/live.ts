import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Visit } from '@/types';

/** An active visit still needs the patient's attention; finished ones do not. */
export function isActive(visit: Visit | null): visit is Visit {
  return (
    !!visit &&
    visit.stage !== 'completed' &&
    visit.stage !== 'no_show' &&
    visit.stage !== 'cancelled'
  );
}

/** "Ruang A-204 · Gedung A, lantai 2" → ["Ruang A-204", "Gedung A, lantai 2"]. */
export function splitRoom(room: string | null): [string, string] {
  const [name, ...rest] = (room ?? 'Lokasi dikonfirmasi petugas').split(' · ');
  return [name, rest.join(' · ')];
}

/** Non-background visits (form posts, navigation) currently running. Reloads are async. */
let actionsInFlight = 0;
let watching = false;
function watchActions() {
  if (watching) return;
  watching = true;
  router.on('start', (event) => {
    if (!event.detail.visit.async) actionsInFlight++;
  });
  router.on('finish', (event) => {
    if (!event.detail.visit.async) actionsInFlight = Math.max(0, actionsInFlight - 1);
  });
}

/** CSRF header for fetch() calls, from Laravel's XSRF-TOKEN cookie (as Inertia does). */
export function csrfHeader(): Record<string, string> {
  const token = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];
  return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {};
}

/**
 * POSTs JSON and returns the server's answer directly, without session flash data, so the
 * result cannot be picked up by a background refresh. Used by the check-in scanner.
 */
export async function postJson<T>(
  url: string,
  data: Record<string, string>,
): Promise<{ ok: true; data: T } | { ok: false; message: string }> {
  let response: Response;
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...csrfHeader(),
      },
      body: JSON.stringify(data),
    });
  } catch {
    return { ok: false, message: 'Koneksi ke server terputus. Coba lagi.' };
  }
  const body = await response.json().catch(() => ({}));
  if (response.ok) return { ok: true, data: body as T };
  const firstError = body.errors ? (Object.values(body.errors)[0] as string[])[0] : null;
  return {
    ok: false,
    message:
      response.status === 419
        ? 'Sesi berakhir. Muat ulang halaman lalu masuk kembali.'
        : response.status === 403
          ? 'Akses ditolak. Hanya petugas yang dapat mencatat kehadiran.'
          : (firstError ?? body.message ?? 'Permintaan gagal diproses.'),
  };
}

/**
 * Live Queue Tracking: re-fetches the page props on an interval. This is polling,
 * not a push connection, so data can be up to `pollSeconds` old.
 */
export function useLive() {
  // syncedAt is the server time of the last response that arrived, so it stays put while
  // the connection is down.
  const { pollSeconds, syncedAt } = usePage().props;
  const [online, setOnline] = useState(true);
  useEffect(() => {
    watchActions();
    const timer = window.setInterval(() => {
      // A refresh landing between an action and its redirect would take that action's
      // flash message (and its session write could undo it), so wait for the next tick.
      if (actionsInFlight > 0) return;
      router.reload({
        onSuccess: () => setOnline(true),
        onNetworkError: () => {
          setOnline(false);
          return false;
        },
      });
    }, pollSeconds * 1000);
    return () => window.clearInterval(timer);
  }, [pollSeconds]);
  useEffect(() => {
    const up = () => setOnline(true);
    const down = () => setOnline(false);
    window.addEventListener('online', up);
    window.addEventListener('offline', down);
    return () => {
      window.removeEventListener('online', up);
      window.removeEventListener('offline', down);
    };
  }, []);
  return { online, syncedAt };
}

/** "● Terhubung · Diperbarui 09.12.45" or "○ Koneksi terputus · Data terakhir 09.12.45". */
export function syncLabel(live: { online: boolean; syncedAt: string }) {
  return live.online
    ? `● Terhubung · Diperbarui ${live.syncedAt}`
    : `○ Koneksi terputus · Data terakhir ${live.syncedAt}`;
}

/** Runs a Laravel action and exposes whether it is still in flight. */
export function useAction() {
  const [busy, setBusy] = useState(false);
  // Validation errors and lost connections. Rule violations arrive as flash.error instead.
  const [error, setError] = useState('');
  const run = (
    method: 'post' | 'delete',
    url: string,
    data: Record<string, string | number>,
    onSuccess?: () => void,
  ) =>
    router.visit(url, {
      method,
      data,
      preserveScroll: true,
      // Keep local UI state (open tab, search, toggles) when the page reloads after an action.
      preserveState: true,
      onStart: () => {
        setBusy(true);
        setError('');
      },
      onFinish: () => setBusy(false),
      onSuccess: (page) => {
        if (!page.flash.error) onSuccess?.();
      },
      onError: (errors) => setError(Object.values(errors)[0] ?? 'Permintaan gagal diproses.'),
      onNetworkError: () => {
        setError('Koneksi ke server terputus. Perubahan belum tersimpan; coba lagi.');
        return false;
      },
    });
  return {
    busy,
    error,
    clearError: () => setError(''),
    send: run,
    post: (url: string, data: Record<string, string | number> = {}) => run('post', url, data),
    destroy: (url: string) => run('delete', url, {}),
  };
}
