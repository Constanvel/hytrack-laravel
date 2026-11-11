import { readFileSync, statSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect } from 'vitest';
const dimensions: Record<string, [number, number]> = {
  arrow: [17.8, 13.8],
  bell: [13.8, 16.8],
  home: [19.8, 19.8],
  ticket: [19.8, 15.8],
  user: [17.8, 20.8],
  back: [24, 24],
  search: [24, 24],
  heart: [24, 24],
  check: [24, 24],
  send: [19.8, 17.8],
  close: [13.8, 13.8],
  'qr-example': [232, 232],
};
describe('vendored assets', () => {
  for (const [name, [width, height]] of Object.entries(dimensions))
    it(`${name} retains its source geometry`, () => {
      const path = resolve('public/assets', `${name}.svg`);
      expect(statSync(path).size).toBeGreaterThan(100);
      const root = readFileSync(path, 'utf8').match(/<svg[^>]+>/)?.[0];
      expect(root).toContain(`width="${width}"`);
      expect(root).toContain(`height="${height}"`);
    });
  for (const name of ['hya.png', 'hya-avatar-source.png'])
    it(`${name} is a non-empty PNG`, () => {
      const path = resolve('public/assets', name);
      expect(statSync(path).size).toBeGreaterThan(1000);
      expect(readFileSync(path).subarray(1, 4).toString()).toBe('PNG');
    });
});
