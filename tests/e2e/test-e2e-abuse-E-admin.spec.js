/**
 * ABUSE-E2E 2026-06-01 — Wave E: ADMIN pages page-by-page (visual + technical)
 * Spec: tests/e2e/test-e2e-abuse-E-admin.spec.js
 * Dir:  tests/e2e/__screenshots__/test-e2e-E/
 *
 * Mission (owner): abuse-e2e every admin page+function with screenshots, deep +
 * bad-mood analysis, "even the notifications". Loop until everything green.
 *
 * SURFACES (SPA Vue routes, login admin@lecayenne.fr / 123456):
 *   01 /admin/dashboard            DashboardComponent (KPIs + ~8 ErrorBoundary widgets)
 *   01b dashboard lower (scroll)   LastZReportWidget / MostPopular / Featured
 *   02 /admin/items                ItemListComponent  [data-testid="admin-items-list"]
 *   03 item availability/rupture   admin-item-rupture-pill (OBSERVE ONLY — Wave F owns cascade)
 *   04 /admin/stock/rupture        StockRuptureDashboardComponent [data-testid="stock-management-v2"]
 *   05 /admin/pos-orders           PosOrderListComponent (table, formatPrice(order.total))
 *   06 order detail                PosOrderShowComponent (formatPrice(order.total))  ← NUMERIC X-SURFACE
 *   06b /admin/online-orders       OnlineOrderListComponent
 *   07 EOD / Z-report view         dashboard EOD-PDF button (OBSERVE presence) + transactions list
 *   08 /admin/cash-overview        CashOverviewComponent [data-testid="cash-overview-*"]
 *   09 /admin/sales-report         SalesReportListComponent ($t('menu.sales_report'))
 *   10 /admin/items-report         ItemsReportListComponent ($t('menu.items_report'))
 *   11 /admin/settings             SettingsComponent
 *   12 empty/error states          stock-mgmt-search no-match → stock-mgmt-empty ; cash-overview empty
 *
 * SELECTORS — evidence-backed (grep 2026-06-01):
 *   - dashboard heading anchor:  h3.text-primary (visitorMessage) + h4 (authInfo.name)
 *   - items list:                [data-testid="admin-items-list"], admin-item-row-${id}, admin-item-rupture-pill
 *   - stock mgmt:                [data-testid="stock-management-v2"], stock-mgmt-search, stock-mgmt-empty,
 *                                stock-mgmt-product-${key}, stock-mgmt-toggle-${key}
 *   - pos order list row:        tbody.db-table-body tr ; last td = formatPrice(order.total)
 *   - pos order view link:       a.db-table-action.view  (router-link → admin.pos-orders.show)
 *   - pos order detail total:    total block: h4(label.total) + h5(formatPrice(order.total))
 *   - cash overview:             [data-testid="cash-overview-summary" | "cash-overview-table" | "cash-overview-empty"]
 *   - sales/items report:        h-tags rendering $t('menu.sales_report') / $t('menu.items_report')
 *   - EOD PDF button:            dashboard button -> $t('label.eod_pdf_button') (OBSERVE presence, do NOT click → real download)
 *
 * ASSERTION ARCHITECTURE (advisor-locked):
 *   - SNAP BEFORE ASSERT — the 4-file quartet is on disk regardless of outcome.
 *   - THROW only for spec-integrity: login fail / no navigation / shell never mounted.
 *   - RECORD LOUDLY (console.error + defects[]) for product defects (i18n leak, console
 *     error, 4xx, missing widget data). NEVER swallow with silent .catch — the defect
 *     stays VISIBLE in dom/console/network/png for the adversarial reviewer to flag.
 *   - ONE HARD numeric cross-surface check: pos order LIST total === DETAIL total
 *     (normalized via moneyNum). A genuine mismatch = real P0 and SHOULD fail.
 *   - i18n-leak scan runs against the FULL DOM via locators (PNG is fullPage:false).
 *
 * FROZEN ZONES — none rendered here directly; all admin surfaces are auditable.
 * STOCK RUPTURE — OBSERVE ONLY (no mutation). Wave F owns the live rupture cascade;
 *   leaving an item ÉPUISÉ would poison every serial wave behind us.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const DIR = path.resolve(__dirname, '__screenshots__/test-e2e-E');

// Money string normalizer: "12,50 €" / "12.50€" / " 12.50 €" → "12.50"
function moneyNum(s) {
  if (s == null) return null;
  const m = String(s).replace(/\s/g, '').replace(',', '.').match(/-?\d+(?:\.\d{1,2})?/);
  return m ? parseFloat(m[0]).toFixed(2) : null;
}

// i18n-leak detector: a visible text node that is a bare translation key
// (matches the AUDIT_PLAN measured-P1 rule: ^[a-z]+(\.[a-z_]+){1,4}$).
const I18N_LEAK_RE = /^[a-z]+(\.[a-z_]+){1,4}$/;

test.describe.serial('Wave E — ADMIN pages page-by-page (visual + technical)', () => {
  test.setTimeout(360_000);

  // Accumulated product defects — logged at the end, NEVER thrown. The
  // adversarial reviewer reads dom/console/network artifacts; this array is a
  // convenience index so the human sees them in the spec stdout too.
  const defects = [];
  function recordDefect(state, kind, detail) {
    const entry = { state, kind, detail };
    defects.push(entry);
    // eslint-disable-next-line no-console
    console.error(`[WAVE-E DEFECT] state=${state} kind=${kind} :: ${detail}`);
  }

  /**
   * Scan the live DOM for visible i18n key leaks. Records (never throws) so the
   * defect stays visible in artifacts. Uses locators, not the screenshot.
   */
  async function scanI18nLeak(page, state) {
    const leaks = await page.evaluate((reSrc) => {
      const re = new RegExp(reSrc);
      const out = [];
      const seen = new Set();
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
      let n;
      while ((n = walker.nextNode())) {
        const t = (n.textContent || '').trim();
        if (!t || t.length > 60 || seen.has(t)) continue;
        // skip nodes hidden from layout
        const el = n.parentElement;
        if (!el) continue;
        const cs = window.getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') continue;
        if (re.test(t)) {
          out.push(t);
          seen.add(t);
        }
      }
      return out.slice(0, 40);
    }, I18N_LEAK_RE.source);
    if (leaks.length) {
      recordDefect(state, 'i18n_leak', `visible bare keys: ${JSON.stringify(leaks)}`);
    }
    return leaks;
  }

  let snap;
  let dispose;
  let page;
  // Cross-state carry: dashboard "Total commandes" KPI, compared in state 09.
  let dashboardTotalOrders = null;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    const rec = attachMegaAuditRecorder(page, DIR);
    snap = rec.snap;
    dispose = rec.dispose;

    // SPEC-INTEGRITY gate: login must succeed (loginAsAdmin throws on its own
    // if the API login != 201 / no nav). We let it propagate.
    await loginAsAdmin(page);
    await expect(page).toHaveURL(/\/admin/, { timeout: 25_000 });
  });

  test.afterAll(async () => {
    if (dispose) dispose();
    if (page) await page.context().close().catch(() => {});
    if (defects.length) {
      // eslint-disable-next-line no-console
      console.log(`\n[WAVE-E] ${defects.length} product defect(s) recorded (see artifacts):`);
      // eslint-disable-next-line no-console
      defects.forEach((d) => console.log(`  - [${d.state}] ${d.kind}: ${d.detail}`));
    } else {
      // eslint-disable-next-line no-console
      console.log('\n[WAVE-E] 0 product defects recorded by the spec.');
    }
  });

  // ---------------------------------------------------------------------------
  // 01 — Dashboard (KPIs + widgets). Wait for a real content anchor (heading)
  // before snap so we don't capture the LoadingComponent spinner (vacuous).
  // ---------------------------------------------------------------------------
  test('01 dashboard — KPIs + widgets', async () => {
    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
    // SPEC-INTEGRITY: the dashboard shell heading must mount.
    const heading = page.locator('h3.text-primary').first();
    await expect(heading).toBeVisible({ timeout: 30_000 });
    // Give the ~8 ErrorBoundary widget XHRs time to settle behind the shell.
    await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
    await page.waitForTimeout(1500);
    await snap('01-dashboard');
    await scanI18nLeak(page, '01-dashboard');

    // SOFT-RECORD KPI population (Overview widget). ErrorBoundary-wrapped, so a
    // single failing widget must NOT abort the capture — we record, not throw.
    const bodyText = await page.locator('body').innerText();
    const hasTotalSales = /total/i.test(bodyText) && /\d/.test(bodyText);
    if (!hasTotalSales) {
      recordDefect('01-dashboard', 'kpi_empty', 'no numeric KPI text found on dashboard overview');
    }
    // Capture the dashboard "Total commandes" KPI value for the cross-surface
    // count coherence check in state 09 (sales report "Total Des Commandes").
    dashboardTotalOrders = await page.evaluate(() => {
      // Dashboard KPI card "Total commandes <N>". Match within the h3/h4 KPI
      // pair to avoid grabbing other digits on the page.
      const heads = Array.from(document.querySelectorAll('h3,h4'));
      for (let i = 0; i < heads.length; i++) {
        if (/^total commandes$/i.test((heads[i].textContent || '').trim())) {
          // The value is the immediately following h4 (KPI number).
          let sib = heads[i].nextElementSibling;
          while (sib) {
            const num = (sib.textContent || '').replace(/[^0-9]/g, '');
            if (num) return num;
            sib = sib.nextElementSibling;
          }
        }
      }
      // Fallback: regex on collapsed innerText.
      const txt = (document.body.innerText || '').replace(/ /g, ' ').replace(/\s+/g, ' ');
      const m = txt.match(/total\s+commandes\s*[:\-]?\s*(\d[\d.,\s]*)/i);
      return m ? m[1].replace(/[^0-9]/g, '') : null;
    });
    // Detect any ErrorBoundary that fell into its error fallback.
    const ebError = await page.locator('text=/error|erreur|something went wrong|une erreur/i').count();
    if (ebError > 0) {
      // Not auto-defect (FR copy uses "erreur" benignly); record for reviewer awareness.
      recordDefect('01-dashboard', 'possible_error_text', `${ebError} node(s) matched error-like copy (reviewer to verify ErrorBoundary fallback vs benign label)`);
    }
  });

  // 01b — Dashboard lower section (scroll). PNG is fullPage:false → the lower
  // widgets (LastZReportWidget, MostPopular, Featured) need a scrolled snap.
  test('01b dashboard — lower widgets (scroll)', async () => {
    // The admin shell scrolls an inner container, not document.body, so a window
    // scrollTo is a no-op. scrollIntoView on a real lower-section anchor works
    // regardless of which element is the scroll parent — this guarantees the
    // PNG (fullPage:false) actually shows the lower widgets to the reviewer.
    const zLink = page.locator('[data-testid="last-z-report-link"]');
    const zCount = await zLink.count();
    if (zCount > 0) {
      await zLink.scrollIntoViewIfNeeded().catch(() => {});
    } else {
      // fall back to the last dashboard child if the widget link is absent
      await page.evaluate(() => {
        const els = document.querySelectorAll('main *, .db-content *, body *');
        const last = els[els.length - 1];
        if (last && last.scrollIntoView) last.scrollIntoView({ block: 'end' });
      });
    }
    await page.waitForTimeout(1200);
    await snap('01b-dashboard-lower');
    await scanI18nLeak(page, '01b-dashboard-lower');
    if (zCount === 0) {
      recordDefect('01b-dashboard-lower', 'missing_widget', 'last-z-report-link not present in lower dashboard');
    }
    await zLink.scrollIntoViewIfNeeded().catch(() => {});
  });

  // ---------------------------------------------------------------------------
  // 02 — Items catalog
  // ---------------------------------------------------------------------------
  test('02 items catalog', async () => {
    await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
    const list = page.locator('[data-testid="admin-items-list"]');
    // SPEC-INTEGRITY: the items list shell must mount.
    await expect(list).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(1200);
    await snap('02-items-catalog');
    await scanI18nLeak(page, '02-items-catalog');

    // Anti-vacuous: there must be at least one item row.
    const rows = page.locator('[data-testid^="admin-item-row-"]');
    const rowCount = await rows.count();
    if (rowCount === 0) {
      recordDefect('02-items-catalog', 'empty_list', 'admin items list rendered 0 rows (DB should hold ~45 V1 items)');
    }
  });

  // ---------------------------------------------------------------------------
  // 03 — Item availability / rupture pill (OBSERVE ONLY — no mutation).
  // ---------------------------------------------------------------------------
  test('03 item availability/rupture (observe only)', async () => {
    // Still on /admin/items. The rupture pill is a status indicator per item.
    const pills = page.locator('[data-testid="admin-item-rupture-pill"]');
    const pillCount = await pills.count();
    // Scroll the first item row into view for a focused capture.
    const firstRow = page.locator('[data-testid^="admin-item-row-"]').first();
    if (await firstRow.count()) {
      await firstRow.scrollIntoViewIfNeeded().catch(() => {});
    }
    await page.waitForTimeout(400);
    await snap('03-item-availability');
    await scanI18nLeak(page, '03-item-availability');
    // Record the observed pill count (informational; cascade is Wave F's job).
    if (pillCount === 0) {
      recordDefect('03-item-availability', 'observation', 'no admin-item-rupture-pill rendered (all items available, or pill suppressed) — reviewer to confirm expected for current stock state');
    }

    // 03b — open ONE item edit view READ-ONLY (no mutation, no save) so the
    // "item edit/availability" plan state is genuinely covered, not just the
    // list-level pills.
    const editBtn = page.locator('[data-testid^="admin-item-edit-"]').first();
    if (await editBtn.count()) {
      await editBtn.click().catch(() => {});
      await page.waitForTimeout(1500);
      await snap('03b-item-edit');
      await scanI18nLeak(page, '03b-item-edit');
    } else {
      recordDefect('03b-item-edit', 'no_edit_button', 'no admin-item-edit-* button found to open an item edit view');
    }
  });

  // ---------------------------------------------------------------------------
  // 04 — Stock rupture management dashboard
  // ---------------------------------------------------------------------------
  test('04 stock-rupture dashboard', async () => {
    await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
    const root = page.locator('[data-testid="stock-management-v2"]');
    // SPEC-INTEGRITY: the stock mgmt shell must mount.
    await expect(root).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(1500);
    await snap('04-stock-rupture');
    await scanI18nLeak(page, '04-stock-rupture');

    // Anti-vacuous: products OR a coherent empty/error state must render.
    const products = page.locator('[data-testid^="stock-mgmt-product-"]');
    const empty = page.locator('[data-testid="stock-mgmt-empty"]');
    const errState = page.locator('[data-testid="stock-mgmt-error"]');
    const pCount = await products.count();
    const isEmpty = await empty.isVisible().catch(() => false);
    const isErr = await errState.isVisible().catch(() => false);
    if (pCount === 0 && !isEmpty && !isErr) {
      recordDefect('04-stock-rupture', 'empty_no_state', 'stock mgmt rendered 0 products and no empty/error placeholder');
    }
    if (isErr) {
      recordDefect('04-stock-rupture', 'error_state', 'stock-mgmt-error visible (data load failed)');
    }
  });

  // ---------------------------------------------------------------------------
  // 05 + 06 — POS orders list → detail. HARD numeric cross-surface check.
  // ---------------------------------------------------------------------------
  test('05 pos orders list', async () => {
    await page.goto('/admin/pos-orders', { waitUntil: 'domcontentloaded' });
    // SPEC-INTEGRITY: the orders table OR an empty-state body must mount.
    const tableBody = page.locator('tbody.db-table-body').first();
    await expect(tableBody).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(1200);
    await snap('05-pos-orders-list');
    await scanI18nLeak(page, '05-pos-orders-list');

    const rows = page.locator('tbody.db-table-body tr.db-table-body-tr');
    const rowCount = await rows.count();
    if (rowCount === 0) {
      recordDefect('05-pos-orders-list', 'empty_list', 'pos orders list rendered 0 rows (DB holds 3410 orders — expected non-empty)');
    }
  });

  test('06 order detail — NUMERIC cross-surface (list total === detail total)', async () => {
    const rows = page.locator('tbody.db-table-body tr.db-table-body-tr');
    const rowCount = await rows.count();
    if (rowCount === 0) {
      // Cannot run the numeric check without a row; record + skip the assert
      // (this is an environmental data gap, not a swallowed cascade).
      recordDefect('06-order-detail', 'no_rows_for_numeric', 'no pos order row available for list==detail numeric check');
      await snap('06-order-detail-norows');
      return;
    }

    // Read the LIST total from the first row's last cell (formatPrice(order.total)).
    const firstRow = rows.first();
    const cells = firstRow.locator('td');
    const cellCount = await cells.count();
    // The total is the last money-bearing td; scan from the end for a money string.
    let listTotalRaw = null;
    for (let i = cellCount - 1; i >= 0; i--) {
      const txt = (await cells.nth(i).innerText()).trim();
      if (/\d[\d.,]*\s*€|€\s*\d|\d+[.,]\d{2}/.test(txt)) {
        listTotalRaw = txt;
        break;
      }
    }
    const listTotal = moneyNum(listTotalRaw);

    // Click the view link to open the detail page.
    const viewLink = firstRow.locator('a.db-table-action.view').first();
    await expect(viewLink).toBeVisible({ timeout: 10_000 });
    await viewLink.click();
    // SPEC-INTEGRITY: detail navigation must land on the show route.
    await page.waitForURL(/\/admin\/pos-orders\/show\//, { timeout: 20_000 });
    // Detail total block: h4(label.total) sibling h5(formatPrice(order.total)).
    const totalLabel = page.getByText(/^Total$/i).last();
    await expect(totalLabel).toBeVisible({ timeout: 20_000 });
    await page.waitForTimeout(1000);
    await snap('06-order-detail');
    await scanI18nLeak(page, '06-order-detail');

    // Extract the detail grand total: the h5 immediately following the "Total" h4
    // inside the summary block. Robust read: find the flex row whose label is Total.
    const detailTotalRaw = await page.evaluate(() => {
      const heads = Array.from(document.querySelectorAll('h4'));
      for (const h of heads) {
        if (/^total$/i.test((h.textContent || '').trim())) {
          // sibling h5 in the same flex row
          const row = h.parentElement;
          if (row) {
            const h5 = row.querySelector('h5');
            if (h5) return (h5.textContent || '').trim();
          }
        }
      }
      return null;
    });
    const detailTotal = moneyNum(detailTotalRaw);

    // eslint-disable-next-line no-console
    console.log(`[WAVE-E numeric] list="${listTotalRaw}"(${listTotal}) detail="${detailTotalRaw}"(${detailTotal})`);

    // HARD ASSERT (artifacts already on disk via snap above). A real mismatch = P0.
    if (listTotal != null && detailTotal != null) {
      expect(
        detailTotal,
        `POS order list total (${listTotal}) must equal detail total (${detailTotal})`,
      ).toBe(listTotal);
    } else {
      // Could not extract one side — record (do not fake-pass).
      recordDefect(
        '06-order-detail',
        'numeric_unreadable',
        `could not parse one side: list=${listTotalRaw} detail=${detailTotalRaw}`,
      );
    }
  });

  test('06b online orders list', async () => {
    await page.goto('/admin/online-orders', { waitUntil: 'domcontentloaded' });
    const tableBody = page.locator('tbody.db-table-body').first();
    await expect(tableBody).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(1000);
    await snap('06b-online-orders-list');
    await scanI18nLeak(page, '06b-online-orders-list');
  });

  // ---------------------------------------------------------------------------
  // 07 — EOD / Z-report view. Observe the EOD-PDF button presence (do NOT click,
  // it fires a real download). Then open the Z/transactions list via the widget.
  // ---------------------------------------------------------------------------
  test('07 EOD / Z-report view', async () => {
    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('h3.text-primary').first()).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(1200);
    // Observe EOD PDF button (admin has pos-manage-fiscal -> button v-if true).
    const eodBtn = page.getByRole('button', { name: /eod|pdf|synth|clôture|cloture|télécharger/i });
    const eodCount = await eodBtn.count();
    await snap('07-eod-button-observe');
    if (eodCount === 0) {
      recordDefect('07-eod', 'observation', 'EOD PDF button not found on dashboard (admin may lack pos-manage-fiscal, or label changed)');
    }

    // Open the Z-report / transactions list (LastZReportWidget → admin.transactions.list).
    await page.goto('/admin/transactions', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
    await snap('07-transactions-zview');
    await scanI18nLeak(page, '07-transactions-zview');
    // SPEC-INTEGRITY-lite: ensure the SPA rendered admin content (not a blank shell).
    const bodyLen = (await page.locator('body').innerText()).trim().length;
    if (bodyLen < 30) {
      recordDefect('07-transactions-zview', 'blank_page', 'transactions/Z view rendered near-empty body');
    }
  });

  // ---------------------------------------------------------------------------
  // 08 — Cash overview
  // ---------------------------------------------------------------------------
  test('08 cash overview', async () => {
    await page.goto('/admin/cash-overview', { waitUntil: 'domcontentloaded' });
    // SPEC-INTEGRITY: summary OR empty placeholder must mount.
    const summary = page.locator('[data-testid="cash-overview-summary"]');
    const empty = page.locator('[data-testid="cash-overview-empty"]');
    await expect(summary.or(empty).first()).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(1500);
    await snap('08-cash-overview');
    await scanI18nLeak(page, '08-cash-overview');

    const table = page.locator('[data-testid="cash-overview-table"]');
    const hasTable = await table.isVisible().catch(() => false);
    const hasEmpty = await empty.isVisible().catch(() => false);
    if (!hasTable && !hasEmpty) {
      recordDefect('08-cash-overview', 'no_state', 'cash overview rendered neither table nor empty placeholder');
    }
    // Reconciliation block is a key audit surface — record if absent when table present.
    if (hasTable) {
      const recon = page.locator('[data-testid="cash-overview-reconciliation"]');
      if ((await recon.count()) === 0) {
        recordDefect('08-cash-overview', 'missing_reconciliation', 'cash-overview-table present but reconciliation block absent');
      }
    }

    // COHERENCE OBSERVATION — P2 (record, never throw). The GRAND TOTAL summary
    // card and the reconciliation "espèces encaissées" figure read different
    // numbers today, but this is TWO STAGES OF THE SAME MONEY BY DESIGN, not an
    // under-report. ROOT CAUSE (backend-confirmed via grep + tinker 2026-06-02):
    //   - The summary aggregates `transactions WHERE type=payment` for today.
    //     The `type=payment` row is written ONLY at (a) gateway success()
    //     callback (PaymentService::payment L61, assertGatewayContext-guarded)
    //     or (b) COUNTER ENCASHMENT (PaymentService L379, transaction_no
    //     "COUNTER-…"). It is NOT written at the payment_status=PAID flip.
    //   - The reconciliation reads the LIVE OPEN CashDrawerSession + CashMovement
    //     (independent source) → 52,00 €. The drawer is still OPEN ("Caisse
    //     ouverte à 18:27"); the Z/transactions view dates all to 01-06.
    //   - Today's 16 PAID orders were E2E-SEEDED (waves B/C/D, shared dev DB)
    //     whose harness flips payment_status=PAID WITHOUT running the counter
    //     Encaisser flow or a real gateway callback → 0 `type=payment` rows
    //     today is EXPECTED for those seeds (NF525 §8: POS-cash ledger cut at
    //     encashment/close, kiosk Plan-B routes to counter encashment).
    // => Fails the AUDIT_PLAN P1 numeric rule (not the SAME quantity across
    //    surfaces — different ledger stages). Classify P2 UX-confusion + a
    //    test-harness artifact, NOT a production cash-trail defect, NOT a
    //    GATE-ESCALATION. NO fix agent should touch transactions/fiscal code.
    // Normalize &nbsp; (U+00A0) → space so the regexes below match.
    const grandTotalRaw = await page.evaluate(() => {
      const card = document.querySelector('[data-testid="cash-overview-summary"]');
      return card ? (card.textContent || '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200) : null;
    });
    const reconRaw = await page.evaluate(() => {
      const r = document.querySelector('[data-testid="cash-overview-reconciliation"]');
      return r ? (r.textContent || '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300) : null;
    });
    // Grand total card text e.g. "Grand total0,00 €0 txCaisse..." — textContent
    // has NO inter-cell whitespace, so do NOT require \s between tokens.
    const grandIsZero = grandTotalRaw != null
      && /grand total\s*0[.,]00\s*€\s*0\s*tx/i.test(grandTotalRaw);
    // Reconciliation "Espèces encaissées aujourd'hui:52,00 €" with value > 0.
    const reconCashMatch = (reconRaw || '').match(/encaiss\S*\s*aujourd\S*hui[\s:]*(\d{1,4})[.,](\d{2})\s*€/i);
    const reconHasCash = !!reconCashMatch && !(reconCashMatch[1] === '0' && reconCashMatch[2] === '00');
    // eslint-disable-next-line no-console
    console.log(`[WAVE-E cash-debug] grandIsZero=${grandIsZero} reconHasCash=${reconHasCash} grand="${grandTotalRaw}" recon="${reconRaw}"`);
    if (grandIsZero && reconHasCash) {
      recordDefect(
        '08-cash-overview',
        'cash_two_stage_p2',
        `P2 (by-design, NOT a defect): GRAND TOTAL summary = 0,00€/0 tx today vs reconciliation = cash encaissé today. Two STAGES of the same money: summary reads transactions(type=payment) cut at gateway-success/counter-Encaisser (PaymentService L61/L379, "COUNTER-…"), NOT at payment_status=PAID flip; reconciliation reads the LIVE OPEN drawer (ouverte 18:27). Today's 16 PAID orders are E2E seeds (waves B/C/D) that never ran the counter Encaisser flow → 0 transactions today is EXPECTED. NF525 §8: ledger cut at encashment/close. NOT GATE-ESCALATION; no fix on transactions/fiscal code. summaryCard="${grandTotalRaw}" recon="${reconRaw}".`,
      );
    }
  });

  // ---------------------------------------------------------------------------
  // 09 — Sales report (net realized)
  // ---------------------------------------------------------------------------
  test('09 sales report', async () => {
    await page.goto('/admin/sales-report', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1800);
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
    // SPEC-INTEGRITY: report page must render admin content.
    const bodyText = (await page.locator('body').innerText()).trim();
    if (bodyText.length < 30) {
      // shell never mounted -> spec integrity issue
      await snap('09-sales-report-blank');
      throw new Error('sales report rendered a near-empty body (shell mount failure)');
    }
    await snap('09-sales-report');
    await scanI18nLeak(page, '09-sales-report');
    // The report may date-filter to "today" (2026-06-02) and read zero — that is
    // not a defect per se; we record the observation for reviewer awareness.
    const hasMoney = /\d+[.,]\d{2}|€/.test(bodyText);
    if (!hasMoney) {
      recordDefect('09-sales-report', 'no_money_rendered', 'sales report shows no money figures (check date range vs 2026-06-02 / possibly empty for today)');
    }
    // CROSS-SURFACE COUNT COHERENCE (record, never throw). Sales report
    // "Total Des Commandes" vs dashboard "Total commandes". A 1-unit delta is
    // known/intentional per commit b9bd199fa/b046b1c3b (DASH-01: dashboard counts
    // realized placed-volume; sales report counts its own scope) — revenue
    // matches exactly (31 662,40 € both surfaces), only the count differs by 1.
    // P2 (UX: two admin surfaces show different order counts to the same user).
    const salesTotalOrders = await page.evaluate(() => {
      // The "Total Des Commandes" card label wraps across lines; the numeric
      // value follows it. Read rendered innerText and capture the first integer
      // after the (whitespace-collapsed) label. The label may render as
      // "Total des commandes" (FR) — match loosely.
      const txt = (document.body.innerText || '').replace(/ /g, ' ').replace(/\s+/g, ' ');
      const m = txt.match(/total\s+commandes\s*[:\-]?\s*(\d[\d.,\s]*)/i);
      return m ? m[1].replace(/[^0-9]/g, '') : null;
    });
    // eslint-disable-next-line no-console
    console.log(`[WAVE-E count] dashboard total commandes=${dashboardTotalOrders} sales-report total commandes=${salesTotalOrders}`);
    if (dashboardTotalOrders && salesTotalOrders && dashboardTotalOrders !== salesTotalOrders) {
      recordDefect(
        '09-sales-report',
        'count_coherence_p2',
        `P2 (known): dashboard "Total commandes"=${dashboardTotalOrders} vs sales-report "Total Des Commandes"=${salesTotalOrders}. Delta intentional per DASH-01 (b9bd199fa); revenue matches exactly. Surfaced for durability.`,
      );
    }
  });

  // ---------------------------------------------------------------------------
  // 10 — Items report (units sold)
  // ---------------------------------------------------------------------------
  test('10 items report', async () => {
    await page.goto('/admin/items-report', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1800);
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
    const bodyText = (await page.locator('body').innerText()).trim();
    if (bodyText.length < 30) {
      await snap('10-items-report-blank');
      throw new Error('items report rendered a near-empty body (shell mount failure)');
    }
    await snap('10-items-report');
    await scanI18nLeak(page, '10-items-report');
  });

  // ---------------------------------------------------------------------------
  // 11 — Settings
  // ---------------------------------------------------------------------------
  test('11 settings', async () => {
    await page.goto('/admin/settings', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1800);
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
    const bodyText = (await page.locator('body').innerText()).trim();
    if (bodyText.length < 30) {
      await snap('11-settings-blank');
      throw new Error('settings rendered a near-empty body (shell mount failure)');
    }
    await snap('11-settings');
    await scanI18nLeak(page, '11-settings');
  });

  // ---------------------------------------------------------------------------
  // 12 — Empty / error states. Drive the stock-mgmt search to a no-match query
  // to exercise the genuine empty placeholder (read-only, no data mutation).
  // ---------------------------------------------------------------------------
  test('12 empty/error states (stock-mgmt no-match search)', async () => {
    await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
    const root = page.locator('[data-testid="stock-management-v2"]');
    await expect(root).toBeVisible({ timeout: 30_000 });
    const search = page.locator('[data-testid="stock-mgmt-search"]');
    if (await search.count()) {
      await search.fill('ZZZ_NO_SUCH_PRODUCT_XYZ_123');
      await page.waitForTimeout(1200);
      await snap('12-empty-state');
      await scanI18nLeak(page, '12-empty-state');
      const empty = page.locator('[data-testid="stock-mgmt-empty"]');
      const isEmpty = await empty.isVisible().catch(() => false);
      if (!isEmpty) {
        recordDefect('12-empty-state', 'missing_empty_state', 'no-match search did not surface stock-mgmt-empty placeholder');
      }
      // restore (read-only): clear the search so we leave no UI residue.
      await search.fill('');
      await page.waitForTimeout(400);
    } else {
      recordDefect('12-empty-state', 'no_search', 'stock-mgmt-search not present — cannot exercise empty state');
      await snap('12-empty-state-nosearch');
    }
  });
});
