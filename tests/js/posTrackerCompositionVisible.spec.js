import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// [GOAL-CAISSE-VISION 2026-08-24]
//
// Demande propriétaire : « si j'ai un client devant moi, j'ai pas pris son nom,
// je peux voir ce qu'il a pris et toutes les personnalisations qu'il a fait ».
//
// Avant : la carte de suivi montrait 3 noms de produits et un total. Deux
// sandwichs identiques commandés différemment étaient INDISTINGUABLES, et le
// reste du contenu exigeait un changement de page (rechargement complet depuis
// /admin/pos-v4). Ce spec épingle les quatre garanties qui ferment ce trou :
//
//   1. la composition est RENDUE sous chaque produit de la carte ;
//   2. « Voir tout » ouvre le contenu COMPLET sans le moindre appel réseau ;
//   3. les canaux réels (téléphone, plateforme, livraison) cessent d'être
//      confondus avec une vente au comptoir ;
//   4. aucun libellé brut, aucune ligne muette, jamais.

vi.mock('axios', () => ({
    default: { post: vi.fn(() => Promise.resolve({ data: {} })), get: vi.fn(() => Promise.resolve({ data: { data: [] } })) },
}));
vi.mock('../../resources/js/services/eventContract', () => ({
    onEvents: vi.fn(() => ({ unsubscribe: vi.fn() })),
}));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { info: vi.fn(), success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));
vi.mock('../../resources/js/services/appService', () => ({
    default: { modalShow: vi.fn(), modalHide: vi.fn() },
}));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({
    default: { name: 'ConnectionStatusBanner', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/ReceiptComponent.vue', () => ({
    default: { name: 'ReceiptComponent', template: '<div />', props: ['order'] },
}));

import axios from 'axios';
import PosOrdersTrackerComponent from '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';

const NOW = 1_800_000_000_000;
const iso = (ts) => new Date(ts).toISOString();

const makeStore = () => ({
    getters: new Proxy(
        { 'auth/authBranchId': 1, 'frontendSetting/lists': {} },
        { get(t, p) { return p in t ? t[p] : undefined; } }
    ),
    state: { auth: { authBranchId: 1 } },
    dispatch: vi.fn(() => Promise.resolve({ data: { data: [] } })),
    commit: vi.fn(),
});

const buildHarness = () => {
    const store = makeStore();
    const Test = {
        ...PosOrdersTrackerComponent,
        mounted() {},
        beforeUnmount() {},
        methods: { ...PosOrdersTrackerComponent.methods, _now: () => NOW },
    };
    const wrapper = shallowMount(Test, {
        global: {
            stubs: { transition: false, 'transition-group': false, 'router-link': true },
            mocks: {
                $store: store,
                // Le catalogue FR réel pour les clés que ce spec traverse : un test
                // qui renvoie la clé ne prouverait PAS l'absence de libellé brut.
                $t: (key) => ({
                    'label.deleted_item': 'Article retiré du catalogue',
                    'label.extras': 'Extras',
                    'label.addons': 'Suppléments',
                    'pos.tracker.source_pos': 'Caisse',
                    'pos.tracker.source_kiosk': 'Borne',
                    'pos.tracker.source_online': 'En ligne',
                    'pos.tracker.source_phone': 'Téléphone',
                    'pos.tracker.source_platform': 'Plateforme',
                    'pos.tracker.source_delivery': 'Livraison',
                    'pos.tracker.source_all': 'Toutes',
                }[key] ?? key),
                $route: { query: {}, params: {} },
                $router: { push: vi.fn(), replace: vi.fn() },
            },
        },
    });
    wrapper.vm.ageTick = NOW;
    return { wrapper, store };
};

/**
 * Une commande telle que le serveur l'expédie désormais : la composition est
 * DÉJÀ compacte et réconciliée (App\Support\Order\CompositionCompactor).
 */
const commandeComposee = (over = {}) => ({
    id: 501,
    queue_number: 42,
    status: orderStatusEnum.PREPARING,
    source_surface: 'pos',
    payment_status: 5,
    total: 19.4,
    created_at: iso(NOW - 3 * 60000),
    order_items: [
        {
            item_id: 22, item_name: 'Sandwich Cayenne', quantity: 1,
            options: [
                { label: 'Pain', value: 'Galette' },
                { label: 'Sauce', value: 'Algérienne' },
                { label: 'Cuisson', value: 'À point' },
            ],
            extras: [{ name: 'Cheddar', quantity: 2 }, { name: 'Salade' }],
            instruction: 'Sans oignons — allergie',
        },
        {
            item_id: 30, item_name: 'Menu Tacos', quantity: 2,
            options: [{ label: 'Viande', value: 'Poulet mariné' }],
            addons: [{ name: 'Frites' }, { name: 'Coca', quantity: 1 }],
        },
    ],
    ...over,
});

describe('resumeComposition — la personnalisation devient lisible sur la carte', () => {
    it('assemble choix, extras et suppléments en une phrase française compacte', () => {
        const vm = buildHarness().wrapper.vm;
        const cmd = commandeComposee();

        expect(vm.resumeComposition(cmd.order_items[0]))
            .toBe('Galette · Algérienne · À point · +2 Cheddar · +Salade');
        expect(vm.resumeComposition(cmd.order_items[1]))
            .toBe('Poulet mariné · +Frites · +Coca');
    });

    it('une ligne sans personnalisation ne produit AUCUN texte — pas de séparateur orphelin', () => {
        const vm = buildHarness().wrapper.vm;

        expect(vm.resumeComposition({ item_name: 'Coca', quantity: 1 })).toBe('');
        expect(vm.resumeComposition({ item_name: 'Coca', options: [], extras: [], addons: [] })).toBe('');
        expect(vm.resumeComposition(null)).toBe('');
    });

    it('écarte les entrées sans nom au lieu de rendre un « · » fantôme', () => {
        const vm = buildHarness().wrapper.vm;

        expect(vm.resumeComposition({
            options: [{ label: 'Sauce', value: '  ' }, { label: 'Pain', value: 'Galette' }],
            extras: [{ name: '' }],
        })).toBe('Galette');
    });
});

describe('nomProduit — jamais de ligne muette', () => {
    it('un article retiré du catalogue reçoit un libellé français, pas un vide', () => {
        const vm = buildHarness().wrapper.vm;

        expect(vm.nomProduit({ item_id: 9, item_name: null, quantity: 1 })).toBe('Article retiré du catalogue');
        expect(vm.nomProduit({ item_id: 9, item_name: '   ', quantity: 1 })).toBe('Article retiré du catalogue');
        expect(vm.nomProduit(null)).toBe('Article retiré du catalogue');
    });

    it('un article normal garde son nom', () => {
        const vm = buildHarness().wrapper.vm;
        expect(vm.nomProduit({ item_name: 'Sandwich Cayenne' })).toBe('Sandwich Cayenne');
    });
});

describe('« Voir tout » — le contenu complet, sans quitter l\'écran', () => {
    it('n\'apparaît que s\'il y a vraiment quelque chose de plus à voir', () => {
        const vm = buildHarness().wrapper.vm;

        // 1 ligne nue, rien à révéler : le bouton mentirait.
        expect(vm.aDuContenuAVoir({ order_items: [{ item_name: 'Coca', quantity: 1 }] })).toBe(false);
        // une personnalisation suffit
        expect(vm.aDuContenuAVoir({ order_items: [{ item_name: 'Coca', options: [{ label: 'Taille', value: 'Maxi' }] }] })).toBe(true);
        // une instruction aussi (allergie)
        expect(vm.aDuContenuAVoir({ order_items: [{ item_name: 'Coca', instruction: 'sans glaçons' }] })).toBe(true);
        // plus de 3 lignes : la carte en cache, donc il y a un « tout » à voir
        expect(vm.aDuContenuAVoir({ order_items: [{}, {}, {}, {}] })).toBe(true);
        expect(vm.aDuContenuAVoir({ order_items: [] })).toBe(false);
        expect(vm.aDuContenuAVoir(null)).toBe(false);
    });

    it('ouvre le panneau SANS le moindre appel réseau — les données sont déjà en mémoire', async () => {
        const { wrapper, store } = buildHarness();
        const vm = wrapper.vm;
        axios.get.mockClear();
        store.dispatch.mockClear();

        vm.ouvrirContenu(commandeComposee());
        await wrapper.vm.$nextTick();

        expect(vm.contenuDialog.open).toBe(true);
        expect(vm.contenuDialog.order.id).toBe(501);
        expect(axios.get).not.toHaveBeenCalled();
        expect(store.dispatch).not.toHaveBeenCalled();
    });

    it('rend TOUTES les lignes et TOUTES les personnalisations, pas seulement les 3 premières', async () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;

        const cmd = commandeComposee({
            order_items: [
                ...commandeComposee().order_items,
                { item_id: 3, item_name: 'Tiramisu', quantity: 1 },
                { item_id: 4, item_name: 'Coca', quantity: 3 },
            ],
        });

        // La carte n'en montre que 3 — c'est le comportement voulu, la carte reste lisible.
        expect(vm.itemsPreview(cmd)).toHaveLength(3);
        expect(vm.extraItemsCount(cmd)).toBe(1);
        // Le panneau, lui, les montre TOUTES.
        expect(vm.lignesCompletes(cmd)).toHaveLength(4);

        vm.ouvrirContenu(cmd);
        await wrapper.vm.$nextTick();

        const html = wrapper.html();
        expect(html).toContain('Sandwich Cayenne');
        expect(html).toContain('Menu Tacos');
        expect(html).toContain('Tiramisu');
        expect(html).toContain('Coca');
        // Les personnalisations de la 1re ligne, en toutes lettres.
        expect(html).toContain('Algérienne');
        expect(html).toContain('Galette');
        expect(html).toContain('Cheddar');
        // Les suppléments de formule de la 2e ligne.
        expect(html).toContain('Frites');
        // L'allergie, jamais tronquée.
        expect(html).toContain('Sans oignons — allergie');
    });

    it('suit la commande VIVANTE : un rafraîchissement pendant que le panneau est ouvert n\'affiche pas un état périmé', async () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;

        const capturee = commandeComposee();
        vm.orders = [capturee];
        vm.ouvrirContenu(capturee);
        await wrapper.vm.$nextTick();

        // Le suivi se rafraîchit (5 s) : la MÊME commande revient enrichie d'une ligne.
        vm.orders = [{
            ...capturee,
            order_items: [...capturee.order_items, { item_id: 99, item_name: 'Tiramisu', quantity: 1 }],
        }];
        await wrapper.vm.$nextTick();

        expect(vm.commandeAffichee.order_items).toHaveLength(3);
        expect(wrapper.html()).toContain('Tiramisu');
    });

    it('garde le contenu à l\'écran si la commande quitte le tableau — jamais un panneau qui se vide', async () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;

        const capturee = commandeComposee();
        vm.orders = [capturee];
        vm.ouvrirContenu(capturee);
        await wrapper.vm.$nextTick();

        // La commande est encaissée / livrée : elle disparaît du tableau.
        vm.orders = [];
        await wrapper.vm.$nextTick();

        expect(vm.commandeAffichee).not.toBeNull();
        expect(vm.commandeAffichee.id).toBe(501);
        expect(wrapper.html()).toContain('Sandwich Cayenne');
    });

    it('Échap referme le panneau où que soit le focus', async () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;

        vm.ouvrirContenu(commandeComposee());
        await wrapper.vm.$nextTick();
        expect(vm.contenuDialog.open).toBe(true);

        vm._contenuOnKeydown({ key: 'Escape' });
        expect(vm.contenuDialog.open).toBe(false);
        expect(vm.contenuDialog.order).toBeNull();

        // Une autre touche ne ferme rien.
        vm.ouvrirContenu(commandeComposee());
        vm._contenuOnKeydown({ key: 'a' });
        expect(vm.contenuDialog.open).toBe(true);
    });

    it('annonce combien d\'articles le panneau contient — sinon rien ne dit qu\'il faut défiler', () => {
        const vm = buildHarness().wrapper.vm;

        // 2 lignes, 3 pièces (1 + 2) : les deux comptes sont utiles et différents.
        expect(vm.compteArticles(commandeComposee())).toBe('2 articles · 3 au total');
        // Une ligne unique en quantité 1 : pas de redondance.
        expect(vm.compteArticles({ order_items: [{ item_name: 'Coca', quantity: 1 }] })).toBe('1 article');
        expect(vm.compteArticles({ order_items: [] })).toBe('');
        expect(vm.compteArticles(null)).toBe('');
    });

    it('listeNommee rend les quantités explicitement, jamais « undefined »', () => {
        const vm = buildHarness().wrapper.vm;

        expect(vm.listeNommee([{ name: 'Cheddar', quantity: 2 }, { name: 'Salade' }])).toBe('2× Cheddar, Salade');
        expect(vm.listeNommee([{ name: '' }, { quantity: 3 }])).toBe('');
        expect(vm.listeNommee(null)).toBe('');
        expect(vm.listeNommee([{ name: 'Coca', quantity: 1 }])).toBe('Coca');
    });
});

describe('canaux — le téléphone cesse d\'être une vente au comptoir', () => {
    it('reconnaît les six canaux réels au lieu de tout fondre dans « Caisse »', () => {
        const vm = buildHarness().wrapper.vm;

        expect(vm.sourceOf({ source_surface: 'pos' })).toBe('pos');
        expect(vm.sourceOf({ source_surface: 'kiosk' })).toBe('kiosk');
        expect(vm.sourceOf({ source_surface: 'web' })).toBe('online');
        // Les trois que le suivi ignorait :
        expect(vm.sourceOf({ source_surface: 'phone' })).toBe('phone');
        expect(vm.sourceOf({ source_surface: 'uber_eats' })).toBe('platform');
        expect(vm.sourceOf({ source_surface: 'deliveroo' })).toBe('platform');
        expect(vm.sourceOf({ source_surface: 'delivery' })).toBe('delivery');
    });

    it('donne à chaque canal son pictogramme et son libellé français', () => {
        const vm = buildHarness().wrapper.vm;

        expect(vm.sourceIcon({ source_surface: 'phone' })).toBe('📞');
        expect(vm.sourceLabel({ source_surface: 'phone' })).toBe('Téléphone');
        expect(vm.sourceIcon({ source_surface: 'uber_eats' })).toBe('🛵');
        expect(vm.sourceLabel({ source_surface: 'uber_eats' })).toBe('Plateforme');
        expect(vm.sourceIcon({ source_surface: 'delivery' })).toBe('🚗');
        expect(vm.sourceLabel({ source_surface: 'delivery' })).toBe('Livraison');
        // Aucun libellé ne doit ressembler à une clé i18n.
        ['pos', 'kiosk', 'web', 'phone', 'uber_eats', 'delivery'].forEach((s) => {
            expect(vm.sourceLabel({ source_surface: s })).not.toContain('pos.tracker.');
        });
    });

    it('l\'heuristique historique reste intacte quand source_surface manque', () => {
        const vm = buildHarness().wrapper.vm;

        expect(vm.sourceOf({ order_type: 17 })).toBe('kiosk');
        expect(vm.sourceOf({ order_type: 15 })).toBe('pos');
        expect(vm.sourceOf({})).toBe('pos');
    });

    it('un onglet de canal n\'apparaît que si ce canal est présent sur le tableau', async () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;

        vm.orders = [{ id: 1, source_surface: 'pos' }];
        await wrapper.vm.$nextTick();
        expect(vm.sourceTabs.map((t) => t.id)).toEqual(['all', 'pos', 'kiosk', 'online']);

        vm.orders = [{ id: 1, source_surface: 'pos' }, { id: 2, source_surface: 'phone' }];
        await wrapper.vm.$nextTick();
        expect(vm.sourceTabs.map((t) => t.id)).toEqual(['all', 'pos', 'kiosk', 'online', 'phone']);
    });

    it('le filtre par canal isole réellement les commandes téléphone', async () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;

        vm.orders = [
            { id: 1, source_surface: 'pos', status: orderStatusEnum.PREPARING },
            { id: 2, source_surface: 'phone', status: orderStatusEnum.PREPARING },
            { id: 3, source_surface: 'uber_eats', status: orderStatusEnum.PREPARING },
        ];
        vm.filters.source = 'phone';
        await wrapper.vm.$nextTick();

        expect(vm.filteredOrders.map((o) => o.id)).toEqual([2]);
    });
});

describe('rendu de la carte — la composition atteint vraiment le DOM', () => {
    it('affiche la personnalisation sous le produit, et le bouton « Voir tout »', async () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;

        vm.orders = [commandeComposee()];
        await wrapper.vm.$nextTick();

        const html = wrapper.html();
        expect(html).toContain('Sandwich Cayenne');
        expect(html).toContain('Algérienne');
        expect(html).toContain('Voir tout');
        expect(html).toContain('tracker-voir-tout-501');
    });
});
