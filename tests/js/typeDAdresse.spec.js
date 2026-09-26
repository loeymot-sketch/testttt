import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { typeDAdresse } from '../../resources/js/services/typeDAdresse';
import labelEnum from '../../resources/js/enums/modules/labelEnum';

/**
 * [ONB-11 2026-08-28] Le type d'adresse survit au changement de libellé.
 *
 * DEUX DÉFAUTS, dont le second est celui qui rendait le premier dangereux à
 * corriger.
 *
 * 1. `label.work` valait « Work » en français — un mot anglais au milieu d'un trio
 *    de boutons radio. Et `label.home` valait « Accueil », qui désigne une page
 *    d'accueil, pas un domicile. Le client choisissait entre « Accueil », « Work »
 *    et « Autre » pour son adresse de livraison.
 *
 * 2. C'est la chaîne TRADUITE qui est écrite en base
 *    (`this.props.form.label = $t('label.home')`), et huit écrans la relisent en la
 *    comparant à la traduction COURANTE. Corriger les libellés aurait donc fait
 *    basculer tous les enregistrements existants sur « Autre » à l'édition.
 *
 * Ce banc verrouille la compatibilité : « Accueil » et « Work », écrits avant ce
 * jour, restent reconnus. Sans cette tolérance, le correctif du libellé aurait été
 * une régression — la présentation servant de donnée, on ne peut pas la changer
 * sans prévoir la lecture de l'ancienne.
 *
 * ⏳ La CAUSE n'est pas corrigée : stocker un identifiant plutôt qu'un libellé
 * demande une migration de données, donc le gate propriétaire G-DATA, en attente.
 */
describe('ONB-11 · type d\'adresse', () => {
    it('reconnaît les libellés ACTUELS', () => {
        expect(typeDAdresse('Domicile')).toBe(labelEnum.HOME);
        expect(typeDAdresse('Travail')).toBe(labelEnum.WORK);
    });

    it('reconnaît encore les libellés HISTORIQUES', () => {
        // C'est tout l'objet du banc : des enregistrements portent ces valeurs.
        expect(
            typeDAdresse('Accueil'),
            "« Accueil » était le libellé français AVANT le 2026-08-28. Les adresses "
            + 'déjà enregistrées le portent : elles doivent rester reconnues.',
        ).toBe(labelEnum.HOME);

        expect(typeDAdresse('Work')).toBe(labelEnum.WORK);
    });

    it("reconnaît l'anglais, quelle que soit la langue affichée aujourd'hui", () => {
        expect(typeDAdresse('Home')).toBe(labelEnum.HOME);
        expect(typeDAdresse('work')).toBe(labelEnum.WORK);
    });

    it('ignore la casse et les espaces de bord', () => {
        expect(typeDAdresse('  DOMICILE ')).toBe(labelEnum.HOME);
        expect(typeDAdresse('travail')).toBe(labelEnum.WORK);
    });

    it('tout libellé libre reste « Autre »', () => {
        // Le commerçant peut écrire ce qu'il veut : « Chantier », « Chez ma mère ».
        // La valeur est CONSERVÉE ; seul le bouton radio bascule.
        expect(typeDAdresse('Chantier')).toBe(labelEnum.OTHER);
        expect(typeDAdresse('')).toBe(labelEnum.OTHER);
        expect(typeDAdresse(null)).toBe(labelEnum.OTHER);
        expect(typeDAdresse(undefined)).toBe(labelEnum.OTHER);
    });

    it('les huit écrans utilisent la fonction, plus la comparaison directe', () => {
        const ecrans = [
            'resources/js/components/frontend/account/address/AddressComponent.vue',
            'resources/js/components/admin/customers/address/CustomerAddressList.vue',
            'resources/js/components/admin/waiters/address/WaiterAddressList.vue',
            'resources/js/components/admin/deliveryBoys/address/DeliveryBoyAddressList.vue',
            'resources/js/components/admin/pos/PosComponent.vue',
            'resources/js/components/admin/chefs/address/ChefAddressList.vue',
            'resources/js/components/admin/administrators/address/AdministratorAddressList.vue',
            'resources/js/components/admin/employees/address/EmployeeAddressList.vue',
        ];

        for (const ecran of ecrans) {
            const source = fs.readFileSync(path.join(process.cwd(), ecran), 'utf8');

            expect(source, `${ecran} n'utilise pas typeDAdresse`).toContain('typeDAdresse');

            expect(
                source.includes('=== this.$t("label.home")'),
                `${ecran} compare encore le libellé stocké à la traduction courante : `
                + 'un changement de libellé y ferait basculer les adresses existantes '
                + 'sur « Autre ».',
            ).toBe(false);
        }
    });

    it('« Accueil » reste la navigation, « Domicile » devient l\'adresse', () => {
        const fr = JSON.parse(
            fs.readFileSync(path.join(process.cwd(), 'resources/js/languages/fr.json'), 'utf8'),
        );

        expect(fr.label.home).toBe('Domicile');
        expect(fr.label.work).toBe('Travail');

        // Contrôle négatif : le lien de navigation ne doit PAS avoir été renommé au
        // passage — « Accueil » y est le mot juste.
        expect(fr.menu.home).toBe('Accueil');
    });
});
