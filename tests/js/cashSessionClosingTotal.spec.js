import { describe, it, expect } from 'vitest';

// [AUDIT-SUPERVISEUR 2026-08-25 · D-003]
//
// LE DÉFAUT — `Number(s.closing_amount || 0)` valait 0 : une caisse ENCORE OUVERTE
// était comptée comme clôturée à 0,00 € dans le total du jour. Le superviseur a
// reparcouru les 11 groupes-jours : 5 journées touchées, 11 sessions, et un jour qui
// se lisait littéralement « 150,00 € entrés, 0,00 € sortis ».
//
// L'ironie du défaut : la cellule de DÉTAIL disait déjà « — » honnêtement pour chaque
// session ouverte. C'est le TOTAL qui fabriquait le zéro — le chiffre agrégé mentait
// pendant que ses composants disaient vrai.
//
// LA RÈGLE — une caisse ouverte n'a pas de clôture ; on ne l'invente pas. Le total
// somme ce qui est RÉELLEMENT clos, et ce qui manque est ANNONCÉ à côté. Un chiffre
// incomplet mais déclaré vaut mieux qu'un chiffre complet et faux.

import CashSessionReportListComponent from '../../resources/js/components/admin/cashSessionReport/CashSessionReportListComponent.vue';
import fr from '../../resources/js/languages/fr.json';

/** Exécute le computed réel du composant sur un jeu de sessions donné. */
const grouper = (sessions) =>
    CashSessionReportListComponent.computed.groupedByDay.call({ sessions });

const session = (over = {}) => ({
    business_date: '2026-06-22',
    opening_amount: 150,
    closing_amount: null,
    transactions_count: 0,
    ...over,
});

const t = (cle, params) => {
    let v = fr;
    for (const p of String(cle).split('.')) v = v?.[p];
    if (typeof v !== 'string') return cle;
    return params ? v.replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)) : v;
};
const libelleOuvertes = (n) =>
    CashSessionReportListComponent.methods.libelleOuvertes.call({ $t: t }, n);

describe('total de clôture — une caisse ouverte n\'est pas une caisse clôturée à zéro', () => {
    it('n\'ajoute PAS de zéro pour une caisse encore ouverte', () => {
        // Le cas exact relevé : 150,00 € entrés, aucune clôture.
        const [jour] = grouper([session({ opening_amount: 150, closing_amount: null })]);

        expect(jour.totalOpening).toBe(150);
        expect(jour.totalClosing).toBe(0);
        expect(jour.sessionsOuvertes).toBe(1);
    });

    it('somme UNIQUEMENT ce qui est réellement clôturé', () => {
        const [jour] = grouper([
            session({ opening_amount: 100, closing_amount: 320.5 }),
            session({ opening_amount: 50, closing_amount: null }),
            session({ opening_amount: 50, closing_amount: 90 }),
        ]);

        expect(jour.totalOpening).toBe(200);
        expect(jour.totalClosing).toBe(410.5); // 320,50 + 90 — la troisième n'entre pas
        expect(jour.sessionsOuvertes).toBe(1);
    });

    it('une clôture à 0,00 € RÉELLE reste comptée — c\'est un fait, pas une absence', () => {
        // Distinction qui compte : `0` est une vraie clôture (caisse vidée),
        // `null` est une absence de clôture. Les confondre était tout le défaut.
        const [jour] = grouper([session({ closing_amount: 0 })]);

        expect(jour.totalClosing).toBe(0);
        expect(jour.sessionsOuvertes).toBe(0);
    });

    it('`undefined` est traité comme une absence, pas comme un zéro', () => {
        const [jour] = grouper([session({ closing_amount: undefined })]);

        expect(jour.sessionsOuvertes).toBe(1);
    });

    it('ne signale rien quand toutes les caisses du jour sont clôturées', () => {
        const [jour] = grouper([
            session({ closing_amount: 10 }),
            session({ closing_amount: 20 }),
        ]);

        expect(jour.sessionsOuvertes).toBe(0);
        expect(jour.totalClosing).toBe(30);
    });

    it('accorde le libellé en nombre, sans « caisse(s) »', () => {
        expect(libelleOuvertes(1)).toBe('1 caisse encore ouverte, non comptée');
        expect(libelleOuvertes(3)).toBe('3 caisses encore ouvertes, non comptées');

        // Garde explicite : ce même audit a relevé ailleurs un « prête(s) » et l'a
        // qualifié d'aveu écrit d'un accord jamais fait. On ne le reproduit pas.
        expect(libelleOuvertes(1)).not.toMatch(/\(s\)/);
        expect(libelleOuvertes(3)).not.toMatch(/\(s\)/);
    });

    it('sépare bien les journées', () => {
        const jours = grouper([
            session({ business_date: '2026-06-22', closing_amount: null }),
            session({ business_date: '2026-06-23', closing_amount: 80 }),
        ]);

        expect(jours).toHaveLength(2);
        expect(jours[0].sessionsOuvertes).toBe(1);
        expect(jours[1].sessionsOuvertes).toBe(0);
        expect(jours[1].totalClosing).toBe(80);
    });
});
