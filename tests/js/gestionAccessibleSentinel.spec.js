import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ACCÈS GESTION 2026-08-08] Trois écrans de gestion étaient décoratifs ou inatteignables.
 *
 * Chaîne d'autorisation du SPA, vérifiée : `PermissionService::permission()` renvoie chaque
 * permission avec un drapeau `access` → `appService.recursiveRouter` l'écrit dans `meta.access` →
 * `router.beforeEach` rejette avec un toast et renvoie au tableau de bord si `access === false`.
 * La barre latérale, elle, décide séparément d'AFFICHER via `MENU_URL_TO_PERMISSION_URL` — et
 * retombe en PERMISSIF quand l'url n'y figure pas. Les deux décisions pouvaient donc se
 * contredire, et se contredisaient.
 *
 * Ce que cette sentinelle verrouille :
 *   1. les rapports Z NF525 exigent côté écran le MÊME droit que côté serveur. Contradiction
 *      mesurée avant correctif : le Gérant a `pos/manage-fiscal` mais pas `settings` — le
 *      back-end l'autorisait (`ZReportController` : `abort_unless($user->can('pos-manage-fiscal'))`)
 *      et le routeur le rejetait. Seul l'Admin atteignait ses propres rapports fiscaux.
 *   2. `cash-overview` et `delivery-boy-cash-sessions` ne sont plus des liens MORTS : faute de
 *      correspondance ils s'affichaient pour tout le monde alors que leur route exige un droit que
 *      les 9 comptes caisse réels n'ont pas. Deux liens morts dans la navigation quotidienne.
 *   3. l'écran « Appareils connectés » a au moins un lien entrant. Livré le 2026-08-07 pour
 *      révoquer un terminal, il n'en avait AUCUN : une fonctionnalité de sécurité qu'on ne peut
 *      atteindre qu'en tapant l'URL n'existe pas.
 *
 * Sentinelles STATIQUES, et c'est voulu : ce qu'on verrouille est la COHÉRENCE entre deux fichiers
 * de configuration. Monter le routeur complet testerait le framework, pas cette cohérence.
 */
const R = (p) => fs.readFileSync(path.resolve(__dirname, '../../', p), 'utf8');

describe('accès aux écrans de gestion', () => {
    it('les rapports Z exigent le même droit à l\'écran qu\'au serveur', () => {
        const routes = R('resources/js/router/modules/settingRoutes.js');
        const i = routes.indexOf('name: "admin.settings.zReports"');
        expect(i, 'la route des rapports Z a disparu').toBeGreaterThan(0);

        const bloc = routes.slice(i, i + 900);
        expect(bloc, 'les rapports Z sont redevenus tributaires du droit « réglages » : le Gérant, '
            + 'que le back-end autorise, serait de nouveau rejeté par le routeur')
            .toContain('permissionUrl: "pos/manage-fiscal"');

        // Contre-preuve : le droit exigé doit être celui que le contrôleur vérifie réellement.
        const ctrl = R('app/Http/Controllers/Admin/Fiscal/ZReportController.php');
        expect(ctrl).toContain("can('pos-manage-fiscal')");
    });

    it('aucun lien mort dans la barre latérale du caissier', () => {
        const menu = R('resources/js/components/layouts/backend/BackendMenuComponent.vue');

        // Chaque entrée doit être mappée sur le droit que sa ROUTE exige — sinon le menu affiche
        // ce que la route refusera.
        expect(menu).toContain("'cash-overview': 'cash-sessions-report'");
        expect(menu).toContain("'delivery-boy-cash-sessions': 'delivery-boys'");
        expect(menu).toContain("'observability/system': 'settings'");
        expect(menu).toContain("'observability/outbox': 'settings'");
        expect(menu).toContain("menuUrl.startsWith('observability/')");

        const overview = R('resources/js/router/modules/cashOverviewRoutes.js');
        expect(overview, 'la route a changé de droit : le mapping du menu doit suivre, sinon le lien '
            + 'redevient mort').toContain('cash-sessions-report');
    });

    it('l\'écran « Appareils connectés » a un lien entrant', () => {
        const navbar = R('resources/js/components/layouts/backend/BackendNavbarComponent.vue');
        expect(navbar, 'l\'écran de révocation des terminaux est redevenu orphelin : inatteignable '
            + 'autrement qu\'en tapant l\'URL').toContain('admin.profile.devices');

        // Les deux coquilles doivent être servies : la caisse V4 (lien dur) et le SPA (router-link).
        expect(navbar).toContain('/admin/profile/devices');

        const routes = R('resources/js/router/modules/profileRoutes.js');
        expect(routes, 'le nom de route pointé par le menu doit exister')
            .toContain('name: "admin.profile.devices"');
    });
});
