/**
 * A1 — Régression i18n KioskConfirmationComponent (P1).
 *
 * Bug pré-existant 25j (commit 660c9341c) : les keys vivaient sous
 * `kiosk.wizard.confirmation.*` mais le composant appelait
 * `$t('kiosk.confirmation.*')` → toutes les chaînes rendues étaient les
 * raw key strings ("kiosk.confirmation.title" au lieu de "Commande
 * confirmée !"). Move des keys vers le namespace top-level `kiosk.confirmation.*`
 * (Option 1 du plan ULTRA_PLAN_CORRECTION_2026-05-08.md §A1).
 *
 * Couverture (3 niveaux) :
 *  1. Schema-only : les keys référencées par le template existent
 *     côté fr/en (AR partial — fallback FR conventionné).
 *  2. Anti-régression : `kiosk.wizard.confirmation` doit être SUPPRIMÉ
 *     (sinon move incomplet → réintroduction du bug).
 *  3. End-to-end light : mount du composant avec vue-i18n + stubs Vuex/router
 *     → assert que les chaînes user-facing apparaissent traduites
 *     (pas de raw key string).
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount } from '@vue/test-utils';
import frMessages from '../../resources/js/languages/fr.json';
import enMessages from '../../resources/js/languages/en.json';
import arMessages from '../../resources/js/languages/ar.json';

// Toutes les keys référencées par KioskConfirmationComponent.vue
// (extraites via grep -on "kiosk\.confirmation\.[a-zA-Z_][a-zA-Z0-9_]*").
// `print_failed` / `print_failed_hint` sont volontairement absents : le
// composant utilise un fallback inline (`$t(...) || 'Impression…'`) — pas
// d'obligation que la key existe.
const REQUIRED_KEYS = [
    'auto_return',
    'csat_aria',
    'csat_n_stars',
    'csat_prompt',
    'csat_thanks',
    'estimated_ready',
    'loyalty_points',
    'message_counter',
    'message_kitchen',
    'minutes_range',
    'new_order',
    'order_number',
    'payment_method',
    'print_button',
    'print_error',
    'printed',
    'printing',
    'receipt_discount',
    'receipt_loyalty',
    'receipt_number',
    'receipt_present',
    'receipt_thanks',
    'receipt_total',
    'speech_summary',
    'title',
    'total_balance',
    'total_paid',
];

describe('KioskConfirmationComponent — i18n keys schema (Option 1 move)', () => {
    it('FR : toutes les keys référencées par le template existent sous kiosk.confirmation.*', () => {
        expect(frMessages?.kiosk?.confirmation).toBeDefined();
        for (const key of REQUIRED_KEYS) {
            expect(
                frMessages.kiosk.confirmation[key],
                `fr.json manque kiosk.confirmation.${key}`,
            ).toBeDefined();
        }
    });

    it('EN : toutes les keys référencées par le template existent sous kiosk.confirmation.*', () => {
        expect(enMessages?.kiosk?.confirmation).toBeDefined();
        for (const key of REQUIRED_KEYS) {
            expect(
                enMessages.kiosk.confirmation[key],
                `en.json manque kiosk.confirmation.${key}`,
            ).toBeDefined();
        }
    });

    it('AR : kiosk.confirmation existe (partial OK — fallback FR conventionné)', () => {
        // AR voulu partial : si une key manque, vue-i18n fallback sur FR.
        // On vérifie au moins que le namespace existe et que `title` est traduite.
        expect(arMessages?.kiosk?.confirmation).toBeDefined();
        expect(arMessages.kiosk.confirmation.title).toBeDefined();
        expect(arMessages.kiosk.confirmation.title).not.toBe('kiosk.confirmation.title');
    });

    it('Anti-régression : kiosk.wizard.confirmation doit être SUPPRIMÉ après le move', () => {
        // Si l'un de ces 3 reste, le move est incomplet et le bug peut revenir
        // (un dev pourrait ré-éditer la mauvaise zone par habitude).
        expect(
            frMessages.kiosk.wizard?.confirmation,
            'fr.json : kiosk.wizard.confirmation devrait être supprimé après le move',
        ).toBeUndefined();
        expect(
            enMessages.kiosk.wizard?.confirmation,
            'en.json : kiosk.wizard.confirmation devrait être supprimé après le move',
        ).toBeUndefined();
        expect(
            arMessages.kiosk.wizard?.confirmation,
            'ar.json : kiosk.wizard.confirmation devrait être supprimé après le move',
        ).toBeUndefined();
    });

    it('FR/EN sont symétriques sur les 27 keys movées', () => {
        const frKeys = Object.keys(frMessages.kiosk.confirmation).sort();
        const enKeys = Object.keys(enMessages.kiosk.confirmation).sort();
        expect(frKeys).toEqual(enKeys);
    });

    it('FR : les chaînes user-facing rendues par vue-i18n ne sont PAS les raw keys', () => {
        const i18n = createI18n({
            legacy: false,
            locale: 'fr',
            fallbackLocale: 'fr',
            messages: { fr: frMessages },
        });
        // Smoke probe sur les 4 keys les plus visibles à l'écran.
        const probe = ['title', 'order_number', 'message_kitchen', 'new_order'];
        for (const k of probe) {
            const fullKey = `kiosk.confirmation.${k}`;
            const rendered = i18n.global.t(fullKey);
            expect(rendered, `${fullKey} rendu raw au lieu de traduit`).not.toBe(fullKey);
            expect(typeof rendered).toBe('string');
            expect(rendered.length).toBeGreaterThan(0);
        }
        // Vérification spécifique du libellé attendu (= pas de typo silencieuse).
        expect(i18n.global.t('kiosk.confirmation.title')).toBe('Commande confirmée !');
    });

    it('EN : title rendu = "Order confirmed!"', () => {
        const i18n = createI18n({
            legacy: false,
            locale: 'en',
            fallbackLocale: 'fr',
            messages: { fr: frMessages, en: enMessages },
        });
        expect(i18n.global.t('kiosk.confirmation.title')).toBe('Order confirmed!');
    });
});

describe('KioskConfirmationComponent — render template avec vue-i18n + stubs (light)', () => {
    /**
     * Mount minimal avec stubs Vuex/router/printer/snapshot. On cible la
     * portion top-of-screen qui ne dépend pas de l'auto-print pipeline (qui
     * nécessite escPosPrint + reportPrinterFailure côté kioskPrinter).
     *
     * On ne peut pas tester toute la mounted() pipeline (lourde : reset
     * panier + snapshot localStorage + auto-print + TTS) dans un test focused
     * i18n, donc on stub les helpers et on vérifie juste que le DOM rendu ne
     * contient AUCUNE raw key "kiosk.confirmation.*".
     */
    let KioskConfirmationComponent;

    beforeEach(async () => {
        // Stub kioskPrinter pour éviter side-effects ESC/POS dans happy-dom
        vi.doMock('../../resources/js/helpers/kioskPrinter', () => ({
            printReceipt: vi.fn().mockResolvedValue({ method: 'window' }),
            buildReceiptData: vi.fn().mockReturnValue({}),
            reportPrinterFailure: vi.fn(),
        }));
        // Stub kioskReceiptPersistence pour éviter localStorage mutations
        vi.doMock('../../resources/js/helpers/kioskReceiptPersistence', () => ({
            saveKioskReceiptSnapshot: vi.fn(),
            readKioskReceiptSnapshot: vi.fn().mockReturnValue(null),
            clearKioskReceiptSnapshot: vi.fn(),
        }));
        // Stub useKioskSpeech pour éviter Web Speech API
        vi.doMock('../../resources/js/composables/useKioskSpeech', () => ({
            useKioskSpeech: () => ({
                speak: vi.fn().mockResolvedValue(undefined),
                stop: vi.fn(),
            }),
        }));
        // Reset module registry pour appliquer les mocks
        vi.resetModules();
        const mod = await import(
            '../../resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue'
        );
        KioskConfirmationComponent = mod.default;
    });

    function buildStore() {
        return {
            state: {
                kioskCart: {
                    queueNumber: 'A042',
                    items: [],
                    paymentMethod: 'card',
                    loyaltyDiscount: 0,
                    orderRef: null,
                    loyaltyCustomer: null,
                },
                globalState: {
                    lists: {
                        company_name: 'FoodKing Test',
                        loyalty_points_per_euro: 1,
                        order_setup_food_preparation_time: 5,
                    },
                },
            },
            getters: { 'kioskCart/total': 12.5 },
            dispatch: vi.fn(),
        };
    }

    it('FR : aucune chaîne raw "kiosk.confirmation.*" ne doit apparaître dans le DOM', async () => {
        const i18n = createI18n({
            legacy: false,
            locale: 'fr',
            fallbackLocale: 'fr',
            messages: { fr: frMessages },
        });
        const wrapper = mount(KioskConfirmationComponent, {
            props: { orderNumber: 'A042', orderTotal: 12.5 },
            global: {
                plugins: [i18n],
                mocks: {
                    $store: buildStore(),
                    $router: { push: vi.fn().mockReturnValue({ catch: vi.fn() }) },
                },
                stubs: {
                    KsButton: {
                        template: '<button class="ks-button"><slot /></button>',
                    },
                },
            },
        });
        await wrapper.vm.$nextTick();

        const text = wrapper.text();
        // Le bug pré-fix faisait apparaître des strings comme
        // "kiosk.confirmation.title" dans le DOM. Aucune ne doit subsister.
        expect(text).not.toMatch(/kiosk\.confirmation\.[a-z_]/);

        // Smoke positif : les libellés FR clés sont effectivement rendus.
        expect(text).toContain('Commande confirmée');
        expect(text).toContain('Numéro de commande');
        expect(text).toContain('Nouvelle commande');
    });

    it('EN : libellés EN rendus ("Order confirmed", "Order number", "New order")', async () => {
        const i18n = createI18n({
            legacy: false,
            locale: 'en',
            fallbackLocale: 'fr',
            messages: { fr: frMessages, en: enMessages },
        });
        const wrapper = mount(KioskConfirmationComponent, {
            props: { orderNumber: 'A042', orderTotal: 12.5 },
            global: {
                plugins: [i18n],
                mocks: {
                    $store: buildStore(),
                    $router: { push: vi.fn().mockReturnValue({ catch: vi.fn() }) },
                },
                stubs: {
                    KsButton: {
                        template: '<button class="ks-button"><slot /></button>',
                    },
                },
            },
        });
        await wrapper.vm.$nextTick();
        const text = wrapper.text();
        expect(text).not.toMatch(/kiosk\.confirmation\.[a-z_]/);
        expect(text).toContain('Order confirmed');
        expect(text).toContain('Order number');
        expect(text).toContain('New order');
    });
});
