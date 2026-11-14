import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB-08 2026-08-28] L'écran des matières premières : atteignable, et il ne
 * confond pas « aucune alerte » avec « alerte à zéro ».
 *
 * ═══ CE QUE CET ÉCRAN DÉBLOQUE ═══
 *
 * Le domaine matière première n'exposait que `movements` (lecture) et `adjust`
 * (correction de quantité). Les seules sources de création étaient un seeder et une
 * commande console : **un nouveau commerçant ne pouvait déclarer aucun ingrédient.**
 *
 * ⚠️ Le commentaire de `RawMaterialAdjustComponent` dans `stockRoutes.js` affirme
 * être « la seule porte d'écriture manquante du domaine matière première ». C'était
 * faux, et c'est le motif que cette session traque : un commentaire qui affirme ce
 * que le code ne fait pas.
 *
 * ═══ LE POINT SUBTIL, ET LE PLUS COÛTEUX ═══
 *
 * `threshold_low` n'avait aucun chemin d'écriture. Mesuré : **20/20** matières et
 * **55/55** niveaux de stock à NULL — pas à 0. Or `StockRuptureDashboardController`
 * et `NotifyStockLowOnStockLevelChanged` filtrent tous deux
 * `whereNotNull('threshold_low')` : **100 % des lignes étaient exclues**, donc
 * l'alerte de stock bas était structurellement muette.
 *
 * En ouvrant ce chemin, il devient facile d'écrire `0` là où le commerçant a laissé
 * le champ vide. Ce serait pire que l'état d'avant : au lieu d'une alerte muette, il
 * en recevrait une au premier gramme manquant, sur chaque matière, sans l'avoir
 * demandé. **Vide et zéro ne veulent pas dire la même chose**, et ce banc le fige.
 */
describe("l'écran des matières distingue « aucune alerte » de « alerte à zéro »", () => {
    const racine = process.cwd();
    const lire = (p) => fs.readFileSync(path.join(racine, p), 'utf8');

    const ECRAN = 'resources/js/components/admin/stock/RawMaterialListComponent.vue';
    const ROUTES = 'resources/js/router/modules/stockRoutes.js';
    const VUE_STOCK = 'resources/js/components/admin/stock/UnifiedStockViewComponent.vue';

    it('le relevé mord — sinon ce banc serait vert en ne lisant rien', () => {
        expect(lire(ECRAN).length, "L'écran est vide ou introuvable.").toBeGreaterThan(4000);
        expect(lire(ECRAN)).toContain('raw-material-form');
    });

    it("l'écran a une route ET une porte depuis la vue stock", () => {
        expect(lire(ROUTES)).toContain("name: \"admin.stock.raw-materials\"");
        expect(lire(ROUTES)).toContain('RawMaterialListComponent');

        // Sans lien, l'écran ne serait atteignable qu'en tapant son URL — le défaut
        // corrigé trois fois cette nuit (import de carte, assistant, page TVA).
        expect(
            lire(VUE_STOCK),
            "L'écran de déclaration n'a aucune porte : il ne serait atteignable qu'en\n"
            + 'tapant son URL.',
        ).toContain("{ name: 'admin.stock.raw-materials' }");
    });

    it("un seuil laissé vide part en `null`, jamais en zéro", () => {
        const source = lire(ECRAN);

        // Envoyer 0 ferait sonner l'alerte au premier gramme manquant, sur chaque
        // matière, sans que le commerçant l'ait demandé.
        expect(
            source,
            'Le seuil vide doit partir en `null` : `0` déclencherait une alerte que le '
            + "commerçant n'a pas réglée.",
        ).toMatch(/threshold_low:\s*this\.form\.threshold_low === ""\s*\?\s*null\s*:/);
    });

    it("un seuil absent revient VIDE dans le formulaire, pas à zéro", () => {
        const source = lire(ECRAN);

        // L'autre moitié de la même corde : hydrater `0` depuis un `null` écrirait
        // zéro au premier enregistrement, sans que rien ne le signale. C'est le motif
        // « une ressource omet un champ que l'écran renvoie », corrigé trois fois
        // aujourd'hui sur `siret`, les réglages de borne et `channels`.
        expect(source).toMatch(/threshold_low:\s*matiere\.threshold_low === null\s*\?\s*""\s*:/);
    });

    it("la liste affiche « aucune alerte » plutôt qu'un zéro trompeur", () => {
        const source = lire(ECRAN);

        expect(source).toContain("m.threshold_low === null");
        expect(source).toContain("label.threshold_low_none");
    });

    it("les unités viennent du SERVEUR, pas d'une liste recopiée", () => {
        const source = lire(ECRAN);

        // La conversion des factures d'achat ne sait traiter qu'un jeu précis
        // d'unités. Une liste écrite en double dériverait au premier ajout — le motif
        // du « jumeau oublié », qui a déjà coûté trois fois dans ce dépôt.
        expect(source).toContain('unites_acceptees');
        expect(source).toContain('v-for="u in unitesAcceptees"');
    });

    it("l'écran ne touche pas aux quantités", () => {
        const source = lire(ECRAN);

        // `adjust` reste la seule porte pour le stock, avec sa traçabilité par
        // mouvement. Déclarer une matière et corriger un stock sont deux gestes
        // distincts ; les confondre ferait perdre la trace de l'un des deux.
        expect(source).not.toContain('/adjust');
        expect(source).not.toContain('on_hand:');
    });

    it('les libellés existent dans les deux langues', () => {
        const cles = [
            'raw_materials_title', 'raw_materials_subtitle', 'raw_materials_empty',
            'raw_materials_manage', 'raw_material_new', 'raw_material_edit',
            'threshold_low', 'threshold_low_help', 'threshold_low_none',
            'unit', 'stock', 'edit',
        ];

        for (const fichier of ['resources/js/languages/fr.json', 'resources/js/languages/en.json']) {
            const arbre = JSON.parse(lire(fichier));
            const manquantes = cles.filter((c) => typeof arbre.label[c] !== 'string');

            expect(
                manquantes,
                `${fichier} : l'écran afficherait ces clés brutes — ${manquantes.join(', ')}`,
            ).toEqual([]);

            // Le fil d'Ariane rend `menu.<clé>`, pas `label.<clé>`.
            expect(typeof arbre.menu.raw_materials_title).toBe('string');
        }
    });
});
