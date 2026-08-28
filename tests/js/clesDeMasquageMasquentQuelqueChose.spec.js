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

    /**
     * [ONB-05 2026-08-28 · PÉRIMÈTRE CORRIGÉ] Le SECOND mécanisme de masquage.
     *
     * Toutes les assertions ci-dessous filtraient sur `startsWith('settings.')` :
     * elles ne voyaient donc que 14 des 23 clés. Les 9 autres — `customers`,
     * `coupons`, `offers`, `creditBalanceReport`, `deliveryBoys`, `onlineOrders`,
     * `tableOrders`, `waiters`, `diningTables` — ne passent pas par
     * `isSettingHidden()` mais par `HIDDEN_KEY_TO_MENU_URL` dans
     * `BackendMenuComponent.vue`, qui les convertit en URL de menu puis les retire
     * de `visibleMenus`. Un fantôme parmi ces neuf serait passé entre les mailles,
     * exactement comme les quatre que ce banc a été écrit pour attraper.
     *
     * Neuvième fois dans cette session qu'une sentinelle verte gardait la moitié
     * d'une porte. Trouvé par un agent adverse lancé sur mon propre travail.
     */
    const menuPrincipal = fs.readFileSync(
        path.join(
            process.cwd(),
            'resources/js/components/layouts/backend/BackendMenuComponent.vue',
        ),
        'utf8',
    );

    const blocUrls = menuPrincipal.match(
        /const HIDDEN_KEY_TO_MENU_URL = Object\.freeze\(\{([\s\S]*?)\}\);/,
    );

    const tableUrls = Object.fromEntries(
        [...(blocUrls ? blocUrls[1] : '').matchAll(/([A-Za-z0-9_]+):\s*'([a-z0-9-]+)'/g)]
            .map((m) => [m[1], m[2]]),
    );

    it("l'extraction mord — sinon ce banc serait vert en ne mesurant rien", () => {
        expect(clesConsommees.size).toBeGreaterThan(10);
        expect(Object.keys(table).length).toBeGreaterThan(10);
        // Le second mécanisme aussi : si la lecture du bloc échoue, `tableUrls` est
        // vide et toutes les assertions qui s'appuient dessus deviennent creuses.
        expect(
            Object.keys(tableUrls).length,
            'HIDDEN_KEY_TO_MENU_URL n\'a pas pu être lu dans BackendMenuComponent.vue '
            + '— les assertions sur les 9 clés hors « settings. » ne mesureraient rien.',
        ).toBeGreaterThan(5);
    });

    it('AUCUNE clé de masquage n\'échappe aux deux mécanismes', () => {
        // C'est l'assertion qui manquait : elle couvre les 23 clés, pas 14.
        const orphelines = V1_HIDDEN_MENU_MODULES.filter(
            (cle) => !table[cle] && !tableUrls[cle],
        );

        expect(
            orphelines,
            'Ces clés ne sont converties par AUCUN des deux mécanismes de masquage '
            + '(isSettingHidden dans MenuComponent.vue, HIDDEN_KEY_TO_MENU_URL dans '
            + 'BackendMenuComponent.vue) : elles ne cachent donc rien nulle part. '
            + `${orphelines.join(', ')}`,
        ).toEqual([]);
    });

    it('les neuf clés de menu principal pointent chacune vers une URL', () => {
        const sansUrl = V1_HIDDEN_MENU_MODULES
            .filter((cle) => !cle.startsWith('settings.'))
            .filter((cle) => !tableUrls[cle]);

        expect(
            sansUrl,
            'Ces clés ne sont pas dans HIDDEN_KEY_TO_MENU_URL : `hiddenMenuUrls` les '
            + 'écarte par son `.filter(Boolean)` et le menu les affiche quand même. '
            + `${sansUrl.join(', ')}`,
        ).toEqual([]);
    });

    it('les deux mécanismes ne se recouvrent pas', () => {
        // Une clé traitée des deux côtés signalerait une décision dupliquée, donc
        // deux endroits à changer pour un seul arbitrage produit.
        const doublons = V1_HIDDEN_MENU_MODULES.filter(
            (cle) => table[cle] && tableUrls[cle],
        );

        expect(doublons, `Clés masquées deux fois : ${doublons.join(', ')}`).toEqual([]);
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
