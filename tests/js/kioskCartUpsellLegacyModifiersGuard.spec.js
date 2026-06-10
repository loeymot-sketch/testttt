/**
 * [W-A BORNE regression 2026-06-10]
 *
 * Les lignes panier créées par l'upsell (FROZEN KioskUpsellComponent.vue:234)
 * portent l'ANCIEN format objet :
 *   item_variations: { variations: {}, names: {} }
 *   item_extras:     { extras: [], names: [] }
 * alors que les lignes wizard portent des tableaux. Avant le fix,
 * `cartLineAllergenSelections` (KioskCartComponent.vue) faisait
 * `(line.item_variations || []).map(...)` → TypeError
 * "(line.item_variations || []).map is not a function" (pageerror à chaque
 * rendu du panier contenant une ligne upsell — observé live W-A A5-accept).
 * Ce test verrouille le guard dual-format (même pattern que [GAP-22-2]).
 */
import { describe, it, expect } from 'vitest';
import KioskCartComponent from '../../resources/js/components/frontend/kiosk/KioskCartComponent.vue';

const method = KioskCartComponent.methods.cartLineAllergenSelections;

const catalogItem = {
  id: 52,
  variations: { Taille: [{ id: 9, name: '33cl' }] },
  extras: [{ id: 4, name: 'Glaçons' }],
};

function ctx() {
  return { cartLineCatalogItem: () => catalogItem };
}

describe('KioskCart cartLineAllergenSelections — dual format item_variations/item_extras', () => {
  it('ne jette pas sur une ligne upsell au format objet legacy', () => {
    const upsellLine = {
      item_id: 52,
      item_variations: { variations: {}, names: {} },
      item_extras: { extras: [], names: [] },
    };
    let out;
    expect(() => { out = method.call(ctx(), upsellLine); }).not.toThrow();
    expect(out).toEqual({ variations: [], extras: [] });
  });

  it('résout toujours les sélections au format tableau (wizard)', () => {
    const wizardLine = {
      item_id: 52,
      item_variations: [{ id: 9 }],
      item_extras: [{ id: 4 }],
    };
    const out = method.call(ctx(), wizardLine);
    expect(out.variations).toHaveLength(1);
    expect(out.variations[0].name).toBe('33cl');
    expect(out.extras).toHaveLength(1);
    expect(out.extras[0].name).toBe('Glaçons');
  });
});
