/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P1-49]
 *
 * Défaut reproduit : dans le tableau des terminaux de paiement, les deux actions
 * de ligne n'étaient que des glyphes de police d'icônes — **ni `title`, ni
 * `aria-label`, ni texte lisible**. Leur nom accessible était donc VIDE. Sur un
 * poste de caisse il n'y a pas de survol, et au lecteur d'écran l'action
 * potentiellement DESTRUCTIVE (supprimer un TPE) était strictement anonyme.
 *
 * Le dépôt avait déjà tranché cette règle ailleurs — « AUDIT-SUPERVISEUR
 * 2026-08-26 · D-007 » sur la file d'encaissement, dont le commentaire dit
 * explicitement : « Le même dépôt fait déjà l'inverse ailleurs (le bouton de
 * réimpression de l'historique porte title ET aria-label) ». Le même fichier des
 * TPE nommait d'ailleurs déjà son bouton de fermeture de modale : la règle était
 * violée à l'intérieur du fichier qui l'applique par ailleurs.
 *
 * Le nom accessible porte le TERMINAL concerné et pas seulement le verbe :
 * plusieurs lignes coexistent, « Supprimer » seul resterait ambigu — c'est
 * exactement le reproche de D-007 (trois boutons identiques pour trois montants
 * différents).
 *
 * Aucune clé i18n ajoutée : `button.edit` et `button.delete` existent déjà.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const CHEMIN = '../../resources/js/components/admin/settings/PaymentTerminals/PaymentTerminalsComponent.vue';

const source = () => readFileSync(resolve(__dirname, CHEMIN), 'utf8');

/** Extrait le bloc des actions de ligne, hors modale. */
const blocActions = (src) => {
  const debut = src.indexOf('flex justify-start items-center gap-1.5');
  const fin = src.indexOf('</td>', debut);
  expect(debut).toBeGreaterThan(-1);
  expect(fin).toBeGreaterThan(debut);
  return src.slice(debut, fin);
};

describe('PaymentTerminalsComponent — les actions de ligne ont un nom accessible', () => {
  it('l’action de modification porte title ET aria-label', () => {
    const bloc = blocActions(source());
    const iEdit = bloc.indexOf('openEdit(terminal)');
    expect(iEdit).toBeGreaterThan(-1);

    const bouton = bloc.slice(0, iEdit);
    expect(bouton).toContain(':title=');
    expect(bouton).toContain(':aria-label=');
    expect(bouton).toContain("$t('button.edit')");
  });

  it('l’action de suppression porte title ET aria-label', () => {
    const bloc = blocActions(source());
    const iDelete = bloc.indexOf('destroy(terminal.id)');
    expect(iDelete).toBeGreaterThan(-1);

    // Le bouton de suppression commence après celui de modification.
    const debutSuppression = bloc.lastIndexOf('<button', iDelete);
    const bouton = bloc.slice(debutSuppression, iDelete);
    expect(bouton).toContain(':title=');
    expect(bouton).toContain(':aria-label=');
    expect(bouton).toContain("$t('button.delete')");
  });

  it('le nom accessible identifie le TERMINAL, pas seulement le verbe', () => {
    const bloc = blocActions(source());
    // Deux occurrences attendues : une par action.
    const occurrences = (bloc.match(/terminal\.name/g) || []).length;
    expect(occurrences).toBeGreaterThanOrEqual(2);
  });

  it('chaque action est ciblable par un test (data-testid)', () => {
    const bloc = blocActions(source());
    expect(bloc).toContain('payment-terminal-edit-');
    expect(bloc).toContain('payment-terminal-delete-');
  });

  it('aucun bouton d’action de ligne ne reste sans nom accessible', () => {
    const bloc = blocActions(source());
    const boutons = bloc.split('<button').slice(1);

    expect(boutons.length).toBeGreaterThan(0);
    boutons.forEach((b, i) => {
      expect(b, `le bouton d'action #${i + 1} doit porter un aria-label`).toContain(':aria-label=');
    });
  });
});
