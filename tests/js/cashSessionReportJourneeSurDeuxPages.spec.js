import { describe, it, expect } from 'vitest';
import CashSessionReportListComponent from '../../resources/js/components/admin/cashSessionReport/CashSessionReportListComponent.vue';
import fr from '../../resources/js/languages/fr.json';

/**
 * Rapport des caisses — une journée à cheval sur deux pages ne se fait pas passer pour complète.
 *
 * LE DÉFAUT : le serveur pagine les SESSIONS (50 par page, `opened_at` décroissant), pas les
 * jours. Les quatre chiffres d'une journée — « Sessions », « Transactions », « Total
 * ouverture », « Total clôture » — sont calculés côté navigateur sur la page chargée. Une
 * journée coupée par la frontière de page affichait donc des totaux partiels, sans rien pour
 * le dire, avec un pagineur visible juste en dessous. Un total de clôture partiel présenté
 * comme celui de la journée est un chiffre de caisse faux.
 *
 * LA RÈGLE (la même que D-003 pour les caisses ouvertes) : un chiffre incomplet mais DÉCLARÉ
 * vaut mieux qu'un chiffre complet et faux. Seuls les deux jours aux bords de la page peuvent
 * être coupés ; les jours intermédiaires sont forcément complets et ne portent aucun
 * avertissement — sinon l'avertissement se banaliserait.
 */
const grouper = (sessions, meta) =>
    CashSessionReportListComponent.computed.groupedByDay.call({ sessions, meta });

const s = (jour, id) => ({
    id,
    business_date: jour,
    opening_amount: 100,
    closing_amount: 120,
    transactions_count: 3,
});

const trois_jours = [s('2026-09-03', 1), s('2026-09-02', 2), s('2026-09-01', 3)];

describe('rapport des caisses — jours aux bords de la page', () => {
    it('une page unique ne signale aucune journée partielle', () => {
        const jours = grouper(trois_jours, { current_page: 1, last_page: 1 });

        for (const j of jours) {
            expect(j.suitePagePrecedente).toBeUndefined();
            expect(j.suitePageSuivante).toBeUndefined();
        }
    });

    it('première page : seul le jour le plus ancien peut continuer sur la page suivante', () => {
        const jours = grouper(trois_jours, { current_page: 1, last_page: 3 });

        expect(jours[0].suitePagePrecedente).toBeUndefined();
        expect(jours[0].suitePageSuivante).toBeUndefined();
        expect(jours[1].suitePageSuivante).toBeUndefined();
        expect(jours[2].suitePageSuivante).toBe(true);
    });

    it('dernière page : seul le jour le plus récent peut venir de la page précédente', () => {
        const jours = grouper(trois_jours, { current_page: 3, last_page: 3 });

        expect(jours[0].suitePagePrecedente).toBe(true);
        expect(jours[2].suitePageSuivante).toBeUndefined();
    });

    it('page intermédiaire : les deux bords sont signalés, le milieu reste propre', () => {
        const jours = grouper(trois_jours, { current_page: 2, last_page: 3 });

        expect(jours[0].suitePagePrecedente).toBe(true);
        expect(jours[1].suitePagePrecedente).toBeUndefined();
        expect(jours[1].suitePageSuivante).toBeUndefined();
        expect(jours[2].suitePageSuivante).toBe(true);
    });

    it('un jour unique au milieu de nulle part porte les deux signaux', () => {
        const [jour] = grouper([s('2026-09-03', 1), s('2026-09-03', 2)], { current_page: 2, last_page: 3 });

        expect(jour.suitePagePrecedente).toBe(true);
        expect(jour.suitePageSuivante).toBe(true);
    });

    it('sans méta (appel historique), ne casse pas et ne signale rien', () => {
        // Les bancs D-003 appellent le computed avec `{ sessions }` seul.
        const jours = grouper(trois_jours, undefined);

        expect(jours).toHaveLength(3);
        expect(jours[0].suitePagePrecedente).toBeUndefined();
    });

    it('ne modifie pas les totaux : on déclare l\'incomplétude, on n\'invente pas de chiffre', () => {
        const [jour] = grouper([s('2026-09-03', 1), s('2026-09-03', 2)], { current_page: 1, last_page: 2 });

        expect(jour.totalOpening).toBe(200);
        expect(jour.totalClosing).toBe(240);
        expect(jour.totalTransactions).toBe(6);
    });

    it('les trois libellés existent en français (sinon la clé brute s\'afficherait)', () => {
        for (const cle of ['cash_day_may_continue_prev', 'cash_day_may_continue_next', 'cash_day_may_continue_both']) {
            expect(typeof fr.label[cle], `label.${cle} manquant`).toBe('string');
            expect(fr.label[cle].length).toBeGreaterThan(20);
        }
    });
});
