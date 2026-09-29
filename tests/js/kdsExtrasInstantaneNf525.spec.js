import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// [GOAL-CAISSE-VISION 2026-08-24 · défaut trouvé en chemin, HORS voie caisse]
//
// Le board KDS *legacy* lisait `extra.name`. Or l'instantané NF525 nomme ce champ
// `extra_name` (CompositionSnapshotBuilder.php:110), et c'est l'instantané qui est
// servi EN PRIORITÉ (KDSOrderItemsResource:81-86 et OrderItemResource:85-93).
//
// Mesuré à l'exécution le 2026-08-24 sur la ligne RÉELLE #3956 de la base :
//   cles_de_la_premiere_entree = extra_id,quantity,extra_name,line_total,unit_price
//   valeur lue par le gabarit (extra.name)      = NULL
//   valeur réellement présente  (extra_name)    = 'Salade'
// La cuisine affichait donc « Extras: , , , » — quatre garnitures invisibles, donc
// un produit remis au client sans ce qu'il avait demandé.
//
// Ce spec importe la MÉTHODE RÉELLE du composant — pas une réplique inline : une
// copie du code ne prouve rien sur le fichier qui tourne en production.

import KitchenDisplaySystemComponent from '../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue';

const nomExtra = (e) => KitchenDisplaySystemComponent.methods.kdsExtraDisplayName(e);

describe('KDS legacy — les extras de l\'instantané NF525 redeviennent lisibles', () => {
    it('lit la forme INSTANTANÉ (extra_name) — celle qui est servie en priorité', () => {
        expect(nomExtra({ extra_id: 5, extra_name: 'Salade', quantity: 1, unit_price: 0, line_total: 0 }))
            .toBe('Salade');
        expect(nomExtra({ extra_id: 9, extra_name: 'Cheddar', quantity: 2 })).toBe('Cheddar');
    });

    it('lit encore l\'ANCIENNE forme (name) — une commande d\'hier reste lisible', () => {
        expect(nomExtra({ id: 12, name: 'Oignons frits', quantity: 1 })).toBe('Oignons frits');
    });

    it('ne rend JAMAIS une chaîne vide pour une entrée qui existe', () => {
        // Une ligne réduite à des ids est facturée : la faire disparaître du board
        // serait pire que de l'annoncer sans la nommer.
        expect(nomExtra({ extra_id: 7, quantity: 1 })).toBe('Supplément');
        expect(nomExtra({})).toBe('Supplément');
    });

    it('reste muet sur une entrée qui n\'est pas un objet', () => {
        expect(nomExtra(null)).toBe('');
        expect(nomExtra(undefined)).toBe('');
        expect(nomExtra('Cheddar')).toBe('');
    });

    it('l\'instantané prime sur l\'ancienne forme quand les deux coexistent', () => {
        expect(nomExtra({ extra_name: 'Salade', name: 'ANCIEN' })).toBe('Salade');
    });

    it('le gabarit n\'appelle plus jamais extra.name en direct', () => {
        // Garde de non-retour : si quelqu'un réintroduit `{{ extra.name }}`, ce test
        // rougit avant que la cuisine ne perde à nouveau ses garnitures.
        const source = readFileSync(
            resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue'),
            'utf8',
        );

        expect(source).not.toContain('{{ extra.name }}');
        // Les 5 sites d'affichage passent tous par l'assistant.
        expect(source.split('kdsExtraDisplayName(extra)').length - 1).toBeGreaterThanOrEqual(5);
    });
});
