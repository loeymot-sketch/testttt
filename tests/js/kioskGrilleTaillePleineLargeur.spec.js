/**
 * [OWNER 2026-08-24] Deux demandes du propriétaire sur la grille produits de la borne :
 *
 *   1. « je voulais toujours les produits ça prennent la taille complète de la borne […]
 *      pas juste des petits produits » → UNE colonne, quelle que soit la catégorie.
 *   2. « entre le M le L et le XL ça doit être visiblement […] avec l'œil on fera la
 *      différence entre les tailles » → un cran de taille PAR taille.
 *
 * POURQUOI CES TESTS EXISTENT PLUTÔT QU'UNE SIMPLE RELECTURE
 * ----------------------------------------------------------
 * Ces deux besoins avaient DÉJÀ été exprimés et implémentés le 2026-07-11 (voir les
 * commentaires `[BORNE-UX 2026-07-11]` dans le composant). Ils ont pourtant régressé, et
 * silencieusement :
 *   · la disposition « grandes cartes » ne couvrait que 1 ou 2 produits ; passer la
 *     catégorie Tacos à 3 l'a fait basculer dans une grille 2 colonnes de 370 px sur un
 *     écran de 1080, avec une case vide et ~40 % d'écran blanc ;
 *   · `--size-l` couvrait L, XL ET XXL, donc le Tacos L et le Tacos XL sortaient à
 *     366×355 px tous les deux — mesuré sur la borne, strictement identiques.
 * Rien ne rougissait. C'est exactement le trou que ces tests ferment : la prochaine
 * régression échouera ici au lieu d'être découverte des semaines plus tard par le client
 * devant la borne.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KioskCategoriesComponent from '../../resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

function makeStore(items = []) {
  return createStore({
    modules: {
      kioskMenu: {
        namespaced: true,
        state: () => ({ kioskSandwichSubcolumn: null }),
        getters: {
          categories: () => [{ id: 5, name: 'Tacos', kioskRowKey: 't' }],
          allItems: () => items,
          selectedCategoryId: () => 5,
          loading: () => false,
          isStale: () => false,
          fromCache: () => false,
          sidebarCategories: () => [{ id: 5, name: 'Tacos', kioskRowKey: 't' }],
          kioskCatalogItems: () => items,
        },
        actions: { fetchMenu: vi.fn().mockResolvedValue(), selectKioskCategory: vi.fn() },
      },
      kioskCart: {
        namespaced: true,
        state: () => ({ branchId: 1, kioskToken: 't' }),
        getters: { count: () => 0, total: () => 0, isEmpty: () => true },
        actions: { addItem: vi.fn(), reset: vi.fn() },
      },
      frontendItem: {
        namespaced: true,
        actions: { details: vi.fn().mockResolvedValue({ data: { data: { id: 1, name: 'Mock' } } }) },
      },
    },
  });
}

function mountGrid(items) {
  return mount(KioskCategoriesComponent, {
    global: {
      plugins: [
        makeStore(items),
        createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'fr', messages: { fr: frMessages } }),
      ],
      mocks: { $router: { push: vi.fn(), replace: vi.fn() }, $route: { query: {}, params: {} } },
      stubs: { KioskWizardComponent: true, transition: false },
    },
  });
}

const item = (id, name) => ({ id, name, item_category_id: 5, convert_price: 9, price: 9 });

beforeEach(() => {
  global.window = global.window || {};
});

describe('borne — chaque produit occupe toute la largeur', () => {
  const cas = [
    [1, 'kiosk-product-grid--solo'],
    [2, 'kiosk-product-grid--duo'],
    [3, 'kiosk-product-grid--trio'],
    [5, 'kiosk-product-grid--liste'],
    [15, 'kiosk-product-grid--liste'],
  ];

  cas.forEach(([n, attendu]) => {
    it(`${n} produit(s) → ${attendu}`, () => {
      const items = Array.from({ length: n }, (_, i) => item(i + 1, `Produit ${i + 1}`));
      expect(mountGrid(items).vm.productGridLayoutClass).toBe(attendu);
    });
  });

  it('AUCUN nombre de produits ne ramène la grille à 2 colonnes', () => {
    // `--quad` était la disposition 2 colonnes : c'est elle qui rendait les cartes
    // à 370 px et laissait un trou sur un nombre impair. Elle ne doit plus exister.
    for (let n = 1; n <= 20; n++) {
      const items = Array.from({ length: n }, (_, i) => item(i + 1, `P${i + 1}`));
      expect(mountGrid(items).vm.productGridLayoutClass).not.toContain('quad');
    }
  });
});

describe('borne — la différence de taille se voit à l\'œil', () => {
  const tacos = [item(1, 'Tacos M'), item(2, 'Tacos L'), item(3, 'Tacos XL')];

  it('M, L et XL reçoivent chacun un cran DIFFÉRENT', () => {
    const vm = mountGrid(tacos).vm;
    const m = vm.productSizeClass({ name: 'Tacos M' });
    const l = vm.productSizeClass({ name: 'Tacos L' });
    const xl = vm.productSizeClass({ name: 'Tacos XL' });

    expect(m).toBe('kiosk-product-image--size-m');
    expect(l).toBe('kiosk-product-image--size-l');
    expect(xl).toBe('kiosk-product-image--size-xl');

    // Le cœur de la régression de juillet : L et XL partageaient une seule classe.
    expect(new Set([m, l, xl]).size).toBe(3);
  });

  it('le XXL a son propre cran, au-dessus du XL', () => {
    const vm = mountGrid(tacos).vm;
    expect(vm.productSizeClass({ name: 'Tacos XXL' })).toBe('kiosk-product-image--size-xxl');
    expect(vm.productSizeClass({ name: 'Tacos XXL' }))
      .not.toBe(vm.productSizeClass({ name: 'Tacos XL' }));
  });

  it('les libellés flous restent au cran L — on ne promeut pas ce qui n\'a pas été demandé', () => {
    const vm = mountGrid(tacos).vm;
    // « grande / large / maxi » n'est PAS un XL : le promouvoir changerait des produits
    // que personne n'a demandé de toucher.
    expect(vm.productSizeClass({ name: 'Frites Grande' })).toBe('kiosk-product-image--size-l');
    expect(vm.productSizeClass({ name: 'Frites Petite' })).toBe('kiosk-product-image--size-m');
  });

  /**
   * CONSTAT, PAS CORRECTION. Le motif est ancré en FIN de nom (`…$`), or le français
   * met l'adjectif devant : « Grande Frites », « Petite Frites » — les deux vrais
   * produits de la carte — ne matchent donc RIEN et sortent à taille identique sur la
   * borne. C'est le même défaut que celui signalé pour le L et le XL, sur une autre
   * catégorie, et il est ANTÉRIEUR à ce changement (le motif n'a pas bougé).
   * Je le verrouille tel quel plutôt que de l'élargir en douce : c'est un arbitrage
   * produit qui revient au propriétaire.
   */
  it('CONSTAT — « Grande/Petite Frites » ne reçoivent aucun cran (défaut antérieur, hors périmètre)', () => {
    const vm = mountGrid(tacos).vm;
    expect(vm.productSizeClass({ name: 'Grande Frites' })).toBe('');
    expect(vm.productSizeClass({ name: 'Petite Frites' })).toBe('');
  });

  it('un produit sans taille ne reçoit aucun cran', () => {
    const vm = mountGrid(tacos).vm;
    expect(vm.productSizeClass({ name: 'Coca-Cola 33cl' })).toBe('');
    expect(vm.productSizeClass({ name: '' })).toBe('');
    expect(vm.productSizeClass(null)).toBe('');
  });

  it('les crans CSS sont strictement croissants M < L < XL < XXL', () => {
    // La classe seule ne prouve rien si les valeurs CSS ne montent pas : on lit
    // l'échelle réellement écrite dans le composant.
    const css = KioskCategoriesComponent.__cssModules
      ? ''
      : require('fs').readFileSync(
        require('path').resolve(__dirname, '../../resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue'),
        'utf8',
      );
    const lire = (cran) => {
      const m = css.match(new RegExp(`kiosk-product-image--size-${cran}\\s*\\{[^}]*scale\\(([0-9.]+)\\)`));
      return m ? parseFloat(m[1]) : null;
    };
    const [m, l, xl, xxl] = ['m', 'l', 'xl', 'xxl'].map(lire);
    expect(m).toBeGreaterThan(0);
    expect(l).toBeGreaterThan(m);
    expect(xl).toBeGreaterThan(l);
    expect(xxl).toBeGreaterThan(xl);
  });
});
