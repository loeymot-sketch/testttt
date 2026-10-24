import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * LA BANNIÈRE DE RAPPROCHEMENT NE DOIT PAS CONTREDIRE LA PAGE QUI LA PORTE.
 *
 * D-001 (P0) et D-002 (P1), mesurés par le superviseur adverse à la ronde 3 (2026-08-25),
 * sur la Vue Caisse Unifiée. Deux défauts distincts, une même famille : un chiffre affirmé
 * en haut de page que le reste de la page dément.
 *
 * D-001 — la bannière annonçait « Espèces encaissées sur la période : 7,50 € » quand le
 * bloc « Répartition par mode », quarante pixels plus bas, affichait « Espèce 2 · 5,00 € »
 * et que le tableau ne contenait que deux lignes espèce à 2,50 €. Les 2,50 € restants
 * n'étaient NULLE PART.
 *
 * La cause n'était pas un calcul faux : c'était DEUX GRANDEURS DIFFÉRENTES SOUS DEUX
 * LIBELLÉS QUASI IDENTIQUES. Le nombre de la bannière est la somme signée des MOUVEMENTS
 * DU TIROIR ouvert (appoints, prélèvements, saisies manuelles) ; celui de la répartition
 * est la somme des VENTES en espèces. J'avais déjà « corrigé » ce champ plus tôt dans cette
 * mission en le restreignant à la période — sans toucher au libellé, qui continuait de dire
 * « encaissées ». Restreindre un chiffre ne le renomme pas.
 *
 * D-002 — le GRAND TOTAL ne bouclait pas avec ses cartes : 247,70 € annoncés contre
 * 222,70 € décomposés, soit 10 % de la période invisible. `displayedSources()` était figée
 * sur trois clés et jetait en silence toute autre clé de `by_source` — en pratique le seau
 * des paiements orphelins, que j'avais pourtant exposé côté serveur plus tôt dans cette
 * même mission. Un correctif à moitié fait ment aussi bien qu'une absence de correctif.
 */

const RACINE = path.resolve(__dirname, '../..');
const VUE = path.join(RACINE, 'resources/js/components/admin/cashOverview/CashOverviewComponent.vue');
const FR = path.join(RACINE, 'resources/js/languages/fr.json');

const source = () => fs.readFileSync(VUE, 'utf8');
const libelles = () => JSON.parse(fs.readFileSync(FR, 'utf8')).label;

describe('Vue Caisse Unifiée — la bannière et la page doivent s\'accorder', () => {
    it('D-001 : le libellé nomme des MOUVEMENTS DE TIROIR, pas des ventes', () => {
        const l = libelles()['cash_collected_in_period'];
        expect(l, 'la clé du libellé a disparu').toBeTruthy();

        expect(
            /encaiss/i.test(l),
            `RÉGRESSION D-001 : le libellé « ${l} » dit « encaissé », donc des VENTES, sur un `
            + 'nombre qui est la somme des MOUVEMENTS du tiroir. C\'est précisément la '
            + 'confusion qui faisait annoncer 7,50 € à côté d\'une page montrant 5,00 €.'
        ).toBe(false);

        expect(
            /mouvement/i.test(l),
            `le libellé « ${l} » doit nommer la grandeur réelle (mouvements du tiroir)`
        ).toBe(true);
    });

    it('D-001 : la bannière affiche AUSSI les ventes espèces et leur ÉCART', () => {
        const src = source();

        expect(
            /ventesEspecesPeriode\s*\(\)/.test(src),
            'la bannière doit exposer les ventes en espèces de la période — sans elles, les '
            + 'deux grandeurs ne sont pas comparables et le lecteur ne peut pas trancher.'
        ).toBe(true);

        expect(
            /ecartEspeces\s*\(\)/.test(src),
            'RÉGRESSION : l\'ÉCART a disparu. C\'est le signal que cette bannière existe pour '
            + 'donner — un tiroir dont les mouvements ne collent pas aux ventes demande une '
            + 'explication — et il n\'était affiché nulle part avant ce correctif.'
        ).toBe(true);

        expect(src).toContain('data-testid="cash-overview-reconciliation-gap"');
        expect(src).toContain('data-testid="cash-overview-reconciliation-sales"');
    });

    it('D-001 : les ventes espèces viennent de LA MÊME SOURCE que la répartition affichée', () => {
        const src = source();
        const calcul = src.match(/ventesEspecesPeriode\(\)\s*\{([\s\S]*?)\n        \},/);
        expect(calcul, 'calcul des ventes espèces introuvable').not.toBeNull();

        // Tout l'objet du correctif : la bannière doit lire `by_mode`, exactement comme le
        // bloc « Répartition par mode » rendu plus bas. Une autre source rouvrirait la
        // contradiction sous une forme différente.
        expect(
            /by_mode/.test(calcul[1]),
            'les ventes espèces de la bannière doivent venir de `summary.by_mode`, la source '
            + 'que la page affiche déjà. Toute autre source rouvre la contradiction.'
        ).toBe(true);

        expect(
            /modes\.cash/.test(calcul[1]),
            'le seau des espèces est la clé `cash` (voir derivePaymentBucket côté serveur)'
        ).toBe(true);
    });

    it('D-002 : la décomposition par source n\'est plus figée sur trois clés', () => {
        const src = source();
        const calcul = src.match(/displayedSources\(\)\s*\{([\s\S]*?)\n        \},/);
        expect(calcul, 'displayedSources introuvable').not.toBeNull();

        expect(
            /Object\.keys\(stats\)/.test(calcul[1]),
            'RÉGRESSION D-002 : la liste des cartes est de nouveau figée. Toute clé de '
            + '`by_source` absente de la liste en dur est alors jetée EN SILENCE, alors que le '
            + 'GRAND TOTAL, lui, la compte — et le total cesse de boucler avec sa décomposition.'
        ).toBe(true);

        expect(
            /known\.concat\(autres\)|autres\.concat|\.\.\.known,\s*\.\.\.autres/.test(calcul[1]),
            'les clés connues doivent être conservées (mise en page stable même à zéro) ET '
            + 'complétées par celles réellement présentes.'
        ).toBe(true);
    });

    it('D-003 : la source non rattachée a un nom français, pas une clé brute', () => {
        const src = source();

        expect(
            /case 'unknown':\s*return this\.\$t\(/.test(src),
            'RÉGRESSION D-003 : le `default` de sourceLabel rend la CLÉ BRUTE. Une pastille '
            + 'portant littéralement « unknown » s\'affichait au milieu de pastilles traduites, '
            + 'sur un produit à locale immuable (ADR-007).'
        ).toBe(true);

        const l = libelles()['cash_source_unknown'];
        expect(l, 'le libellé de la source non rattachée est absent').toBeTruthy();
        expect(/^[\x00-\x7F]*[a-z]+$/i.test(l) && /unknown/i.test(l)).toBe(false);
    });

    it('les libellés ajoutés sont bien en français et non vides', () => {
        const l = libelles();
        ['cash_collected_in_period', 'cash_sales_in_period', 'cash_reconciliation_gap',
            'cash_reconciliation_gap_none', 'cash_source_unknown'].forEach((cle) => {
            expect(l[cle], `libellé « ${cle} » manquant`).toBeTruthy();
            expect(
                String(l[cle]).trim().length,
                `libellé « ${cle} » vide`
            ).toBeGreaterThan(2);
        });
    });
});
