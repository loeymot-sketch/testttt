/**
 * Kiosk — KioskIdleScreenComponent FR-lock policy regression
 *
 * Vérifie l'application de l'ADR-007 (FR-lock V1, cf.
 * `docs/ADR/ADR-2026-05-09-kiosk-locale-policy.md`) :
 *
 *  - `voiceLang` computed retourne TOUJOURS `'fr-FR'` (constante FR-lock,
 *    indépendamment de la locale courante de vue-i18n).
 *  - Le sélecteur de langue `[data-testid="kiosk-idle-lang-selector"]`
 *    n'est JAMAIS rendu, même quand `frontendSetting/lists` retourne
 *    `kiosk_languages_enabled: ['fr','en','ar']` (test discriminant : on
 *    s'assure que la branche `enabledLanguages.length > 1` est défaite par
 *    le `v-if="false"` ADR-007, et pas seulement par la valeur par défaut
 *    de `enabledLanguages`).
 *
 * Ces tests sont une régression strictement défensive : ils font foi du
 * contrat FR-lock V1 et doivent rester rouges si quelqu'un retire le
 * `v-if="false"` ou réintroduit le switch `currentLocale → en-US/ar-SA`
 * sans passer par la phase BACKLOG V2 documentée dans l'ADR-007.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

// Le module `resources/js/i18n.js` utilise `require.context` (webpack-only)
// pour charger dynamiquement les fichiers de langue ; ça plante sous vitest.
// On stub `setLocale` / `getCurrentLocale` directement — `KioskIdleScreenComponent`
// les importe mais sous FR-lock V1 `setLocale` n'est plus appelé. La valeur
// retournée par `getCurrentLocale` peut être pilotée via `__mockLocale` pour
// vérifier que `voiceLang` reste `'fr-FR'` même si la locale courante diverge
// (test discriminant ADR-007).
const i18nMock = {
    __mockLocale: 'fr',
    setLocale: vi.fn(),
    getCurrentLocale: vi.fn(() => i18nMock.__mockLocale),
};
vi.mock('../../resources/js/i18n', () => ({
    setLocale: (...args) => i18nMock.setLocale(...args),
    getCurrentLocale: () => i18nMock.getCurrentLocale(),
}));

// eslint-disable-next-line import/first
import KioskIdleScreenComponent from '../../resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue';
// eslint-disable-next-line import/first
import frMessages from '../../resources/js/languages/fr.json';

function makeI18n(locale = 'fr') {
    return createI18n({
        legacy: false,
        locale,
        fallbackLocale: 'fr',
        messages: { fr: frMessages },
    });
}

function makeStoreMock(frontendSettingsData = {}) {
    return {
        // Le composant appelle :
        //  - kioskCart/reset (mounted)
        //  - frontendSetting/lists (loadSettings)
        //  - kioskSettings/setLocale (changeLanguage — désactivé sous FR-lock)
        state: { kioskSettings: { voiceOrderingEnabled: false } },
        dispatch: vi.fn().mockImplementation((action) => {
            if (action === 'frontendSetting/lists') {
                return Promise.resolve({ data: { data: frontendSettingsData } });
            }
            return Promise.resolve();
        }),
    };
}

function mountIdle(opts = {}) {
    const { locale = 'fr', frontendSettingsData = {} } = opts;
    // Pilote le mock i18n.getCurrentLocale() pour ce test.
    i18nMock.__mockLocale = locale;
    const $store = makeStoreMock(frontendSettingsData);
    const wrapper = mount(KioskIdleScreenComponent, {
        global: {
            plugins: [makeI18n(locale)],
            mocks: {
                $router: { push: vi.fn().mockResolvedValue() },
                $store,
            },
            stubs: {
                // Sous-composants additifs non testés ici — on les stub pour
                // isoler la régression FR-lock.
                KsA11ySettings: true,
                KioskVoiceOrderingButton: true,
                KioskVoiceOrderingDialog: true,
            },
        },
    });
    return { wrapper, $store };
}

beforeEach(() => {
    // Pas de spy axios nécessaire : KioskIdleScreenComponent passe par
    // $store.dispatch('frontendSetting/lists') qu'on mocke ci-dessus.
});

describe('KioskIdleScreenComponent — FR-lock policy (ADR-007)', () => {
    it('voiceLang retourne toujours "fr-FR" (constante FR-lock, locale fr)', () => {
        const { wrapper } = mountIdle({ locale: 'fr' });
        expect(wrapper.vm.voiceLang).toBe('fr-FR');
    });

    it('voiceLang reste "fr-FR" même si l\'i18n locale a été modifiée (dead branch en-US/ar-SA)', () => {
        // Sous FR-lock V1 : `ensureKioskLocale()` reforce `fr` à chaque nav,
        // mais même en mountant directement le composant avec `locale: 'en'`
        // ou `locale: 'ar'` (cas pathologique post-bootstrap pré-nav),
        // `voiceLang` doit retourner `fr-FR` — c'est le contrat ADR-007.
        const { wrapper: wEn } = mountIdle({ locale: 'en' });
        expect(wEn.vm.voiceLang).toBe('fr-FR');

        const { wrapper: wAr } = mountIdle({ locale: 'ar' });
        expect(wAr.vm.voiceLang).toBe('fr-FR');
    });

    it('le sélecteur de langue est masqué même quand kiosk_languages_enabled liste 3 langues (test discriminant)', async () => {
        // Test DISCRIMINANT : on simule un setting backend qui retourne
        // explicitement les 3 langues activées. Avant ADR-007, le `v-if`
        // était `enabledLanguages.length > 1` → le sélecteur aurait été
        // rendu. Sous FR-lock, le `v-if="false"` doit court-circuiter
        // peu importe la valeur de `enabledLanguages`.
        const { wrapper } = mountIdle({
            locale: 'fr',
            frontendSettingsData: {
                kiosk_languages_enabled: ['fr', 'en', 'ar'],
            },
        });

        // Laisse loadSettings() résoudre + Vue re-render.
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Sanity : le mock a bien injecté la liste 3-langues.
        expect(wrapper.vm.enabledLanguages).toEqual(['fr', 'en', 'ar']);

        // Contract FR-lock : le sélecteur n'est JAMAIS rendu, malgré
        // `enabledLanguages.length > 1`.
        const selector = wrapper.find('[data-testid="kiosk-idle-lang-selector"]');
        expect(selector.exists()).toBe(false);
    });

    it('le sélecteur reste masqué avec la valeur par défaut enabledLanguages=["fr","en"]', async () => {
        const { wrapper } = mountIdle({ locale: 'fr', frontendSettingsData: {} });
        await flushPromises();
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="kiosk-idle-lang-selector"]').exists()).toBe(false);
    });
});
