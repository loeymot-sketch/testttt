/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P0-16 / triage A5]
 *
 * Défaut reproduit : dans l'étape « Menu complet → Choix de la boisson » de la
 * borne, une boisson en RUPTURE restait sélectionnable. Le catalogue peignait
 * bien « ÉPUISÉE » sur la tuile produit, mais la grille de boissons du wizard
 * ignorait le champ `is_available` que le backend envoie pourtant déjà
 * (`KioskMenuService::menuPayload` → `addons[].is_available`, calculé par
 * `ChoiceAvailabilityResolver::availabilityForAddonItem`, qui replie le 86
 * par branche). Résultat : le client composait un menu avec une boisson
 * réellement indisponible.
 *
 * Deux jumeaux du dépôt font DÉJÀ ce filtrage — ce correctif ne fait que
 * rétablir la symétrie, il n'invente aucune règle :
 *   - `public/js/pos-wizard.js:609` (zone gelée §7, NON modifiée) :
 *     `if (d.is_available === false) continue; // rupture stock → pas proposée`
 *   - le repli catalogue du MÊME composant, `KioskStepMenuComponent.isDrinkCatalogItem` :
 *     `if (row.is_available === false) return false;`
 *
 * Sûreté : `KioskStepMenuComponent.needsExplicitBoissonSelection` renvoie
 * `false` quand la liste est vide, donc filtrer ne peut PAS bloquer une
 * commande — au pire l'étape n'exige plus de boisson. Vérifié avant correctif.
 *
 * Le contrôle est STRICT (`=== false`) à dessein : une clé absente ou nulle
 * ne doit jamais masquer tout le catalogue de boissons.
 */
import { describe, it, expect } from 'vitest';
import { kioskDrinkAddonRowsFromItem } from '../../resources/js/helpers/kioskDrinkAddons';

describe('borne — une boisson épuisée ne doit pas être proposée dans l’étape menu', () => {
  it('exclut une boisson dont is_available est false (le cas Fanta Citron 33cl du rapport)', () => {
    const item = {
      addons: [
        { addon_item_name: 'Coca-Cola 33cl', group_label: 'boisson', addon_item_id: 12, is_available: true },
        {
          addon_item_name: 'Fanta Citron 33cl',
          group_label: 'boisson',
          addon_item_id: 13,
          is_available: false,
          unavailable_reason: 'branch_unavailable',
        },
      ],
    };

    const rows = kioskDrinkAddonRowsFromItem(item);

    expect(rows.map((r) => r.addon_item_name)).toEqual(['Coca-Cola 33cl']);
  });

  it('garde les boissons disponibles quand is_available est true', () => {
    const item = {
      addons: [
        { addon_item_name: 'Coca-Cola 33cl', group_label: 'boisson', addon_item_id: 12, is_available: true },
        { addon_item_name: 'Fanta Orange 33cl', group_label: 'boisson', addon_item_id: 14, is_available: true },
      ],
    };

    expect(kioskDrinkAddonRowsFromItem(item)).toHaveLength(2);
  });

  it('ne masque RIEN quand le champ is_available est absent (payload legacy)', () => {
    const item = {
      addons: [
        { addon_item_name: 'Coca-Cola 33cl', group_label: 'boisson', addon_item_id: 12 },
        { addon_item_name: 'Oasis Tropical', group_label: 'boisson', addon_item_id: 15 },
      ],
    };

    expect(kioskDrinkAddonRowsFromItem(item)).toHaveLength(2);
  });

  it('ne masque rien quand is_available est null (le résolveur n’a rien tranché)', () => {
    const item = {
      addons: [
        { addon_item_name: 'Coca-Cola 33cl', group_label: 'boisson', addon_item_id: 12, is_available: null },
      ],
    };

    expect(kioskDrinkAddonRowsFromItem(item)).toHaveLength(1);
  });

  it('traite is_available = 0 comme une rupture (booléen sérialisé en entier par l’API)', () => {
    const item = {
      addons: [
        { addon_item_name: 'Coca-Cola 33cl', group_label: 'boisson', addon_item_id: 12, is_available: 1 },
        { addon_item_name: 'Fanta Citron 33cl', group_label: 'boisson', addon_item_id: 13, is_available: 0 },
      ],
    };

    const rows = kioskDrinkAddonRowsFromItem(item);

    expect(rows.map((r) => r.addon_item_name)).toEqual(['Coca-Cola 33cl']);
  });

  it('cumule le filtre de rupture et le filtre « boisson générique » déjà en place', () => {
    const item = {
      addons: [
        { addon_item_name: 'Boisson seule', group_label: 'boisson', addon_item_id: 10, is_available: true },
        { addon_item_name: 'Frites à part', group_label: 'menu_frites', addon_item_id: 11, is_available: true },
        { addon_item_name: 'Coca-Cola 33cl', group_label: 'boisson', addon_item_id: 12, is_available: true },
        { addon_item_name: 'Fanta Citron 33cl', group_label: 'boisson', addon_item_id: 13, is_available: false },
      ],
    };

    const rows = kioskDrinkAddonRowsFromItem(item);

    expect(rows.map((r) => r.addon_item_name)).toEqual(['Coca-Cola 33cl']);
  });
});
