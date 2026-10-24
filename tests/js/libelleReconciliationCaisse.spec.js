import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB-10 2026-08-28 · RÉÉCRIT à la fusion du 2026-08-28]
 *
 * LE DÉFAUT D'ORIGINE. Le bandeau de réconciliation disait « aujourd'hui » pour une
 * valeur qui n'était pas celle du jour. Vu à l'écran Vue Caisse Unifiée, à 00 h 30 :
 * le bandeau annonçait « Espèces encaissées aujourd'hui : 8,50 € » pendant que le
 * tableau juste en dessous affichait « Aucune transaction » et « 0,00 € ». La session
 * de caisse avait été ouverte la veille à 20 h 56.
 *
 * POURQUOI CE BANC A ÉTÉ RÉÉCRIT. Sa première version épinglait un LIBELLÉ précis
 * (`label.cash_collected_since_opening`). Or la ligne servie avait corrigé le même
 * défaut autrement, et mieux : `CashOverviewController` expose désormais DEUX champs
 * distincts —
 *
 *   - `cash_collected`           : mouvement net DEPUIS L'OUVERTURE (durée de vie de
 *                                  la session) ;
 *   - `cash_collected_in_period` : mouvement net BORNÉ À LA PÉRIODE affichée
 *                                  (correctif FIX-3 du 2026-08-25).
 *
 * Le bandeau affiche `cash_collected_in_period`. Lui coller « depuis l'ouverture »
 * aurait recréé EXACTEMENT le défaut d'origine, avec un autre mot faux : un libellé
 * qui annonce une fenêtre de temps que la valeur n'a pas.
 *
 * Épingler un libellé, c'était donc garder la mauvaise chose. Un banc qui verrouille
 * une formulation empêche une meilleure correction d'entrer, et laisse passer la
 * seule faute qui compte. Ce banc verrouille désormais l'APPARIEMENT : chaque libellé
 * doit annoncer la fenêtre de temps du champ qu'il accompagne.
 */
describe('ONB-10 · bandeau de réconciliation de caisse', () => {
    const cheminComposant = path.join(
        process.cwd(),
        'resources/js/components/admin/cashOverview/CashOverviewComponent.vue',
    );
    const source = () => fs.readFileSync(cheminComposant, 'utf8');

    /** Le bloc de 400 caractères qui suit un libellé — libellé et valeur y cohabitent. */
    const blocApres = (texte, ancre) => {
        const debut = texte.indexOf(ancre);

        return debut === -1 ? null : texte.slice(debut, debut + 400);
    };

    it("le libellé « aujourd'hui » ne revient pas sur cet écran", () => {
        expect(
            source().includes("$t('label.cash_collected_today')"),
            "« aujourd'hui » est revenu. Aucune des deux valeurs de ce bandeau n'est "
            + 'comptée depuis minuit : l\'une court depuis l\'ouverture de la session, '
            + "l'autre est bornée à la période affichée.",
        ).toBe(false);
    });

    it('un libellé « sur la période » accompagne bien la valeur bornée à la période', () => {
        const bloc = blocApres(source(), "label.cash_collected_in_period");

        expect(
            bloc,
            "le libellé `cash_collected_in_period` a disparu du composant : le bandeau "
            + "n'annonce plus quelle fenêtre de temps il montre",
        ).not.toBeNull();

        expect(
            bloc,
            'le libellé « sur la période » doit afficher `cashSession.cash_collected_in_period` — '
            + 'la valeur RÉELLEMENT bornée à la période. Collé à une autre valeur, il ment.',
        ).toContain('cashSession.cash_collected_in_period');
    });

    it("un libellé « depuis l'ouverture », s'il existe, n'accompagne PAS la valeur de période", () => {
        const texte = source();
        const bloc = blocApres(texte, "label.cash_collected_since_opening");

        // Ce libellé n'est pas obligatoire. Mais s'il est réintroduit un jour, il doit
        // accompagner `cash_collected` (durée de vie de la session), JAMAIS
        // `cash_collected_in_period` — ce serait le défaut d'origine, remis.
        if (bloc === null) {
            expect(true).toBe(true);

            return;
        }

        expect(
            bloc.includes('cashSession.cash_collected_in_period'),
            "« depuis l'ouverture » est collé à `cash_collected_in_period`, qui est borné "
            + "à la période affichée. C'est le défaut d'origine avec un autre mot faux.",
        ).toBe(false);

        expect(
            bloc,
            "« depuis l'ouverture » doit afficher `cashSession.cash_collected`",
        ).toContain('cashSession.cash_collected');
    });

    it('les deux langues portent les clés affichées, et plus l\'ancienne', () => {
        const texte = source();
        const clesAffichees = [...texte.matchAll(/\$t\('label\.(cash_[a-z_]+)'\)/g)]
            .map((m) => m[1]);

        expect(
            clesAffichees.length,
            'aucune clé `label.cash_*` trouvée dans le composant : le sélecteur de ce banc '
            + 'ne mesure plus rien',
        ).toBeGreaterThan(0);

        for (const langue of ['fr', 'en']) {
            const json = JSON.parse(
                fs.readFileSync(
                    path.join(process.cwd(), `resources/js/languages/${langue}.json`),
                    'utf8',
                ),
            );

            for (const cle of clesAffichees) {
                expect(
                    json.label?.[cle],
                    `${langue}.json : la clé « ${cle} » manque — l'écran afficherait la clé brute`,
                ).toBeTruthy();
            }

            expect(
                json.label?.cash_collected_today,
                `${langue}.json : l'ancienne clé « aujourd'hui » traîne encore`,
            ).toBeUndefined();
        }
    });
});
