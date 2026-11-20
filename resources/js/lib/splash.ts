/**
 * Fades out the first-load splash (resources/views/partials/splash.blade.php) once the first
 * page has painted. No minimum time: it leaves as soon as the app can be used.
 */
export function hideSplash() {
  const splash = document.getElementById('splash');
  if (!splash) return;
  // A background tab pauses animation frames, and nobody sees the splash there anyway.
  if (document.hidden) return splash.remove();
  // Two frames: React has committed the first page and the browser has painted it.
  requestAnimationFrame(() =>
    requestAnimationFrame(() => {
      const remove = () => splash.remove();
      if (matchMedia('(prefers-reduced-motion: reduce)').matches) return remove();
      splash.classList.add('is-done');
      splash.addEventListener('transitionend', remove, { once: true });
      setTimeout(remove, 400); // transitionend never fires in a background tab
    }),
  );
}
