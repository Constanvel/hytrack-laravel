import { Link } from '@inertiajs/react';
import qrcode from 'qrcode-generator';
import type { ReactNode } from 'react';

const iconDimensions: Record<string, [number, number]> = {
  arrow: [17.8, 13.8],
  bell: [13.8, 16.8],
  home: [19.8, 19.8],
  ticket: [19.8, 15.8],
  user: [17.8, 20.8],
  send: [19.8, 17.8],
  close: [13.8, 13.8],
};
export function Icon({ name, className = '' }: { name: string; className?: string }) {
  const [width, height] = iconDimensions[name] ?? [24, 24];
  return (
    <span className={`icon ${className}`} aria-hidden="true">
      <img src={`/assets/${name}.svg`} alt="" width={width} height={height} />
    </span>
  );
}
export function Avatar({ large = false }: { large?: boolean }) {
  return (
    <span className={`avatar ${large ? 'avatar-large' : ''}`}>
      <img src="/assets/hya-avatar-source.png" alt="" width={400} height={300} />
    </span>
  );
}
export function ActionLink({
  href,
  children,
  secondary = false,
  className = '',
}: {
  href: string;
  children: ReactNode;
  secondary?: boolean;
  className?: string;
}) {
  return (
    <Link className={`button ${secondary ? 'secondary' : ''} ${className}`} href={href}>
      {children}
    </Link>
  );
}
export function RowLink({
  href,
  children,
  detail,
}: {
  href: string;
  children: ReactNode;
  detail?: string;
}) {
  return (
    <Link className="row-link" href={href}>
      <span>
        <strong>{children}</strong>
        {detail && <small>{detail}</small>}
      </span>
      <Icon name="arrow" />
    </Link>
  );
}
export function Notice({
  children,
  tone = 'green',
  role,
}: {
  children: ReactNode;
  tone?: 'green' | 'warning' | 'info';
  role?: 'status' | 'alert';
}) {
  return (
    <div className={`notice ${tone}`} role={role}>
      {children}
    </div>
  );
}
/**
 * Numbered steps in a marked card. `done` steps before it show a tick and `current` is filled,
 * as in Figma 07 and 08; `numbered` gives the guide's plain "01" style (Figma 23).
 */
export function Steps({
  items,
  done = 0,
  current,
  numbered = false,
}: {
  items: { title: string; detail: string }[];
  done?: number;
  current?: number;
  numbered?: boolean;
}) {
  return (
    <ol className={`steps card ${numbered ? 'numbered' : 'marked'}`}>
      {items.map((item, index) => {
        const state = index < done ? 'done' : index === current ? 'current' : undefined;
        return (
          <li
            key={item.title}
            className={state}
            aria-current={state === 'current' ? 'step' : undefined}
          >
            <span className="step-number" aria-hidden={state === 'done'}>
              {state === 'done' ? '✓' : numbered ? String(index + 1).padStart(2, '0') : index + 1}
            </span>
            <div>
              <strong>
                {state === 'done' && <span className="sr-only">Selesai: </span>}
                {item.title}
              </strong>
              <p className="small muted">{item.detail}</p>
            </div>
          </li>
        );
      })}
    </ol>
  );
}
/** The check-in QR, drawn from the signed payload the server issues for this visit. */
export function TicketQr({ value, label }: { value: string; label: string }) {
  const qr = qrcode(0, 'M');
  qr.addData(value);
  qr.make();
  const size = qr.getModuleCount();
  let path = '';
  for (let row = 0; row < size; row++)
    for (let col = 0; col < size; col++) if (qr.isDark(row, col)) path += `M${col} ${row}h1v1h-1z`;
  return (
    <svg
      className="ticket-qr"
      viewBox={`-4 -4 ${size + 8} ${size + 8}`}
      width={232}
      height={232}
      role="img"
      aria-label={label}
      shapeRendering="crispEdges"
    >
      <rect x={-4} y={-4} width={size + 8} height={size + 8} fill="#fff" />
      <path d={path} fill="#173d3b" />
    </svg>
  );
}
