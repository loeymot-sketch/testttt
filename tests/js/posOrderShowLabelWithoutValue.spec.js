import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

/**
 * [C-003 + C-004 · supervisor-caisse-2026-08-24/round-1/wave-C-findings.json]
 *
 * MOTIF COMMUN : « UN LIBELLÉ QUI SURVIT À SA VALEUR ».
 *
 * Troisième et quatrième instances de la même famille sur CETTE fiche, après
 * deux corrections (« Extras: , » orphelin, « Instruction: » orpheline) qui les
 * avaient manquées — parce que personne n'était remonté à la SOURCE de la
 * valeur, seulement au garde qui l'affiche.
 *
 *  C-003 — « Heure de retrait: 25-08-2026 » : un libellé qui promet une HEURE,
 *          suivi d'une DATE, elle-même déjà affichée trois lignes plus haut.
 *          Le garde `v-if="order.delivery_date || order.delivery_time"` est
 *          TOUJOURS vrai parce que `OrderDetailsResource.php:66` calculait
 *          `delivery_date` INCONDITIONNELLEMENT (les deux branches du ternaire
 *          renvoient une date non vide). Le commentaire posé le 2026-07-29
 *          promettait « la ligne disparaît si aucun créneau n'est posé » :
 *          structurellement infaisable tel que le code était écrit.
 *          Correction à DEUX étages — la ressource cesse d'inventer un créneau
 *          (cf. tests/Feature/OrderHistory/OrderDetailsPickupSlotTest.php) ET
 *          le garde du composant cesse de croire une chaîne vide.
 *
 *  C-004 — « Type de commande: » suivi de RIEN sur TOUT le parc V1 :
 *          `orderTypeEnumArray` ne connaissait que DELIVERY / TAKEAWAY /
 *          DINING_TABLE. POS (15) et KIOSK (25) — les deux SEULS types produits
 *          par Le Cayenne — étaient absents. La LISTE d'où l'on ouvre la fiche,
 *          elle, sait les nommer (PosOrderListComponent.vue:254-259).
 *
 *  BONUS (même famille, trouvé en balayant la fiche, visible sur la MÊME image
 *  du superviseur qui lit « Type de paiement: » nu) : `posPaymentMethodEnumArray`
 *  ignorait TICKET_RESTAURANT (5) et COUNTER_DEFERRED (6). Or 6 est le mode
 *  porté par TOUTE commande borne Plan B en attente d'encaissement.
 */

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: { data: {} } })), post: vi.fn() } }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { error: vi.fn(), success: vi.fn() } }));
vi.mock('../../resources/js/services/appService', () => ({
    // Proxy : toute méthode du service rend une chaîne vide / true (permissions),
    // pour que ce spec porte sur les LIBELLÉS de la fiche, pas sur appService.
    default: new Proxy({}, {
        get: (_t, prop) => (prop === 'permissionChecker' ? () => true : () => ''),
    }),
}));
vi.mock('vue3-print-nb', () => ({ default: { directive: {} } }));

import PosOrderShowComponent from '../../resources/js/components/admin/posOrders/PosOrderShowComponent.vue';
import orderTypeEnum from '../../resources/js/enums/modules/orderTypeEnum';
import posPaymentMethodEnum from '../../resources/js/enums/modules/posPaymentMethodEnum';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';

const build = (order = {}) => {
    const store = {
        getters: new Proxy(
            {
                'posOrder/show': { order_type: orderTypeEnum.KIOSK, status: orderStatusEnum.DELIVERED, ...order },
                'posOrder/orderItems': [],
                'posOrder/orderUser': {},
                'posOrder/orderAddress': {},
            },
            { get: (t, p) => (p in t ? t[p] : undefined) }
        ),
        dispatch: vi.fn(() => Promise.resolve({ data: { data: {} } })),
        commit: vi.fn(),
    };
    const Test = { ...PosOrderShowComponent, mounted() {}, beforeUnmount() {} };
    return shallowMount(Test, {
        global: {
            stubs: { 'router-link': true },
            directives: { print: {} },
            mocks: { $store: store, $t: (k) => k, $route: { params: { id: 1 }, query: {} }, $router: { push: vi.fn() } },
        },
    });
};

describe('C-004 — « Type de commande » sur le parc réel (borne + caisse)', () => {
    it('nomme BORNE (kiosk=25) — le type de toute commande de borne', () => {
        expect(build().vm.orderTypeEnumArray[orderTypeEnum.KIOSK]).toBe('label.kiosk');
    });

    it('nomme CAISSE (pos=15) — le type de toute commande prise au comptoir', () => {
        expect(build().vm.orderTypeEnumArray[orderTypeEnum.POS]).toBe('label.pos');
    });

    it('couvre TOUT l\'enum : aucune valeur produite ne peut rendre un libellé nu', () => {
        const map = build().vm.orderTypeEnumArray;
        Object.entries(orderTypeEnum).forEach(([name, value]) => {
            expect(map[value], `orderTypeEnum.${name} (=${value}) n'a pas de libellé`).toBeTruthy();
        });
    });

    it('la valeur est bien RENDUE dans la fiche (pas seulement présente dans la carte)', () => {
        expect(build({ order_type: orderTypeEnum.KIOSK }).text()).toContain('label.kiosk');
    });
});

describe('BONUS — « Type de paiement » nu sur toute commande borne Plan B', () => {
    it('couvre TOUT posPaymentMethodEnum, COUNTER_DEFERRED (6) et TICKET_RESTAURANT (5) inclus', () => {
        const map = build().vm.posPaymentMethodEnumArray;
        Object.entries(posPaymentMethodEnum).forEach(([name, value]) => {
            expect(map[value], `posPaymentMethodEnum.${name} (=${value}) n'a pas de libellé`).toBeTruthy();
        });
    });
});

describe('C-003 — « Heure de retrait » ne doit pas survivre à l\'absence de créneau', () => {
    const pickupLine = (wrapper) =>
        wrapper.findAll('li').filter((li) => li.text().includes('label.pickup_time') || li.text().includes('label.delivery_time'));

    it('AUCUN créneau posé (commande borne immédiate) : la ligne DISPARAÎT', () => {
        const w = build({ order_type: orderTypeEnum.KIOSK, delivery_date: null, delivery_time: '' });
        expect(pickupLine(w), 'un libellé « Heure de retrait » sans heure ne doit pas se rendre').toHaveLength(0);
    });

    it('une valeur RÉDUITE À DES BLANCS ne réveille pas la ligne', () => {
        const w = build({ order_type: orderTypeEnum.KIOSK, delivery_date: '   ', delivery_time: ' ' });
        expect(pickupLine(w)).toHaveLength(0);
    });

    it('un créneau RÉEL (commande programmée) reste affiché avec son heure', () => {
        const w = build({ order_type: orderTypeEnum.TAKEAWAY, delivery_date: '26-08-2026', delivery_time: '12:00 - 12:30' });
        const lines = pickupLine(w);
        expect(lines).toHaveLength(1);
        expect(lines[0].text()).toContain('26-08-2026');
        expect(lines[0].text()).toContain('12:00 - 12:30');
    });

    it('une heure sans date reste affichée (on ne masque jamais une valeur réelle)', () => {
        const w = build({ order_type: orderTypeEnum.DELIVERY, delivery_date: null, delivery_time: '12:00 - 12:30' });
        expect(pickupLine(w)).toHaveLength(1);
    });
});
