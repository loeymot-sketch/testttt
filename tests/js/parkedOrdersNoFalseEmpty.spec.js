/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P1-04 / P1-18]
 *
 * Défaut reproduit : le panneau « Commandes en attente » affichait
 * SIMULTANÉMENT trois choses contradictoires — le compteur `0`, le message
 * « Aucune commande parkée. » et le toast « Impossible de charger les commandes
 * parkées. ». Une panne réseau était donc **indistinguable** d'une file
 * réellement vide : le caissier conclut « rien en attente » alors qu'il n'a rien
 * pu charger.
 *
 * Cause : le composant n'avait AUCUN champ d'erreur dans son `data()`. L'échec
 * ne produisait qu'un toast éphémère, pendant que l'état vide restait rendu sur
 * le seul critère `parkedOrders.length === 0`. Le store ne dégrade pas non plus :
 * il ne commit la liste qu'en cas de succès, donc `list` garde `[]`.
 *
 * Le dépôt avait DÉJÀ résolu exactement cette forme ailleurs — « T-4.1
 * FAUX-VIDE 2026-08-15 » dans `EncaissementComponent.vue`, avec sa sentinelle
 * `tests/js/encaissementNoFalseEmpty.spec.js`, et le même patron dans
 * `stockLowAlertsWidgetNoFalseEmpty.spec.js`. Le panneau des commandes en
 * attente était le jumeau oublié. Ce banc est son équivalent.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import ParkedOrdersComponent from '../../resources/js/components/admin/pos/ParkedOrdersComponent.vue';

const source = () => readFileSync(
  resolve(__dirname, '../../resources/js/components/admin/pos/ParkedOrdersComponent.vue'),
  'utf8'
);

describe('ParkedOrdersComponent — une panne ne doit plus se lire « aucune commande »', () => {
  it('expose un état d’erreur distinct dans son data()', () => {
    const data = ParkedOrdersComponent.data.call({});
    expect(data).toHaveProperty('fetchError');
    expect(data.fetchError).toBe(false);
  });

  it('le gabarit rend l’erreur AVANT l’état vide, et les deux sont exclusifs', () => {
    const src = source();

    const iErreur = src.indexOf('fetchError');
    const iVide = src.indexOf("$t('pos.empty_parked_orders')");
    expect(iErreur).toBeGreaterThan(-1);
    expect(iVide).toBeGreaterThan(-1);

    // La branche d'erreur doit précéder la branche vide dans la chaîne v-if.
    // C'est un `v-else-if` : `v-if="loading"` reste la première branche.
    const iBrancheErreur = src.indexOf('v-else-if="fetchError');
    expect(iBrancheErreur).toBeGreaterThan(-1);
    expect(iBrancheErreur).toBeLessThan(iVide);

    // Exclusivité : l'état vide doit être un v-else-if, jamais un v-if autonome.
    const blocVide = src.slice(Math.max(0, iVide - 260), iVide);
    expect(blocVide).toContain('v-else-if');
  });

  it('l’erreur n’est affichée que si aucune liste n’est déjà rendue', () => {
    // Un poll transitoire qui échoue ne doit pas effacer une liste utile —
    // même règle que l'encaissement.
    expect(source()).toContain('fetchError && parkedOrders.length === 0');
  });

  it('le compteur d’en-tête ne clame plus « 0 » quand le chargement a échoué', () => {
    const src = source();
    const iSousTitre = src.indexOf('parked-orders-subtitle');
    expect(iSousTitre).toBeGreaterThan(-1);

    const bloc = src.slice(iSousTitre, iSousTitre + 220);
    expect(bloc).toContain('fetchError');
  });

  it('fetchList remet l’indicateur à zéro au succès et le lève à l’échec', () => {
    const src = source();
    const iFetch = src.indexOf('async fetchList()');
    expect(iFetch).toBeGreaterThan(-1);

    const corps = src.slice(iFetch, iFetch + 700);
    expect(corps).toContain('this.fetchError = false');
    expect(corps).toContain('this.fetchError = true');
  });

  it('l’ouverture du panneau n’avale plus l’erreur en silence', () => {
    const pos = readFileSync(
      resolve(__dirname, '../../resources/js/components/admin/pos/PosComponent.vue'),
      'utf8'
    );
    // `openParkedOrders` faisait `.catch(() => {})` — une panne disparaissait.
    expect(pos).not.toContain(".dispatch('posParked/fetchList').then().catch(() => {})");
  });
});
