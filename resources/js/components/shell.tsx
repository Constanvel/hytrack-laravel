import { Link, usePage } from '@inertiajs/react';
import { Icon, Avatar, Notice } from './ui';
import type { ReactNode } from 'react';
import { GuideHint, useGuide, type GuideStep } from '@/features/guide/guide';

export function Shell({
  title,
  subtitle,
  back,
  children,
  hya = false,
  queue = false,
  bare = false,
  guide,
  decor,
  brand = false,
}: {
  title: string;
  subtitle?: string;
  back?: string;
  children: ReactNode;
  hya?: boolean;
  queue?: boolean;
  /** No navigation, bell or Hya: for the sign-in screen and the public companion link. */
  bare?: boolean;
  guide?: GuideStep;
  /** Background accent from the design: mint waves above the nav, or a corner wave behind Hya. */
  decor?: 'waves' | 'corner';
  /** Header shows the HyTrack logo and tagline (as on sign-in) instead of title and subtitle. */
  brand?: boolean;
}) {
  const { url, props, flash } = usePage();
  const path = url.split('?')[0];
  const { visible: guiding } = useGuide(guide);
  const active =
    path === '/'
      ? 'home'
      : path.startsWith('/akun') || path.startsWith('/bantuan')
        ? 'user'
        : 'ticket';
  return (
    <div
      className={`app-shell ${hya ? 'chat-shell' : ''} ${queue ? 'queue-shell' : ''} ${guiding ? 'guide-active' : ''} ${decor ? `decor-${decor}` : ''}`}
    >
      <div className="demo-banner">Data contoh · Tidak terhubung ke sistem rumah sakit</div>
      <header className="page-header">
        {back && (
          <Link className="icon-button back" href={back} aria-label="Kembali">
            <Icon name="back" />
          </Link>
        )}
        {hya && <Avatar />}
        {brand ? (
          <div className="lockup">
            <img src="/assets/logo-hytrack.png" alt="" width={72} height={54} />
            <div>
              <h1 className="lockup-name">{title}</h1>
              <p className="lockup-tagline">TEMAN PERJALANAN BEROBAT</p>
            </div>
          </div>
        ) : (
          <div className="heading">
            <h1>{title}</h1>
            {subtitle && <p>{subtitle}</p>}
          </div>
        )}
        {hya ? (
          <Link href="/kunjungan" className="icon-button close" aria-label="Tutup Hya">
            <Icon name="close" />
          </Link>
        ) : (
          !back &&
          !bare && (
            <Link
              href="/notifikasi"
              className={`icon-button ${props.unread ? 'has-unread' : ''}`}
              aria-label={props.unread ? `Notifikasi, ${props.unread} belum dibaca` : 'Notifikasi'}
            >
              <Icon name="bell" />
            </Link>
          )
        )}
      </header>
      <main id="main" className="page-content">
        {flash.error && (
          <Notice tone="warning" role="alert">
            {flash.error}
          </Notice>
        )}
        {flash.status && <Notice role="status">{flash.status}</Notice>}
        {guide && <GuideHint step={guide} />}
        {children}
      </main>
      {!hya && !bare && (
        <Link href="/hya" className="hya-fab" aria-label="Tanya Hya">
          <Avatar large />
        </Link>
      )}
      {!hya && !bare && (
        <nav className="bottom-nav" aria-label="Navigasi utama">
          {[
            { href: '/', icon: 'home', label: 'Beranda' },
            { href: '/kunjungan', icon: 'ticket', label: 'Kunjungan' },
            { href: '/akun', icon: 'user', label: 'Akun' },
          ].map((item) => (
            <Link
              href={item.href}
              key={item.href}
              aria-current={active === item.icon ? 'page' : undefined}
            >
              <span className="nav-marker">
                <Icon name={item.icon} />
              </span>
              <span>{item.label}</span>
            </Link>
          ))}
        </nav>
      )}
    </div>
  );
}
export function HyaHelp() {
  return (
    <Link href="/hya" className="hya-help">
      <span>
        <strong>Ada yang bisa Hya bantu?</strong>
        <small>Tanya Hya</small>
      </span>
    </Link>
  );
}
