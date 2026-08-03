/**
 * [OWNER 2026-07-28] CAISSE — les FRITES doivent proposer une étape SAUCE multi (plainte : « pour
 * ajouter des frites je trouve pas l'option de choisir la sauce »). Le wizard caisse (FROZEN
 * public/js/pos-wizard.js) rend la section sauce de façon DATA-DRIVEN : dès que l'item porte un
 * attribut « Sauce (…) » (hors « frites ») avec des variations, `renderSinglePage` affiche
 * `.sauce-section` (1ère gratuite, chaque sauce en plus +0,50 via « Sauce supplémentaire »).
 * Ce harnais exécute le VRAI pos-wizard.js — aucune modif frozen, on prouve juste que la DATA
 * ajoutée par menu:ensure-frites-sauce-step suffit à faire apparaître + tarifer la sauce en caisse.
 */
import { describe, it, expect } from 'vitest';
import { mountPosWizard, tick } from './posWizardHarness.js';

// Payload frites tel que projeté par ItemResource APRÈS la commande : attribut sauce id 5 +
// 12 variations (prix 0) + ItemExtra « Sauce supplémentaire » @0,50. Nom « Petite Frites ».
function fritesWithSauceItem() {
  const sauces = [
    'Mayonnaise', 'Ketchup', 'Blanche', 'Hannibal', 'Samouraï', 'Algérienne',
    'Andalouse', 'Curry', 'Barbecue', 'Harissa', 'Fromagère maison', 'Spicy maison',
  ].map((name, i) => ({ id: 5000 + i, name, thumb: null }));

  return {
    id: 33,
    name: 'Petite Frites',
    description: '',
    category_name: 'Frites',
    convert_price: 2.5,
    currency_price: '€2.50',
    thumb: '',
    itemAttributes: [
      { id: 5, name: 'Sauce (1ère Gratuite)' },
    ],
    variations: { 5: sauces },
    extras: [
      { id: 700, name: 'Sauce supplémentaire', convert_price: 0.5, currency_price: '€0.50', thumb: null },
    ],
    addons: [],
  };
}

const sauceChips = (wizard) => wizard.querySelectorAll('.sauce-section .sauce-chip');
const chip = (wizard, id) => wizard.querySelector('.sauce-section .sauce-chip[data-id="s_' + id + '"]');

describe('CAISSE frites — étape sauce data-driven (owner 2026-07-28)', () => {
  it('la section SAUCE s\'affiche avec les 12 sauces + badge « 1ère gratuite »', async () => {
    const { wizard } = await mountPosWizard({ itemData: fritesWithSauceItem() });
    expect(wizard, 'le wizard s\'ouvre pour des frites (plus un simple produit)').not.toBeNull();

    const section = wizard.querySelector('.sauce-section');
    expect(section, 'section sauce rendue pour les frites').not.toBeNull();
    expect(sauceChips(wizard).length).toBe(12);

    const badge = section.querySelector('.sauce-badge.free');
    expect(badge, 'badge 1ère gratuite au départ').not.toBeNull();
    expect(badge.textContent).toMatch(/1ère gratuite/i);
  });

  it('1ère sauce = gratuite ; 2ᵉ sauce = +0,50 (multi, money-path « comme la logique d\'avant »)', async () => {
    const { wizard } = await mountPosWizard({ itemData: fritesWithSauceItem() });
    const total = (root) => root.querySelector('.sticky-total .total-value')?.textContent || '';

    // 1ère sauce → sélectionnée, section « ✅ Gratuite », total inchangé (2,50).
    chip(wizard, 5000).click();
    await tick(20);
    let root = document.getElementById('pos-wizard-root');
    expect(chip(root, 5000).classList.contains('selected')).toBe(true);
    const freeBadge = root.querySelector('.sauce-section .sauce-badge.free');
    expect(freeBadge, 'badge section « ✅ Gratuite » à 1 sauce').not.toBeNull();
    expect(total(root)).toMatch(/2[.,]50/);

    // 2ᵉ sauce → sélectionnée, section bascule « +0,50 », total = 3,00.
    chip(root, 5001).click();
    await tick(20);
    root = document.getElementById('pos-wizard-root');
    expect(chip(root, 5001).classList.contains('selected')).toBe(true);
    const paidBadge = root.querySelector('.sauce-section .sauce-badge.paid');
    expect(paidBadge, 'badge section « +0,50 » à 2 sauces').not.toBeNull();
    expect(paidBadge.textContent).toMatch(/0[.,]50/);
    expect(total(root), '2,50 + 0,50 = 3,00 (2ᵉ sauce facturée)').toMatch(/3[.,]00/);
  });
});
