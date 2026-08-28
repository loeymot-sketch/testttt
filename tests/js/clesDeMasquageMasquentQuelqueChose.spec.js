import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { V1_HIDDEN_MENU_MODULES } from '../../resources/js/config/v1-hidden-modules';

/**
 * [ONB-05 2026-08-28] Quatre clés de masquage masquaient du vide.
 *
 * `V1_HIDDEN_MENU_MODULES` portait `settings.permission`, `settings.charge`,
 * `settings.translation` et `settings.activity-log`. Vérifié des DEUX côtés : aucune
 * route dans `settingRoutes.js`, et aucun `isSettingHidden()` ne les consomme dans
 * `MenuComponent.vue`. C'étaient des restes du nettoyage du 2 mai.
 *
 * Une clé fantôme n'a pas d'effet visible, et c'est précisément le problème : elle
 * donne l'illusion d'une décision de produit là où il n'y a plus rien à décider. Une
 * liste de 23 entrées dont 4 ne servent à rien ne se relit plus — on n'ose plus y
 * toucher parce qu'on ne sait plus laquelle compte.
 *
 * Ce banc exige que chaque clé de masquage AGISSE réellement. Il n'exprime aucune
 * opinion sur CE QUI doit être caché — c'est une décision de produit, et l'audit
 * ONB-05 la documente longuement (la page des taux de TVA et celle des rôles sont
 * cachées, ce qui mérite un arbitrage du propriétaire, pas un patch de ma part).
 */
describe('ONB-05 · liste de masquage du menu Réglages', () => {
    const menu = fs.readFileSync(
        path.join(
            process.cwd(),
            'resources/js/components/admin/settings/MenuComponent.vue',
        ),
        'utf8',
    );

    /** Les clés locales réellement interrogées par un `isSettingHidden(...)`. */
    const clesConsommees = new Set(
        [...menu.matchAll(/isSettingHidden\(['"]([A-Za-z0-9_]+)['"]\)/g)].map((m) => m[1]),
    );

    /** La table de conversion clé publique → clé locale. */
    const table = Object.fromEntries(
        [...menu.matchAll(/'(settings\.[a-z0-9-]+)':\s*'([A-Za-z0-9_]+)'/g)]
            .map((m) => [m[1], m[2]]),
    );

    it("l'extraction mord — sinon ce banc serait vert en ne mesurant rien", () => {
        expect(clesConsommees.size).toBeGreaterThan(10);
        expect(Object.keys(table).length).toBeGreaterThan(10);
    });

    it('chaque clé de masquage a une conversion vers une clé locale', () => {
        const orphelines = V1_HIDDEN_MENU_MODULES
            .filter((cle) => cle.startsWith('settings.'))
            .filter((cle) => !table[cle]);

        expect(
            orphelines,
            `Ces clés n'ont aucune conversion dans HIDDEN_KEY_TO_LOCAL_SETTING : elles `
            + `ne masquent donc rien. ${orphelines.join(', ')}`,
        ).toEqual([]);
    });

    it('chaque clé de masquage est réellement consommée par le menu', () => {
        const fantomes = V1_HIDDEN_MENU_MODULES
            .filter((cle) => cle.startsWith('settings.'))
            .filter((cle) => table[cle] && !clesConsommees.has(table[cle]));

        expect(
            fantomes,
            `Ces clés masquent du VIDE : aucun isSettingHidden() ne les interroge. `
            + `Une liste où certaines entrées ne servent à rien ne se relit plus. `
            + `${fantomes.join(', ')}`,
        ).toEqual([]);
    });

    it('les quatre fantômes retirés ne reviennent pas', () => {
        for (const fantome of [
            'settings.permission',
            'settings.charge',
            'settings.translation',
            'settings.activity-log',
        ]) {
            expect(
                V1_HIDDEN_MENU_MODULES.includes(fantome),
                `${fantome} est revenue dans la liste : elle n'a ni route ni consommateur`,
            ).toBe(false);
        }
    });

    it('les décisions de masquage réelles sont préservées', () => {
        // Contrôle négatif : nettoyer les fantômes ne doit pas démasquer des pages
        // que le produit cache DÉLIBÉRÉMENT. `settings.tax` et `settings.role` sont
        // les deux plus lourdes de conséquence — leur sort appartient au propriétaire,
        // pas à un nettoyage de liste.
        expect(V1_HIDDEN_MENU_MODULES).toContain('settings.tax');
        expect(V1_HIDDEN_MENU_MODULES).toContain('settings.role');
    });
});
