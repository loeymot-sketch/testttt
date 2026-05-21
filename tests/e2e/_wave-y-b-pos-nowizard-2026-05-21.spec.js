/**
 * Wave Y-B — GStack capture agent — POS V4 NON-WIZARD (cashier persona)
 *
 * Mission: Capture + audit + fix-authorized (<=30 LOC, no frozen-zone) of the
 * cashier journey in POS V4 EXCLUDING the wizard "Personnaliser" popup
 * (Wave D scope).
 *
 * Owner-flagged P0: category header image sync — when cashier switches
 * categories, does the category visual (pill thumb + item grid) actually
 * refresh, or does it stick on the previous category?
 *
 * Frozen-zone STRICT (touch ZERO of):
 *   - resources/js/components/admin/pos/PaymentComponent.vue
 *   - resources/js/components/admin/pos/v5/PosV5TrancheRow.vue
 *   - public/js/pos-wizard.js / public/css/pos-wizard.css
 *   - resources/views/admin-pos-v4.blade.php
 *   - app/Services/Fiscal/*, PricingService, OrderStateMachine
 */

const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const SHOT_DIR = 'reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/captures/wave-B';
const FINDINGS_PATH =
  'reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/round-1/wave-B-gstack-findings.json';

test.use({ viewport: { width: 1440, height: 900 } });

test.describe.configure({ mode: 'serial' });

test.describe('Wave Y-B POS V4 NON-WIZARD E2E (cashier)', () => {
  test.setTimeout(540_000);

  test('POS V4 cashier journey — non-wizard surfaces capture suite', async ({ page }) => {
    fs.mkdirSync(SHOT_DIR, { recursive: true });
    fs.mkdirSync(path.dirname(FINDINGS_PATH), { recursive: true });

    const findings = {
      wave: 'B',
      persona: 'cashier',
      route: '/admin/pos-v4',
      timestamp: new Date().toISOString(),
      surfaces: [],
      steps: [],
      consoleErrors: [],
      networkErrors: [],
      pageErrors: [],
      categoryImageSync: [],
      issues: [],
      fixesApplied: [],
      verdict: 'PENDING',
    };

    // ------ Listeners BEFORE any goto ------
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        findings.consoleErrors.push({
          url: page.url(),
          text: String(msg.text() || '').slice(0, 500),
        });
      }
    });
    page.on('pageerror', (err) =>
      findings.pageErrors.push({ url: page.url(), text: String(err.message).slice(0, 500) }),
    );
    page.on('response', (resp) => {
      const s = resp.status();
      const u = resp.url();
      if (s >= 400 && !u.includes('favicon') && !u.includes('hot-update')) {
        findings.networkErrors.push({ status: s, url: u.slice(0, 300) });
      }
    });

    let stepNo = 0;
    const shot = async (name, opts = { fullPage: true }) => {
      stepNo += 1;
      const padded = String(stepNo).padStart(2, '0');
      const filePath = `${SHOT_DIR}/B${padded}-${name}.png`;
      try {
        await page.screenshot({ path: filePath, fullPage: opts.fullPage });
        findings.steps.push({ step: stepNo, name, file: filePath, ok: true });
      } catch (e) {
        findings.steps.push({
          step: stepNo,
          name,
          ok: false,
          error: String(e).slice(0, 200),
        });
      }
      return filePath;
    };

    const safe = async (label, fn) => {
      try {
        await fn();
      } catch (e) {
        findings.issues.push({
          severity: 'P2',
          label,
          error: String(e).slice(0, 200),
        });
      }
    };

    // ===== Step 1: /admin/pos-v4 unauth (login modal) =====
    await safe('goto-pos-v4-unauth', async () => {
      await page.goto('/admin/pos-v4', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2000);
    });
    findings.surfaces.push({ id: 1, url: page.url(), label: 'pos-v4 pre-auth' });
    await shot('login-modal-bon-retour');

    // ===== Auth via task's specified pattern =====
    await safe('auth-fill-and-submit', async () => {
      const inputs = await page.locator('input').all();
      if (inputs.length >= 2) {
        await inputs[0].fill('admin@lecayenne.fr');
        await inputs[1].fill('123456');
        const btn = page.getByRole('button', { name: /connexion/i });
        if (await btn.isVisible({ timeout: 3000 }).catch(() => false)) {
          await btn.click();
        } else {
          // Fallback: any submit button
          await page.locator('button[type="submit"]').first().click().catch(() => {});
        }
        await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {});
        await page.waitForTimeout(1500);
      } else {
        findings.issues.push({
          severity: 'P1',
          label: 'login-inputs-missing',
          detail: `Only ${inputs.length} input found on /admin/pos-v4`,
        });
      }
    });

    // ===== Step 2: POS V4 dashboard after auth =====
    await safe('goto-pos-v4-authed', async () => {
      if (!page.url().includes('/admin/pos')) {
        await page.goto('/admin/pos-v4', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2500);
      }
      await page.waitForTimeout(2500);
    });
    findings.surfaces.push({ id: 2, url: page.url(), label: 'pos-v4 dashboard authed' });
    await shot('dashboard-authed');

    // ===== Step 3: Cash drawer dialog (may auto-open) =====
    await safe('cash-drawer-detect', async () => {
      const drawerHeading = page.locator('text=/aucune caisse ouverte|ouvrir la caisse/i').first();
      const drawerVisible = await drawerHeading.isVisible({ timeout: 3000 }).catch(() => false);
      findings.surfaces.push({
        id: 3,
        url: page.url(),
        label: 'cash-drawer-state',
        drawerAutoOpened: drawerVisible,
      });
      await shot('cash-drawer-state');

      if (drawerVisible) {
        const openBtn = page.getByRole('button', { name: /ouvrir la caisse/i }).first();
        if (await openBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
          await openBtn.click().catch(() => {});
          await page.waitForTimeout(2000);
          await shot('cash-drawer-after-open');
        }
      }
    });

    // ===== Step 4: Enumerate categories from DOM and capture each =====
    await safe('category-strip-enumeration', async () => {
      const catSelector = '.pos-v4-category-pill, .pos-v5-category';
      const pills = page.locator(catSelector);
      const count = await pills.count();
      findings.surfaces.push({
        id: 4,
        url: page.url(),
        label: 'category-strip',
        pillCount: count,
      });

      // Capture category names + thumb srcs BEFORE any click (baseline)
      const baseline = await page.evaluate((sel) => {
        const out = [];
        document.querySelectorAll(sel).forEach((el, idx) => {
          const lbl = el.querySelector('.pos-v5-category__label')?.textContent?.trim() || '';
          const img = el.querySelector('img');
          out.push({
            idx,
            label: lbl,
            thumbSrc: img?.getAttribute('src') || null,
            isActive: el.classList.contains('is-active'),
            ariaSelected: el.getAttribute('aria-selected'),
          });
        });
        return out;
      }, catSelector);
      findings.categoryImageSync.push({ phase: 'baseline', pills: baseline });

      // Loop through every category pill (skip toggle "Toutes/Réduire")
      // Cap at 15 to bound test time.
      const cap = Math.min(count, 15);
      for (let i = 0; i < cap; i++) {
        const pill = pills.nth(i);
        let labelTxt = '';
        try {
          labelTxt = (await pill.locator('.pos-v5-category__label').textContent({ timeout: 1500 }))
            ?.trim() || `cat-${i}`;
        } catch (_e) {
          labelTxt = `cat-${i}`;
        }
        // Skip the "Toutes"/"Réduire" toggle which has class pos-v5-category--toggle
        const isToggle = await pill
          .evaluate((el) => el.classList.contains('pos-v5-category--toggle'))
          .catch(() => false);
        if (isToggle) continue;

        await pill.click({ timeout: 3000 }).catch(() => {});
        await page.waitForTimeout(550);

        // Capture DOM-level evidence for the P0 image-sync check
        const dom = await page.evaluate(
          ({ sel, idx }) => {
            const pills = document.querySelectorAll(sel);
            const cur = pills[idx];
            // FIX: querySelector(`${sel}.is-active`) was buggy — the comma in sel
            // made it match ANY first pill. Use Array filter on full list.
            const all = Array.from(pills);
            const activeList = all.filter((el) => el.classList.contains('is-active'));
            const firstItemImg = document.querySelector('.pos-menu-products-region img');
            return {
              clickedIdx: idx,
              clickedLabel:
                cur?.querySelector('.pos-v5-category__label')?.textContent?.trim() || null,
              activeCount: activeList.length,
              activeLabels: activeList.map(
                (el) => el.querySelector('.pos-v5-category__label')?.textContent?.trim() || null,
              ),
              activeMatchesClicked:
                activeList.length === 1 && activeList[0] === cur,
              firstItemImgSrc: firstItemImg?.getAttribute('src') || null,
              itemCount: document.querySelectorAll('.pos-menu-products-region img').length,
            };
          },
          { sel: catSelector, idx: i },
        );
        findings.categoryImageSync.push({ phase: 'click', ...dom });
        const slug = (labelTxt || `cat-${i}`)
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '')
          .slice(0, 30);
        await shot(`category-${String(i).padStart(2, '0')}-${slug}`);
      }
    });

    // ===== Step 5: Add item via "+" button (NOT via wizard) =====
    await safe('quick-add-via-plus-button', async () => {
      // Scroll back to "Toutes" / first category
      const allBtn = page
        .locator('.pos-v4-category-pill, .pos-v5-category')
        .first();
      await allBtn.click({ timeout: 2000 }).catch(() => {});
      await page.waitForTimeout(800);

      // Try to locate "+" / add-to-cart buttons on item cards.
      // Selector heuristics: class includes "add" or aria-label /ajout/i.
      const addBtns = page.locator(
        'button[aria-label*="jouter" i], button[title*="jouter" i], .pos-item-add, .pos-v5-item__add, button.item-add-btn',
      );
      const addCount = await addBtns.count();
      findings.surfaces.push({
        id: 5,
        url: page.url(),
        label: 'quick-add-buttons',
        addBtnCount: addCount,
      });

      // Pick first 3 add buttons that DON'T trigger any modal/popup.
      // Both the full pos-wizard AND the inline "customize-light" modal
      // (with "Ajouter au panier" button on a single item) count as
      // wizard-trigger → abort. We want true quick-add only.
      let added = 0;
      for (let i = 0; i < Math.min(addCount, 12) && added < 3; i++) {
        const btn = addBtns.nth(i);
        if (!(await btn.isVisible({ timeout: 800 }).catch(() => false))) continue;
        await btn.click({ timeout: 1500 }).catch(() => {});
        await page.waitForTimeout(700);

        // Detect any modal popup (full wizard OR inline customize-light).
        const modalOpen = await page
          .locator(
            '.pos-wizard-modal, .pos-wizard, .modal-wizard, [data-testid="pos-wizard-root"], .modal.show, [role="dialog"]',
          )
          .first()
          .isVisible({ timeout: 400 })
          .catch(() => false);
        if (modalOpen) {
          // Close it via Escape AND click annuler if visible — ensure dismissed.
          await page.keyboard.press('Escape').catch(() => {});
          await page.waitForTimeout(300);
          const annuler = page.getByRole('button', { name: /annuler/i }).first();
          if (await annuler.isVisible({ timeout: 400 }).catch(() => false)) {
            await annuler.click().catch(() => {});
            await page.waitForTimeout(300);
          }
          continue;
        }
        added += 1;
      }
      findings.surfaces.push({
        id: '5b',
        label: 'items-added-non-wizard',
        added,
      });
      // Hard close any lingering modal before screenshot
      await page.keyboard.press('Escape').catch(() => {});
      await page.waitForTimeout(400);
      await shot('quick-add-after-clicks');
    });

    // ===== Step 6: Cart panel (multi-item, total visible) =====
    await safe('cart-panel-capture', async () => {
      // Belt-and-braces dismiss any lingering modal before measuring cart
      await page.keyboard.press('Escape').catch(() => {});
      await page.waitForTimeout(300);
      const annulerCart = page.getByRole('button', { name: /annuler/i }).first();
      if (await annulerCart.isVisible({ timeout: 400 }).catch(() => false)) {
        await annulerCart.click().catch(() => {});
        await page.waitForTimeout(300);
      }
      const cartArea = page.locator('.pos-cart, .pos-v5-cart, .cart-panel, [data-testid="pos-cart"]').first();
      const cartVisible = await cartArea.isVisible({ timeout: 1500 }).catch(() => false);
      findings.surfaces.push({
        id: 6,
        url: page.url(),
        label: 'cart-panel',
        visible: cartVisible,
      });
      await shot('cart-panel');
    });

    // ===== Step 7: "Encaisser" modal — open then capture, do NOT submit =====
    await safe('encaisser-modal-open', async () => {
      const enc = page
        .getByRole('button', { name: /encaisser|paiement|payer|valider/i })
        .first();
      const visible = await enc.isVisible({ timeout: 2000 }).catch(() => false);
      if (visible) {
        await enc.click().catch(() => {});
        await page.waitForTimeout(1800);
        findings.surfaces.push({
          id: 7,
          url: page.url(),
          label: 'encaisser-modal-open',
          opened: true,
        });
        await shot('encaisser-modal');

        // Try toggling cash vs card mode if visible (no submit)
        await safe('encaisser-toggle-cash', async () => {
          const cash = page.getByRole('button', { name: /espèces|cash/i }).first();
          if (await cash.isVisible({ timeout: 1500 }).catch(() => false)) {
            await cash.click().catch(() => {});
            await page.waitForTimeout(700);
            await shot('encaisser-cash-selected');
          }
        });
        await safe('encaisser-toggle-card', async () => {
          const card = page.getByRole('button', { name: /carte|tpe/i }).first();
          if (await card.isVisible({ timeout: 1500 }).catch(() => false)) {
            await card.click().catch(() => {});
            await page.waitForTimeout(700);
            await shot('encaisser-card-selected');
          }
        });

        // Close the modal without submitting (escape)
        await page.keyboard.press('Escape').catch(() => {});
        await page.waitForTimeout(800);
      } else {
        findings.surfaces.push({
          id: 7,
          url: page.url(),
          label: 'encaisser-button-not-visible',
          opened: false,
        });
        await shot('encaisser-button-missing');
      }
    });

    // ===== Step 8: "Suivi commandes" tab =====
    await safe('suivi-commandes-tab', async () => {
      const tab = page.getByRole('tab', { name: /suivi.?commandes?/i }).first();
      const btn = page.getByRole('button', { name: /suivi.?commandes?/i }).first();
      const link = page.getByRole('link', { name: /suivi.?commandes?/i }).first();
      let opened = false;
      for (const c of [tab, btn, link]) {
        if (await c.isVisible({ timeout: 1500 }).catch(() => false)) {
          await c.click().catch(() => {});
          await page.waitForTimeout(1500);
          opened = true;
          break;
        }
      }
      findings.surfaces.push({
        id: 8,
        url: page.url(),
        label: 'suivi-commandes',
        opened,
      });
      await shot('suivi-commandes');
    });

    // ===== Step 9: "Écran client" tab =====
    await safe('ecran-client-tab', async () => {
      const tab = page.getByRole('tab', { name: /écran.?client|ecran.?client/i }).first();
      const btn = page.getByRole('button', { name: /écran.?client|ecran.?client/i }).first();
      const link = page.getByRole('link', { name: /écran.?client|ecran.?client/i }).first();
      let opened = false;
      for (const c of [tab, btn, link]) {
        if (await c.isVisible({ timeout: 1500 }).catch(() => false)) {
          await c.click().catch(() => {});
          await page.waitForTimeout(1500);
          opened = true;
          break;
        }
      }
      findings.surfaces.push({
        id: 9,
        url: page.url(),
        label: 'ecran-client',
        opened,
      });
      await shot('ecran-client');
    });

    // ===== Step 10: Loyalty / Fidélité =====
    await safe('fidelite-ui', async () => {
      const fid = page.getByRole('button', { name: /fidélité|fidelite|loyalty/i }).first();
      const visible = await fid.isVisible({ timeout: 1500 }).catch(() => false);
      if (visible) {
        await fid.click().catch(() => {});
        await page.waitForTimeout(1200);
      }
      findings.surfaces.push({
        id: 10,
        url: page.url(),
        label: 'fidelite',
        visible,
      });
      await shot('fidelite-ui');
      if (visible) {
        await page.keyboard.press('Escape').catch(() => {});
        await page.waitForTimeout(500);
      }
    });

    // ===== Step 11: Logout =====
    await safe('logout-flow', async () => {
      // Try common logout triggers (avatar/menu/text)
      const candidates = [
        page.getByRole('button', { name: /déconnexion|deconnexion|logout/i }),
        page.getByRole('link', { name: /déconnexion|deconnexion|logout/i }),
        page.getByText(/déconnexion|deconnexion|logout/i),
      ];
      let clicked = false;
      for (const c of candidates) {
        const first = c.first();
        if (await first.isVisible({ timeout: 1200 }).catch(() => false)) {
          await first.click().catch(() => {});
          await page.waitForTimeout(1500);
          clicked = true;
          break;
        }
      }
      findings.surfaces.push({
        id: 11,
        url: page.url(),
        label: 'logout',
        clicked,
      });
      await shot('logout');
    });

    // ===== Final write =====
    // Compute simple verdict
    const hasP0 = findings.issues.some((i) => i.severity === 'P0');
    const hasManyConsole = findings.consoleErrors.length > 15;
    const hasServer5xx = findings.networkErrors.some((n) => n.status >= 500);

    findings.summary = {
      totalCaptures: findings.steps.filter((s) => s.ok).length,
      totalIssues: findings.issues.length,
      consoleErrorCount: findings.consoleErrors.length,
      networkErrorCount: findings.networkErrors.length,
      pageErrorCount: findings.pageErrors.length,
      categoriesProbed: findings.categoryImageSync.filter((c) => c.phase === 'click').length,
    };

    if (hasP0 || hasServer5xx) findings.verdict = 'BLOCK';
    else if (hasManyConsole || findings.pageErrors.length > 0) findings.verdict = 'HEAL';
    else findings.verdict = 'GO';

    fs.writeFileSync(FINDINGS_PATH, JSON.stringify(findings, null, 2));
    // eslint-disable-next-line no-console
    console.log(`[wave-Y-B] findings written → ${FINDINGS_PATH}`);
  });
});
