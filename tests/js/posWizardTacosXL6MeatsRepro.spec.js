/**
 * [Root cause 2026-09-20, audit externe — reproduction] Commande POS #2009261353 / A0057 :
 * 6 viandes choisies sur un Tacos XL, une seule a disparu à la validation (commande créée à
 * 17,90 € au lieu des 20,40 € affichés au configurateur).
 *
 * Hypothèse de root cause : les fixtures de test existantes (posWizardHarness.cayenneLikeItem,
 * tacos2ViandesItem) réutilisent le MÊME id de variation pour un même nom de viande sous
 * plusieurs attributs (« Poulet mariné » = id 9001 partout). La VRAIE base de données assigne
 * un id DISTINCT par attribut pour le même nom (vérifié en base : Mexicanos = 777 sous
 * « Viande 1 », 784 sous « Viande 2 », 791 sous « Viande 3 »). Cette fixture reproduit fidèlement
 * cette structure réelle pour vérifier si le wizard perd une viande dans ce cas précis.
 */
import { describe, it, expect } from 'vitest';
import { mountPosWizard, cayenneLikeItem } from './posWizardHarness.js';

const tick = (ms = 15) => new Promise((r) => setTimeout(r, ms));

const MEAT_NAMES = ['Mexicanos', 'Cordon Bleu', 'Viande Hachée', 'Nuggets', 'Tenders', 'Fricadelle', 'Poulet mariné'];

function tacosXLRealShapeItem() {
  // Ids DISTINCTS par attribut pour le même nom, comme en base réelle (777/784/791 pour
  // Mexicanos, etc.) — PAS les ids partagés des fixtures existantes.
  function variationsFor(attrBase) {
    return MEAT_NAMES.map((name, i) => ({ id: attrBase * 100 + i, name, thumb: '' }));
  }
  return cayenneLikeItem({
    name: 'Tacos XL',
    itemAttributes: [
      { id: 1, name: 'Viande 1', max_select: 1 },
      { id: 2, name: 'Viande 2', max_select: 1 },
      { id: 3, name: 'Viande 3', max_select: 1 },
      { id: 5, name: 'Sauce (1ère Gratuite)' },
    ],
    variations: {
      1: variationsFor(1),
      2: variationsFor(2),
      3: variationsFor(3),
      5: [{ id: 9101, name: 'Mayonnaise', thumb: null }],
    },
    extras: [
      { id: 614, name: 'Viande supplémentaire', convert_price: 2.5, currency_price: '€2.50', thumb: null },
      { id: 585, name: 'Sauce supplémentaire', convert_price: 0.5, currency_price: '€0.50', thumb: null },
    ],
  });
}

describe('POS wizard — Tacos XL, 6 viandes distinctes (reproduction audit)', () => {
  it('clique 6 tuiles viande différentes et vérifie qu\'aucune ne disparaît', async () => {
    const item = tacosXLRealShapeItem();
    const { wizard } = await mountPosWizard({ itemData: item });
    expect(wizard, 'wizard monté').toBeTruthy();

    // Les tuiles viande sont rendues une fois par NOM (dédupliquées), avec l'id de la
    // PREMIÈRE variation trouvée pour ce nom (celle de l'attribut 1, ids 100-106).
    const tiles = Array.from(wizard.querySelectorAll('.wizard-viande-grid [data-viande]'));
    console.log('TILES FOUND:', tiles.map((t) => t.getAttribute('data-viande')));
    expect(tiles.length, 'une tuile par nom de viande unique (7 attendues)').toBe(7);

    // Cliquer les 6 premières viandes distinctes (Mexicanos, Cordon Bleu, Viande Hachée,
    // Nuggets, Tenders, Fricadelle) — la 7e (Poulet mariné) reste NON sélectionnée.
    const idsToClick = [100, 101, 102, 103, 104, 105]; // attr1's ids for each of the 6 names
    for (const id of idsToClick) {
      const plusBtn = wizard.querySelector('.viande-tile-add.plus[data-viande="v_' + id + '"]');
      expect(plusBtn, 'bouton + pour id ' + id).toBeTruthy();
      plusBtn.click();
      await tick();
    }

    const bodyText = wizard.textContent || '';
    console.log('WIZARD BODY AFTER 6 CLICKS (viande section):', (wizard.querySelector('.wizard-viande-grid')?.parentElement?.textContent || '').replace(/\s+/g, ' ').slice(0, 800));

    // Badge de supplément : 3 viandes incluses + 3 en supplément attendu.
    const badge = wizard.querySelector('.viande-suppl-badge');
    console.log('SUPPL BADGE TEXT:', badge ? badge.textContent : '(absent)');

    // Instruction ticket : doit lister TOUTES les viandes en plus, sans en perdre une.
    const ticket = wizard.querySelector('.ticket-content');
    const ticketText = ticket ? ticket.textContent : '';
    console.log('TICKET TEXT:', ticketText);

    const expectedExtraNames = ['Nuggets', 'Tenders', 'Fricadelle'];
    for (const name of expectedExtraNames) {
      expect(ticketText, 'la viande en plus "' + name + '" doit apparaître au ticket').toMatch(new RegExp(name, 'i'));
    }
  });
});
