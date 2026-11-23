/**
 * The first-load splash (resources/views/partials/splash.blade.php). Its loader moves on real
 * milestones only (painted with the HTML, this bundle running, the first page rendered), and the
 * exit starts the moment the app is ready, from wherever the intro is: there is no minimum time.
 */
const find = () => document.getElementById('splash');

// Milestone two: the app bundle is running.
find()?.style.setProperty('--p', '0.6');

const ease = 'cubic-bezier(0.65, 0, 0.35, 1)';
// The band's slanted left edge, as in the CSS: 300 of 916 in the Figma frame.
const edge = (x: string) => `polygon(${x} 0, 100% 0, 100% 100%, calc(${x} + 32.75vh) 100%)`;

export function hideSplash() {
  const splash = find();
  if (!splash) return;
  // A background tab pauses animation frames, and nobody sees the splash there anyway.
  if (document.hidden) return splash.remove();
  splash.style.setProperty('--p', '1');
  // Two frames, so the first page has painted underneath before the splash goes.
  requestAnimationFrame(() =>
    requestAnimationFrame(() =>
      matchMedia('(prefers-reduced-motion: reduce)').matches ? splash.remove() : leave(splash),
    ),
  );
}

function leave(splash: HTMLElement) {
  const part = (selector: string) => splash.querySelector<HTMLElement>(selector)!;
  // Implicit start values: each part leaves from wherever its intro has got to.
  for (const selector of ['.splash-name', '.splash-tagline', '.splash-load']) {
    part(selector).animate({ opacity: 0 }, { duration: 220, easing: 'ease-out', fill: 'forwards' });
  }
  // The mark settles in the centre, larger, as the teal band reaches it (Figma 43:218).
  const lockup = part('.splash-lockup');
  const shift = getComputedStyle(lockup).getPropertyValue('--shift');
  part('.splash-mark').animate(
    { transform: `translateX(${shift}) scale(1.46)` },
    { duration: 420, easing: ease, fill: 'forwards' },
  );
  // The band crosses the screen and its trailing edge uncovers the page: the splash is clipped
  // along the same slanted line, on the same timing.
  const from = '-104.8vh';
  const to = '100vw';
  const timing: KeyframeAnimationOptions = {
    duration: 600,
    delay: 100,
    easing: ease,
    fill: 'forwards',
  };
  part('.splash-sweep-out').animate(
    { transform: [`translateX(${from})`, `translateX(${to})`] },
    timing,
  );
  const remove = () => splash.remove();
  splash.animate({ clipPath: [edge(from), edge(to)] }, timing).finished.then(remove, remove);
  setTimeout(remove, 1500);
}
