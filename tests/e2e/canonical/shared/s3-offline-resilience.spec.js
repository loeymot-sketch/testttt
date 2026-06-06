/**
 * s3-offline-resilience.spec.js
 * ---------------------------------------------------------------------------
 * Canonical E2E for the two "caisse-never-stops" offline-resilience heals
 * (reports/test-e2e/all-systems-2026-06-06/WAVE1_POS_AUDIT_FINDINGS.md).
 * Run by the orchestrator AFTER the global bundle rebuild — the fixes live in
 * PosComponent.vue + usePosOfflineState.js, which are bundled into
 * public/js/app.js. (Vitest already proves the source logic on un-bundled
 * source; these specs prove the wired behavior end-to-end in the browser.)
 *
 *   [OFF-01 FIX]  PosComponent.orderSubmit() used to enqueue ONLY when
 *     navigator.onLine===false. If the server is UNREACHABLE while the
 *     interface still reports online (transport error / timeout / 5xx on the
 *     live submit), the sale was LOST. The heal adds a side-effect-free
 *     preflight GET (admin/pos/counter-collect/pending); a transport-level
 *     failure routes the sale into the SAME enqueue path instead of opening the
 *     payment modal onto a doomed POST.
 *
 *   [OFF-03 FIX]  usePosOfflineState.js auto-flush (online event + 30s timer)
 *     swallowed sync results silently — a FAILED background replay was
 *     invisible. The heal surfaces result.failed>0 via the same alertService
 *     warning the manual "Synchroniser" button uses, so the cashier is told.
 *
 * HARNESS FACTS (proven by s3-off02-offline-cart-clear.spec.js):
 *   - worktree server :8765 → PLAYWRIGHT_BASE_URL (orchestrator sets it).
 *   - login helper loginAsPosOperator (pos@lecayenne.fr / 123456).
 *   - CHAIN-SAFETY: page.route abort of POST /api/admin/pos so NO order ever
 *     reaches the server → the NF525 fiscal chain stays untouched.
 *   - Read queue depth from IndexedDB (db foodking-pos-offline-queue, store
 *     "queue", key pos:offline-queue:v1) — NOT the badge (a by-value ref that
 *     does not track the helper's module _cache, so the badge is stale).
 *   - NEVER indexedDB.deleteDatabase (blocks idb-keyval's live connection and
 *     corrupts depth reads). The fresh Playwright context's IDB is ephemeral.
 *   - The add-to-cart CTA renders with a 0×0 box (modal flex quirk) → use
 *     dispatchEvent('click') to bypass actionability checks (verified live).
 */
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('../../helpers/login');

// Order-create endpoint: axios baseURL = <API_URL>/api ; tryFlush posts
// 'admin/pos' ; PaymentComponent posts the same. Abort POST to it everywhere.
const ORDER_POST_RE = /\/api\/admin\/pos(\?|$)/;
// [OFF-01 FIX] Side-effect-free preflight probe the gate GETs to detect an
// unreachable server while navigator.onLine still reports online.
const PREFLIGHT_RE = /\/api\/admin\/pos\/counter-collect\/pending(\?|$)/;

const OFFLINE_TOAST_RE = /mise en file d'attente/i;
// [OFF-03 FIX] notifyAutoFlushResult warning copy on a failed replay.
const FAILED_REPLAY_TOAST_RE = /en échec/i;

// IndexedDB the POS offline queue lives in (posOfflineQueueDb.js).
const IDB_NAME = 'foodking-pos-offline-queue';
const IDB_STORE = 'queue';
const IDB_KEY = 'pos:offline-queue:v1';
const LS_FALLBACK_KEY = '__pos_idb_fallback__:pos:offline-queue:v1';

/**
 * Read the POS offline queue depth straight from the browser's IndexedDB (with
 * the localStorage fallback the helper uses in private/ITP mode). Returns the
 * number of queued entries, or 0 if absent. Lifted verbatim from the OFF-02
 * spec (proven live).
 */
async function readQueueDepth(page) {
  return page.evaluate(
    async ({ dbName, storeName, key, lsKey }) => {
      try {
        const raw = window.localStorage.getItem(lsKey);
        if (raw) {
          const arr = JSON.parse(raw);
          if (Array.isArray(arr)) return arr.length;
        }
      } catch (_) { /* ignore */ }

      if (typeof indexedDB === 'undefined') return 0;
      return await new Promise((resolve) => {
        let settled = false;
        const done = (n) => { if (!settled) { settled = true; resolve(n); } };
        setTimeout(() => done(-1), 3000);
        let openReq;
        try { openReq = indexedDB.open(dbName); } catch (_) { return done(0); }
        openReq.onerror = () => done(0);
        openReq.onblocked = () => done(-1);
        openReq.onsuccess = () => {
          const db = openReq.result;
          if (!db.objectStoreNames.contains(storeName)) { db.close(); return done(0); }
          try {
            const tx = db.transaction(storeName, 'readonly');
            const getReq = tx.objectStore(storeName).get(key);
            getReq.onerror = () => { db.close(); done(0); };
            getReq.onsuccess = () => {
              const val = getReq.result;
              db.close();
              done(Array.isArray(val) ? val.length : 0);
            };
          } catch (_) { db.close(); done(0); }
        };
        openReq.onupgradeneeded = () => { try { openReq.transaction.abort(); } catch (_) {} done(0); };
      });
    },
    { dbName: IDB_NAME, storeName: IDB_STORE, key: IDB_KEY, lsKey: LS_FALLBACK_KEY },
  );
}

/**
 * Add one real line to the POS cart via the production flow (tile → wizard →
 * "Ajouter au panier") while ONLINE. Lifted from the OFF-02 spec. The pay CTA
 * (v-if="carts.length > 0") becomes visible on return.
 */
async function addOneLineToCart(page) {
  let tile = page.locator('[data-pos-item-id="52"]:not([disabled])');
  if (!(await tile.count())) {
    tile = page.locator('[data-pos-item-id]:not([disabled])').first();
  }
  await expect(tile).toBeVisible({ timeout: 25_000 });
  await tile.click();

  await expect(page.locator('#item-variation-modal.active')).toBeVisible({ timeout: 15_000 });
  const addToCartBtn = page.locator('#item-variation-modal.active .pos-v5-item-add-cta');
  await expect(addToCartBtn).toBeEnabled({ timeout: 15_000 });
  await addToCartBtn.dispatchEvent('click');
  await expect(page.locator('#item-variation-modal.active')).toBeHidden({ timeout: 10_000 });

  const payBtn = page.getByTestId('pos-v5-pay');
  await expect(payBtn).toBeVisible({ timeout: 15_000 });
  return payBtn;
}

test.describe('POS S3 offline-resilience — OFF-01 (unreachable-while-online) + OFF-03 (failed replay notice)', () => {
  test.describe.configure({ retries: 0, timeout: 120_000 });

  // -------------------------------------------------------------------------
  // OFF-01 — server UNREACHABLE while interface still ONLINE → sale QUEUED
  //          (pre-heal: the modal opened onto a doomed POST → sale LOST).
  // -------------------------------------------------------------------------
  test('[OFF-01 FIX] unreachable-while-online pay enqueues the sale instead of losing it', async ({ page }) => {
    // CHAIN-SAFETY + simulate-unreachable: abort the order-create POST AND the
    // preflight GET. navigator.onLine stays TRUE the whole time → this exercises
    // the NEW gate (preflight transport-fail → enqueue), not the old
    // navigator-offline gate.
    await page.route('**/*', async (route) => {
      const req = route.request();
      const url = req.url();
      if (req.method() === 'POST' && ORDER_POST_RE.test(url)) {
        return route.abort('failed'); // doomed live submit → must NOT lose the sale
      }
      if (req.method() === 'GET' && PREFLIGHT_RE.test(url)) {
        return route.abort('failed'); // preflight → transport failure → unreachable
      }
      return route.continue();
    });

    await loginAsPosOperator(page);
    await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 25_000 });

    const payBtn = await addOneLineToCart(page);

    // Sanity: we are NOT in navigator-offline mode (the degraded banner is for
    // navigator.onLine===false; here the interface believes it is online, so the
    // banner must be ABSENT — proving the OFF-01 path, not the OFF-02/offline one).
    await expect(page.getByTestId('pos-offline-banner')).toBeHidden();

    // Trigger the submit. The preflight GET is aborted → reachable===false →
    // enqueueCurrentCheckout() runs (instead of modalShow('#orderpayment')).
    await payBtn.click();

    // (a) the same queue toast as the offline path appeared.
    await expect(page.getByText(OFFLINE_TOAST_RE).first()).toBeVisible({ timeout: 15_000 });

    // (b) HEAL ASSERTION — the sale is QUEUED, not lost: IDB depth === 1.
    //     Pre-heal: the online branch went straight to the modal, the aborted
    //     POST failed inside PaymentComponent → nothing queued → depth stays 0.
    await expect.poll(() => readQueueDepth(page), { timeout: 15_000 }).toBe(1);

    // (c) cart cleared (OFF-02 shared path) → pay CTA hidden.
    await expect(payBtn).toBeHidden({ timeout: 10_000 });

    // (d) the payment modal must NOT have opened (we routed AROUND it).
    await expect(page.locator('#orderpayment.active')).toBeHidden();
  });

  // -------------------------------------------------------------------------
  // OFF-03 — a FAILED background auto-flush replay must NOTIFY the cashier.
  //          Enqueue offline, return online with the POST still failing → the
  //          auto-flush replay fails → warning toast "en échec" must appear.
  // -------------------------------------------------------------------------
  test('[OFF-03 FIX] failed auto-flush replay surfaces a warning to the cashier', async ({ page }) => {
    // The order POST stays aborted for the WHOLE test → both the initial submit
    // (queues) and every replay (fails) hit a transport error. Chain-safe: no
    // order ever reaches the server. The preflight GET is left REACHABLE here so
    // the offline enqueue is driven by navigator.onLine (clean, deterministic).
    await page.route('**/*', async (route) => {
      const req = route.request();
      if (req.method() === 'POST' && ORDER_POST_RE.test(req.url())) {
        return route.abort('failed');
      }
      return route.continue();
    });

    await loginAsPosOperator(page);
    await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 25_000 });

    const payBtn = await addOneLineToCart(page);

    // Go OFFLINE → enqueue one sale through the navigator-offline gate.
    await page.context().setOffline(true);
    await expect(page.getByTestId('pos-offline-banner')).toBeVisible({ timeout: 10_000 });
    await payBtn.click();
    await expect(page.getByText(OFFLINE_TOAST_RE).first()).toBeVisible({ timeout: 10_000 });
    await expect.poll(() => readQueueDepth(page), { timeout: 10_000 }).toBe(1);

    // Return ONLINE → the composable's `online`-event auto-flush fires and tries
    // to replay. The POST is still aborted → the replay FAILS (result.failed>0).
    await page.context().setOffline(false);

    // (HEAL ASSERTION) — the cashier is TOLD the replay failed. Pre-heal the
    // auto-flush swallowed the result (.catch(()=>{})) → no toast → the entry
    // sat in IDB silently while the cashier believed it synced.
    await expect(page.getByText(FAILED_REPLAY_TOAST_RE).first()).toBeVisible({ timeout: 20_000 });

    // The failed entry is RETAINED for the next retry (markFailed keeps it) —
    // it is NOT silently dropped. Depth stays 1.
    await expect.poll(() => readQueueDepth(page), { timeout: 10_000 }).toBe(1);

    // Teardown note: we DID go back online here, but the order POST is aborted
    // for the whole test, so no order ever reaches the server and the fiscal
    // chain is untouched. The fresh context's IDB is discarded on close. Do NOT
    // deleteDatabase (blocks idb-keyval's live connection).
  });
});
