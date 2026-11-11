import { router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { Visit } from '@/types';

/** An active visit still needs the patient's attention; finished ones do not. */
export function isActive(visit: Visit | null): visit is Visit {
  return !!visit && visit.stage !== 'completed' && visit.stage !== 'no_show';
}

/** "Ruang A-204 · Gedung A, lantai 2" → ["Ruang A-204", "Gedung A, lantai 2"]. */
export function splitRoom(room: string | null): [string, string] {
  const [name, ...rest] = (room ?? 'Lokasi dikonfirmasi petugas').split(' · ');
  return [name, rest.join(' · ')];
}

/**
 * Live Queue Tracking: re-fetches the page props on an interval. This is polling,
 * not a push connection, so data can be up to `pollSeconds` old.
 */
export function useLive() {
  const { pollSeconds, now } = usePage().props;
  const [online, setOnline] = useState(true);
  usePoll(pollSeconds * 1000, {
    onSuccess: () => setOnline(true),
    onNetworkError: () => {
      setOnline(false);
      return false;
    },
  });
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
  return { online, syncedAt: now };
}

/** Runs a Laravel action and exposes whether it is still in flight. */
export function useAction() {
  const [busy, setBusy] = useState(false);
  const run = (method: 'post' | 'delete', url: string, data: Record<string, string | number>) =>
    router.visit(url, {
      method,
      data,
      preserveScroll: true,
      // Keep local UI state (open tab, search, toggles) when the page reloads after an action.
      preserveState: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
    });
  return {
    busy,
    post: (url: string, data: Record<string, string | number> = {}) => run('post', url, data),
    destroy: (url: string) => run('delete', url, {}),
  };
}
