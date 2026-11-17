import './styles';
import { createInertiaApp } from '@inertiajs/react';
import { pages } from './pages';

createInertiaApp({
  resolve: (name) => {
    const page = pages[name];
    if (!page) throw new Error(`Halaman Inertia "${name}" belum terdaftar di pages.ts`);
    return page;
  },
  progress: { color: '#0b7268' },
});
