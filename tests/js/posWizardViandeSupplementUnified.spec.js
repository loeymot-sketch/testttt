/**
 * [VIANDE-SUPPL UNIFIÉ 2026-07-24 · LOCK_POS_WIZARD_VIANDE_SUPPL_UNIFIE] Geste fusionné caisse :
 * les MÊMES tuiles viande gèrent les viandes incluses (gratuites jusqu'au max du produit) PUIS
 * le supplément (au-delà = +2,50 € NOMMÉ). L'ancien toggle séparé est supprimé, « Viande
 * supplémentaire » n'est plus dans la liste des suppléments génériques, et le nom part dans
 * l'instruction « Viandes en plus : … » (résolu au ticket cuisine).
 * Le harness exécute le VRAI pos-wizard.js (rendu single-page + handlers).
 */
import { describe, it, expect } from 'vitest';
import { mountPosWizard, cayenneLikeItem } from './posWizardHarness.js';

const tick = (ms = 10) => new Promise((r) => setTimeout(r, ms));

// Item Tacos-like : 2 viandes incluses (nom « (2 viandes) ») + extra « Viande supplémentaire » @2,50.
function tacos2ViandesItem() {
  return cayenneLikeItem({
    name: 'Tacos L (2 viandes)',
    itemAttributes: [
      { id: 301, name: 'Viande 1', max_select: 1 },
      { id: 302, name: 'Viande 2', max_select: 1 },
      { id: 311, name: 'Sauce (1ère Gratuite)' },
    ],
    variations: {
      301: [
        { id: 9001, name: 'Poulet mariné', thumb: '' },
        { id: 9002, name: 'Mexicanos', thumb: '' },
        { id: 9003, name: 'Kefta', thumb: '' },
      ],
      302: [
        { id: 9001, name: 'Poulet mariné', thumb: '' },
        { id: 9002, name: 'Mexicanos', thumb: '' },
        { id: 9003, name: 'Kefta', thumb: '' },
      ],
      311: [{ id: 9101, name: 'Algérienne', thumb: null }],
    },
    extras: [
      { id: 52, name: 'Salade', convert_price: 0, currency_price: '€0.00', thumb: null },
      { id: 61, name: 'Cheddar', convert_price: 0.9, currency_price: '€0.90', thumb: null },
      { id: 398, name: 'Viande supplémentaire', convert_price: 2.5, currency_price: '€2.50', thumb: null },
    ],
  });
}

const plus = (wizard, id) => wizard.querySelector('.viande-tile-add.plus[data-viande="v_' + id + '"]');

describe('caisse — supplément viande unifié sur les tuiles', () => {
  it('« Viande supplémentaire » N\'EST PLUS dans la liste des suppléments génériques', async () => {
    const { wizard } = await mountPosWizard({ itemData: tacos2ViandesItem() });
    const supplTiles = Array.from(wizard.querySelectorAll('[data-type="supplement"]'));
    const names = supplTiles.map((t) => (t.textContent || '').toLowerCase());
    expect(names.some((n) => /viande\s*suppl/.test(n)), 'viande suppl exclue du générique').toBe(false);
    // Cheddar (vrai supplément) reste présent.
    expect(names.some((n) => n.includes('cheddar'))).toBe(true);
  });

  it('Merguez et Viande Hachée absentes des tuiles viande', async () => {
    const { wizard } = await mountPosWizard({ itemData: tacos2ViandesItem() });
    const txt = wizard.querySelector('.wizard-viande-grid')?.textContent || '';
    expect(/merguez/i.test(txt)).toBe(false);
    expect(/hach/i.test(txt)).toBe(false);
  });

  it('clic ≤ max = inclus gratuit ; clic au-delà = supplément @2,50 nommé sur la tuile', async () => {
    const { wizard } = await mountPosWizard({ itemData: tacos2ViandesItem() });
    // 2 viandes incluses : Poulet + Mexicanos (max=2)
    plus(wizard, 9001).click(); await tick();
    plus(wizard, 9002).click(); await tick();
    // à ce stade : 0 supplément (2/2 inclus)
    expect(wizard.querySelector('.viande-suppl-badge'), 'pas encore de supplément').toBeNull();
    expect(wizard.querySelector('.viande-tile-suppl-tag')).toBeNull();
    // 3ᵉ clic (Kefta) → au-delà du max → supplément @2,50
    plus(wizard, 9003).click(); await tick();
    const header = wizard.querySelector('.viande-suppl-badge');
    expect(header, 'badge supplément affiché').toBeTruthy();
    expect(header.textContent).toMatch(/2[.,]50/);
    // la tuile Kefta porte le tag +2,50
    const keftaTile = plus(wizard, 9003).closest('.wizard-viande-tile');
    expect(keftaTile.classList.contains('has-suppl')).toBe(true);
    expect(keftaTile.querySelector('.viande-tile-suppl-tag')?.textContent).toMatch(/2[.,]50/);
  });

  it('instruction cuisine : « Viandes en plus : Kefta » présente après supplément', async () => {
    const { wizard } = await mountPosWizard({ itemData: tacos2ViandesItem() });
    plus(wizard, 9001).click(); await tick();
    plus(wizard, 9002).click(); await tick();
    plus(wizard, 9003).click(); await tick(); // Kefta en supplément
    // [VIANDE-TICKET 2026-08-03] L'instruction SOUMISE (buildTicketInstruction →
    // .ticket-content, celle que le ticket cuisine parse via extraViandeNames) doit
    // porter la ligne dédiée. L'ancien assert sur le seul RÉCAP (buildWizardInstruction)
    // était vert alors que la cuisine recevait l'extra générique sans type.
    const ticket = wizard.querySelector('.ticket-content');
    expect(ticket, 'panneau ticket single-page présent').toBeTruthy();
    expect(ticket.textContent).toMatch(/Viandes en plus\s*:\s*Kefta/i);
  });

  it('retrait : − enlève d\'abord le supplément, puis l\'inclus', async () => {
    const { wizard } = await mountPosWizard({ itemData: tacos2ViandesItem() });
    plus(wizard, 9001).click(); await tick();
    plus(wizard, 9002).click(); await tick();
    plus(wizard, 9003).click(); await tick(); // Kefta suppl
    // Kefta count = 1 (0 inclus + 1 suppl) → − retire le suppl
    const minus = wizard.querySelector('.viande-tile-minus.minus[data-viande="v_9003"]');
    expect(minus, 'bouton retirer Kefta').toBeTruthy();
    minus.click(); await tick();
    expect(wizard.querySelector('.viande-suppl-badge'), 'supplément retiré').toBeNull();
  });
});
