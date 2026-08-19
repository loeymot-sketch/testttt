import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';
import { item } from '../../resources/js/store/modules/item';
import ItemComponent from '../../resources/js/components/admin/pos/ItemComponent.vue';

// [PERF 2026-07-23 POS-instant-open] Spec du levier « ouverture instantanée du wizard
// caisse » :
//   (a) cache client — 2 dispatch details même id (surface POS) = 1 seul axios ;
//   (b) feedback — l'état chargement/tuile-pressée est posé AVANT la résolution du fetch ;
//   (c) échec — fermeture propre (overlay off, aucun modal fantôme, erreur remontée).
// axios mocké (seul `item/details` l'utilise ici). appService est mocké pour rompre le
// cycle d'import modules/item → appService → ../store → modules/item (sinon `item` est
// undefined au createStore) — même pattern que tests/js/itemListBranchAvailability.spec.js.
// Aucune méthode appService n'est appelée par les chemins testés (shim plain-`this`,
// template non rendu), le stub sert uniquement à satisfaire l'évaluation des modules.
vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        requestHandler: () => '',
        currencyFormat: () => '',
        textShortener: (text) => text,
        onlyNumber: () => true,
        modalHide: () => {},
    },
}));

const storeCtx = { commit: () => {}, dispatch: () => {}, rootState: {}, rootGetters: {} };

// ---------------------------------------------------------------------------
// (a) Cache client + dédup in-flight dans l'action item/details (item.js)
// ---------------------------------------------------------------------------
describe('POS item/details — cache client + dédup (item.js)', () => {
    beforeEach(() => {
        // Vide le cache module (persistant entre tests) via l'action dédiée.
        item.actions.invalidateDetails(storeCtx, null);
        vi.mocked(axios.get).mockReset();
        vi.mocked(axios.get).mockResolvedValue({ data: { data: { id: 1, name: 'X' } } });
    });

    it('deux dispatch POS identiques ne déclenchent QU’UNE requête axios (re-clic instantané)', async () => {
        const payload = { id: 7, surface: 'pos', branch_id: 1 };
        const r1 = await item.actions.details(storeCtx, payload);
        const r2 = await item.actions.details(storeCtx, payload);

        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1);
        expect(r1).toBe(r2); // la 2ᵉ ouverture est servie depuis le cache (même réponse)
    });

    it('dédup des requêtes POS concurrentes en vol (préchauffe + clic = 1 requête)', async () => {
        let resolveGet;
        vi.mocked(axios.get).mockReturnValueOnce(
            new Promise((resolve) => { resolveGet = () => resolve({ data: { data: { id: 8 } } }); })
        );

        const p1 = item.actions.details(storeCtx, { id: 8, surface: 'pos', branch_id: 1 });
        const p2 = item.actions.details(storeCtx, { id: 8, surface: 'pos', branch_id: 1 });

        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(1); // 2ᵉ appel partage l'in-flight

        resolveGet();
        const [a, b] = await Promise.all([p1, p2]);
        expect(a).toBe(b);
    });

    it('les surfaces NON-POS ne sont pas cachées (admin/kiosk/web inchangés)', async () => {
        await item.actions.details(storeCtx, { id: 9 });        // admin : aucune surface
        await item.actions.details(storeCtx, { id: 9 });

        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(2); // fetch direct à chaque fois
    });

    it('invalidateDetails purge l’entrée → refetch au prochain dispatch', async () => {
        const payload = { id: 10, surface: 'pos', branch_id: 1 };
        await item.actions.details(storeCtx, payload);
        item.actions.invalidateDetails(storeCtx, 10);
        await item.actions.details(storeCtx, payload);

        expect(vi.mocked(axios.get)).toHaveBeenCalledTimes(2);
    });
});

// ---------------------------------------------------------------------------
// (b) + (c) Ouverture optimiste du wizard (feedback instantané) — ItemComponent
// ---------------------------------------------------------------------------
function makeRefs() {
    return {
        itemVariationModal: {
            dataset: {},
            setAttribute: vi.fn(),
            removeAttribute: vi.fn(),
            classList: { add: vi.fn(), remove: vi.fn(), contains: () => false },
        },
        itemInfoModal: { classList: { add: vi.fn(), remove: vi.fn() } },
    };
}

function createVm(storeDispatch) {
    const data = ItemComponent.data.call({});
    const vm = {
        ...data,
        $t: (key) => key,
        $store: {
            dispatch: storeDispatch,
            getters: {
                'frontendSetting/lists': {
                    site_digit_after_decimal_point: 2,
                    site_default_currency_symbol: 'EUR',
                    site_currency_position: 'left',
                },
            },
        },
        $refs: makeRefs(),
    };
    Object.entries(ItemComponent.methods).forEach(([name, fn]) => {
        vm[name] = fn.bind(vm);
    });
    // [RED-TEAM 2026-08-19] Les computed doivent être câblés : l'ajout en un appui
    // est conditionné par `canAddToCart`, précisément pour ne jamais produire un
    // clic muet sur un produit devenu indisponible. Sans ce câblage, la valeur
    // serait `undefined` et la spec testerait un chemin qui n'existe pas.
    ['catalogItemAvailable', 'canAddToCart'].forEach((name) => {
        Object.defineProperty(vm, name, {
            get() { return ItemComponent.computed[name].call(vm); },
            configurable: true,
        });
    });
    return vm;
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('POS ItemComponent — ouverture optimiste (feedback instantané)', () => {
    it('pose l’état chargement + tuile pressée AVANT la résolution du fetch', () => {
        // dispatch en attente (jamais résolu) → simule le réseau encore en vol.
        const dispatch = vi.fn(() => new Promise(() => {}));
        const vm = createVm(dispatch);

        vm.variationModalShow({ id: 77 });

        // synchrone, sans attendre le réseau :
        expect(vm.wizardLoading).toBe(true);
        expect(String(vm.pendingItemId)).toBe('77');
        // le modal frozen n'est PAS activé tant que la donnée n'est pas revenue :
        expect(vm.$refs.itemVariationModal.classList.add).not.toHaveBeenCalledWith('active');
    });

    it('échec du fetch → fermeture propre (overlay off, aucun modal, erreur remontée)', async () => {
        const err = new Error('boom');
        const dispatch = vi.fn(() => Promise.reject(err));
        const vm = createVm(dispatch);
        vm.showItemLoadError = vi.fn();

        vm.variationModalShow({ id: 88 });
        await flush();

        expect(vm.wizardLoading).toBe(false);
        expect(vm.pendingItemId).toBe(null);
        expect(vm.showItemLoadError).toHaveBeenCalledWith(err);
        // aucune classe .active posée → pas de modal fantôme au-dessus de la caisse
        expect(vm.$refs.itemVariationModal.classList.add).not.toHaveBeenCalledWith('active');
    });

    it('succès → modal ouvert (.active) + overlay masqué', async () => {
        // [T-CAISSE-1TAP 2026-08-19] Le produit porte une VRAIE option : c'est la
        // condition d'ouverture du wizard. Un produit sans aucune option rejoint
        // désormais le panier en un seul appui — cas couvert juste en dessous.
        const detail = {
            id: 99, name: 'Y', offer: [], convert_price: 5, currency_price: '5.00 EUR',
            itemAttributes: [{ id: 1, name: 'Viande', min_select: 1, max_select: 1 }],
            variations: { 1: [{ id: 101, item_attribute_id: 1, name: 'Poulet mariné', convert_price: 0 }] },
            extras: [], addons: [],
        };
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: detail } }));
        const vm = createVm(dispatch);

        vm.variationModalShow({ id: 99 });
        await flush();

        expect(vm.wizardLoading).toBe(false);
        expect(vm.pendingItemId).toBe(null);
        expect(vm.item.id).toBe(99);
        expect(vm.$refs.itemVariationModal.classList.add).toHaveBeenCalledWith('active');
    });

    /**
     * [T-CAISSE-1TAP 2026-08-19 · GOAL owner] Produit SANS aucune option : la
     * modale plein écran ne contenait qu'un champ « Instruction spéciale » vide et
     * exigeait un second clic. Elle ne s'ouvre plus, et l'overlay de chargement est
     * bien relâché (sinon la caisse resterait grisée sans rien afficher).
     */
    it('succès sans option → aucune modale, panier direct, overlay masqué', async () => {
        const detail = {
            id: 52, name: 'Coca-Cola 33cl', offer: [], convert_price: 1.9, currency_price: '1.90 EUR',
            itemAttributes: [], variations: {}, extras: [], addons: [],
        };
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: detail } }));
        const vm = createVm(dispatch);
        vm.addToCart = vi.fn();

        vm.variationModalShow({ id: 52 });
        await flush();

        expect(vm.addToCart).toHaveBeenCalled();
        expect(vm.wizardLoading).toBe(false);
        expect(vm.pendingItemId).toBe(null);
        expect(vm.$refs.itemVariationModal.classList.add).not.toHaveBeenCalledWith('active');
    });

    /**
     * [RED-TEAM 2026-08-19] Charge dégradée : `normalizeLoadedItem` remplace tout champ
     * absent/null/mal typé par `[]`. Si la garde lisait l'objet NORMALISÉ, un
     * `item/details` en succès mais à forme inattendue (régression d'API, projection
     * `surface=pos` fautive) ressemblerait à « produit sans option » et partirait au
     * panier SANS viande, sans pain, sans sauce, en un appui et sans écran — le mode de
     * panne exact du P0 borne du 2026-08-08. La garde lit donc la charge BRUTE.
     */
    it('charge dégradée (champs manquants) → le wizard s\'ouvre, aucun ajout aveugle', async () => {
        const detail = {
            id: 61, name: 'Produit à forme inattendue', offer: [],
            convert_price: 7, currency_price: '7.00 EUR',
            // itemAttributes / extras / addons ABSENTS de la réponse
        };
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: detail } }));
        const vm = createVm(dispatch);
        vm.addToCart = vi.fn();

        vm.variationModalShow({ id: 61 });
        await flush();

        expect(vm.addToCart, 'aucun ajout sur une charge dont on ne sait rien').not.toHaveBeenCalled();
        expect(vm.$refs.itemVariationModal.classList.add).toHaveBeenCalledWith('active');
    });

    /**
     * [RED-TEAM 2026-08-19] RÉGRESSION ÉVITÉE — un clic ne doit JAMAIS rester muet.
     *
     * La grille peut être périmée : la tuile annonce « disponible » alors que le
     * détail frais dit le contraire (produit passé « 86 » pendant le service).
     * Sans garde, l'ajout en un appui appelait `addToCart()`, qui commence par
     * `if (!this.canAddToCart) return;` — et ne dit RIEN. Le caissier aurait tapé
     * plusieurs fois sans comprendre pourquoi le produit ne descend pas.
     * On retombe donc sur l'ouverture du wizard, qui porte le bandeau
     * d'indisponibilité expliquant la situation.
     */
    it('sans option mais INDISPONIBLE → le wizard s\'ouvre (jamais de clic muet)', async () => {
        const detail = {
            id: 53, name: 'Sprite 33cl', offer: [], convert_price: 1.9, currency_price: '1.90 EUR',
            itemAttributes: [], variations: {}, extras: [], addons: [],
            is_available: false,
        };
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: detail } }));
        const vm = createVm(dispatch);
        vm.addToCart = vi.fn();

        vm.variationModalShow({ id: 53 });
        await flush();

        expect(vm.addToCart, 'aucun ajout silencieux').not.toHaveBeenCalled();
        expect(
            vm.$refs.itemVariationModal.classList.add,
            'le wizard doit s\'ouvrir pour montrer le bandeau d\'indisponibilité'
        ).toHaveBeenCalledWith('active');
    });

    /**
     * [RED-TEAM 2026-08-19] Même garde pour un article à prix nul : `canAddToCart`
     * exige `total_price > 0`. Le wizard s'ouvre, le bouton y est désactivé — le
     * caissier voit au moins l'écran au lieu d'un clic sans effet.
     */
    it('sans option et à prix nul → le wizard s\'ouvre (jamais de clic muet)', async () => {
        const detail = {
            id: 54, name: 'Article offert', offer: [], convert_price: 0, currency_price: '0.00 EUR',
            itemAttributes: [], variations: {}, extras: [], addons: [],
        };
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: detail } }));
        const vm = createVm(dispatch);
        vm.addToCart = vi.fn();

        vm.variationModalShow({ id: 54 });
        await flush();

        expect(vm.addToCart).not.toHaveBeenCalled();
        expect(vm.$refs.itemVariationModal.classList.add).toHaveBeenCalledWith('active');
    });

    it('préchauffe : survol d’une tuile disponible déclenche un dispatch item/details', () => {
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: {} } }));
        const vm = createVm(dispatch);
        vm.items = [{ id: 55, name: 'Z', is_available: true }];

        const tile = {
            disabled: false,
            getAttribute: (attr) => (attr === 'data-pos-item-id' ? '55' : null),
        };
        vm.handleTilePrewarm({ target: { closest: () => tile } });

        expect(dispatch).toHaveBeenCalledWith('item/details', expect.objectContaining({ id: 55, surface: 'pos' }));
    });

    it('préchauffe : une tuile en rupture ne déclenche AUCUN fetch', () => {
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: {} } }));
        const vm = createVm(dispatch);
        vm.items = [{ id: 56, name: 'Rupture', is_available: false }];

        const tile = {
            disabled: false,
            getAttribute: (attr) => (attr === 'data-pos-item-id' ? '56' : null),
        };
        vm.handleTilePrewarm({ target: { closest: () => tile } });

        expect(dispatch).not.toHaveBeenCalled();
    });
});
