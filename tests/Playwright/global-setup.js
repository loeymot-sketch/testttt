/**
 * Playwright global setup — when E2E_BACKEND_AVAILABLE=1:
 * - ensures the documented admin account (override with E2E_ADMIN_USER / E2E_ADMIN_PASS);
 * - seeds minimal ingredient rows + catalog studio category/item + permissions for critical-flow specs.
 */
const { execFileSync } = require('child_process');
const path = require('path');

module.exports = async function globalSetup() {
    const backend = /^(1|true|yes)$/i.test(process.env.E2E_BACKEND_AVAILABLE || '');
    if (!backend) {
        return;
    }

    const root = path.join(__dirname, '..', '..');

    // [REPLAN_8 2026-08-24] MÊME DOUBLE GARDE que les helpers borne — elle manquait ICI.
    //
    // Ce globalSetup s'exécute pour TOUTES les specs, avant tout `beforeAll`, et il écrit lourd :
    // `foodking:ensure-admin` réécrit un mot de passe admin, réactive le compte et remet
    // `deleted_at` à null (donc RESSUSCITE un admin supprimé), puis quatre seeders repassent
    // dessus. Aucune de ces écritures ne vérifiait la base cible : `E2E_BACKEND_AVAILABLE=1` seul
    // suffisait, et c'est un drapeau documenté dans les plans du dépôt. Un serveur pointé sur la
    // base de production aurait été réécrit sans un mot.
    //
    // On exige donc les deux mêmes signaux indépendants que les écritures E2E borne : l'opt-in
    // explicite ET une base dont le NOM porte un segment de test. `APP_ENV` est volontairement
    // ignoré : un processus mal configuré peut être en `testing` tout en visant la prod.
    const identiteBase = (() => {
        try {
            return execFileSync('php', [
                'artisan', 'tinker', '--execute',
                "echo json_encode(['db' => (string) DB::connection()->getDatabaseName()]);",
            ], { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
        } catch (erreur) {
            throw new Error(
                'globalSetup ne peut pas identifier la base de données avant d\'écrire : '
                + String(erreur && erreur.message).slice(0, 300),
            );
        }
    })();
    const nomBase = (() => {
        const lignes = String(identiteBase).trim().split('\n').filter(Boolean);
        for (let i = lignes.length - 1; i >= 0; i -= 1) {
            try {
                const objet = JSON.parse(lignes[i]);
                if (objet && typeof objet.db === 'string') return objet.db;
            } catch (_) { /* ligne de bruit tinker */ }
        }
        return '';
    })();
    const segmentDeTest = /(^|[^a-z0-9])(test|tests|testing|e2e|playwright)([^a-z0-9]|$)/i.test(nomBase);
    if (process.env.FOODKING_E2E_DEDICATED_DB !== '1' || !segmentDeTest) {
        throw new Error(
            'ARRÊT globalSetup : les seeders E2E réécrivent des comptes (mot de passe admin, '
            + 'réactivation, restauration d\'un compte supprimé) et la machine borne. Ils exigent '
            + 'FOODKING_E2E_DEDICATED_DB=1 ET une base dont le nom porte un segment test/e2e/'
            + `playwright. Base vue : ${nomBase || 'inconnue'}. `
            + 'E2E_BACKEND_AVAILABLE=1 seul ne suffit plus.',
        );
    }
    // [GOAL CONSOLIDATION_V1_PRODUCTION_20260825] LE SERVEUR CIBLÉ SERT-IL BIEN CET ARBRE ?
    //
    // Constaté le 2026-08-25 : `playwright.config.js` vise `http://localhost:8000` par défaut, et
    // sur cette machine ce port servait `.claude/worktrees/goal-caisse-vision-2026-08-24/public`
    // — un AUTRE worktree, à 89 fichiers et 15 356 lignes d'écart. Même URL, deux codes :
    // `/api/healthz` renvoyait `queue_pending=0` sur :8000 et `1490` sur :8766.
    //
    // Une campagne lancée ainsi ne produit pas des échecs isolés : elle produit une confiance
    // mal placée. Un correctif appliqué ici paraît « ne pas marcher » ; un défaut corrigé
    // ailleurs paraît « déjà résolu ». C'est le pire état possible d'un harnais de test.
    //
    // La vérification est volontairement bête et sûre : on dépose un marqueur unique dans le
    // `public/` de CET arbre, on demande au serveur de le rendre, et on le retire aussitôt.
    // Si le serveur est ailleurs, il ne peut pas le connaître.
    await (async function verifierMemeArbreDeTravail() {
        const fs = require('fs');
        const cible = (process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000').replace(/\/+$/, '');
        const jeton = `${Date.now()}-${Math.floor(Math.random() * 1e9)}`;
        const nomMarqueur = '.e2e-worktree-marker';
        const cheminMarqueur = path.join(root, 'public', nomMarqueur);

        let servi = null;
        let erreurReseau = null;
        try {
            fs.writeFileSync(cheminMarqueur, jeton, 'utf8');
            const reponse = await fetch(`${cible}/${nomMarqueur}?t=${jeton}`, {
                signal: AbortSignal.timeout(5000),
            });
            servi = reponse.ok ? (await reponse.text()).trim() : null;
        } catch (erreur) {
            erreurReseau = String((erreur && erreur.message) || erreur).slice(0, 200);
        } finally {
            try { fs.unlinkSync(cheminMarqueur); } catch (_) { /* déjà retiré */ }
        }

        if (erreurReseau !== null) {
            throw new Error(
                `ARRÊT globalSetup : impossible de joindre le serveur ciblé (${cible}) pour vérifier `
                + `qu'il sert bien cet arbre de travail. Détail : ${erreurReseau}. `
                + 'Démarrez le serveur, ou pointez PLAYWRIGHT_BASE_URL sur le bon port.',
            );
        }

        if (servi !== jeton) {
            throw new Error(
                `ARRÊT globalSetup : le serveur ${cible} NE SERT PAS cet arbre de travail `
                + `(${root}).\n`
                + 'Une campagne lancée ainsi mesurerait un autre code que celui que vous modifiez : '
                + 'vos correctifs paraîtraient sans effet, et des défauts déjà corrigés ailleurs '
                + 'paraîtraient résolus ici.\n'
                + 'Corrigez en pointant PLAYWRIGHT_BASE_URL sur le serveur de CET arbre — '
                + 'par exemple PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766.\n'
                + 'Voir reports/audit/P0_E2E_VISE_UN_AUTRE_WORKTREE_2026-08-25.md',
            );
        }
    })();

    const run = (args) =>
        execFileSync('php', ['artisan', ...args], {
            cwd: root,
            stdio: 'inherit',
            env: { ...process.env },
        });

    const adminEmail = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
    const adminPassword = process.env.E2E_ADMIN_PASS || '123456';

    // Align DB credentials with Playwright specs (avoids staying on /login when DB was reseeded elsewhere).
    run([
        'foodking:ensure-admin',
        `--email=${adminEmail}`,
        `--password=${adminPassword}`,
        '--no-interaction',
    ]);

    const posEmail = process.env.E2E_POS_USER || 'pos@lecayenne.fr';
    const posPassword = process.env.E2E_POS_PASS || '123456';
    run([
        'foodking:ensure-pos-operator',
        `--email=${posEmail}`,
        `--password=${posPassword}`,
        '--no-interaction',
    ]);

    const chefEmail = process.env.E2E_CHEF_USER || 'chef@lecayenne.fr';
    const chefPassword = process.env.E2E_CHEF_PASS || '123456';
    run([
        'foodking:ensure-chef-operator',
        `--email=${chefEmail}`,
        `--password=${chefPassword}`,
        '--no-interaction',
    ]);

    // Borne kiosk-lecayenne : user_id doit suivre l’admin E2E (voir KioskMachineTableSeeder).
    run(['db:seed', '--class=Database\\Seeders\\KioskMachineTableSeeder', '--no-interaction']);

    // argv form avoids shell eating `\` in `--class=Database\Seeders\...`.
    // Bases avec ~1 permission (ingredients_manage seul) : RolePermissionTableSeeder ne donne rien d’utile
    // → 403 settings/item-category → Catalog Studio 0 catégories. Heal conditionnel puis composer.
    run(['db:seed', '--class=Database\\Seeders\\E2EPlaywrightPermissionsHealSeeder', '--no-interaction']);
    run(['db:seed', '--class=Database\\Seeders\\ComposerPermissionsMinimalSeeder', '--no-interaction']);
    run(['db:seed', '--class=Database\\Seeders\\E2EPlaywrightIngredientRowsSeeder', '--no-interaction']);
    run(['db:seed', '--class=Database\\Seeders\\E2EPlaywrightCatalogStudioSeeder', '--no-interaction']);
    // permission:cache-reset busts Spatie/file cache so `php artisan serve` sees the new permission (H3).
    run(['permission:cache-reset']);
};
