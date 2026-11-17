// Entry for /prototipe: the real screens, answered by an in-browser mock instead of Laravel.
import './styles';
import type { Page } from '@inertiajs/core';
import { createInertiaApp, http } from '@inertiajs/react';
import { pages } from './pages';
import { csv, hya, initialState, PREFIX, respond, type State } from './prototype/server';

const KEY = 'hytrack-prototype';
const strip = (path: string) => path.replace(new RegExp(`^${PREFIX}(?=/|$)`), '') || '/';

function load(): State {
  try {
    return { ...initialState, ...JSON.parse(sessionStorage.getItem(KEY) ?? '{}') };
  } catch {
    return initialState;
  }
}
let state = load();
function save(next: State) {
  state = next;
  try {
    sessionStorage.setItem(KEY, JSON.stringify(next));
  } catch {
    // private window: the prototype still works, it just forgets on reload
  }
}

const here = new URL(location.href);
if (here.searchParams.has('reset')) {
  save(initialState);
  here.searchParams.delete('reset');
}
const first = respond(state, 'GET', strip(here.pathname) + here.search);
save(first.state);
history.replaceState(history.state, '', first.page.url);

function toObject(data: unknown): Record<string, unknown> {
  if (data instanceof FormData) return Object.fromEntries(data);
  if (typeof data === 'string') return data ? JSON.parse(data) : {};
  return (data as Record<string, unknown>) ?? {};
}

http.setClient({
  async request(config) {
    const url = new URL(config.url, location.origin);
    for (const [key, value] of Object.entries(config.params ?? {}))
      url.searchParams.set(key, String(value));
    const from = strip(location.pathname) + location.search;
    const { state: next, page } = respond(
      state,
      config.method,
      strip(url.pathname) + url.search,
      toObject(config.data),
      from,
    );
    save(next);
    return {
      status: 200,
      data: JSON.stringify(page),
      headers: { 'x-inertia': 'true', 'content-type': 'application/json' },
    };
  },
});

// Hya calls fetch('/hya') directly rather than going through Inertia.
const realFetch = window.fetch.bind(window);
window.fetch = async (input, init) => {
  const url = new URL(input instanceof Request ? input.url : String(input), location.origin);
  if (url.pathname !== '/hya') return realFetch(input, init);
  const { question } = JSON.parse(String(init?.body ?? '{}'));
  return new Response(JSON.stringify({ reply: hya(state, question) }), {
    headers: { 'Content-Type': 'application/json' },
  });
};

// The management CSV is a plain download link; hand over the recorded file instead.
document.addEventListener(
  'click',
  (event) => {
    const link = (event.target as Element).closest?.('a[href^="/manajemen/ekspor"]');
    if (!link) return;
    event.preventDefault();
    const query = link.getAttribute('href')!.split('?')[1] ?? '';
    const file = URL.createObjectURL(new Blob([csv(query)], { type: 'text/csv' }));
    const a = Object.assign(document.createElement('a'), {
      href: file,
      download: 'hytrack-laporan-prototype.csv',
    });
    a.click();
    URL.revokeObjectURL(file);
  },
  true,
);

createInertiaApp({
  page: first.page as unknown as Page,
  resolve: (name) => pages[name],
  progress: { color: '#0b7268' },
});
