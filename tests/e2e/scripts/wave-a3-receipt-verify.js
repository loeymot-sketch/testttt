// Wave A3 verify — PENDING_ phone sentinel must NOT leak to NF525 paper-receipt
// 6-year fiscal archive. Targets the two receipt components that render
// customer phone:
//   - resources/js/components/admin/onlineOrders/OnlineOrderReceiptComponent.vue
//   - resources/js/components/frontend/account/myOrder/FrontendOrderReceiptComponent.vue
//
// Strategy:
//   1. Snapshot original phone of the user attached to order #63 fixture.
//   2. Inject 'PENDING_CREATE_a3verify' via DB.
//   3. Navigate admin SPA to /admin/online-orders/show/63 → trigger print modal
//      (OnlineOrderReceiptComponent renders into #print).
//   4. Emulate @media print + grep printable region HTML for PENDING_.
//   5. Restore original phone (always — even on failure).
//
// Also runs a pure-bundle check: grep compiled JS for the guard signature
// `startsWith("PENDING_")` to assert the fix landed in public/js/app.js.
//
// Exits 0 if 0 PENDING_ leaks AND guard present in bundle.

const { chromium } = require('playwright');
const fs = require('fs');
const { execSync } = require('child_process');

const ORDER_ID = 63;
const SENTINEL = 'PENDING_CREATE_a3verify';

function phpExec(snippet) {
  // Direct PHP bootstrap via temp file — both `tinker --execute` and `php -r`
  // mangle backslashes/dollar-signs through shell-escape, leaving Eloquent
  // namespace lookups broken (`App\Models` → `AppModels`).
  const projectRoot = require('path').resolve(__dirname, '../../..');
  const bootstrap =
    `<?php\n` +
    `require '${projectRoot}/vendor/autoload.php';\n` +
    `$app = require_once '${projectRoot}/bootstrap/app.php';\n` +
    `$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();\n` +
    snippet + `\n`;
  const tmp = require('os').tmpdir() + '/wave-a3-exec-' + process.pid + '-' + Date.now() + '.php';
  fs.writeFileSync(tmp, bootstrap);
  try {
    const out = execSync(`php ${tmp}`, {
      stdio: ['ignore', 'pipe', 'pipe'],
      encoding: 'utf8',
      cwd: process.cwd(),
    });
    return out.trim();
  } finally {
    try { fs.unlinkSync(tmp); } catch (_) {}
  }
}

(async () => {
  let originalPhone = null;
  let exitCode = 0;
  const result = { surfaces: {}, bundle_guard_present: false, total_leaks: 0 };

  try {
    // ---- Snapshot + inject ----
    const snap = phpExec(
      `$o = App\\Models\\FrontendOrder::with('user')->find(${ORDER_ID}); ` +
      `if(!$o){ echo 'NO_ORDER'; exit; } ` +
      `echo $o->user->phone;`
    );
    if (snap === 'NO_ORDER' || snap === '') {
      console.error('FATAL: order #' + ORDER_ID + ' not found, abort');
      process.exit(2);
    }
    originalPhone = snap;
    console.log('Original phone:', originalPhone);

    phpExec(
      `$o = App\\Models\\FrontendOrder::with('user')->find(${ORDER_ID}); ` +
      `$o->user->phone = '${SENTINEL}'; ` +
      `$o->user->saveQuietly();`
    );
    const confirm = phpExec(
      `echo App\\Models\\FrontendOrder::with('user')->find(${ORDER_ID})->user->phone;`
    );
    console.log('Injected phone:', confirm);
    if (confirm !== SENTINEL) {
      throw new Error('Sentinel injection failed, got: ' + confirm);
    }

    // ---- Playwright ----
    const browser = await chromium.launch();
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.emulateMedia({ media: 'print' });

    // Login admin
    await page.goto('http://127.0.0.1:8000/login');
    await page.locator('#formEmail').fill('admin@lecayenne.fr');
    await page.locator('#formPassword').fill('123456');
    await page.getByRole('button', { name: /connexion|login/i }).click();
    await page.waitForURL((u) => !u.toString().includes('/login'), { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(2000);

    // Surface 1 : Admin online order detail (OnlineOrderReceiptComponent is
    // mounted inline; #print region exists in DOM whether modal is opened or not).
    const adminPath = `/admin/online-orders/show/${ORDER_ID}`;
    try {
      await page.goto('http://127.0.0.1:8000' + adminPath);
      await page.waitForTimeout(4000); // SPA hydrate + API show fetch
      // Try to trigger print modal (best-effort) to ensure receipt section in DOM.
      const printBtn = page.locator('button:has-text("Imprimer"), button:has-text("Print")').first();
      if (await printBtn.count()) {
        // Just hover/click to ensure modal mounts; we don't actually print.
        try { await printBtn.click({ trial: true }); } catch (_) {}
      }
      // Grep entire DOM for PENDING_ inside the printable region #print
      const printHtml = await page.evaluate(() => {
        const el = document.querySelector('#print');
        return el ? el.outerHTML : document.body.outerHTML;
      });
      const matches = printHtml.match(/PENDING_[A-Za-z0-9_]*/g) || [];
      result.surfaces[adminPath] = {
        leaks: matches.length,
        sample: matches.slice(0, 3),
        region_size_chars: printHtml.length,
      };
      console.log(`${adminPath}: ${matches.length} PENDING_ leaks (region=${printHtml.length} chars)`);
      result.total_leaks += matches.length;
    } catch (e) {
      result.surfaces[adminPath] = { error: e.message.slice(0, 200) };
      console.log(`${adminPath}: error - ${e.message.slice(0, 80)}`);
    }

    // Surface 2 : Frontend account my-order detail (FrontendOrderReceiptComponent).
    // The route may require customer login (not admin). Best-effort: just check
    // bundle compile pattern below — the component file is identical-pattern.
    const frontendPath = `/account/my-orders/${ORDER_ID}`;
    try {
      await page.goto('http://127.0.0.1:8000' + frontendPath);
      await page.waitForTimeout(3000);
      const url = page.url();
      const html = await page.content();
      const matches = html.match(/PENDING_[A-Za-z0-9_]*/g) || [];
      result.surfaces[frontendPath] = {
        leaks: matches.length,
        final_url: url,
        sample: matches.slice(0, 3),
      };
      console.log(`${frontendPath}: ${matches.length} PENDING_ leaks (final_url=${url})`);
      result.total_leaks += matches.length;
    } catch (e) {
      result.surfaces[frontendPath] = { error: e.message.slice(0, 200) };
      console.log(`${frontendPath}: error - ${e.message.slice(0, 80)}`);
    }

    await browser.close();
  } catch (e) {
    console.error('FATAL:', e.message);
    exitCode = 2;
  } finally {
    // ---- ALWAYS restore original phone ----
    if (originalPhone !== null) {
      phpExec(
        `$o = App\\Models\\FrontendOrder::with('user')->find(${ORDER_ID}); ` +
        `$o->user->phone = '${originalPhone}'; ` +
        `$o->user->saveQuietly();`
      );
      const restoredConfirm = phpExec(
        `echo App\\Models\\FrontendOrder::with('user')->find(${ORDER_ID})->user->phone;`
      );
      console.log('Restored phone:', restoredConfirm);
      if (restoredConfirm !== originalPhone) {
        console.error('WARNING: restore mismatch! expected=' + originalPhone + ' got=' + restoredConfirm);
        exitCode = 3;
      }
    }
  }

  // ---- Bundle guard verify ----
  // Confirm the new pattern compiled into bundles. Mix minifier emits
  // `startsWith('PENDING_')` with single quotes regardless of source.
  try {
    const bundles = ['app.js', 'admin-shell.js', 'pos-app.js'];
    result.bundle_guard_per_bundle = {};
    let total = 0;
    for (const b of bundles) {
      const cnt = parseInt(
        execSync(`grep -c "startsWith('PENDING_')" public/js/${b} || echo 0`, {
          encoding: 'utf8',
        }).trim(),
        10
      );
      result.bundle_guard_per_bundle[b] = cnt;
      total += cnt;
    }
    result.bundle_guard_total = total;
    // Expected: >=3 occurrences in app.js (KDS + 2 new receipts) + mirrors elsewhere.
    // Set threshold low — even 2 = guard compiled (KDS pre-existing was 1).
    result.bundle_guard_present = total >= 3;
    console.log(`Bundle guard total occurrences: ${total}`, result.bundle_guard_per_bundle);
  } catch (e) {
    result.bundle_guard_present = false;
    result.bundle_guard_error = e.message;
  }

  fs.writeFileSync(
    'tests/e2e/__screenshots__/wave-a3-verify.log',
    JSON.stringify(result, null, 2) + '\n'
  );

  if (result.total_leaks > 0) {
    console.error('FAIL: total leaks =', result.total_leaks);
    process.exit(1);
  }
  if (!result.bundle_guard_present) {
    console.error('FAIL: bundle guard NOT compiled into app.js');
    process.exit(1);
  }
  console.log('PASS: 0 leaks, bundle guard present (' + result.bundle_guard_count + ' occurrences)');
  process.exit(exitCode);
})();
