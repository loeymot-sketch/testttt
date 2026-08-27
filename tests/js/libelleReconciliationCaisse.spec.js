import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB-10 2026-08-28] Le bandeau de réconciliation disait « aujourd'hui » pour une
 * valeur comptée depuis l'ouverture de la caisse.
 *
 * Vu à l'écran Vue Caisse Unifiée, à 00 h 30 : le bandeau annonçait « Espèces
 * encaissées aujourd'hui : 8,50 € » et « Espèces attendues au tiroir : 58,50 € »,
 * pendant que le tableau juste en dessous affichait « Aucune transaction » et
 * « 0,00 € ». La session de caisse avait été ouverte la veille à 20 h 56.
 *
 * Les deux moitiés de l'écran ne parlent pas de la même chose, et c'est VOULU :
 * `CashOverviewController` documente que la réconciliation est scopée à
 * `cash_drawer_session_id` et « MUST be invariant against the UI source/mode/branch
 * filters », tandis que le tableau suit la période choisie. Le calcul est juste.
 *
 * C'est le LIBELLÉ qui était faux. Le contrôleur écrit lui-même, à côté de la
 * valeur : « Net cash movement SINCE OPENING (IN minus OUT), the reconciliation
 * delta ». « Aujourd'hui » est inexact dès qu'un service passe minuit — c'est-à-dire
 * tous les soirs, et précisément à l'heure où un restaurateur ferme sa caisse.
 *
 * Ce banc verrouille l'accord entre le libellé et ce que le contrôleur calcule.
 */
describe('ONB-10 · bandeau de réconciliation de caisse', () => {
    const composant = path.join(
        process.cwd(),
        'resources/js/components/admin/cashOverview/CashOverviewComponent.vue',
    );

    it('le libellé dit « depuis l\'ouverture », pas « aujourd\'hui »', () => {
        const source = fs.readFileSync(composant, 'utf8');

        expect(source).toContain("$t('label.cash_collected_since_opening')");
        expect(
            source.includes("$t('label.cash_collected_today')"),
            "« aujourd'hui » est revenu : la valeur affichée est comptée depuis "
            + "l'ouverture de la session, pas depuis minuit.",
        ).toBe(false);
    });

    it('le libellé est bien accolé à la valeur de session, pas à une somme du jour', () => {
        const source = fs.readFileSync(composant, 'utf8');

        // Le libellé et la valeur doivent rester dans le même bloc : c'est
        // l'appariement qui porte la vérité, pas le libellé seul.
        const bloc = source.slice(
            source.indexOf("label.cash_collected_since_opening"),
            source.indexOf("label.cash_collected_since_opening") + 400,
        );

        expect(
            bloc,
            'le libellé doit afficher cashSession.cash_collected — la valeur scopée à la session',
        ).toContain('cashSession.cash_collected');
    });

    it('les deux langues portent la clé, et plus l\'ancienne', () => {
        for (const langue of ['fr', 'en']) {
            const json = JSON.parse(
                fs.readFileSync(
                    path.join(process.cwd(), `resources/js/languages/${langue}.json`),
                    'utf8',
                ),
            );

            expect(
                json.label?.cash_collected_since_opening,
                `${langue}.json : la clé manque — l'écran afficherait la clé brute`,
            ).toBeTruthy();
            expect(
                json.label?.cash_collected_today,
                `${langue}.json : l'ancienne clé traîne encore`,
            ).toBeUndefined();
        }
    });
});
