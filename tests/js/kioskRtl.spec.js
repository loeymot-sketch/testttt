import { nextTick } from 'vue';
import i18n, { setLocale } from '../../resources/js/i18n.js';
import { beforeEach, describe, expect, it } from 'vitest';

describe('P-MEGA-10 kiosk RTL — document dir/lang via i18n', () => {
    beforeEach(() => {
        setLocale('fr');
    });

    it('document.documentElement.dir est rtl quand locale = ar', () => {
        setLocale('ar');
        expect(document.documentElement.dir).toBe('rtl');
    });

    it('document.documentElement.dir repasse ltr quand locale = fr (retour depuis rtl)', () => {
        setLocale('ar');
        setLocale('fr');
        expect(document.documentElement.dir).toBe('ltr');
    });

    it('document.documentElement.lang est ar après switch vers ar', () => {
        setLocale('ar');
        expect(document.documentElement.lang).toBe('ar');
    });

    it('assignation directe de locale synchronise dir (watch i18n)', async () => {
        setLocale('fr');
        i18n.global.locale.value = 'ar';
        await nextTick();
        expect(document.documentElement.dir).toBe('rtl');
        i18n.global.locale.value = 'fr';
        await nextTick();
        expect(document.documentElement.dir).toBe('ltr');
    });
});
