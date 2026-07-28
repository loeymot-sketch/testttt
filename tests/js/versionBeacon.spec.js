// [VERSION-BEACON 2026-07-28] Bandeau « Mettre à jour » des écrans long-running
// (caisse/KDS/OSS/borne) : hash mix de app.js changé → bandeau ; clic → reload.
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const SRC = readFileSync(resolve(__dirname, '../../public/js/version-beacon.js'), 'utf8');
const flush = () => new Promise((r) => setTimeout(r, 0));

function mockManifest(appJsId) {
  global.fetch = vi.fn(() => Promise.resolve({
    ok: true,
    json: () => Promise.resolve({ '/js/app.js': appJsId }),
  }));
}

describe('version-beacon — écrans long-running', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    delete window.__fkBeaconReload;
  });

  it('même version au boot et au check → AUCUN bandeau', async () => {
    mockManifest('/js/app.js?id=AAA111');
    (0, eval)(SRC);
    await flush();
    document.dispatchEvent(new Event('visibilitychange')); // re-check, id inchangé
    await flush();
    expect(document.getElementById('fk-version-banner')).toBeNull();
  });

  it('hash changé après deploy → bandeau « Mettre à jour » ; clic → reload', async () => {
    const reload = vi.fn();
    window.__fkBeaconReload = reload; // indirection testable (posée AVANT le boot)
    mockManifest('/js/app.js?id=AAA111');
    (0, eval)(SRC);
    await flush(); // bootId = AAA111

    mockManifest('/js/app.js?id=BBB222'); // ← deploy simulé
    document.dispatchEvent(new Event('visibilitychange'));
    await flush();

    const banner = document.getElementById('fk-version-banner');
    expect(banner).not.toBeNull();
    expect(banner.textContent).toContain('Nouvelle version disponible');
    banner.querySelector('button').click();
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('manifest illisible (dev sans build) → silencieux, jamais de bandeau', async () => {
    global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
    (0, eval)(SRC);
    await flush();
    document.dispatchEvent(new Event('visibilitychange'));
    await flush();
    expect(document.getElementById('fk-version-banner')).toBeNull();
  });
});
