/**
 * [Root cause 2026-09-20, audit externe — commande POS #2009261353/A0057] syncAndSubmit()
 * (public/js/pos-wizard.js) déduplique les viandes PAR NOM pour peupler les dropdowns Viande 1/
 * 2/3 de la modale Vue d'origine, mais lisait le COMPTE de sélection uniquement sous l'id de
 * variation gardé par la déduplication (celui de l'attribut 1). La vraie base de données assigne
 * un id DISTINCT par attribut pour le même nom (Mexicanos = 777/784/791 sous Viande 1/2/3) : si
 * le compte réel est enregistré sous l'id d'un AUTRE attribut portant le même nom, il devient
 * invisible à cette lecture → la viande disparaît silencieusement de syncAndSubmit(), donc de la
 * commande soumise.
 *
 * Ce test instrumente une copie du fichier FROZEN (lecture seule, comme posWizardHarness.js)
 * pour exposer `selections` et `syncAndSubmit` en test uniquement, afin de forcer précisément
 * ce scénario (compte enregistré sous l'id de l'attribut 2, pas l'attribut 1) et vérifier l'effet
 * réel sur les <select> Viande 1/2/3 de la modale d'origine — la donnée qui part réellement à la
 * soumission.
 */
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { describe, it, expect, beforeAll } from 'vitest';

const RAW_SOURCE = readFileSync(resolve(__dirname, '../../public/js/pos-wizard.js'), 'utf8');
const SOURCE = RAW_SOURCE.replace(
  /\}\)\(\);\s*$/,
  'window.__wizTestX = { open: openWizard, close: closeWizard, getSelections: function () { return selections; }, syncAndSubmit: syncAndSubmit };\n})();\n'
);
if (!SOURCE.includes('__wizTestX')) {
  throw new Error('[posWizardViandeSyncCrossAttrIdBug] instrumentation impossible (fin IIFE introuvable)');
}

const MEAT_NAMES = ['Mexicanos', 'Cordon Bleu', 'Viande Hachée', 'Nuggets', 'Tenders', 'Fricadelle', 'Poulet mariné'];
function variationsFor(attrBase) {
  return MEAT_NAMES.map((name, i) => ({ id: attrBase * 100 + i, name, thumb: '' }));
}

function tacosXLItem() {
  return {
    id: 234, name: 'Tacos XL', description: '', category_name: 'Tacos',
    convert_price: 10.9, currency_price: '€10.90', thumb: '',
    itemAttributes: [
      { id: 1, name: 'Viande 1', max_select: 1 },
      { id: 2, name: 'Viande 2', max_select: 1 },
      { id: 3, name: 'Viande 3', max_select: 1 },
      { id: 5, name: 'Sauce (1ère Gratuite)' },
    ],
    variations: { 1: variationsFor(1), 2: variationsFor(2), 3: variationsFor(3), 5: [{ id: 9101, name: 'Mayonnaise', thumb: null }] },
    extras: [{ id: 614, name: 'Viande supplémentaire', convert_price: 2.5, currency_price: '€2.50', thumb: null }],
    addons: [],
  };
}

const tick = (ms = 15) => new Promise((r) => setTimeout(r, ms));

describe('syncAndSubmit — viande enregistrée sous un id d\'attribut différent de celui gardé par la dédup', () => {
  let modal;

  beforeAll(async () => {
    // originalBodyHtml : 3 <select> réels pour Viande 1/2/3, comme la modale Vue d'origine.
    const originalBodyHtml =
      '<div class="row"><label>Viande 1</label><select><option value="">-</option>' +
      variationsFor(1).map((v) => '<option value="' + v.id + '">' + v.name + '</option>').join('') + '</select></div>' +
      '<div class="row"><label>Viande 2</label><select><option value="">-</option>' +
      variationsFor(2).map((v) => '<option value="' + v.id + '">' + v.name + '</option>').join('') + '</select></div>' +
      '<div class="row"><label>Viande 3</label><select><option value="">-</option>' +
      variationsFor(3).map((v) => '<option value="' + v.id + '">' + v.name + '</option>').join('') + '</select></div>' +
      '<textarea></textarea><input class="indec-value" value="1">';

    modal = document.createElement('div');
    modal.id = 'item-variation-modal-x';
    modal.className = 'modal';
    modal.innerHTML = '<div class="modal-dialog"><div class="modal-header"></div><div class="modal-body">' + originalBodyHtml + '</div></div>';
    document.body.appendChild(modal);
    // Réutilise le nœud attendu par openWizard : #item-variation-modal
    modal.id = 'item-variation-modal';
    modal.setAttribute('data-pos-drinks-catalog', '[]');
    modal.setAttribute('data-wizard-item-data', JSON.stringify(tacosXLItem()));

    // eslint-disable-next-line no-new-func
    new Function(SOURCE)();
    document.dispatchEvent(new Event('DOMContentLoaded'));
    await tick(10);
    modal.classList.add('active');
    await tick(10);
    if (!document.getElementById('pos-wizard-root')) {
      window.__wizTestX.open(modal);
      await tick(10);
    }
  });

  it('reproduit : compte sous l\'id de Viande 2 pour "Nuggets" (pas Viande 1) → syncAndSubmit doit quand même la voir', async () => {
    const selections = window.__wizTestX.getSelections();
    expect(selections, 'selections exposé').toBeTruthy();

    // Sélection réaliste : Mexicanos + Cordon Bleu inclus sous leurs ids d'attribut 1 (100, 101),
    // et "Nuggets" enregistré sous l'id de l'attribut 2 (203) au lieu de l'attribut 1 (103) —
    // exactement le scénario où la dédup par nom garde l'id 103 (jamais compté) et perd Nuggets.
    selections.viandes = selections.viandes || {};
    selections.viandes['v_100'] = 1; // Mexicanos (attr1)
    selections.viandes['v_101'] = 1; // Cordon Bleu (attr1)
    selections.viandes['v_203'] = 1; // Nuggets, mais sous l'id de l'ATTRIBUT 2, pas l'attribut 1 (103)

    const originalBody = document.querySelector('#item-variation-modal .modal-body');
    window.__wizTestX.syncAndSubmit();
    await tick(10);

    const selects = Array.from(originalBody.querySelectorAll('select'));
    const chosenNames = selects.map((sel) => {
      const opt = Array.from(sel.options).find((o) => o.value === sel.value);
      return opt ? opt.textContent : null;
    });
    console.log('DROPDOWN VALUES AFTER syncAndSubmit():', chosenNames);

    expect(chosenNames, 'Nuggets doit apparaître dans un des 3 dropdowns viande (pas perdu)').toContain('Nuggets');
    expect(chosenNames, 'Mexicanos doit aussi être présent').toContain('Mexicanos');
    expect(chosenNames, 'Cordon Bleu doit aussi être présent').toContain('Cordon Bleu');
  });
});
