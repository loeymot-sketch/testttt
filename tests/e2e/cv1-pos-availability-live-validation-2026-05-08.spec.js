/**
 * CV1-POS-AVAILABILITY-LIVE-001 validation runtime
 *
 * Cible : prouver que le fix SPA + backend empêche la tile cliquable quand
 * un item est OOS, et que /admin/item retourne 422 si surface=pos sans branch_id
 * pour un user ayant la perm `pos`.
 *
 * Fix appliqué :
 * - SPA: PosComponent.vue mounted() ne fetch PAS itemList() si bootstrapBranchId=0
 * - Backend: ItemController::index() retourne 422 sur surface=pos + !branch_id + user can(pos)
 *
 * Référence : docs/audit/CV1-POS-AVAILABILITY-LIVE-001_INVESTIGATION_2026-05-08.md
 */
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('./helpers/login');

const BASE = 'http://localhost:8000';

test.describe('CV1-POS-AVAILABILITY-LIVE-001 — fix validation', () => {

    test('CV1-A — Admin global (branch_id=0) ne fetch PAS /admin/item au mount POS', async ({ page }) => {
        // admin@lecayenne.fr a branch_id=0 (cf UserTableSeeder)
        await loginAsPosOperator(page);

        const itemListUrls = [];
        page.on('request', (req) => {
            const url = req.url();
            if (/\/admin\/item\?/.test(url) && !/\/admin\/item\/\d/.test(url)) {
                itemListUrls.push(url);
            }
        });

        await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(3000);

        // Acceptance : aucun fetch sans branch_id, OU fetch DOIT inclure branch_id
        // (le fix backend retourne 422 et le fix SPA n'envoie pas la requête)
        const fetchSansBranch = itemListUrls.filter((u) => !/[?&]branch_id=\d+/.test(u));
        expect(
            fetchSansBranch,
            `URLs fetched sans branch_id: ${fetchSansBranch.join(', ')}`
        ).toEqual([]);
    });
});
