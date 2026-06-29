import { describe, it, expect } from 'vitest';

/**
 * [FIX P0 2026-06-30] Régression : un Tacos L (2 viandes) sortait du wizard SANS sa
 * "Viande 2" → le quote backend rejetait "Viande 2 (actuel: 0)" → produits multi-viandes
 * (Tacos L/XL/XXL, Méga, Terminator, Suprême) INCOMMANDABLES sur la borne.
 *
 * Ce test verrouille l'algorithme de distribution des viandes ajouté dans
 * KioskWizardComponent.buildCartItem() : chaque viande sélectionnée va sur SON attribut
 * "Viande N" (Viande 1, Viande 2, …), en prenant la variation de ce NOM sous CET attribut.
 *
 * Data réelle (foodking_e2e) : chaque viande existe en variation sous CHAQUE attribut
 * (ex. "Viande Hachée" = id 363 sous Viande 1, id 370 sous Viande 2).
 */

// Reproduction EXACTE du bloc viande de buildCartItem (KioskWizardComponent.vue).
function distributeViandes(item, viandeMeta) {
  const allVariations = {};
  const allVariationNames = {};
  const variationViandes = (viandeMeta || []).filter(v => v.source === 'variation' && typeof v.id === 'number');
  if (variationViandes.length && Array.isArray(item.itemAttributes)) {
    const viandeAttrs = item.itemAttributes
      .filter(a => (a.name || '').toLowerCase().includes('viande'))
      .sort((a, b) => (Number(a.id) || 0) - (Number(b.id) || 0));
    const slots = [];
    variationViandes.forEach(v => { for (let i = 0; i < Math.max(1, Number(v.count) || 1); i++) slots.push(v); });
    const allVars = Array.isArray(item.variations) ? item.variations : [];
    slots.forEach((v, idx) => {
      const attr = viandeAttrs[idx];
      if (!attr) return;
      const match = allVars.find(x =>
        String(x.item_attribute_id) === String(attr.id) &&
        (x.name || '').trim().toLowerCase() === (v.name || '').trim().toLowerCase()
      );
      const varId = match ? match.id : (idx === 0 ? v.id : null);
      if (varId) { allVariations[attr.id] = varId; allVariationNames[attr.name] = v.name; }
    });
  }
  return { allVariations, allVariationNames };
}

// Fixture = vraie structure Tacos L (item_id 97) : Viande 1 (attr 1) + Viande 2 (attr 2),
// chaque viande déclinée sous les 2 attributs avec des IDs distincts.
const tacosL = {
  id: 97, name: 'Tacos L',
  itemAttributes: [
    { id: 1, name: 'Viande 1' },
    { id: 2, name: 'Viande 2' },
    { id: 5, name: 'Sauce (1ère Gratuite)' },
  ],
  variations: [
    { id: 361, name: 'Mexicanos', item_attribute_id: 1 },
    { id: 363, name: 'Viande Hachée', item_attribute_id: 1 },
    { id: 365, name: 'Tenders', item_attribute_id: 1 },
    { id: 368, name: 'Mexicanos', item_attribute_id: 2 },
    { id: 370, name: 'Viande Hachée', item_attribute_id: 2 },
    { id: 372, name: 'Tenders', item_attribute_id: 2 },
    { id: 379, name: 'Samouraï', item_attribute_id: 5 },
  ],
};

describe('Wizard borne — distribution multi-viandes (FIX P0)', () => {
  it('2 viandes différentes → Viande 1 ET Viande 2 remplies (variation du bon attribut)', () => {
    const meta = [
      { id: 361, name: 'Mexicanos', source: 'variation', attrId: 1, count: 1 },
      { id: 363, name: 'Viande Hachée', source: 'variation', attrId: 1, count: 1 },
    ];
    const { allVariations } = distributeViandes(tacosL, meta);
    // Viande 1 = Mexicanos (variation attr-1), Viande 2 = Viande Hachée (variation attr-2 = 370, PAS 363)
    expect(allVariations[1]).toBe(361);
    expect(allVariations[2]).toBe(370);
    // les DEUX attributs viande sont remplis (le bug d'avant n'en remplissait qu'un)
    expect(Object.keys(allVariations).filter(k => k === '1' || k === '2')).toHaveLength(2);
  });

  it('2 fois la même viande → Viande 1 ET Viande 2 = cette viande (sous chaque attribut)', () => {
    const meta = [{ id: 365, name: 'Tenders', source: 'variation', attrId: 1, count: 2 }];
    const { allVariations } = distributeViandes(tacosL, meta);
    expect(allVariations[1]).toBe(365); // Tenders sous Viande 1
    expect(allVariations[2]).toBe(372); // Tenders sous Viande 2
  });

  it('produit 1 viande (Tacos M) → seule Viande 1 remplie, pas d’erreur', () => {
    const tacosM = { ...tacosL, itemAttributes: [{ id: 1, name: 'Viande 1' }, { id: 5, name: 'Sauce' }] };
    const meta = [{ id: 361, name: 'Mexicanos', source: 'variation', attrId: 1, count: 1 }];
    const { allVariations } = distributeViandes(tacosM, meta);
    expect(allVariations[1]).toBe(361);
    expect(allVariations[2]).toBeUndefined();
  });

  it('SENTINELLE anti-régression : l’ancienne logique (1ère viande seule) ne suffirait PAS', () => {
    // L'ancien code ne remplissait que la 1ère attr viande → Viande 2 vide → quote rejeté.
    const meta = [
      { id: 361, name: 'Mexicanos', source: 'variation', attrId: 1, count: 1 },
      { id: 363, name: 'Viande Hachée', source: 'variation', attrId: 1, count: 1 },
    ];
    const { allVariations } = distributeViandes(tacosL, meta);
    expect(allVariations[2]).toBeDefined(); // DOIT être rempli (sinon régression du bug P0)
  });
});
