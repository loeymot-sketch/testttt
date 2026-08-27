import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { regleDeChoix } from '../../resources/js/services/regleDeChoix';

/**
 * [ONB-10 2026-08-27] La règle de choix doit se lire, pas se déchiffrer.
 *
 * L'écran « Attribut d'articles » affichait « 0 - 1 » et « 1 - 1 » — l'encodage
 * min/max du développeur. Rien n'indiquait lequel des deux nombres était le minimum,
 * ni que « 0 » voulait dire « facultatif ». Un restaurateur qui compose sa carte y
 * définit ses sauces, ses viandes, ses boissons : c'est l'écran où il traduit son
 * métier en règles, et il était écrit dans une notation qui n'est pas la sienne.
 *
 * On rend la traduction, pas le texte final : la clé i18n est vérifiée ici, le
 * français dans fr.json, l'anglais dans en.json. Ainsi le test ne casse pas si
 * quelqu'un reformule la phrase, mais casse si quelqu'un change la RÈGLE.
 */
describe('ONB-10 · règle de choix lisible', () => {
    // Double de $t : renvoie la clé et les paramètres, pour assertion exacte.
    const t = (cle, params) => (params ? `${cle}(${JSON.stringify(params)})` : cle);

    it('0 à 1 se lit « facultatif, un seul choix »', () => {
        expect(regleDeChoix(0, 1, t)).toBe('label.choice_optional_one');
    });

    it('1 à 1 se lit « obligatoire, un seul choix »', () => {
        expect(regleDeChoix(1, 1, t)).toBe('label.choice_required_one');
    });

    it('0 à 3 se lit « facultatif, jusqu\'à 3 »', () => {
        expect(regleDeChoix(0, 3, t)).toBe('label.choice_optional_up_to({"n":3})');
    });

    it('2 à 2 se lit « obligatoire, exactement 2 »', () => {
        expect(regleDeChoix(2, 2, t)).toBe('label.choice_required_exactly({"n":2})');
    });

    it('1 à 4 se lit « obligatoire, de 1 à 4 »', () => {
        expect(regleDeChoix(1, 4, t)).toBe('label.choice_required_range({"min":1,"max":4})');
    });

    it('reprend les valeurs par défaut du formulaire quand la base est incomplète', () => {
        // Le formulaire de création écrit min_select: 0, max_select: 1. Une ligne
        // ancienne sans ces colonnes doit se lire comme ce que le formulaire aurait
        // produit, pas comme une plage vide.
        expect(regleDeChoix(null, null, t)).toBe('label.choice_optional_one');
        expect(regleDeChoix(undefined, undefined, t)).toBe('label.choice_optional_one');
    });

    it('ne rend jamais une plage impossible', () => {
        // Un maximum inférieur au minimum ne doit pas produire « 3 à 1 » à l'écran.
        expect(regleDeChoix(3, 1, t)).toBe('label.choice_required_exactly({"n":3})');
        // Un maximum de 0 non plus.
        expect(regleDeChoix(0, 0, t)).toBe('label.choice_optional_one');
    });

    it('les cinq clés existent en français ET en anglais', () => {
        const cles = [
            'choice_optional_one',
            'choice_required_one',
            'choice_optional_up_to',
            'choice_required_exactly',
            'choice_required_range',
        ];

        for (const langue of ['fr', 'en']) {
            const json = JSON.parse(
                fs.readFileSync(
                    path.join(process.cwd(), `resources/js/languages/${langue}.json`),
                    'utf8',
                ),
            );

            for (const cle of cles) {
                expect(
                    json.label?.[cle],
                    `${langue}.json : la clé label.${cle} manque — l'écran afficherait la clé brute`,
                ).toBeTruthy();
            }
        }
    });

    it("l'écran utilise la fonction plutôt que la notation brute", () => {
        const source = fs.readFileSync(
            path.join(
                process.cwd(),
                'resources/js/components/admin/settings/ItemAttribute/ItemAttributeListComponent.vue',
            ),
            'utf8',
        );

        expect(source).toContain('regleDeChoix');
        // La notation d'origine ne doit pas revenir par mégarde.
        expect(source).not.toContain('min_select ?? 0 }} - {{');
    });
});
