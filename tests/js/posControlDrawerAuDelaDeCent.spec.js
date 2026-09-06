// [GOAL G1 — LA CAISSE VOIT TOUTE LA JOURNÉE 2026-09-03] Au-delà de cent commandes.
//
// LE DÉFAUT MESURÉ
// ----------------
// Le tiroir de contrôle affiche « les commandes du service ». La caisse en demandait CENT,
// sur une seule page, triées de la plus récente à la plus ancienne (`id desc`, défaut de
// `OrderService::list`). Un soir de rush au-delà de cent commandes, ce sont donc les PLUS
// ANCIENNES qui disparaissaient de l'écran — précisément celles qui traînent, celles qu'il faut
// voir — et rien ne le signalait : ni bandeau, ni compteur tronqué, ni indication de page.
//
// CE QUE CE BANC MESURE VRAIMENT
// ------------------------------
// Il ne monte pas le tiroir avec 137 commandes déjà en main : ça ne prouverait rien (le tiroir
// affiche ce qu'on lui donne, il l'a toujours fait). Il monte la CAISSE, avec un faux serveur
// qui se comporte comme le vrai — `paginate=1` fait honorer `per_page`, tri `id desc` — et il
// regarde ce qui arrive dans `serviceOrders`, c'est-à-dire la source unique des quatre files,
// des deux pastilles de la barre et du rang cuisine du ticket.
//
// Sans le correctif, §1 et §2 rougissent : la plus ancienne commande à encaisser est ABSENTE.
// §4 et §5 rougissent aussi : le tiroir n'a aucun moyen d'avouer une troncature.
//
// PIÈGE ÉVITÉ : aucune recherche par TEXTE. La caisse contient deux boutons « Ajouter au
// panier » dont un caché ; un `find()` textuel y tombe sur le mauvais. On lit les `data-testid`
// `pos-control-*` et l'état calculé.

import { beforeEach, describe, it, expect, vi } from 'vitest';
import { mount, shallowMount } from '@vue/test-utils';
import fr from '../../resources/js/languages/fr.json';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({
    default: { name: 'LoadingComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/ItemComponent.vue', () => ({
    default: { name: 'ItemComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({
    default: { name: 'PaymentComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({
    default: { name: 'CreateCustomerAddressComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({
    default: { name: 'CustomerAddressCreateComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({
    default: { name: 'ConnectionStatusBanner', template: '<div />' },
}));

import axios from 'axios';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';
import PosControlDrawer from '../../resources/js/components/admin/pos/PosControlDrawer.vue';

/** Résolution contre le VRAI catalogue : une clé absente revient telle quelle et fait rougir. */
const $t = (cle, params) => {
    let v = fr;
    for (const p of String(cle).split('.')) v = v?.[p];
    if (typeof v !== 'string') return cle;
    return params ? v.replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)) : v;
};

const MAINTENANT = Date.parse('2026-09-03T21:00:00+02:00');

// ── Le service semé : 137 commandes de la journée ────────────────────────────────────────
// id 1 = la PLUS ANCIENNE, à encaisser, celle qui traîne depuis l'ouverture. C'est elle que la
// page de cent, triée `id desc`, jetait par-dessus bord.
const TOTAL_SERVICE = 137;

function serviceSeme() {
    const commandes = [];
    for (let i = 1; i <= TOTAL_SERVICE; i++) {
        // Une commande sur onze reste à encaisser (borne « Plan B », paiement au comptoir).
        const aEncaisser = i % 11 === 1;
        commandes.push({
            id: i,
            queue_number: `K${i}`,
            // À encaisser → ACCEPT (4). Les autres alternent cuisine (7), prêtes (8), livrées (13).
            status: aEncaisser ? 4 : [7, 8, 13][i % 3],
            payment_status: aEncaisser ? 15 : 5,
            pos_payment_method: aEncaisser ? 6 : 1,
            order_type: 25,
            source_surface: 'kiosk',
            is_cash_pending: aEncaisser,
            cash_pending_amount: aEncaisser ? '14.90' : null,
            total: 14.9,
            // id croissant = de plus en plus RÉCENT : id 1 est la doyenne du service.
            created_at: new Date(MAINTENANT - (TOTAL_SERVICE - i) * 60000).toISOString(),
            order_items: [{ item_id: 1, item_name: 'Tacos M', quantity: 1, options: [], extras: [], addons: [] }],
        });
    }
    return commandes;
}

const SERVICE = serviceSeme();
const CASH_PENDING_REELS = SERVICE.filter((o) => o.is_cash_pending).length;

/**
 * Le faux serveur — MIROIR du vrai, pas une commodité de test.
 *
 *  · `admin/pos-order` (action `posOrder/lists`) : `paginate=1` fait honorer `per_page`
 *    (`OrderService::list:137-138`), tri par défaut `id desc` (`:139-140`). Sans `paginate`,
 *    `->get('*')` rend toute la fenêtre.
 *  · `admin/pos-order/service-day` (action `posOrder/serviceDay`) : la journée de service
 *    bornée aux quatre files, avec son `meta.total`.
 *
 * Si le correctif n'existe pas encore, seule la première branche est sollicitée — et c'est
 * exactement là que le banc rougit.
 */
function serveurFactice() {
    return vi.fn((action, payload = {}) => {
        if (action === 'posOrder/lists') {
            const parId = SERVICE.slice().sort((a, b) => b.id - a.id);
            if (parseInt(payload.paginate, 10) === 1) {
                const perPage = parseInt(payload.per_page, 10) || 10;
                const page = parId.slice(0, perPage);
                return Promise.resolve({
                    data: {
                        data: page,
                        meta: { total: parId.length, per_page: perPage, current_page: 1, last_page: Math.ceil(parId.length / perPage) },
                    },
                });
            }
            return Promise.resolve({ data: { data: parId, meta: { total: parId.length } } });
        }
        if (action === 'posOrder/serviceDay') {
            // Les quatre files utiles, sans plafond arbitraire, avec le total annoncé.
            const lignes = SERVICE.filter((o) => [1, 4, 7, 8, 10, 13].includes(o.status));
            return Promise.resolve({
                data: {
                    data: lignes,
                    meta: { total: lignes.length, shown: lignes.length, truncated: false },
                },
            });
        }
        return Promise.resolve({ data: { data: [] } });
    });
}

const getterValues = {
    'frontendSetting/lists': { site_digit_after_decimal_point: 2, site_default_currency_symbol: 'EUR', site_currency_position: 'left', pos_dine_in_enabled: 0 },
    'frontendLanguage/show': { display_mode: 0 },
    'posCategory/lists': [], 'item/lists': [], 'user/lists': [], 'posCart/lists': [],
    'posCart/subtotal': 0, 'posCart/discount': 0, 'diningTable/lists': [], 'user/addressLists': [],
    'auth/authBranchId': 1, 'auth/authInfo': { branch_id: 1 },
};

function caisse(dispatch) {
    const storeMock = {
        getters: new Proxy(getterValues, { get: (t, p) => (p in t ? t[p] : []) }),
        dispatch,
        commit: vi.fn(),
    };
    const TestPosComponent = {
        ...PosComponent,
        mounted() {},
        beforeUnmount() {},
        methods: {
            ...PosComponent.methods,
            closeSidebar: vi.fn(), itemCategories: vi.fn(), itemList: vi.fn(),
            loadKioskCashOrders: vi.fn(), _subscribeEcho: vi.fn(), _startKioskPolling: vi.fn(),
            _bindWsService: vi.fn(), _unsubscribeEcho: vi.fn(), _unbindWsService: vi.fn(),
        },
    };
    return shallowMount(TestPosComponent, {
        global: {
            stubs: { transition: false, 'router-link': true, 'vue-select': true },
            mocks: {
                $store: storeMock,
                $t: (key) => key,
                $route: { query: {}, params: {} },
                $router: { push: vi.fn(), replace: vi.fn() },
            },
        },
    });
}

function tiroir(props = {}) {
    return mount(PosControlDrawer, {
        props: { open: true, orders: [], ...props },
        // Le tiroir est TÉLÉPORTÉ dans `<body>` en production (un ancêtre transformé de la caisse
        // cassait sinon `position: fixed`). Le stub le rend en place pour que les assertions
        // portent sur le même arbre — même patron que `tests/js/posControlDrawer.spec.js`.
        global: { mocks: { $t }, stubs: { teleport: true } },
    });
}

describe('G1 — la caisse voit toute la journée de service, au-delà de cent commandes', () => {
    beforeEach(() => {
        axios.get.mockReset();
        axios.post.mockResolvedValue({ data: { data: null } });
    });

    // §1 — La doyenne du service, celle qui attend depuis l'ouverture, doit ARRIVER.
    it('la plus ancienne commande à encaisser du service arrive jusqu’à la caisse', async () => {
        const wrapper = caisse(serveurFactice());

        await wrapper.vm.loadActiveOrdersStats();

        const ids = wrapper.vm.serviceOrders.map((o) => o.id);
        expect(ids).toContain(1);
    });

    // §2 — Les quatre files et les deux pastilles disent le VRAI nombre, pas la taille d'une page.
    it('la pastille « à encaisser » compte le service entier, pas une page de cent', async () => {
        const wrapper = caisse(serveurFactice());

        await wrapper.vm.loadActiveOrdersStats();

        expect(wrapper.vm.filesControle.encaisser.length).toBe(CASH_PENDING_REELS);
        // La doyenne est bien en TÊTE de la file (tri plus-ancienne-d'abord).
        expect(wrapper.vm.filesControle.encaisser[0].id).toBe(1);
    });

    // §3 — Le second chemin de chargement (panneau « Prêt à livrer ») partage la même source :
    // il ne doit pas la REMPLACER par une page tronquée.
    it('le chargement des commandes prêtes ne retronque pas la journée', async () => {
        const wrapper = caisse(serveurFactice());

        await wrapper.vm.loadReadyOrders();

        expect(wrapper.vm.serviceOrders.map((o) => o.id)).toContain(1);
    });

    // §4 — T1.4 : si une borne subsiste, l'écran l'ANNONCE. Un compteur silencieusement faux est
    // pire qu'une borne assumée.
    it('le tiroir avoue la troncature quand le serveur en déclare une', () => {
        const wrapper = tiroir({
            orders: SERVICE.slice(0, 100),
            troncature: { total: TOTAL_SERVICE, affichees: 100 },
        });

        const bandeau = wrapper.find('[data-testid="pos-control-troncature"]');
        expect(bandeau.exists()).toBe(true);
        expect(bandeau.text()).toContain('100');
        expect(bandeau.text()).toContain(String(TOTAL_SERVICE));
        // Aucun libellé brut : la clé i18n doit être résolue.
        expect(bandeau.text()).not.toMatch(/pos\.controle\./);
    });

    // §5 — …et il se tait quand il n'y a rien à avouer. Un bandeau permanent serait du bruit.
    it('aucun bandeau de troncature quand la journée est complète', () => {
        const wrapper = tiroir({
            orders: SERVICE,
            troncature: { total: TOTAL_SERVICE, affichees: TOTAL_SERVICE, tronquee: false },
        });

        expect(wrapper.find('[data-testid="pos-control-troncature"]').exists()).toBe(false);
    });

    // §6 — Le fil complet : ce que le SERVEUR déclare doit arriver jusqu'au tiroir. Sans ce
    // maillon, §4 prouverait seulement qu'un composant sait afficher un objet qu'on lui tend.
    it('la caisse retient ce que le serveur dit de sa propre réponse', async () => {
        const dispatch = vi.fn(() => Promise.resolve({
            data: {
                data: SERVICE.slice(0, 100),
                meta: { total: TOTAL_SERVICE, shown: 100, truncated: true },
            },
        }));
        const wrapper = caisse(dispatch);

        await wrapper.vm.loadActiveOrdersStats();

        expect(wrapper.vm.serviceOrdersMeta).toEqual({
            total: TOTAL_SERVICE, affichees: 100, tronquee: true,
        });
    });
});
