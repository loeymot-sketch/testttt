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
