import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB-03 2026-08-28] Un bouton visible qui ne menait nulle part.
 *
 * Le bouton engrenage « Composer / wizard » du Studio Catalogue ne regardait que la
 * permission `catalog.compose`. Or :
 *
 *   · cette permission est donnée à l'Admin dès l'installation
 *     (`ComposerPermissionsMinimalSeeder`) ;
 *   · le drapeau `wizard_per_item_demo` vaut **false** par défaut
 *     (`config/catalog_v15.php`, `.env.example`) ;
 *   · et le routeur REDIRIGE vers le catalogue quand il est éteint
 *     (`itemRoutes.js:15`), pendant que le middleware serveur renvoie un 404.
 *
 * Sur une installation neuve, cliquer l'engrenage ouvrait donc un panneau qui
 * affichait… le catalogue lui-même, sans un mot d'explication. Un bouton visible qui
 * ne mène nulle part est pire qu'un bouton absent : le commerçant croit avoir raté
 * quelque chose et recommence.
 *
 * Ce n'est pas une invention : **cinq** autres endroits vérifiaient déjà ce drapeau —
 * `MenuComponent`, `ItemCreateComponent`, `ProductComposerSummaryComponent`,
 * `ItemListComponent` et le routeur. Le Studio était le seul à ne pas le faire. Ce
 * banc rétablit la cohérence et la garde.
 */
describe('ONB-03 · bouton composeur du Studio Catalogue', () => {
    const studio = fs.readFileSync(
        path.join(process.cwd(), 'resources/js/components/admin/items/CatalogStudioComponent.vue'),
        'utf8',
    );

    it('le bouton exige le drapeau EN PLUS de la permission', () => {
        expect(studio).toContain('canComposeCatalog && wizardPerItemDemoEnabled');
        expect(
            studio.includes('v-if="canComposeCatalog"'),
            'le bouton est repassé sur la seule permission : il redeviendrait visible '
            + 'alors que le routeur redirige',
        ).toBe(false);
    });

    it('le drapeau est lu de la même façon que partout ailleurs', () => {
        // Une sixième façon d'écrire la même condition serait une divergence
        // programmée : c'est exactement ce qui a créé ce défaut.
        expect(studio).toContain("window.foodkingConfig?.features?.wizard_per_item_demo === true");
    });

    it('les cinq autres lecteurs du drapeau sont toujours là', () => {
        // Contrôle négatif : ce banc ne doit pas devenir la seule garde. Si les
        // siblings perdaient la leur, le drapeau cesserait d'être une barrière.
        const lecteurs = [
            'resources/js/components/admin/settings/MenuComponent.vue',
            'resources/js/components/admin/items/ItemCreateComponent.vue',
            'resources/js/components/admin/items/ProductComposerSummaryComponent.vue',
            'resources/js/components/admin/items/ItemListComponent.vue',
            'resources/js/router/modules/itemRoutes.js',
        ];

        for (const fichier of lecteurs) {
            const source = fs.readFileSync(path.join(process.cwd(), fichier), 'utf8');
            expect(
                source,
                `${fichier} ne vérifie plus le drapeau wizard_per_item_demo`,
            ).toContain('wizard_per_item_demo');
        }
    });

    it('le SECOND drapeau est documenté dans .env.example', () => {
        // `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` commande la lecture du profil par la
        // CAISSE. Il était absent du fichier d'exemple : deux verrous, un seul visible.
        // Sans lui, configurer ses étapes et lever le premier drapeau ne suffit pas —
        // la caisse retombe sur son heuristique de noms héritée.
        const exemple = fs.readFileSync(path.join(process.cwd(), '.env.example'), 'utf8');

        expect(exemple).toContain('FK_POS_WIZARD_COMPOSER_AWARE_ENABLED');
        expect(exemple).toContain('FEATURE_WIZARD_PER_ITEM_DEMO');
    });
});
