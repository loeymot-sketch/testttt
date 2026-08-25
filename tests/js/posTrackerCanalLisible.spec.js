import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { readFileSync } from 'fs';
import { resolve } from 'path';

/**
 * [FIX-6 · GOAL-CAISSE-VISION 2026-08-25]
 *
 * Cinq défauts relevés par la vague A du superviseur adverse
 * (`reports/test-e2e/supervisor-caisse-2026-08-24/round-1/wave-A-findings.json`),
 * tous VISIBLES à l'image et aucun gardé par un test :
 *
 *   A-002 — le canal TÉLÉPHONE n'est pas distinguable : la CSS ne colore que
 *           `--kiosk` et `--online` ; `--phone` / `--pos` / `--platform`
 *           retombent sur le même gris. Et la pastille n'a AUCUN nom
 *           accessible (un `title` inatteignable au doigt).
 *   A-003 — le bouton « rafraîchir » du panneau « en souffrance » est le SEUL
 *           bouton anonyme de l'écran.
 *   A-006 — la composition est coupée par la CSS et le texte complet n'existe
 *           que dans `title=` : sur une caisse tactile, il n'existe pas.
 *   A-011 — sous filtre, un couloir vide affirme un ABSOLU faux
 *           (« Aucune commande livrée pour l'instant » alors que 8 l'ont été).
 *   A-014 — « 1 actives » et « + 1 autres » : deux accords français faux.
 */

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

import fr from '../../resources/js/languages/fr.json';
import en from '../../resources/js/languages/en.json';
import PosOrdersTrackerComponent from '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';

// La CSS d'un SFC ne traverse pas `shallowMount` : pour prouver que chaque canal
// a VRAIMENT sa couleur, on lit la source du composant. C'est la seule évidence
// disponible côté unitaire — l'alternative serait un test visuel Playwright.
const SFC = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/pos/PosOrdersTrackerComponent.vue'),
    'utf8'
);

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
                // Résolution contre le VRAI `fr.json` : une clé absente revient
                // telle quelle, et les assertions la refusent.
                $t: (key, params) => {
                    let v = fr;
                    for (const p of String(key).split('.')) v = v?.[p];
                    if (typeof v !== 'string') return key;
                    return params
                        ? v.replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m))
                        : v;
                },
                $route: { query: {}, params: {} },
                $router: { push: vi.fn(), replace: vi.fn() },
            },
        },
    });
    wrapper.vm.ageTick = NOW;
    return { wrapper, store };
};

const commandeTel = (over = {}) => ({
    id: 701,
    queue_number: 12,
    status: orderStatusEnum.PREPARING,
    source_surface: 'phone',
    payment_status: 5,
    total: 14.5,
    created_at: iso(NOW - 4 * 60000),
    customer_name: 'Karim Bensalah',
    customer_phone: '0612345678',
    order_items: [{ item_id: 1, item_name: 'Tacos', quantity: 1 }],
    ...over,
});

/* ------------------------------------------------------------------ A-002 */

describe('A-002 — le canal TÉLÉPHONE devient distinguable à l\'œil ET au lecteur d\'écran', () => {
    const regleFond = (variante) => {
        const m = SFC.match(
            new RegExp(`\\.pos-tracker-card-source--${variante}\\s*\\{([^}]*)\\}`)
        );
        if (!m) return null;
        const fond = m[1].match(/background\s*:\s*([^;}]+)/);
        return fond ? fond[1].trim().toLowerCase() : null;
    };

    it('chaque canal réel porte sa propre couleur de fond — pas trois gris identiques', () => {
        const canaux = ['kiosk', 'online', 'phone', 'pos', 'platform'];
        const fonds = {};

        canaux.forEach((c) => {
            const fond = regleFond(c);
            expect(fond, `aucune règle CSS .pos-tracker-card-source--${c}`).toBeTruthy();
            expect(fond, `.pos-tracker-card-source--${c} retombe sur le gris neutre`)
                .not.toContain('--pos-tracker-muted-soft');
            fonds[c] = fond;
        });

        // Cinq canaux, cinq fonds DISTINCTS : sinon deux canaux restent confondus.
        expect(new Set(Object.values(fonds)).size).toBe(canaux.length);
    });

    it('la pastille de canal porte un nom accessible, pas seulement un `title` de souris', async () => {
        const { wrapper } = buildHarness();
        wrapper.vm.orders = [commandeTel()];
        await wrapper.vm.$nextTick();

        const pastille = wrapper.find('.pos-tracker-card-source');
        expect(pastille.exists()).toBe(true);
        expect(pastille.classes()).toContain('pos-tracker-card-source--phone');

        // Le nom accessible EXISTE en texte (sr-only) ou en aria-label — jamais
        // porté par le seul `title` : sur une caisse tactile il n'y a pas de survol.
        const nomAccessible = pastille.attributes('aria-label') || pastille.text();
        expect(nomAccessible).toContain('Téléphone');

        // Et l'emoji lui-même n'est pas annoncé deux fois.
        expect(wrapper.html()).toContain('aria-hidden="true">📞');
    });
});

/* ------------------------------------------------------------------ A-003 */

describe('A-003 — plus un seul bouton anonyme sur l\'écran', () => {
    it('le bouton « rafraîchir » du panneau en souffrance a un nom accessible', async () => {
        const { wrapper } = buildHarness();
        wrapper.vm.staleOpen = true;
        wrapper.vm.staleMeta = { count: 581, shown: 50, truncated: true };
        await wrapper.vm.$nextTick();

        const btn = wrapper.find('[data-testid="tracker-stale-refresh"]');
        expect(btn.exists(), 'le bouton rafraîchir doit être identifiable').toBe(true);

        const nom = (btn.attributes('aria-label') || '').trim() || btn.text().trim();
        expect(nom.length, 'bouton sans nom accessible').toBeGreaterThan(3);
        expect(nom.toLowerCase()).toContain('rafra');
    });

    it('aucun <button> du panneau en souffrance ne reste sans nom du tout', async () => {
        const { wrapper } = buildHarness();
        wrapper.vm.staleOpen = true;
        wrapper.vm.staleMeta = { count: 581, shown: 50, truncated: true };
        await wrapper.vm.$nextTick();

        const anonymes = wrapper.findAll('.pos-tracker-stale button').filter((b) => {
            const nom = (b.attributes('aria-label') || '').trim() || b.text().trim();
            return nom === '';
        });
        expect(anonymes.length, 'boutons sans nom accessible').toBe(0);
    });
});

/* ------------------------------------------------------------------ A-006 */

describe('A-006 — la composition coupée le DIT, au lieu de se cacher dans un `title`', () => {
    // La ligne EXACTE relevée par la vague A sur la carte #AUDA-COMPO : « +2 Cheddar »
    // et « +Salade », deux extras PAYANTS, étaient coupés à l'écran.
    const ligneVagueA = {
        item_id: 22, item_name: 'Sandwich Cayenne', quantity: 2,
        options: [
            { label: 'Pain', value: 'Galette' },
            { label: 'Sauce', value: 'Algérienne' },
            { label: 'Cuisson', value: 'Bien cuit' },
        ],
        extras: [{ name: 'Cheddar', quantity: 2 }, { name: 'Salade' }],
    };

    const ligneDemesuree = {
        item_id: 23, item_name: 'Menu Cayenne', quantity: 1,
        options: [
            { label: 'Pain', value: 'Galette complète' },
            { label: 'Sauce', value: 'Algérienne maison' },
            { label: 'Cuisson', value: 'Bien cuit' },
            { label: 'Boisson', value: 'Coca Zéro 50 cl' },
            { label: 'Accompagnement', value: 'Frites cheddar bacon' },
        ],
        extras: [{ name: 'Cheddar', quantity: 2 }, { name: 'Salade' }, { name: 'Oignons frits' }],
    };

    it('une composition courte n\'est PAS coupée et n\'affiche aucun marqueur', () => {
        const vm = buildHarness().wrapper.vm;
        const vue = vm.compoAffichee({ item_name: 'Coca', options: [{ label: 'Taille', value: 'Maxi' }] });

        expect(vue.texte).toBe('Maxi');
        expect(vue.tronque).toBe(false);
        expect(vue.restants).toBe(0);
    });

    it('le cas EXACT de la vague A n\'est plus coupé du tout — les deux extras payants sont à l\'écran', async () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;
        const vue = vm.compoAffichee(ligneVagueA);

        expect(vue.tronque).toBe(false);
        expect(vue.texte).toBe('Galette · Algérienne · Bien cuit · +2 Cheddar · +Salade');

        // Et ce texte est bien RENDU, pas seulement calculé.
        vm.orders = [commandeTel({ order_items: [ligneVagueA] })];
        await vm.$nextTick();
        expect(wrapper.find('[data-testid="tracker-compo-701-0"]').text()).toContain('+2 Cheddar');
        expect(wrapper.find('[data-testid="tracker-compo-701-0"]').text()).toContain('+Salade');
        expect(wrapper.find('[data-testid="tracker-compo-more-701-0"]').exists()).toBe(false);
    });

    it('une composition démesurée est coupée sur un séparateur et annonce combien manque', () => {
        const vm = buildHarness().wrapper.vm;
        const complet = vm.resumeComposition(ligneDemesuree);
        const vue = vm.compoAffichee(ligneDemesuree);

        expect(vue.tronque).toBe(true);
        expect(vue.restants).toBeGreaterThan(0);
        // Coupe NETTE : jamais au milieu d'un morceau.
        expect(complet.startsWith(vue.texte)).toBe(true);
        expect(vue.texte.endsWith('·')).toBe(false);
        expect(vue.texte.trim()).toBe(vue.texte);
        // Le compte annoncé correspond aux morceaux réellement retirés.
        expect(vue.restants).toBe(complet.split(' · ').length - vue.texte.split(' · ').length);
    });

    it('le marqueur est un vrai bouton TAPABLE qui ouvre « Voir tout » — pas un survol souris', async () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;
        vm.orders = [commandeTel({ order_items: [ligneDemesuree] })];
        await vm.$nextTick();

        const marqueur = wrapper.find('[data-testid="tracker-compo-more-701-0"]');
        expect(marqueur.exists(), 'aucun marqueur visible de troncature').toBe(true);
        expect(marqueur.element.tagName).toBe('BUTTON');
        expect(marqueur.text()).toContain('+');
        expect((marqueur.attributes('aria-label') || '').length).toBeGreaterThan(3);

        await marqueur.trigger('click');
        expect(vm.contenuDialog.open).toBe(true);
        expect(vm.contenuDialog.order.id).toBe(701);
    });

    it('la CSS ne coupe plus la ligne au bout d\'un seul rang invisible', () => {
        const bloc = SFC.match(/\.pos-tracker-card-compo\s*\{([^}]*)\}/);
        expect(bloc, 'règle .pos-tracker-card-compo introuvable').toBeTruthy();
        expect(bloc[1], 'white-space:nowrap cache le texte sans le dire').not.toMatch(/white-space\s*:\s*nowrap/);
        // `white-space` est HÉRITÉ du `li` parent, qui est en `nowrap` pour le nom du
        // produit. Ne pas le redéclarer ici ne suffit PAS : la capture visuelle du
        // 2026-08-25 a montré la composition encore tronquée dans ce cas précis.
        expect(bloc[1], 'sans white-space:normal, la coupe du li parent est héritée')
            .toMatch(/white-space\s*:\s*normal/);
    });
});

/* ------------------------------------------------------------------ A-011 */

describe('A-011 — un couloir vide sous filtre cesse de mentir', () => {
    it('sans filtre, la phrase absolue est conservée', () => {
        const vm = buildHarness().wrapper.vm;
        const livres = vm.columns.find((c) => c.id === 'delivered');

        expect(vm.emptyLabelFor(livres)).toBe('Aucune commande livrée pour l\'instant.');
    });

    it('sous filtre canal, la phrase NOMME le filtre et n\'affirme plus d\'absolu', async () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;
        vm.orders = [commandeTel()];
        vm.filters.source = 'phone';
        await vm.$nextTick();

        const livres = vm.columns.find((c) => c.id === 'delivered');
        const phrase = vm.emptyLabelFor(livres);

        expect(phrase).not.toBe('Aucune commande livrée pour l\'instant.');
        expect(phrase).toContain('Téléphone');
        expect(phrase.toLowerCase()).toContain('filtre');
        expect(phrase).not.toContain('pos.tracker.');
    });

    it('sous recherche, la phrase cite la recherche au lieu du néant', async () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;
        vm.filters.query = 'A0032';
        await vm.$nextTick();

        const livres = vm.columns.find((c) => c.id === 'delivered');
        const phrase = vm.emptyLabelFor(livres);

        expect(phrase).toContain('A0032');
        expect(phrase).not.toContain('pos.tracker.');
    });

    it('l\'état vide filtré offre une SORTIE : un bouton qui remet tous les canaux', async () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;
        vm.orders = [commandeTel()];
        vm.filters.source = 'phone';
        await vm.$nextTick();

        const reset = wrapper.find('[data-testid="tracker-empty-reset"]');
        expect(reset.exists(), 'aucune sortie depuis un couloir vide filtré').toBe(true);
        expect(reset.text().length).toBeGreaterThan(3);

        await reset.trigger('click');
        expect(vm.filters.source).toBe('all');
        expect(vm.filters.query).toBe('');
    });
});

/* ------------------------------------------------------------------ A-014 */

describe('A-014 — les accords français au singulier', () => {
    it('« 1 active » au singulier, « 2 actives » au pluriel', async () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;

        vm.orders = [commandeTel()];
        await vm.$nextTick();
        expect(vm.stats.active).toBe(1);
        expect(vm.activeOrdersWord).toBe('active');

        vm.orders = [commandeTel(), commandeTel({ id: 702 })];
        await vm.$nextTick();
        expect(vm.stats.active).toBe(2);
        expect(vm.activeOrdersWord).toBe('actives');
    });

    it('« + 1 autre » au singulier, « + 2 autres » au pluriel', () => {
        const vm = buildHarness().wrapper.vm;

        expect(vm.moreItemsWord(1)).toBe('autre');
        expect(vm.moreItemsWord(2)).toBe('autres');
        expect(vm.moreItemsWord(0)).toBe('autres');
    });

    it('« 1 prête » au singulier, « 2 prêtes » au pluriel — le « prête(s) » disparaît', () => {
        const vm = buildHarness().wrapper.vm;

        expect(vm.readyWord(1)).toBe('prête');
        expect(vm.readyWord(3)).toBe('prêtes');
        expect(vm.readyWord(1)).not.toContain('(');
    });

    it('l\'écran ne rend AUCUN « 1 <mot au pluriel> »', async () => {
        const { wrapper } = buildHarness();
        wrapper.vm.orders = [commandeTel({ status: orderStatusEnum.PREPARED })];
        await wrapper.vm.$nextTick();

        const texte = wrapper.text();
        expect(texte).not.toMatch(/\b1\s+actives\b/);
        expect(texte).not.toMatch(/\b1\s+autres\b/);
        expect(texte).not.toMatch(/prête\(s\)/);
    });
});

/* -------------------------------------------------- catalogue FR ET EN */

describe('catalogue — chaque clé ajoutée existe dans fr.json ET en.json', () => {
    it('aucune clé orpheline d\'un côté ou de l\'autre', () => {
        const resolue = (cat, cle) => {
            let v = cat;
            for (const p of cle.split('.')) v = v?.[p];
            return v;
        };

        for (const cle of [
            'pos.tracker.active_orders', 'pos.tracker.active_orders_one',
            'pos.tracker.more_items', 'pos.tracker.more_items_one',
            'pos.tracker.ready_short', 'pos.tracker.ready_short_one',
            'pos.tracker.empty_filtered_source', 'pos.tracker.empty_filtered_search',
            'pos.tracker.empty_filter_reset',
            'pos.tracker.compo_more_aria',
            'pos.tracker.stale_refresh',
            'pos.tracker.source_phone', 'pos.tracker.source_platform',
        ]) {
            for (const [nom, cat] of [['fr', fr], ['en', en]]) {
                const v = resolue(cat, cle);
                expect(typeof v, `clé manquante dans ${nom}.json : ${cle}`).toBe('string');
                expect(v.trim(), `clé vide dans ${nom}.json : ${cle}`).not.toBe('');
            }
        }
    });
});
