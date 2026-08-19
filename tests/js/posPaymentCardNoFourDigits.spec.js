import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * [LOCK-PAY-NO-CARD4 2026-08-19 · GOAL owner, gate propriétaire obtenu]
 *
 * Le patron : « je n'arrive pas à valider l'encaissement par carte bleue sans
 * taper 4 chiffres. N'importe quel code passe de toute façon. Je veux qu'en
 * cliquant sur Carte bleue ça passe DIRECTEMENT. »
 *
 * CE QUI A ÉTÉ VÉRIFIÉ AVANT DE RETIRER LE CHAMP (aucune régression fiscale) :
 *   · `pos_payment_note` n'apparaît NULLE PART dans app/Services/Fiscal/ ;
 *   · la ligne d'audit chaînée HMAC `order.created.pos` ne la contient pas —
 *     elle scelle `pos_payment_method`, pas les 4 chiffres ;
 *   · `ZReportService` ventile sur `pos_payment_method`, jamais sur la note ;
 *   · le backend accepte déjà la note VIDE depuis le 2026-08-05
 *     (tests/Feature/Pos/PosCardDeclarativeNoNoteTest.php) ;
 *   · une vente carte SANS TPE passe déjà côté serveur
 *     (tests/Feature/Pos/PosCardSaleWithoutTerminalTest.php).
 *
 * `PaymentComponent.vue` est en ZONE GELÉE (CLAUDE.md §7). Cette suite est le
 * cliquet qui empêche la réintroduction silencieuse du rituel des 4 chiffres.
 */

const SOURCE = readFileSync(
    resolve(__dirname, '../../resources/js/components/admin/pos/PaymentComponent.vue'),
    'utf8'
);

describe('Encaissement carte — plus aucune saisie de 4 chiffres', () => {
    it('le champ de saisie a disparu du composant', () => {
        expect(SOURCE, 'l\'input #cardInput ne doit plus exister').not.toMatch(/id="cardInput"/);
        expect(SOURCE, 'la ref cardInput ne doit plus exister').not.toMatch(/ref="cardInput"/);
    });

    it('le libellé « 4 derniers chiffres » n\'est plus affiché', () => {
        // La clé de langue peut rester dans les fichiers de traduction (orpheline),
        // mais elle ne doit plus être rendue par ce composant.
        expect(SOURCE).not.toMatch(/\$t\(\s*'label\.enter_card_last_4_digits'\s*\)/);
    });

    it('le pavé numérique est réservé aux espèces', () => {
        // En mode carte il n'a plus de cible : l'afficher suggérerait à tort
        // qu'une saisie est attendue.
        expect(SOURCE).toMatch(/v-if="paymentMode === 'cash'"/);
        expect(SOURCE).not.toMatch(/paymentMode === 'cash' \|\| paymentMode === 'card'/);
    });

    it('le repli sûr de collectPaymentInputPatch est conservé', () => {
        // Sans la ref, `this.$refs.cardInput?.value` vaut undefined → branche `: ""`
        // → ConvertEmptyStringsToNull → NULL → la règle `nullable` s'applique.
        // Si ce chaînage optionnel disparaissait, la suppression du champ
        // provoquerait un TypeError à chaque encaissement carte.
        expect(SOURCE).toMatch(/this\.\$refs\.cardInput\?\.value/);
    });
});

describe('Encaissement carte — le bouton Confirmer n\'est jamais mort', () => {
    it('canConfirmCard vaut true même sans aucun TPE sélectionné', async () => {
        const PaymentComponent = (await import(
            '../../resources/js/components/admin/pos/PaymentComponent.vue'
        )).default;

        const canConfirmCard = PaymentComponent.computed.canConfirmCard;

        // Cas terrain qui rendait la caisse incapable d'encaisser par carte :
        // GET admin/payment-terminals renvoie une liste vide (TPE désactivé ou
        // appel en échec) → selectedTerminalId reste null → bouton désactivé,
        // sans le moindre message d'explication.
        expect(canConfirmCard.call({ paymentMode: 'card', selectedTerminalId: null })).toBe(true);
        expect(canConfirmCard.call({ paymentMode: 'card', selectedTerminalId: 0 })).toBe(true);
        expect(canConfirmCard.call({ paymentMode: 'card', selectedTerminalId: 1 })).toBe(true);
        expect(canConfirmCard.call({ paymentMode: 'cash', selectedTerminalId: null })).toBe(true);
    });
});
