import { describe, it, expect } from 'vitest';

// [AUDIT-SUPERVISEUR 2026-08-25 · C-017] Sentinelle du facteur commun des libellés.
//
// L'HISTOIRE QUE CE FICHIER EMPÊCHE DE SE RÉPÉTER
// Le même tableau de correspondance était recopié à la main dans huit composants, et
// chaque copie oubliait des valeurs différentes. Trois audits successifs ont trouvé
// « un libellé qui survit à sa valeur » sur ces écrans, et les ont corrigés un par un.
// La QUATRIÈME copie — celle de la facture REMISE AU CLIENT — est restée cassée
// pendant tout ce temps : « Type de commande: » et « Type de paiement: » vides, sur
// 100 % du parc V1.
//
// La cause n'était pas l'inattention, c'était la duplication. Ce spec balaie
// l'énumération ENTIÈRE : une valeur ajoutée demain sans libellé fait rougir ici,
// avant d'atteindre un écran.

import orderTypeEnum from '../../resources/js/enums/modules/orderTypeEnum';
import posPaymentMethodEnum from '../../resources/js/enums/modules/posPaymentMethodEnum';
import fr from '../../resources/js/languages/fr.json';
import { orderTypeLabels, posPaymentMethodLabels } from '../../resources/js/helpers/orderEnumLabels';

/** Résout une clé i18n contre le VRAI catalogue français. */
const t = (cle) => {
    let v = fr;
    for (const p of String(cle).split('.')) v = v?.[p];
    return typeof v === 'string' ? v : cle;
};

describe('libellés d\'énumération — aucune valeur ne peut rester sans nom', () => {
    it('nomme TOUS les types de commande, sans exception', () => {
        const labels = orderTypeLabels(t);

        Object.entries(orderTypeEnum).forEach(([nom, valeur]) => {
            expect(labels[valeur], `le type ${nom} (${valeur}) n'a aucun libellé`).toBeTruthy();
        });
    });

    it('nomme TOUS les modes de paiement, sans exception', () => {
        const labels = posPaymentMethodLabels(t);

        Object.entries(posPaymentMethodEnum).forEach(([nom, valeur]) => {
            expect(labels[valeur], `le mode ${nom} (${valeur}) n'a aucun libellé`).toBeTruthy();
        });
    });

    it('aucun libellé n\'est une clé i18n non résolue', () => {
        const tous = { ...orderTypeLabels(t), ...posPaymentMethodLabels(t) };

        Object.entries(tous).forEach(([valeur, libelle]) => {
            expect(libelle, `valeur ${valeur} : libellé vide`).not.toBe('');
            // Une clé non résolue ressemble à « label.quelque_chose » : point + minuscules.
            expect(libelle, `valeur ${valeur} : « ${libelle} » ressemble à une clé brute`)
                .not.toMatch(/^[a-z]+(\.[a-z_]+)+$/);
        });
    });

    it('nomme les deux SEULS types du parc V1 — le défaut d\'origine', () => {
        const labels = orderTypeLabels(t);

        expect(labels[orderTypeEnum.POS]).toBe('POS');
        expect(labels[orderTypeEnum.KIOSK]).toBe('Borne');
    });

    it('« À encaisser » est un ÉTAT, pas un instrument de paiement', () => {
        // COUNTER_DEFERRED est le mode de toute commande borne en attente : la
        // commande est enregistrée, l'argent n'est pas encore pris. Le libellé doit
        // dire ce qu'il reste à faire, pas nommer un moyen de paiement inexistant.
        expect(posPaymentMethodLabels(t)[posPaymentMethodEnum.COUNTER_DEFERRED]).toBe('À encaisser');
    });
});
