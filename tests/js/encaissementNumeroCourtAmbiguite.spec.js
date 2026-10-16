/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P0-18 / triage A2 (risque n°2)]
 *
 * Défaut reproduit : `queue_number` (« N°A0041 ») est un compteur QUOTIDIEN par
 * branche (`OrderService::allocateQueueNumber` — remise à zéro à chaque
 * `business_date`, PAR CONCEPTION, pinné par
 * `tests/Feature/QueueNumberConcurrencyTest` et le sentinel d'unicité). La file
 * « à encaisser » n'a, elle, AUCUN filtre de journée
 * (`routes/api.php` counter-collect.pending : payment_status + statuts exclus +
 * branche, jamais de `business_date`) — c'est volontaire, plusieurs incidents
 * « ENCAISSEMENT-ROBUSTE » ont montré qu'un filtre de date rend des commandes
 * légitimes invisibles et non encaissables.
 *
 * Conséquence : deux commandes de JOURS DIFFÉRENTS portant le MÊME numéro court
 * cohabitent dans la liste. Le rapport l'observe en vrai : `A0041` avec
 * `2209261421 / 10,80 €` ET `2409261472 / 11,50 €`. La carte
 * d'encaissement n'affichait que `N°A0041` — pas de date, pas de série — donc
 * le caissier pouvait encaisser la MAUVAISE commande (argent réel).
 *
 * Le correctif existe DÉJÀ côté POS depuis le 2026-09-26
 * (`PosComponent.shortcutDateBadge`, pinné par
 * `posShortcutDateBadgeAmbiguity.spec.js`) mais n'avait jamais été porté sur
 * l'écran dédié `/admin/encaissement`. On extrait donc la règle dans un helper
 * partagé plutôt que de recopier une troisième table de vérité.
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { queueNumberDateBadge } from '../../resources/js/helpers/queueNumberDateBadge';
import EncaissementComponent from '../../resources/js/components/admin/encaissement/EncaissementComponent.vue';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

describe('helper partagé queueNumberDateBadge', () => {
  let realDate;

  beforeEach(() => {
    realDate = Date;
    // Fige « aujourd'hui » au 26/09/2026 pour un test déterministe.
    function MockDate(...args) {
      if (args.length) return new realDate(...args);
      return new realDate('2026-09-26T12:00:00+02:00');
    }
    MockDate.prototype = realDate.prototype;
    global.Date = MockDate;
  });

  afterEach(() => {
    global.Date = realDate;
  });

  it('ne met aucun badge sur une commande du jour (pas de bruit visuel)', () => {
    expect(queueNumberDateBadge({ created_at: '2026-09-26T23:55:00+02:00' })).toBe('');
  });

  it('affiche jj/mm sur une commande d’un jour antérieur — le cas réel A0041 du rapport', () => {
    expect(queueNumberDateBadge({ created_at: '2026-09-22T14:21:00+02:00' })).toBe('22/09');
    expect(queueNumberDateBadge({ created_at: '2026-09-24T14:22:00+02:00' })).toBe('24/09');
  });

  it('distingue deux commandes de jours différents portant le même numéro court', () => {
    const veille = queueNumberDateBadge({ queue_number: 'A0041', created_at: '2026-09-22T14:21:00+02:00' });
    const jour = queueNumberDateBadge({ queue_number: 'A0041', created_at: '2026-09-26T09:10:00+02:00' });

    expect(veille).toBe('22/09');
    expect(jour).toBe('');
    expect(veille).not.toBe(jour);
  });

  it('reste muet et ne jette pas sur une donnée absente ou illisible', () => {
    expect(queueNumberDateBadge(null)).toBe('');
    expect(queueNumberDateBadge({})).toBe('');
    expect(queueNumberDateBadge({ created_at: null })).toBe('');
    expect(queueNumberDateBadge({ created_at: 'pas-une-date' })).toBe('');
  });
});

describe('EncaissementComponent — le numéro court n’est plus ambigu', () => {
  it('expose une méthode de badge de date', () => {
    expect(typeof EncaissementComponent.methods.queueDateBadge).toBe('function');
  });

  it('délègue au helper partagé et non à une copie locale de la règle', () => {
    const realDate = Date;
    function MockDate(...args) {
      if (args.length) return new realDate(...args);
      return new realDate('2026-09-26T12:00:00+02:00');
    }
    MockDate.prototype = realDate.prototype;
    global.Date = MockDate;
    try {
      const call = (o) => EncaissementComponent.methods.queueDateBadge.call(null, o);
      expect(call({ created_at: '2026-09-22T14:21:00+02:00' })).toBe('22/09');
      expect(call({ created_at: '2026-09-26T08:00:00+02:00' })).toBe('');
    } finally {
      global.Date = realDate;
    }
  });

  /**
   * Garde de PÉRIMÈTRE. Une méthode correcte ne prouve rien si le gabarit ne
   * l'appelle pas : `tests/js/posDeliveryFlag.spec.js` est précisément un banc
   * qui reste vert alors qu'il ne teste qu'une copie de la règle. On vérifie
   * donc que le gabarit rend réellement le badge à côté du numéro court.
   */
  it('le gabarit rend bien le badge à côté de N°{{ queue_number }}', () => {
    const src = readFileSync(
      resolve(__dirname, '../../resources/js/components/admin/encaissement/EncaissementComponent.vue'),
      'utf8'
    );

    expect(src).toContain('queueDateBadge(order)');

    // Le badge doit être rendu dans le même bloc que le numéro court, pas
    // relégué ailleurs dans la carte.
    const bloc = src.slice(src.indexOf('enc-ticket-top'), src.indexOf('enc-ticket-customer'));
    expect(bloc).toContain('order.queue_number');
    expect(bloc).toContain('queueDateBadge(order)');
  });
});

describe('PosComponent — le correctif POS existant reste branché sur le helper', () => {
  it('shortcutDateBadge donne le même verdict que le helper partagé', () => {
    const realDate = Date;
    function MockDate(...args) {
      if (args.length) return new realDate(...args);
      return new realDate('2026-09-26T12:00:00+02:00');
    }
    MockDate.prototype = realDate.prototype;
    global.Date = MockDate;
    try {
      const cas = [
        { created_at: '2026-09-26T23:55:00+02:00' },
        { created_at: '2026-09-22T14:21:00+02:00' },
        { created_at: null },
      ];
      cas.forEach((o) => {
        expect(PosComponent.methods.shortcutDateBadge.call(null, o)).toBe(queueNumberDateBadge(o));
      });
    } finally {
      global.Date = realDate;
    }
  });
});
