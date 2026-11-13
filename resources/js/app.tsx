import '@fontsource/plus-jakarta-sans/latin-400.css';
import '@fontsource/plus-jakarta-sans/latin-500.css';
import '@fontsource/plus-jakarta-sans/latin-600.css';
import '@fontsource/plus-jakarta-sans/latin-700.css';
// Inter is used by the sign-in and sign-up screens, as in their Figma frames.
import '@fontsource/inter/latin-400.css';
import '@fontsource/inter/latin-500.css';
import '@fontsource/inter/latin-600.css';
import '@fontsource/inter/latin-700.css';
import '../css/app.css';
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
