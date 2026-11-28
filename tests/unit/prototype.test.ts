import { describe, expect, it } from 'vitest';
import { initialState, respond, type State } from '../../resources/js/prototype/server';

/** Drives the mock backend like the browser would, carrying state between requests. */
function session() {
  let state: State = initialState;
  let at = '/masuk';
  return (method: string, path: string, body = {}) => {
    const result = respond(state, method, path, body, at);
    state = result.state;
    at = result.page.url.replace('/prototipe', '') || '/';
    return { ...result.page, step: state.step };
  };
}
const stage = (page: { props: Record<string, unknown> }) =>
  (page.props.visit as { stage: string } | null)?.stage ?? null;

describe('prototype mode', () => {
  it('answers a polyclinic deletion without touching the sample visit', () => {
    const visit = session();
    visit('POST', '/masuk/internal', { username: 'petugas' });
    const page = visit('DELETE', '/petugas/poliklinik/1');
    expect(page.flash.error).toBeUndefined();
    expect(page.flash.status).toContain('tidak disimpan');
    expect(page.step).toBe(0);
  });

  it('sends guests to the sign-in screen with the prototype toggle on', () => {
    const page = session()('GET', '/kunjungan');
    expect(page.component).toBe('Login');
    expect(page.url).toBe('/prototipe/masuk');
    expect(page.props.prototype).toBe(true);
  });

  it('offers the staff and manager samples on the internal sign-in only', () => {
    const page = session()('GET', '/masuk/internal');
    expect(page.component).toBe('InternalLogin');
    expect(page.url).toBe('/prototipe/masuk/internal');
    expect(page.props.prototype).toBe(true);
  });

  it('walks one visit from booking to pickup across every role', () => {
    const visit = session();
    expect(visit('POST', '/masuk', { username: '0812-5550-0842' }).component).toBe('Home');
    expect(stage(visit('GET', '/'))).toBeNull();

    const booked = visit('POST', '/kunjungan', { doctor_id: 2, date: '2026-11-17' });
    expect(booked.url).toBe('/prototipe/kunjungan');
    expect(stage(booked)).toBe('booked');

    visit('GET', '/pendamping');
    const invited = visit('POST', '/pendamping');
    expect(String(invited.flash.shareLink)).toContain('/prototipe/pantauan/');

    visit('POST', '/keluar');
    expect(visit('POST', '/masuk', { username: '0812-5550-0843' }).props.auth).toMatchObject({
      name: 'Ahmad Fauzi',
    });
    const following = visit('POST', '/pantauan/abc');
    expect(following.url).toBe('/prototipe/pendampingan/1');
    expect(following.component).toBe('CompanionDashboard');

    visit('POST', '/keluar');
    expect(visit('POST', '/masuk/internal', { username: 'dina', as: 'staff' }).component).toBe(
      'Staff',
    );
    expect(visit('POST', '/petugas/scan', { code: 'x' }).flash.scan).toBeTruthy();
    expect(visit('POST', '/petugas/check-in', { code: 'x' }).flash.status).toBeTruthy();
    expect(visit('GET', '/').url).toBe('/prototipe/petugas'); // staff never see the patient home

    // Internal accounts return to their own sign-in page.
    expect(visit('POST', '/keluar').url).toBe('/prototipe/masuk/internal');
    visit('POST', '/masuk', { username: '0812-5550-0842' });
    let page = visit('GET', '/kunjungan');
    expect(stage(page)).toBe('waiting');
    for (let i = 0; i < 10; i++) page = visit('POST', '/kunjungan/simulasi', { action: 'next' });
    expect(stage(page)).toBe('completed');
    expect(page.url).toBe('/prototipe/kunjungan');
  });

  it('keeps each role in its own area', () => {
    const visit = session();
    visit('POST', '/masuk/internal', { username: 'manajer', as: 'manager' });
    expect(visit('GET', '/petugas').url).toBe('/prototipe/manajemen');
    expect(visit('GET', '/manajemen?tanggal=2026-11-17&poli=2').component).toBe('Management');
    visit('POST', '/keluar');
    visit('POST', '/masuk', { username: '0812-5550-0842' });
    expect(visit('GET', '/tidak-ada').component).toBe('ErrorPage');
  });
});
