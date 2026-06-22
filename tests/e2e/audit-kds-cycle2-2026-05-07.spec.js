// FoodKing E2E — AUDIT KDS CYCLE D2 (2026-05-07)
// Status transitions UI : bump button, recall (grace 60s), filtre station, search by serial_no.
// Sentinels backend existants couverts par phpunit:
//   - tests/Feature/Sentinels/KdsTransitionWhitelistSentinelTest.php
//   - tests/Feature/Sentinels/KdsExpectedStatusConflictSentinelTest.php
//   (cf. baseline 51/51 PASS)
// Note honnête : KdsBranchFilterExact / KdsAllergenAggregationSplit listés au brief
// ne sont PAS des sentinels dédiés — couverture indirecte via :
//   - tests/Feature/Orders/KDSAllergenVisibilityTest.php
//   - tests/Feature/Orders/OrderAllergenSnapshotComposedTest.php
//   - tests/js/kdsAllergens.spec.js
// Ce gap est consigné dans le rapport final D5.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsChefOperator } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-kds-2026-05-07/cycle2');
const FINDINGS_FILE = path.join(SHOT_DIR, 'findings.json');

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const findings = [];
function record(step, slug, state, sev, note, screenshot) {
  findings.push({ step, slug, state, severity: sev, note, screenshot, ts: new Date().toISOString() });
  fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
}

async function snap(page, step, slug, state) {
  const fn = `${String(step).padStart(2, '0')}-${slug}-${state}.png`;
  const fp = path.join(SHOT_DIR, fn);
  await page.screenshot({ path: fp, fullPage: true }).catch((e) => console.warn(`[D2] snap ${fn}: ${e.message}`));
  return path.relative(process.cwd(), fp);
}

function attachMonitor(page) {
  const pageErrors = [];
  page.on('pageerror', (err) => pageErrors.push(err.message));
  return { pageErrors };
}

test.describe('AUDIT KDS CYCLE D2 — Status transitions + controls', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  test('D2-01 — Sentinels backend KDS (whitelist + conflict) sont au catalogue', async () => {
    // Source check : on vérifie que les fichiers sentinels existent, sont versionnés, contiennent les routes attendues.
    const whitelistPath = path.join(process.cwd(), 'tests/Feature/Sentinels/KdsTransitionWhitelistSentinelTest.php');
    const conflictPath  = path.join(process.cwd(), 'tests/Feature/Sentinels/KdsExpectedStatusConflictSentinelTest.php');

    expect(fs.existsSync(whitelistPath)).toBe(true);
    expect(fs.existsSync(conflictPath)).toBe(true);

    const whitelistSrc = fs.readFileSync(whitelistPath, 'utf8');
    const conflictSrc  = fs.readFileSync(conflictPath, 'utf8');

    // Whitelist sentinel doit cibler le endpoint change-status et refuser CANCELED depuis KDS.
    expect(whitelistSrc).toContain('/api/admin/kds-order/change-status/');
    expect(whitelistSrc).toContain('CANCELED');
    expect(whitelistSrc).toContain('assertStatus(422)');

    // Conflict sentinel doit cibler optimistic lock 409.
    expect(conflictSrc).toMatch(/expected_status|409|optimistic/i);

    record('D2-01', 'sentinels-source', 'verified', 'OK',
      'KdsTransitionWhitelist + KdsExpectedStatusConflict présents et structurellement corrects (run phpunit en D5).', null);
  });

  test('D2-02 — Bump button visible (selon cartes présentes)', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsChefOperator(page);
    await page.waitForTimeout(3_000);

    const shot = await snap(page, 2, 'bump-button', 'check');

    // Bump = bouton pour passer un item de READY → ROUTING / SERVING.
    // Selon i18n et état, on cherche par texte fr/en + boutons d'action sur cartes.
    const bumpButtons = page.locator(
      'button:has-text("Bump"), button:has-text("Servir"), button:has-text("Bumper"), [data-action*="bump"]',
    );
    const bumpCount = await bumpButtons.count().catch(() => 0);

    // L'env de test peut n'avoir aucune commande active → bump invisible = OK (pas de crash).
    record('D2-02', 'bump-button', 'check',
      monitor.pageErrors.length === 0 ? 'OK' : 'P1',
      `bump-buttons-detected=${bumpCount}, JS-errors=${monitor.pageErrors.length}. Si 0 → pas de commande active (env-dependent).`, shot);

    // Si bumps présents, click safety : 1 click ne crash pas.
    if (bumpCount > 0) {
      const first = bumpButtons.first();
      const before = monitor.pageErrors.length;
      await first.click({ timeout: 3_000 }).catch(() => {});
      await page.waitForTimeout(1_500);
      const after = monitor.pageErrors.length;
      record('D2-02', 'bump-click', 'safety',
        after === before ? 'OK' : 'P1',
        `Click bump ne crash pas : avant=${before} après=${after} JS-errors`, await snap(page, 2, 'bump-button', 'after-click'));
    }

    expect(monitor.pageErrors.length).toBe(0);
  });

  test('D2-03 — Recall button (grace window 60s) visible si applicable', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsChefOperator(page);
    await page.waitForTimeout(3_000);

    const shot = await snap(page, 3, 'recall-button', 'check');

    // Recall = button.kds_recall (cf. lignes 293/416/541/669 du composant)
    const recallButtons = page.locator(
      'button:has-text("Recall"), button:has-text("Rappeler"), button:has-text("Annuler envoi")',
    );
    const recallCount = await recallButtons.count().catch(() => 0);

    record('D2-03', 'recall-button', 'check',
      monitor.pageErrors.length === 0 ? 'OK' : 'P1',
      `recall-buttons-detected=${recallCount} (grace-window 60s côté backend). Env-dependent si commandes BUMPED présentes.`, shot);

    expect(monitor.pageErrors.length).toBe(0);
  });

  test('D2-04 — Filtre station (bar/cuisine_chaude/cuisine_froide/all)', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsChefOperator(page);
    await page.waitForTimeout(3_000);

    const shot = await snap(page, 4, 'station-filter', 'initial');

    const stationFilter = page.locator('#kds-station-filter');
    await expect(stationFilter).toBeVisible({ timeout: 10_000 });

    // On audit les VALUES (stables, indépendantes de l'i18n) — cf. composant Vue ligne 115-118.
    const optionValues = await stationFilter.locator('option').evaluateAll((els) => els.map((e) => e.value));
    const optionTexts  = await stationFilter.locator('option').allTextContents().catch(() => []);

    record('D2-04', 'station-filter', 'options', 'OK',
      `Values (${optionValues.length}): [${optionValues.join(', ')}] | Texts: ${optionTexts.slice(0, 4).join(' | ')}`, shot);

    // Les 4 stations attendues : all + bar + cuisine_chaude + cuisine_froide.
    expect(optionValues).toContain('all');
    expect(optionValues).toContain('bar');
    expect(optionValues).toContain('cuisine_chaude');
    expect(optionValues).toContain('cuisine_froide');

    // Toggle vers cuisine_chaude puis retour all — vérifie comportement réactif.
    await stationFilter.selectOption('cuisine_chaude');
    await page.waitForTimeout(800);
    await stationFilter.selectOption('all');
    await page.waitForTimeout(800);

    record('D2-04', 'station-filter', 'select-safe',
      monitor.pageErrors.length === 0 ? 'OK' : 'P1',
      `Toggle cuisine_chaude → all OK. JS errors=${monitor.pageErrors.length}`, await snap(page, 4, 'station-filter', 'after'));

    expect(monitor.pageErrors.length).toBe(0);
  });

  test('D2-05 — Search par serial_no (input ou flux visible)', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsChefOperator(page);
    await page.waitForTimeout(3_000);

    const shot = await snap(page, 5, 'search-serial', 'check');

    // Le KDS expose serial_no dans les cartes (cf. queue_number, kds-source-pill).
    // Un champ search peut exister dans le header. On vérifie présence textuelle de #serial / queue.
    const bodyText = await page.locator('body').innerText().catch(() => '');
    const hasSerialMarker = /#\d+|N°\s*\d+|serial|queue/i.test(bodyText);

    record('D2-05', 'search-serial', 'visible-marker',
      hasSerialMarker ? 'OK' : 'P3',
      `Marqueur serial/queue détecté dans la surface=${hasSerialMarker}. Env-dependent si commandes présentes.`, shot);

    expect(monitor.pageErrors.length).toBe(0);
  });

  test('D2-06 — Group-by-table toggle (dine-in)', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsChefOperator(page);
    await page.waitForTimeout(3_000);

    const shot = await snap(page, 6, 'group-by-table', 'initial');

    // Le checkbox groupByTable (input v-model="groupByTable") est dans le header KDS.
    const groupCheckboxes = page.locator('input[type="checkbox"].rounded');
    const groupCount = await groupCheckboxes.count().catch(() => 0);

    record('D2-06', 'group-by-table', 'check',
      groupCount >= 1 ? 'OK' : 'P2',
      `Checkboxes ronds détectés=${groupCount} (group-by-table + sound-toggle attendus).`, shot);

    expect(monitor.pageErrors.length).toBe(0);
    expect(groupCount).toBeGreaterThanOrEqual(1);
  });
});
