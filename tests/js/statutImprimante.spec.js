import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import {
    statutImprimante,
    imprimanteActive,
    STATUT_ACTIF,
    STATUT_ARCHIVE,
} from '../../resources/js/services/statutImprimante';

/**
 * [ONB-10 2026-08-28] Le formulaire d'imprimante s'ouvrait sans aucun statut coché.
 *
 * `openEdit()` faisait `status: Number(printer.status) || 5`. Pour une ligne portant
 * la valeur héritée 1 — celle que le schéma pose par défaut
 * (`unsignedTinyInteger('status')->default(1)`) — cela donne 1, et les deux boutons
 * radio portent 5 et 10. Résultat : aucun n'est coché, le commerçant ne voit pas
 * l'état de son imprimante, et enregistrer renvoie un 422 muet puisque la validation
 * (que j'ai moi-même resserrée à `Rule::in([ACTIVE, INACTIVE])`) refuse 1.
 *
 * Constat d'honnêteté : sur cette installation, aucune ligne ne porte 1 — les deux
 * commandes de configuration et le contrôleur écrivent tous 5. C'est une incohérence
 * LATENTE entre le schéma et l'énumération, pas une panne en cours. On la ferme
 * parce qu'elle est gratuite à fermer, pas parce qu'elle brûle.
 */
describe('ONB-10 · statut d\'imprimante normalisé', () => {
    it('rend les deux valeurs de l\'énumération inchangées', () => {
        expect(statutImprimante(5)).toBe(STATUT_ACTIF);
        expect(statutImprimante(10)).toBe(STATUT_ARCHIVE);
    });

    it('ramène la valeur héritée 1 sur « actif » plutôt que sur rien', () => {
        // C'est le défaut de schéma ET l'« actif » de l'ancien écran : les deux
        // lectures concordent, la normalisation est sans ambiguïté ici.
        expect(statutImprimante(1)).toBe(STATUT_ACTIF);
    });

    it('ramène l\'ancien 0 sur « archivé »', () => {
        // L'ancienne validation acceptait `Rule::in([0, 1])` : 0 y était l'inactif.
        expect(statutImprimante(0)).toBe(STATUT_ARCHIVE);
    });

    it('tombe sur « actif » quand la valeur est absente ou illisible', () => {
        expect(statutImprimante(null)).toBe(STATUT_ACTIF);
        expect(statutImprimante(undefined)).toBe(STATUT_ACTIF);
        expect(statutImprimante('')).toBe(STATUT_ACTIF);
        expect(statutImprimante('bonjour')).toBe(STATUT_ACTIF);
    });

    it('ne renvoie JAMAIS une valeur qu\'aucun bouton radio ne porte', () => {
        // C'est l'invariant qui compte : quelle que soit l'entrée, le formulaire
        // s'ouvre avec un bouton coché.
        for (const entree of [0, 1, 2, 3, 5, 7, 10, 42, -1, null, undefined, '', 'x', '5', '10']) {
            expect(
                [STATUT_ACTIF, STATUT_ARCHIVE],
                `statutImprimante(${JSON.stringify(entree)}) doit tomber sur un des deux boutons`,
            ).toContain(statutImprimante(entree));
        }
    });

    it('accepte les chaînes, parce que l\'API renvoie du JSON', () => {
        expect(statutImprimante('5')).toBe(STATUT_ACTIF);
        expect(statutImprimante('10')).toBe(STATUT_ARCHIVE);
    });

    it('imprimanteActive répond à la question que se pose le commerçant', () => {
        expect(imprimanteActive(5)).toBe(true);
        expect(imprimanteActive(1)).toBe(true);
        expect(imprimanteActive(10)).toBe(false);
        expect(imprimanteActive(0)).toBe(false);
    });

    it('la valeur 5 reste « actif » — la collision est tranchée pour l\'énumération', () => {
        // L'ancien écran écrivait 5 pour « archivé ». On ne peut pas distinguer les
        // deux origines. On suit l'énumération, parce que c'est elle que consultent
        // `EscPosPrinterService` et les écouteurs d'impression à l'exécution :
        // afficher « archivé » sur une imprimante qui imprime serait le mensonge
        // le plus coûteux des deux.
        expect(statutImprimante(5)).toBe(STATUT_ACTIF);
    });

    it("l'écran utilise la normalisation aux deux endroits", () => {
        const source = fs.readFileSync(
            path.join(
                process.cwd(),
                'resources/js/components/admin/settings/Printers/PrintersComponent.vue',
            ),
            'utf8',
        );

        expect(source).toContain('statutImprimante');
        // La forme d'origine ne doit pas revenir : elle laissait le formulaire vide.
        expect(source).not.toContain('Number(printer.status) || 5');
        // Ni la comparaison brute de la liste, qui affichait « Archivé » sur un 1.
        expect(source).not.toContain('Number(printer.status) === 5');
    });
});
