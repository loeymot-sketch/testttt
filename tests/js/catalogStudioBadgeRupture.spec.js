/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P1-30 / triage A5]
 *
 * Défaut reproduit : la page « Catalogue » du back-office (entrée de menu
 * `items/studio` → `CatalogStudioComponent`) affichait `Actif` pour des produits
 * que le POS signale indisponibles à l'ouverture de la catégorie — le rapport
 * cite `Bol Riz`, `Glace` et `Fanta Citron 33cl`.
 *
 * Ce n'est PAS une divergence de backend : `ItemService::applyBranchAvailabilityOverlay`
 * calcule l'overlay une seule fois et `SimpleItemResource` expose le champ
 * canonique `is_available` ; les deux écrans consomment le MÊME endpoint.
 * `ItemListComponent` (page /admin/items) replie déjà correctement les deux
 * notions via `isItemRuptured` — décision explicite du dépôt, MISSION FIX D4
 * 2026-05-21 : « Status pill must reflect per-branch availability, not just
 * items.status ». `CatalogStudioComponent` est le jumeau qui n'a jamais reçu ce
 * correctif : il ne lit que `item.status`.
 *
 * `status = ACTIVE (5)` est le statut catalogue GLOBAL ; la rupture vient de
 * `item_branch_availability`. Afficher « Actif » sur un produit en rupture fait
 * croire au gérant que le produit est vendable.
 *
 * Le prédicat est extrait dans un helper partagé pour que les deux écrans
 * cessent de porter deux copies de la même règle.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { itemIsRuptured } from '../../resources/js/helpers/itemRupture';
import CatalogStudioComponent from '../../resources/js/components/admin/items/CatalogStudioComponent.vue';
import ItemListComponent from '../../resources/js/components/admin/items/ItemListComponent.vue';

describe('helper partagé itemIsRuptured', () => {
  it('détecte la rupture sur les trois formes sérialisées du booléen', () => {
    expect(itemIsRuptured({ is_available: false })).toBe(true);
    expect(itemIsRuptured({ is_available: 0 })).toBe(true);
    expect(itemIsRuptured({ is_available: '0' })).toBe(true);
  });

  it('ne déclare PAS en rupture un produit disponible', () => {
    expect(itemIsRuptured({ is_available: true })).toBe(false);
    expect(itemIsRuptured({ is_available: 1 })).toBe(false);
    expect(itemIsRuptured({ is_available: '1' })).toBe(false);
  });

  /**
   * Garde volontaire : une clé absente ne doit JAMAIS peindre tout le
   * catalogue en rupture. C'est la raison du test strict `=== false/0/'0'`
   * déjà présent dans ItemListComponent.
   */
  it('ne déclare rien en rupture quand l’information est absente', () => {
    expect(itemIsRuptured({})).toBe(false);
    expect(itemIsRuptured({ is_available: undefined })).toBe(false);
    expect(itemIsRuptured({ is_available: null })).toBe(false);
    expect(itemIsRuptured(null)).toBe(false);
    expect(itemIsRuptured(undefined)).toBe(false);
  });

  it('ignore le statut catalogue global — un ACTIF peut être en rupture de branche', () => {
    // Status::ACTIVE = 5 (app/Enums/Status.php).
    expect(itemIsRuptured({ status: 5, is_available: false })).toBe(true);
    expect(itemIsRuptured({ status: 5, is_available: true })).toBe(false);
  });
});

describe('CatalogStudioComponent — le badge ne peut plus annoncer « Actif » sur une rupture', () => {
  it('expose le prédicat de rupture', () => {
    expect(typeof CatalogStudioComponent.methods.isItemRuptured).toBe('function');
  });

  it('délègue au helper partagé et non à une copie locale', () => {
    const call = (i) => CatalogStudioComponent.methods.isItemRuptured.call(null, i);
    expect(call({ status: 5, is_available: false })).toBe(true);
    expect(call({ status: 5, is_available: true })).toBe(false);
    expect(call({ status: 5 })).toBe(false);
  });

  /**
   * Garde de PÉRIMÈTRE : un prédicat correct ne prouve rien si le gabarit ne
   * l'appelle pas. On vérifie que la cellule de statut rend bien la pastille
   * de rupture AVANT la pastille de statut, comme le fait déjà ItemList.
   */
  it('le gabarit rend la pastille de rupture à la place du statut', () => {
    const src = readFileSync(
      resolve(__dirname, '../../resources/js/components/admin/items/CatalogStudioComponent.vue'),
      'utf8'
    );

    expect(src).toContain('isItemRuptured(item)');

    const iRupture = src.indexOf('isItemRuptured(item)');
    const iStatut = src.indexOf('statusEnumArray[item.status]');
    expect(iRupture).toBeGreaterThan(-1);
    expect(iStatut).toBeGreaterThan(-1);
    // La pastille de rupture doit précéder le v-else qui rend le statut global.
    expect(iRupture).toBeLessThan(iStatut);
    expect(src).toContain('v-else');
  });
});

describe('ItemListComponent — parité conservée après extraction', () => {
  it('rend le même verdict que le helper partagé', () => {
    const cas = [
      { status: 5, is_available: false },
      { status: 5, is_available: 0 },
      { status: 5, is_available: '0' },
      { status: 5, is_available: true },
      { status: 5 },
      null,
    ];
    cas.forEach((i) => {
      expect(ItemListComponent.methods.isItemRuptured.call(null, i)).toBe(itemIsRuptured(i));
    });
  });
});
