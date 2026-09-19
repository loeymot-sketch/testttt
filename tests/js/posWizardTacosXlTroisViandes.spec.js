/**
 * [OWNER TACOS-XL 2026-08-24] La caisse doit comprendre, du seul nom « Tacos XL », que TROIS
 * viandes sont comprises dans le prix — et ne facturer le supplément @2,50 qu'à partir de la
 * QUATRIÈME.
 *
 * POURQUOI CE TEST EXISTE
 * -----------------------
 * `public/js/pos-wizard.js` est en ZONE GELÉE : on ne peut pas y ajouter « Tacos XL ». On s'est
 * donc appuyé sur ce qu'il sait déjà lire — `detectViandeCount()` mappe `/tacos\s*xl\b/ → 3`.
 * C'est ce pari qui a décidé du NOM du produit. Si quelqu'un renomme un jour le produit
 * (« Tacos 3 viandes », « Triple Tacos »), la caisse retomberait silencieusement à 1 viande
 * incluse et FACTURERAIT les 2ᵉ et 3ᵉ viandes @2,50 — un défaut d'argent, invisible en base.
 * Ce test transforme ce risque muet en échec bruyant.
 *
 * Le harnais exécute le VRAI fichier gelé (rendu single-page + handlers), pas une imitation.
 * Le nom de la fixture est celui de la BASE (« Tacos XL », SANS suffixe « (3 viandes) ») : c'est
 * précisément la détection par le nom seul qu'on veut prouver.
 */
import { describe, it, expect } from 'vitest';
import { mountPosWizard, cayenneLikeItem } from './posWizardHarness.js';

const tick = (ms = 10) => new Promise((r) => setTimeout(r, ms));

const MEATS = [
  { id: 9001, name: 'Poulet mariné', thumb: '' },
  { id: 9002, name: 'Mexicanos', thumb: '' },
  { id: 9003, name: 'Cordon Bleu', thumb: '' },
  { id: 9004, name: 'Tenders', thumb: '' },
];

/** Tacos XL tel qu'il sort de la base : 3 attributs « Viande N », 7 viandes sous chacun. */
function tacosXlItem() {
  return cayenneLikeItem({
    id: 234,
    name: 'Tacos XL',
    description: 'Galette de blé, 3 viandes au choix, frites maison et sauce.',
    category_name: 'Tacos',
    convert_price: 10.9,
    currency_price: '€10.90',
    itemAttributes: [
      { id: 301, name: 'Viande 1', max_select: 1 },
      { id: 302, name: 'Viande 2', max_select: 1 },
      { id: 303, name: 'Viande 3', max_select: 1 },
      { id: 311, name: 'Sauce (1ère Gratuite)' },
    ],
    variations: {
      301: MEATS.map((m) => ({ ...m })),
      302: MEATS.map((m) => ({ ...m })),
      303: MEATS.map((m) => ({ ...m })),
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

describe('caisse — Tacos XL : trois viandes comprises dans le prix', () => {
  it('chaque viande est proposée une seule fois, malgré les 3 emplacements', async () => {
    const { wizard } = await mountPosWizard({ itemData: tacosXlItem() });
    expect(wizard).toBeTruthy();

    const tiles = Array.from(wizard.querySelectorAll('.wizard-viande-tile'));
    expect(tiles.length, 'liste dédoublonnée entre Viande 1/2/3').toBe(MEATS.length);
  });

  it('les TROIS premières viandes sont gratuites — aucun supplément', async () => {
    const { wizard } = await mountPosWizard({ itemData: tacosXlItem() });

    plus(wizard, 9001).click(); await tick();
    plus(wizard, 9002).click(); await tick();
    plus(wizard, 9003).click(); await tick();

    expect(
      wizard.querySelector('.viande-suppl-badge'),
      '3 viandes = ce que le client paie déjà dans les 10,90 € — rien à facturer'
    ).toBeNull();
    expect(wizard.querySelector('.viande-tile-suppl-tag')).toBeNull();
  });

  it('la QUATRIÈME viande, elle, est bien facturée @2,50', async () => {
    const { wizard } = await mountPosWizard({ itemData: tacosXlItem() });

    plus(wizard, 9001).click(); await tick();
    plus(wizard, 9002).click(); await tick();
    plus(wizard, 9003).click(); await tick();
    plus(wizard, 9004).click(); await tick();

    expect(
      wizard.querySelector('.viande-suppl-badge'),
      'au-delà des 3 comprises, le supplément reprend ses droits'
    ).toBeTruthy();
    expect(wizard.querySelector('.viande-tile-suppl-tag')).toBeTruthy();
  });

  it('le Tacos L reste à DEUX viandes comprises — pas de dérive collatérale', async () => {
    const { wizard } = await mountPosWizard({
      itemData: cayenneLikeItem({
        id: 97,
        name: 'Tacos L',
        description: 'Galette de blé, 2 viandes au choix, frites maison et sauce.',
        convert_price: 8.9,
        currency_price: '€8.90',
        itemAttributes: [
          { id: 301, name: 'Viande 1', max_select: 1 },
          { id: 302, name: 'Viande 2', max_select: 1 },
          { id: 311, name: 'Sauce (1ère Gratuite)' },
        ],
        variations: {
          301: MEATS.map((m) => ({ ...m })),
          302: MEATS.map((m) => ({ ...m })),
          311: [{ id: 9101, name: 'Algérienne', thumb: null }],
        },
        extras: [
          { id: 398, name: 'Viande supplémentaire', convert_price: 2.5, currency_price: '€2.50', thumb: null },
        ],
      }),
    });

    plus(wizard, 9001).click(); await tick();
    plus(wizard, 9002).click(); await tick();
    expect(wizard.querySelector('.viande-suppl-badge'), '2 viandes comprises').toBeNull();

    plus(wizard, 9003).click(); await tick();
    expect(wizard.querySelector('.viande-suppl-badge'), 'la 3ᵉ est payante sur un Tacos L').toBeTruthy();
  });
});
