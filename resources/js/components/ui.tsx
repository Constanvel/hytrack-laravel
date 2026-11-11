import { Link } from '@inertiajs/react';
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
}: {
  href: string;
  children: ReactNode;
  secondary?: boolean;
}) {
  return (
    <Link className={`button ${secondary ? 'secondary' : ''}`} href={href}>
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
export function Steps({ items }: { items: { title: string; detail: string }[] }) {
  return (
    <ol className="steps card">
      {items.map((item, index) => (
        <li key={item.title}>
          <span className="step-number">{index + 1}</span>
          <div>
            <strong>{item.title}</strong>
            <p className="small muted">{item.detail}</p>
          </div>
        </li>
      ))}
    </ol>
  );
}
