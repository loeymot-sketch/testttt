import { describe, it, expect, beforeEach, vi } from 'vitest';
import { createStore } from 'vuex';
import { kioskCart, buildKioskOrderPayload } from '../../resources/js/store/modules/kioskCart.js';

/**
 * [P-MEGA-05] Edit roundtrip cart → wizard → wizard save → cart.
 *
 * Couvre :
 *   - Le store mémorise l'index + snapshot, sans toucher au panier.
 *   - replaceEditingCartItem remplace en place (préserve l'ordre).
 *   - cancelEditingCartItem n'altère PAS le panier (ligne intacte).
 *   - L'item est intact si l'utilisateur abandonne.
 *   - `_wizardSelections` n'est JAMAIS sérialisé vers le serveur
 *     (sanitizeKioskOrderItem whitelist stricte).
 *   - RESET (logout, idle) clear l'état d'édition.
 *   - Race condition : addToCart après cancelEditingCartItem fait un ADD,
 *     pas un crash.
 */

vi.mock('../../resources/js/helpers/kioskOfflineQueue', () => ({
  saveOrder: vi.fn(),
  getPendingCount: vi.fn(() => 0),
  startAutoSync: vi.fn(),
}));
vi.mock('../../resources/js/helpers/kioskMenuCache', () => ({
  isSnapshotStale: vi.fn(() => false),
  loadSnapshot: vi.fn(() => null),
}));

// Vuex module state is a shared object literal across createStore calls,
// so we deep-clone the module on each test to avoid cross-test pollution.
const cloneModule = () => ({
  ...kioskCart,
  state: JSON.parse(JSON.stringify(kioskCart.state)),
});
const makeStore = () => createStore({ modules: { kioskCart: cloneModule() } });

const sampleLine = (overrides = {}) => ({
  item_id: 42,
  name: 'Tacos XL 3 viandes',
  quantity: 2,
  convert_price: 9,
  currency_price: '9,00 €',
  item_variations: [{ id: 11, variation_name: 'Viande', name: 'Boeuf' }],
  item_extras: [{ id: 301, name: 'Tomate' }],
  item_variation_total: 0,
  item_extra_total: 0,
  total: 18,
  instruction: '',
  _wizardSelections: {
    pain: null,
    taille: 'XL',
    _tailleMeta: { viandeCount: 3, label: 'XL' },
    _viandeMeta: [
      { id: 11, key: 'var_11', name: 'Boeuf', source: 'variation', count: 2 },
      { id: 12, key: 'var_12', name: 'Poulet', source: 'variation', count: 1 },
    ],
    viandes: { 11: 2, 12: 1 },
    totalViandes: 3,
    sauces: { 21: true },
    sauceOrder: [21],
    garnitures: { 301: true },
    supplements: {},
    menuChoice: null,
    boissonChoice: null,
    fritesSauce: null,
    fritesSauceOrder: [],
    quantity: 2,
    instruction: '',
  },
  ...overrides,
});

describe('[P-MEGA-05] kioskCart store — edit roundtrip', () => {
  let store;
  beforeEach(() => {
    store = makeStore();
  });

  it('startEditingCartItem mémorise index + snapshot SANS toucher au panier', async () => {
    await store.dispatch('kioskCart/addItem', sampleLine());
    await store.dispatch('kioskCart/addItem', sampleLine({ item_id: 99, name: 'Burger', item_extras: [] }));
    expect(store.getters['kioskCart/items']).toHaveLength(2);

    const ok = await store.dispatch('kioskCart/startEditingCartItem', 0);
    expect(ok).toBe(true);
    expect(store.getters['kioskCart/items']).toHaveLength(2);
    expect(store.getters['kioskCart/isEditingCart']).toBe(true);
    expect(store.getters['kioskCart/editingCartIndex']).toBe(0);
    expect(store.getters['kioskCart/editingCartSnapshot'].item_id).toBe(42);
  });

  it('startEditingCartItem retourne false si index invalide (pas de mutation)', async () => {
    const ok = await store.dispatch('kioskCart/startEditingCartItem', 999);
    expect(ok).toBe(false);
    expect(store.getters['kioskCart/isEditingCart']).toBe(false);
  });

  it('snapshot est un DEEP CLONE — mutation ultérieure du panier ne contamine pas le snapshot', async () => {
    await store.dispatch('kioskCart/addItem', sampleLine());
    await store.dispatch('kioskCart/startEditingCartItem', 0);
    const snap = store.getters['kioskCart/editingCartSnapshot'];

    // Modifier la ligne du panier ne doit PAS toucher le snapshot store
    store.state.kioskCart.items[0].quantity = 99;
    expect(snap.quantity).toBe(2);
  });

  it('replaceEditingCartItem remplace EN PLACE (préserve l\'ordre)', async () => {
    await store.dispatch('kioskCart/addItem', sampleLine({ item_id: 1, name: 'Burger A', item_variations: [], item_extras: [] }));
    await store.dispatch('kioskCart/addItem', sampleLine({ item_id: 42, name: 'Tacos' }));
    await store.dispatch('kioskCart/addItem', sampleLine({ item_id: 99, name: 'Boisson', item_variations: [], item_extras: [{ id: 999, name: 'Glaçons' }] }));
    await store.dispatch('kioskCart/startEditingCartItem', 1);

    const newCartItem = sampleLine({ item_id: 42, quantity: 5, name: 'Tacos modifié', item_extras: [{ id: 302, name: 'Salade' }] });
    await store.dispatch('kioskCart/replaceEditingCartItem', newCartItem);

    const items = store.getters['kioskCart/items'];
    expect(items).toHaveLength(3);
    expect(items[0].name).toBe('Burger A');
    expect(items[1].name).toBe('Tacos modifié');
    expect(items[1].quantity).toBe(5);
    expect(items[2].name).toBe('Boisson');
    expect(store.getters['kioskCart/isEditingCart']).toBe(false);
  });

  it('cancelEditingCartItem laisse la ligne ORIGINALE intacte', async () => {
    await store.dispatch('kioskCart/addItem', sampleLine());
    await store.dispatch('kioskCart/startEditingCartItem', 0);
    await store.dispatch('kioskCart/cancelEditingCartItem');

    expect(store.getters['kioskCart/items']).toHaveLength(1);
    expect(store.getters['kioskCart/items'][0].quantity).toBe(2);
    expect(store.getters['kioskCart/isEditingCart']).toBe(false);
  });

  it('replaceEditingCartItem sans édition active fait un ADD (race condition safe)', async () => {
    expect(store.getters['kioskCart/isEditingCart']).toBe(false);
    await store.dispatch('kioskCart/replaceEditingCartItem', sampleLine({ item_id: 7 }));
    expect(store.getters['kioskCart/items']).toHaveLength(1);
    expect(store.getters['kioskCart/items'][0].item_id).toBe(7);
  });

  it('REPLACE_ITEM_AT clamp la quantité (cohérent avec ADD_ITEM)', async () => {
    await store.dispatch('kioskCart/addItem', sampleLine());
    await store.dispatch('kioskCart/startEditingCartItem', 0);
    await store.dispatch('kioskCart/replaceEditingCartItem', sampleLine({ quantity: 999 }));
    expect(store.getters['kioskCart/items'][0].quantity).toBeLessThanOrEqual(20);
  });

  it('REPLACE_ITEM_AT recompute total si absent', async () => {
    await store.dispatch('kioskCart/addItem', sampleLine());
    await store.dispatch('kioskCart/startEditingCartItem', 0);
    const lineNoTotal = sampleLine({ total: undefined, quantity: 3, convert_price: 10 });
    await store.dispatch('kioskCart/replaceEditingCartItem', lineNoTotal);
    expect(store.getters['kioskCart/items'][0].total).toBe(30);
  });

  it('reset clear l\'état d\'édition (idle, logout)', async () => {
    await store.dispatch('kioskCart/addItem', sampleLine());
    await store.dispatch('kioskCart/startEditingCartItem', 0);
    await store.dispatch('kioskCart/reset');
    expect(store.getters['kioskCart/items']).toHaveLength(0);
    expect(store.getters['kioskCart/isEditingCart']).toBe(false);
  });

  it('_wizardSelections n\'est JAMAIS sérialisé vers le serveur (sanitize whitelist)', async () => {
    await store.dispatch('kioskCart/addItem', sampleLine());
    const payload = buildKioskOrderPayload(store.state.kioskCart, { paymentMethod: 'cash' });
    const items = JSON.parse(payload.items);
    expect(items).toHaveLength(1);
    expect(items[0]).toEqual({
      item_id: 42,
      instruction: '',
      quantity: 2,
      item_variations: [{ id: 11, variation_name: 'Viande', name: 'Boeuf' }],
      item_extras: [{ id: 301, name: 'Tomate' }],
    });
    expect(items[0]._wizardSelections).toBeUndefined();
    expect(items[0].name).toBeUndefined();
    expect(items[0].convert_price).toBeUndefined();
    expect(items[0].total).toBeUndefined();
  });

  it('snapshot conserve _wizardSelections complet pour restoration fidèle', async () => {
    await store.dispatch('kioskCart/addItem', sampleLine());
    await store.dispatch('kioskCart/startEditingCartItem', 0);
    const snap = store.getters['kioskCart/editingCartSnapshot'];
    expect(snap._wizardSelections).toBeDefined();
    expect(snap._wizardSelections.viandes).toEqual({ 11: 2, 12: 1 });
    expect(snap._wizardSelections.totalViandes).toBe(3);
    expect(snap._wizardSelections._viandeMeta).toHaveLength(2);
    expect(snap._wizardSelections.sauces).toEqual({ 21: true });
    expect(snap._wizardSelections._tailleMeta).toEqual({ viandeCount: 3, label: 'XL' });
  });
});
