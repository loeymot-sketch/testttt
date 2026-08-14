// @ts-check
/**
 * [test-e2e verif-globale-2026-08-14 / Wave D2] RawMaterialAdjustComponent — fresh page
 * (commit `60faeba6e`), never audited before. Per this project's standard (see
 * `memory/goal_admin_nav_breadth_convergence_2026-08-13.md`), "page loads" is NOT proof —
 * this spec proves functional CRUD end-to-end: list → open → both client-side guards
 * (reason, target) → server-side guard independent of the UI → a REAL adjustment with
 * read-after-write proof across BOTH the stock card and the movement history (state 6,
 * the one that actually matters) → permission gate → branch isolation.
 *
 * Route + selectors verified by reading the source FIRST (not paraphrased from the plan):
 *   - Vue: resources/js/components/admin/stock/RawMaterialAdjustComponent.vue
 *   - SPA route: /admin/stock/raw-material-adjust (resources/js/router/modules/stockRoutes.js)
 *   - Sidebar: resources/js/components/layouts/backend/BackendMenuComponent.vue
 *     V1_PRIMARY_SIDEBAR_MENUS entry { url: 'stock/raw-material-adjust', language:
 *     'raw_material_adjust' } → menu.raw_material_adjust = "Ajustement stock" (fr.json).
 *   - Backend: app/Http/Controllers/Admin/RawMaterialAdjustController.php
 *       GET  /api/admin/raw-materials/{id}/movements  — gate permission:items_show
 *       POST /api/admin/raw-materials/{id}/adjust     — gate permission:items_create
 *     Both call authorize(Writable)BranchScope FIRST (branch mismatch → 403), THEN
 *     (for adjust) $request->validate(['target_on_hand'=>'required|numeric|min:0|...',
 *     'reason'=>'required|string|min:3|max:64']) → violation is 422, NOT 403 (confirmed
 *     empirically with curl against the local server before writing this spec — the
 *     task brief said "backend-403" for the negative-target bypass but the actual
 *     contract, and the plan file's own §D2 state 5 text, both say 422).
 *
 * Native HTML5 constraints (`required`, `minlength=3`, `min=0` on the form inputs) block
 * the browser's own submit event before Vue's `@submit.prevent` handler ever runs, which
 * would make it impossible to reach the app's OWN duplicate JS-level guard (the thing
 * state 4/5 are supposed to prove). We flip `form.noValidate = true` via `page.evaluate`
 * right before those two states — an explicit, commented bypass of the BROWSER's guard so
 * we can exercise and prove the APPLICATION's guard, not a bypass of the app itself.
 *
 * Fixtures: one raw material (branch 1) + one items_show-only user (branch 1, no
 * items_create) + one items_create user on a second branch — all seeded via `php artisan
 * tinker` in beforeAll (same idiom as `_d4-items-stock-consistency-2026-05-21.spec.js`),
 * force-deleted and re-created idempotently so repeat runs don't collide with the
 * `raw_materials.raw_materials_branch_id_name_unique` index (soft-deletes don't exempt it).
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { execSync } = require('child_process');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { loginAsAdmin } = require('./helpers/login');
const { loginAdmin, createApiContext } = require('./helpers/admin-auth');

const SCREENSHOT_DIR = path.resolve(__dirname, '../captures/verif-globale-raw-material-adjust-2026-08-14');
const REPO_ROOT = path.resolve(__dirname, '../..');
const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || 'TestVisuel2026!';
const FIXTURE_TOKEN = 'E2E-RMA-2026-08-14';
const FIXTURE_MATERIAL_NAME = `ZZE2E RMA ${FIXTURE_TOKEN}`;
const INITIAL_ON_HAND = 50;

function tinker(php) {
  return execSync('php artisan tinker --no-interaction', {
    input: `${php}\n`,
    encoding: 'utf-8',
    cwd: REPO_ROOT,
  });
}

/**
 * Idempotent seed: force-deletes any prior run's fixtures (soft-delete does NOT free the
 * unique (branch_id,name) index — verified empirically, a plain ->delete() 2nd run threw
 * a duplicate-key error), then creates a fresh material + two scoped users with minted
 * Sanctum tokens (full ['*'] token ability — the thing under test is the Spatie
 * permission gate + branch scope, not the token ability system).
 *
 * Readonly user is placed on branch 1 (same as the material) on purpose: with branch_id=0
 * it would hit `authorizeBranchScope`'s "not Admin, branch mismatch → 403" path BEFORE the
 * permission gate is ever exercised (confirmed empirically — first seed attempt used
 * branch_id=0 and every call 403'd for the wrong reason).
 */
function seedFixtures() {
  // [MEMORY 2026-08-13 pitfall] In a JS template literal, "\\$m" prints as the literal
  // two-character string "\$m" which PHP rejects (T_NS_SEPARATOR) — PHP variables are
  // "$m", not "\$m". Only the namespace separators need the doubled backslash ("\\App"
  // → literal "\App"); plain "$m" needs NO backslash since bare "$identifier" (no curly
  // braces) is not template-literal interpolation syntax in JS.
  const php = `
\\App\\Models\\RawMaterial::withTrashed()->where('name', 'like', 'ZZE2E RMA%')->get()->each(function ($m) {
    \\App\\Models\\RawMaterialStock::where('raw_material_id', $m->id)->delete();
    \\App\\Models\\RawMaterialMovement::where('raw_material_id', $m->id)->delete();
    $m->forceDelete();
});
\\App\\Models\\User::where('name', 'like', 'E2E RMA%')->get()->each(fn ($u) => $u->delete());

$m = \\App\\Models\\RawMaterial::create(['branch_id' => 1, 'name' => '${FIXTURE_MATERIAL_NAME}', 'unit' => 'g', 'is_active' => true]);
\\App\\Models\\RawMaterialStock::create(['raw_material_id' => $m->id, 'branch_id' => 1, 'on_hand' => ${INITIAL_ON_HAND}]);

// [heal] LoginController derives the SPA's permission/menu list from
// PermissionResource::collection(permissionService->permission($user->roles[0])) — i.e.
// from the user's ROLE's permissions, NOT from Illuminate\Auth\Access\Gate/hasPermissionTo
// (which DOES see direct user-level permissions). A Chef-role user with items_show granted
// directly authenticates fine and the BACKEND gate honours it (confirmed via curl), but the
// frontend router guard never sees it in the login payload and silently redirects away from
// the page. Fix: a dedicated role carrying items_show as a ROLE permission, so the SPA's own
// permission list includes it. firstOrCreate keeps repeat runs idempotent.
// [heal, 2nd layer] The sidebar/router gate for 'stock/raw-material-adjust' resolves to
// the GENERIC 'items' permission key (permissionUrlForSidebarPath → MENU_URL_TO_PERMISSION_URL),
// not 'items_show' specifically — resolvePermissionEntry matches by url FIRST, and permission
// id 2 ("Items", url "items") is a SEPARATE row from id 6 ("Items Show", url "items/show").
// Confirmed empirically: a role with ONLY items_show granted logs in fine but the login
// payload's "items" entry still has access:false, so the SPA hides/blocks the page even
// though the BACKEND (which gates on items_show specifically) would happily serve it. A
// real "catalogue read-only" role needs both. items_create stays OFF — that is the actual
// thing under test.
$readonlyRole = \\Spatie\\Permission\\Models\\Role::firstOrCreate(
    ['name' => 'E2E RMA Readonly Role', 'guard_name' => 'sanctum']
);
$readonlyRole->givePermissionTo(['items', 'items_show']);

$readonly = \\App\\Models\\User::factory()->create(['branch_id' => 1, 'name' => 'E2E RMA Readonly']);
$readonly->assignRole($readonlyRole);
$readonlyToken = $readonly->createToken('e2e-rma-readonly', ['*'])->plainTextToken;

$branch2Model = \\App\\Models\\Branch::query()->whereKey(2)->first();
if (! $branch2Model) { $branch2Model = \\App\\Models\\Branch::factory()->create(['id' => 2]); }
$branch2 = \\App\\Models\\User::factory()->create(['branch_id' => 2, 'name' => 'E2E RMA Branch2']);
$branch2->assignRole('Branch Manager');
$branch2->givePermissionTo(['items_create', 'items_show']);
$branch2Token = $branch2->createToken('e2e-rma-branch2', ['*'])->plainTextToken;

echo "RESULT_JSON:".json_encode([
  'material_id' => $m->id,
  'readonly_email' => $readonly->email,
  'readonly_token' => $readonlyToken,
  'branch2_email' => $branch2->email,
  'branch2_token' => $branch2Token,
]);
`;
  const out = tinker(php);
  const line = out.split('\n').find((l) => l.startsWith('RESULT_JSON:'));
  if (!line) {
    throw new Error(`Seed script did not print RESULT_JSON. Full output:\n${out}`);
  }
  return JSON.parse(line.slice('RESULT_JSON:'.length));
}

function cleanupFixtures() {
  tinker(`
\\App\\Models\\RawMaterial::withTrashed()->where('name', 'like', 'ZZE2E RMA%')->get()->each(function ($m) {
    \\App\\Models\\RawMaterialStock::where('raw_material_id', $m->id)->delete();
    \\App\\Models\\RawMaterialMovement::where('raw_material_id', $m->id)->delete();
    $m->forceDelete();
});
\\App\\Models\\User::where('name', 'like', 'E2E RMA%')->get()->each(fn ($u) => $u->delete());
\\Spatie\\Permission\\Models\\Role::where('name', 'E2E RMA Readonly Role')->delete();
echo "cleaned";
`);
}

test.describe('verif-globale Wave D2 — RawMaterialAdjustComponent (fresh page CRUD proof)', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(180_000);

  /** @type {ReturnType<typeof seedFixtures>} */
  let fixtures;
  let page;
  let recorder;
  /** @type {import('@playwright/test').APIRequestContext} */
  let adminApiContext;

  test.beforeAll(async ({ browser }) => {
    fixtures = seedFixtures();
    const ctx = await browser.newContext();
    page = await ctx.newPage();
    recorder = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    // Admin API context (bearer + X-API-KEY), independent of the browser session — used
    // for the "backend guard independent of the UI" bypass calls (states 5, 6 cross-check).
    const admin = await loginAdmin({ email: ADMIN_EMAIL, password: ADMIN_PASS });
    adminApiContext = admin.apiContext;
  });

  test.afterAll(async () => {
    recorder?.dispose();
    await page?.context()?.close();
    await adminApiContext?.dispose();
    cleanupFixtures();
  });

  test('01 — sidebar entry present, navigates to /admin/stock/raw-material-adjust', async () => {
    await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASS);
    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });

    // [heal] The sidebar renders "Ajustement Stock" visually (CSS text-transform:
    // capitalize on .db-sidebar-nav-menu), so an exact-match locator against the raw
    // fr.json string "Ajustement stock" (lowercase s) failed to resolve — use a
    // case-insensitive match against the accessible name instead of guessing the exact
    // casing/whitespace the DOM text node ends up with.
    const link = page.getByRole('link', { name: /Ajustement Stock/i });
    await expect(link).toBeVisible({ timeout: 15_000 });
    await link.click();

    await expect(page).toHaveURL(/\/admin\/stock\/raw-material-adjust/, { timeout: 20_000 });
    await recorder.snap('01-sidebar-entry-present');
  });

  test('02 — list loads materials, search filter narrows the list', async () => {
    await expect(page.getByTestId('raw-material-adjust')).toBeVisible({ timeout: 15_000 });
    // rma-loading must NOT still be showing once the list has settled.
    await expect(page.getByTestId('rma-loading')).toHaveCount(0, { timeout: 20_000 });

    const materialCard = page.getByTestId(`rma-material-${fixtures.material_id}`);
    // Our seeded fixture must be present somewhere in the unfiltered list first.
    await expect(materialCard).toBeVisible({ timeout: 15_000 });

    await page.getByTestId('rma-search').fill('ZZE2E RMA');
    await expect(materialCard).toBeVisible();
    // Search must actually narrow — a real catalogue has other active materials that
    // should now be hidden. If it doesn't narrow, filteredMaterials is broken.
    const visibleCount = await page.locator('.rma-list > .rma-card').count();
    expect(visibleCount).toBeLessThan(50); // sanity: not silently ignoring the query
    expect(visibleCount).toBeGreaterThanOrEqual(1);

    await recorder.snap('02-list-loads-materials');
  });

  test('03 — open adjust panel, form pre-filled with current on_hand', async () => {
    await page.getByTestId(`rma-open-${fixtures.material_id}`).click();

    const panel = page.getByTestId(`rma-panel-${fixtures.material_id}`);
    await expect(panel).toBeVisible({ timeout: 10_000 });

    const targetInput = page.getByTestId(`rma-target-${fixtures.material_id}`);
    await expect(targetInput).toHaveValue(String(INITIAL_ON_HAND));

    await recorder.snap('03-open-adjust-panel');
  });

  test('04 — empty reason: client-side guard blocks submit, zero network calls', async () => {
    const id = fixtures.material_id;

    // Bypass the browser's OWN required/minlength constraint validation so the click
    // actually dispatches a submit event and we can prove the APP's duplicate JS guard
    // (see file header comment) — without this the native tooltip intercepts first and
    // submitAdjust() never runs, meaning rma-form-error would never appear either way.
    await page.evaluate((materialId) => {
      const form = document.querySelector(`[data-testid="rma-panel-${materialId}"] form.rma-form`);
      if (form) form.noValidate = true;
    }, id);

    await page.getByTestId(`rma-reason-${id}`).fill('');

    let posted = false;
    const onRequest = (req) => {
      if (req.method() === 'POST' && /\/raw-materials\/\d+\/adjust/.test(req.url())) posted = true;
    };
    page.on('request', onRequest);
    await page.getByTestId(`rma-submit-${id}`).click();
    await page.waitForTimeout(500); // let a would-be request fire before we assert its absence
    page.off('request', onRequest);

    await expect(page.getByTestId('rma-form-error')).toBeVisible();
    expect(posted, 'no POST /adjust should fire when the client-side guard blocks the form').toBe(false);

    await recorder.snap('04-submit-empty-reason-blocked');
  });

  test('05 — negative target: client-side guard blocks, THEN direct backend bypass proves 422 independently', async () => {
    const id = fixtures.material_id;

    // noValidate persists on the same DOM form from state 4 (Vue doesn't re-render the
    // panel between states) — set it again anyway for this test's independence.
    await page.evaluate((materialId) => {
      const form = document.querySelector(`[data-testid="rma-panel-${materialId}"] form.rma-form`);
      if (form) form.noValidate = true;
    }, id);

    await page.getByTestId(`rma-reason-${id}`).fill('comptage e2e state5');
    await page.getByTestId(`rma-target-${id}`).fill('-5');

    let posted = false;
    const onRequest = (req) => {
      if (req.method() === 'POST' && /\/raw-materials\/\d+\/adjust/.test(req.url())) posted = true;
    };
    page.on('request', onRequest);
    await page.getByTestId(`rma-submit-${id}`).click();
    await page.waitForTimeout(500);
    page.off('request', onRequest);

    await expect(page.getByTestId('rma-form-error')).toBeVisible();
    expect(posted, 'no POST /adjust should fire when the client-side guard blocks the form').toBe(false);
    await recorder.snap('05-submit-negative-target-blocked');

    // THE REAL PROOF: bypass the UI entirely via a direct authenticated API call — the
    // backend must reject a negative target on its own, independent of any client guard.
    // Empirically confirmed (curl, pre-write): this is HTTP 422 (Laravel FormRequest
    // validation `min:0`), NOT 403 — the branch-scope check (which IS a 403) runs first
    // in the controller but passes for the admin, so validation is what fires here.
    const resp = await adminApiContext.post(`/api/admin/raw-materials/${id}/adjust`, {
      data: { target_on_hand: -5, reason: 'comptage e2e state5 bypass' },
    });
    expect(resp.status()).toBe(422);
    const body = await resp.json();
    expect(body?.errors?.target_on_hand).toBeTruthy();
  });

  test('06 — valid adjustment: read-after-write proof on BOTH the stock card AND the movement history', async () => {
    const id = fixtures.material_id;
    const NEW_TARGET = 37.25;
    const REASON = 'audit e2e state6';
    const NOTE = 'preuve read-after-write';

    await page.getByTestId(`rma-target-${id}`).fill(String(NEW_TARGET));
    await page.getByTestId(`rma-reason-${id}`).fill(REASON);
    await page.getByTestId(`rma-note-${id}`).fill(NOTE);

    const adjustResponsePromise = page.waitForResponse(
      (r) => r.request().method() === 'POST' && /\/raw-materials\/\d+\/adjust/.test(r.url()),
    );
    // The component reloads the whole list + history after a successful submit
    // (this.load() then this.loadHistory) — wait for both follow-up GETs too.
    const overviewResponsePromise = page.waitForResponse(
      (r) => r.request().method() === 'GET' && /\/stock\/unified-overview/.test(r.url()),
    );
    const historyResponsePromise = page.waitForResponse(
      (r) => r.request().method() === 'GET' && /\/raw-materials\/\d+\/movements/.test(r.url()),
    );

    await page.getByTestId(`rma-submit-${id}`).click();

    const adjustResponse = await adjustResponsePromise;
    expect(adjustResponse.status()).toBe(200);
    const adjustBody = await adjustResponse.json();
    expect(adjustBody.ok).toBe(true);
    expect(Number(adjustBody.previous_on_hand)).toBeCloseTo(INITIAL_ON_HAND, 3);
    expect(Number(adjustBody.on_hand)).toBeCloseTo(NEW_TARGET, 3);
    expect(Number(adjustBody.delta)).toBeCloseTo(NEW_TARGET - INITIAL_ON_HAND, 3);

    await overviewResponsePromise;
    await historyResponsePromise;

    // ── Proof #1: the stock card (DOM read-after-write) ──────────────────────────────
    await expect(page.getByTestId(`rma-onhand-${id}`)).toContainText('37.25');

    // ── Proof #2: the movement history list (DOM read-after-write) ───────────────────
    const historyList = page.getByTestId(`rma-history-list-${id}`);
    await expect(historyList).toBeVisible();
    const firstRow = historyList.locator('.rma-history-row').first();
    await expect(firstRow).toContainText(REASON);
    await expect(firstRow).toContainText(NOTE);
    await expect(firstRow).toContainText('-12.75'); // delta = 37.25 - 50

    await recorder.snap('06-submit-valid-adjustment-writes-stock-and-movement');

    // ── Proof #3: independent API-level confirmation (bypasses the DOM entirely) ─────
    // This is the assertion that actually matters per the audit plan — it proves the
    // WRITE landed correctly in the data layer, not just that the DOM re-rendered
    // something plausible-looking.
    const historyCheck = await adminApiContext.get(`/api/admin/raw-materials/${id}/movements`);
    expect(historyCheck.status()).toBe(200);
    const historyBody = await historyCheck.json();
    const latest = historyBody.movements[0];
    expect(latest.reason).toBe(REASON);
    expect(latest.note).toBe(NOTE);
    expect(Number(latest.delta)).toBeCloseTo(NEW_TARGET - INITIAL_ON_HAND, 3);
    expect(Number(latest.previous_on_hand)).toBeCloseTo(INITIAL_ON_HAND, 3);
    expect(Number(latest.target_on_hand)).toBeCloseTo(NEW_TARGET, 3);
  });

  test('07 — permission gate: items_show-without-items_create sees read-only, adjust API 403s directly', async () => {
    const id = fixtures.material_id;
    const ctx = await page.context().browser().newContext();
    const readonlyPage = await ctx.newPage();
    const readonlyRecorder = attachMegaAuditRecorder(readonlyPage, SCREENSHOT_DIR);

    try {
      await loginAsAdmin(readonlyPage, fixtures.readonly_email, 'password'); // factory default hash
      await readonlyPage.goto('/admin/stock/raw-material-adjust', { waitUntil: 'domcontentloaded' });

      await expect(readonlyPage.getByTestId('rma-read-only')).toBeVisible({ timeout: 15_000 });

      await readonlyPage.getByTestId(`rma-open-${id}`).click();
      const panel = readonlyPage.getByTestId(`rma-panel-${id}`);
      await expect(panel).toBeVisible();

      // The adjust form must be entirely absent (v-if="canAdjust"), not just disabled.
      await expect(readonlyPage.getByTestId(`rma-submit-${id}`)).toHaveCount(0);
      await expect(readonlyPage.getByTestId('rma-readonly-panel')).toBeVisible();

      // History (items_show gate) must still load — read access is granted.
      const historyEmpty = readonlyPage.getByTestId(`rma-history-empty-${id}`);
      const historyList = readonlyPage.getByTestId(`rma-history-list-${id}`);
      await expect(historyEmpty.or(historyList)).toBeVisible({ timeout: 10_000 });

      await readonlyRecorder.snap('07-permission-gate-history-vs-adjust');
    } finally {
      readonlyRecorder.dispose();
      await ctx.close();
    }

    // Direct API bypass, independent of what the UI hides: the controller-level
    // `permission:items_create` middleware gate must reject this user's token on its
    // own, mirroring RawMaterialAdjustEndpointTest::test_adjust_requires_items_create_permission.
    const readonlyApi = await createApiContext({ bearerToken: fixtures.readonly_token });
    try {
      const resp = await readonlyApi.post(`/api/admin/raw-materials/${id}/adjust`, {
        data: { target_on_hand: 10, reason: 'should be rejected' },
      });
      expect(resp.status()).toBe(403);
    } finally {
      await readonlyApi.dispose();
    }
  });

  test('08 — branch isolation: a branch-2 user cannot adjust or read a branch-1 material', async () => {
    const id = fixtures.material_id;

    // V1 is mono-branch (Le Cayenne, branch_id=1) — there is no legitimate 2nd-branch
    // admin UI session to exercise here, and `RawMaterialAdjustEndpointTest::
    // test_a_branch_scoped_user_cannot_adjust_another_branchs_material` already proves
    // this exhaustively at the PHPUnit level. Since seeding a 2nd branch + scoped user
    // is cheap (already done in beforeAll for fixtures.branch2_token), we still exercise
    // the REAL guard here via a direct API call rather than only documenting it — this
    // is the `authorizeWritableBranchScope` / `authorizeBranchScope` code path, live.
    const branch2Api = await createApiContext({ bearerToken: fixtures.branch2_token });
    try {
      const adjustResp = await branch2Api.post(`/api/admin/raw-materials/${id}/adjust`, {
        data: { target_on_hand: 1, reason: 'cross-branch attempt' },
      });
      expect(adjustResp.status()).toBe(403);

      const historyResp = await branch2Api.get(`/api/admin/raw-materials/${id}/movements`);
      expect(historyResp.status()).toBe(403);
    } finally {
      await branch2Api.dispose();
    }

    // Confirm zero write actually landed (belt-and-suspenders on top of the 403 itself).
    const finalCheck = await adminApiContext.get(`/api/admin/raw-materials/${id}/movements`);
    const finalBody = await finalCheck.json();
    expect(finalBody.movements[0].reason).not.toBe('cross-branch attempt');
  });
});
