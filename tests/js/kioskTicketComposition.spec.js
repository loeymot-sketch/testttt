import { describe, expect, it } from 'vitest';
import { afterEach } from 'vitest';
import {
  kioskItemCompositionText,
  buildReceiptData,
  buildBridgePayload,
  borneTicketSize,
} from '../../resources/js/helpers/kioskPrinter';
import {
  saveKioskReceiptSnapshot,
  readKioskReceiptSnapshot,
  clearKioskReceiptSnapshot,
} from '../../resources/js/helpers/kioskReceiptPersistence';

// [BORNE-TICKET-COMPO 2026-06-28] Le ticket borne ne décrivait que la formule —
// sauce, crudités, viandes, suppléments étaient absents (buildInstruction du wizard
// frozen les omet). On reconstruit la compo COMPLÈTE depuis les champs structurés
// du cartItem (item_variations/extras/addons + _wizardSelections), hors frozen.

const cheeseBurger = {
  name: 'Cheese Burger',
  quantity: 1,
  item_variations: [
    { id: 489, variation_name: 'Sauce (1ère Gratuite)', name: 'Samouraï' },
  ],
  item_extras: [
    { id: 11, name: 'Salade' },
    { id: 12, name: 'Tomate' },
    { id: 13, name: 'Oignon' },
  ],
  item_addons: [
    { id: 7, name: 'Menu (Frites + Boisson)', role: 'menu_full' },
  ],
  _wizardSelections: { _boissonMeta: { boissonName: 'Oasis Tropical 33cl' } },
};

const megaTwoMeats = {
  name: 'Méga',
  quantity: 1,
  item_variations: [
    { id: 484, variation_name: 'Type de Pain', name: 'Galette' },
    { id: 472, variation_name: 'Viande 1', name: 'Nuggets' },
    { id: 480, variation_name: 'Viande 2', name: 'Tenders' },
    { id: 489, variation_name: 'Sauce (1ère Gratuite)', name: 'Samouraï' },
  ],
  item_extras: [{ id: 99, name: 'Cheddar' }],
  item_addons: [],
  _wizardSelections: {
    _tailleMeta: { label: 'Menu L' },
    instruction: 'sans cornichons',
  },
};

describe('kioskItemCompositionText — compo complète borne', () => {
  it('Cheese Burger : sauce + crudités + formule + boisson tous décrits', () => {
    const t = kioskItemCompositionText(cheeseBurger);
    expect(t).toMatch(/Sauce.*Samoura/i);          // sauce principale (que buildInstruction omettait)
    expect(t).toContain('Salade');                  // crudités (omises avant)
    expect(t).toContain('Tomate');
    expect(t).toContain('Oignon');
    expect(t).toMatch(/Formule/i);                  // formule
    expect(t).toContain('Oasis Tropical 33cl');     // nom de la boisson
  });

  it('Méga : 2 viandes fusionnées en 1 ligne, pain, sauce, supplément, taille, note — sans doublage', () => {
    const t = kioskItemCompositionText(megaTwoMeats);
    expect(t).toContain('Nuggets');
    expect(t).toContain('Tenders');
    // Une seule occurrence de chaque viande (pas de doublage)
    expect((t.match(/Nuggets/g) || []).length).toBe(1);
    expect((t.match(/Tenders/g) || []).length).toBe(1);
    expect(t).toMatch(/Galette/);                   // pain
    expect(t).toContain('Cheddar');                 // supplément
    expect(t).toMatch(/Menu L/);                    // taille
    expect(t).toMatch(/sans cornichons/i);          // note manuelle
  });

  it('item sans compo structurée → chaîne vide (fallback géré par buildReceiptData)', () => {
    expect(kioskItemCompositionText({ name: 'Coca-Cola 33cl', quantity: 2 })).toBe('');
    expect(kioskItemCompositionText(null)).toBe('');
  });

  it('formule boisson-seule (role menu_boisson) n’indique PAS frites', () => {
    const t = kioskItemCompositionText({
      name: 'Cayenne',
      item_addons: [{ id: 7, name: 'Menu (Frites + Boisson)', role: 'menu_boisson' }],
      _wizardSelections: { _boissonMeta: { boissonName: 'Coca-Cola 33cl' } },
    });
    expect(t).not.toMatch(/frite/i);
    expect(t).toContain('Coca-Cola 33cl');
  });
});

describe('buildReceiptData → instruction enrichie + fallback', () => {
  it('remplit instruction depuis la compo structurée', () => {
    const r = buildReceiptData({ cartItems: [cheeseBurger], total: 8.8 });
    expect(r.items).toHaveLength(1);
    expect(r.items[0].instruction).toMatch(/Sauce.*Samoura/i);
    expect(r.items[0].instruction).toContain('Salade');
  });

  it('fallback sur l’instruction existante si pas de compo structurée', () => {
    const r = buildReceiptData({
      cartItems: [{ name: 'X', quantity: 1, instruction: 'note libre' }],
      total: 1,
    });
    expect(r.items[0].instruction).toBe('note libre');
  });
});

describe('buildBridgePayload — le pont imprime la compo complète', () => {
  it('les lignes > contiennent sauce + crudités (plus seulement la formule)', () => {
    const receipt = buildReceiptData({ cartItems: [cheeseBurger], total: 8.8, queueNumber: 'A0009' });
    const payload = buildBridgePayload(receipt);
    const blob = payload.lines.join('\n');
    expect(blob).toMatch(/Samoura|Samourai/i);  // ascii-fold possible
    expect(blob).toContain('Salade');
    expect(blob).toContain('Tomate');
  });
});

describe('taille ticket borne — pilotée serveur, grande par défaut', () => {
  afterEach(() => { try { delete window.foodkingConfig; } catch (_) {} });

  it('défaut = double hauteur corps (0x01) + n° commande 2×2 (0x11)', () => {
    expect(borneTicketSize()).toEqual({ bodySize: 0x01, titleSize: 0x11 });
  });

  it('le payload porte bodySize/titleSize pour que le pont applique GS ! n', () => {
    const receipt = buildReceiptData({ cartItems: [cheeseBurger], total: 8.8, queueNumber: 'A0009' });
    const p = buildBridgePayload(receipt);
    expect(p.bodySize).toBe(0x01);
    expect(p.titleSize).toBe(0x11);
  });

  it('config serveur peut piloter la taille (window.foodkingConfig.borneTicket)', () => {
    window.foodkingConfig = { borneTicket: { bodySize: 0x11, titleSize: 0x22 } };
    expect(borneTicketSize()).toEqual({ bodySize: 0x11, titleSize: 0x22 });
    const p = buildBridgePayload(buildReceiptData({ cartItems: [cheeseBurger], total: 8.8 }));
    expect(p.bodySize).toBe(0x11);
  });

  it('en double LARGEUR (0x11) la compo wrappe plus court (jamais coupée)', () => {
    window.foodkingConfig = { borneTicket: { bodySize: 0x11, titleSize: 0x11 } };
    const p = buildBridgePayload(buildReceiptData({ cartItems: [cheeseBurger], total: 8.8 }));
    // largeur effective ~16 → aucune ligne du corps ne dépasse ~16 + le préfixe "  > "
    const longest = Math.max(...p.lines.map((l) => l.length));
    expect(longest).toBeLessThanOrEqual(20);
    // la compo reste présente (rien n'est perdu)
    expect(p.lines.join('\n')).toContain('Salade');
  });
});

describe('snapshot F5 — la compo survit au reprint après reload', () => {
  it('save → read conserve sauce + crudités dans instruction', () => {
    clearKioskReceiptSnapshot();
    saveKioskReceiptSnapshot({ total: 8.8, queueNumber: 'A0009', items: [cheeseBurger] });
    const snap = readKioskReceiptSnapshot();
    expect(snap).toBeTruthy();
    const instr = snap.items[0].instruction || '';
    expect(instr).toMatch(/Samoura/i);
    expect(instr).toContain('Salade');
    // Re-rendu depuis le snapshot (champs structurés absents) → fallback sur instruction
    const r = buildReceiptData({ cartItems: snap.items, total: 8.8 });
    expect(r.items[0].instruction).toContain('Salade');
    clearKioskReceiptSnapshot();
  });
});
