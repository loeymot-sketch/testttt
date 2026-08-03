import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it } from 'vitest';

import { applyKioskA11yFromStore } from '../../resources/js/composables/useKioskA11y.js';
import i18n, { ensureKioskLocale, setLocale } from '../../resources/js/i18n.js';

const langDir = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/languages');

describe('kiosk RTL — document dir/lang via i18n', () => {
  afterEach(() => {
    setLocale('fr');
  });

  it('sets document.documentElement.dir to rtl when locale is ar', () => {
    setLocale('ar');
    expect(document.documentElement.dir).toBe('rtl');
  });

  it('sets document.documentElement.dir to ltr when switching back to fr from rtl', () => {
    setLocale('ar');
    setLocale('fr');
    expect(document.documentElement.dir).toBe('ltr');
  });

  it('sets document.documentElement.lang to ar after switching to ar', () => {
    setLocale('ar');
    expect(document.documentElement.lang).toBe('ar');
  });

  it('ar.json exposes kiosk section used by borne', () => {
    const ar = JSON.parse(readFileSync(join(langDir, 'ar.json'), 'utf8'));
    expect(ar.kiosk).toBeDefined();
    expect(typeof ar.kiosk).toBe('object');
  });

  const kioskSettingsAr = {
    locale: 'ar',
    contrast: 'aa',
    pmr: false,
    audio: false,
    audioDescription: false,
    reducedMotion: false,
  };

  it('reload scenario: store ar + i18n fr → applyKioskA11yFromStore syncs i18n + html', () => {
    setLocale('fr');
    expect(i18n.global.locale.value).toBe('fr');
    const store = { state: { kioskSettings: { ...kioskSettingsAr } } };
    applyKioskA11yFromStore(store);
    expect(i18n.global.locale.value).toBe('ar');
    expect(document.documentElement.dir).toBe('rtl');
    expect(document.documentElement.lang).toBe('ar');
  });

  it('ensureKioskLocale does not override persisted ar after applyKioskA11yFromStore', () => {
    setLocale('fr');
    applyKioskA11yFromStore({ state: { kioskSettings: { ...kioskSettingsAr } } });
    ensureKioskLocale();
    expect(i18n.global.locale.value).toBe('ar');
    expect(document.documentElement.dir).toBe('rtl');
  });
});
