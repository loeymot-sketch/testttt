/**
 * s3-off02-offline-cart-clear.spec.js
 * ---------------------------------------------------------------------------
 * P1 OFF-02 — POS offline-enqueue branch must CLEAR THE CART after queuing.
 *
 * DEFECT (confirmed Wave-1 adversarial audit):
 *   PosComponent.vue orderSubmit() offline branch (navigator.onLine===false)
 *   enqueues the assembled checkout payload via enqueueOrder(...), toasts
 *   "mise en file d'attente", drops loading, and `return`s — but NEVER clears
 *   the cart. A cashier who clicks pay again while still offline fires a
 *   SECOND enqueue with a fresh idempotency key → DOUBLE order + double cash
 *   line on replay. "Caisse-never-stops" crux defect.
 *
 * HEAL: inside the `if (queued) { ... }` success block, reset cart state
 *   mirroring resetCart() (online success path already does this).
 *
 * STRATEGY (NF525 chain-safe — verified with advisor):
 *   - Add a real item to the cart while ONLINE (tile click → wizard →
 *     "Ajouter au panier"). The item/details XHR needs network; enqueue is
 *     local IndexedDB and works offline.
 *   - HARD GUARD: abort every POST to the /api/admin/pos endpoint so NO order
 *     ever reaches the server — neither the offline-replay path nor the online
 *     fall-through (#orderpayment → PaymentComponent posts the same endpoint).
 *     The fiscal chain stays untouched; no order row is created.
 *   - setOffline(true) → click the real pay button → exercise the offline
 *     branch. Read the queue depth from IndexedDB (the queueDepth badge is a
 *     by-value ref that does NOT track the helper's module _cache, so it is
 *     stale — never assert on it).
 *
 * DISCRIMINATING ASSERTIONS (advisor):
 *   (a) info toast "mise en file d'attente"      — passes pre+post heal
 *   (b) IndexedDB queue depth === 1 after enqueue — passes pre+post heal
 *   (c) CART EMPTY after enqueue (pay button hidden) — RED pre-heal, GREEN post
 *   (d) second pay while offline → depth STILL 1   — RED pre-heal, GREEN post
 *   => "RED for the right reason" = (c) failing.
 *
 * Teardown: none needed — the Playwright `page` fixture is a fresh browser
 * context whose IndexedDB is ephemeral and discarded on close. We never call
 * setOffline(false) on a live page bound to autoflush, and the route-abort on
 * POST /api/admin/pos means no order ever reaches the server, so the fiscal
 * chain is untouched. (Do NOT deleteDatabase here — it blocks against
 * idb-keyval's live connection and corrupts the depth reads.)
 */
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('../../helpers/login');

// Order-create endpoint: axios baseURL = <API_URL>/api ; tryFlush posts
// 'admin/pos' ; PaymentComponent posts the same. Abort POST to it everywhere.
const ORDER_POST_RE = /\/api\/admin\/pos(\?|$)/;

const OFFLINE_TOAST_RE = /mise en file d'attente/i;

// IndexedDB the POS offline queue lives in (posOfflineQueueDb.js).
const IDB_NAME = 'foodking-pos-offline-queue';
const IDB_STORE = 'queue';
const IDB_KEY = 'pos:offline-queue:v1';
const LS_FALLBACK_KEY = '__pos_idb_fallback__:pos:offline-queue:v1';

/**
 * Read the POS offline queue depth straight from the browser's IndexedDB
 * (with the localStorage fallback the helper uses in private/ITP mode).
 * Returns the number of queued entries, or 0 if the store/key is absent.
 */
async function readQueueDepth(page) {
  return page.evaluate(
    async ({ dbName, storeName, key, lsKey }) => {
      // localStorage fallback path (idb-keyval unavailable)
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
        // Sentinel: if the open never resolves (e.g. a stray pending delete
        // contends with idb-keyval's live connection), fail the poll fast with
        // -1 instead of silently reading "empty".
        setTimeout(() => done(-1), 3000);
        let openReq;
        try {
          openReq = indexedDB.open(dbName);
        } catch (_) { return done(0); }
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
        // idb-keyval stores never need an upgrade here; if it triggers, the DB
        // did not exist → depth 0.
        openReq.onupgradeneeded = () => { try { openReq.transaction.abort(); } catch (_) {} done(0); };
      });
    },
    { dbName: IDB_NAME, storeName: IDB_STORE, key: IDB_KEY, lsKey: LS_FALLBACK_KEY },
  );
}


test.describe('POS OFF-02 — offline enqueue must clear the cart (no double-enqueue)', () => {
  // No retry: the route-abort already guarantees chain safety; a retry would
  // only re-run the offline harness needlessly. Cap per-test at 90s so a
  // genuine failure returns fast instead of riding the 600s global timeout.
  test.describe.configure({ retries: 0, timeout: 90_000 });

  test('offline pay enqueues once, empties cart, and re-click does not double-enqueue', async ({ page }) => {
    // (0) HARD GUARD — abort any order-create POST before doing anything else.
    // Covers offline-replay AND the online fall-through (#orderpayment).
    await page.route('**/*', async (route) => {
      const req = route.request();
      if (req.method() === 'POST' && ORDER_POST_RE.test(req.url())) {
        return route.abort('failed');
      }
      return route.continue();
    });

    // (1) Login + land on /admin/pos (still ONLINE — catalog/wizard XHR need net).
    //     The Playwright `page` fixture is a fresh browser context → IndexedDB
    //     is already empty + ephemeral, so no start-clear is needed (and a
    //     deleteDatabase here would block against idb-keyval's live connection).
    await loginAsPosOperator(page);
    await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 25_000 });

    // (2) Add a real item to the cart via the production flow:
    //     product tile → wizard modal → "Ajouter au panier".
    //     Use a plain drink (Coca-Cola 33cl, id=52) — no composer steps, so the
    //     CTA is enabled immediately. Fall back to the first non-disabled tile
    //     if the catalog id ever shifts.
    let tile = page.locator('[data-pos-item-id="52"]:not([disabled])');
    if (!(await tile.count())) {
      tile = page.locator('[data-pos-item-id]:not([disabled])').first();
    }
    await expect(tile).toBeVisible({ timeout: 25_000 });
    await tile.click();

    // Wizard modal opens (#item-variation-modal gains .active; item/details XHR).
    // GOTCHA (verified live): the sticky add-to-cart footer renders with a
    // 0×0 bounding box (flex layout quirk of the modal-dialog) so Playwright
    // considers the CTA "not visible" / un-scrollable even with { force: true }.
    // The element IS connected + enabled and a raw DOM click adds the line
    // (verified live), so dispatch the click event directly — bypasses all
    // actionability checks. Modal-close is the success signal.
    await expect(page.locator('#item-variation-modal.active')).toBeVisible({ timeout: 15_000 });
    const addToCartBtn = page.locator('#item-variation-modal.active .pos-v5-item-add-cta');
    await expect(addToCartBtn).toBeEnabled({ timeout: 15_000 });
    await addToCartBtn.dispatchEvent('click');
    // Modal closes after a successful add.
    await expect(page.locator('#item-variation-modal.active')).toBeHidden({ timeout: 10_000 });

    // Cart now has a line → the pay CTA renders (v-if="carts.length > 0").
    const payBtn = page.getByTestId('pos-v5-pay');
    await expect(payBtn).toBeVisible({ timeout: 15_000 });

    // (3) GO OFFLINE. setOffline(true) flips navigator.onLine === false.
    await page.context().setOffline(true);

    // Banner proves isOnline flipped (gates the rest; the branch also checks
    // navigator.onLine directly).
    await expect(page.getByTestId('pos-offline-banner')).toBeVisible({ timeout: 10_000 });

    // (4) Trigger the offline-enqueue branch via the real pay button.
    await payBtn.click();

    // (a) info toast "mise en file d'attente" appeared.
    await expect(page.getByText(OFFLINE_TOAST_RE).first()).toBeVisible({ timeout: 10_000 });

    // (b) exactly one entry queued in IndexedDB.
    await expect.poll(() => readQueueDepth(page), { timeout: 10_000 }).toBe(1);

    // (c) HEAL ASSERTION — cart is now EMPTY. The pay CTA is under
    //     v-if="carts.length > 0", so an empty cart hides it.
    //     Pre-heal: cart NOT cleared → button still visible → THIS FAILS (RED).
    await expect(payBtn).toBeHidden({ timeout: 10_000 });

    // (d) NO double-enqueue. Re-trigger pay while still offline.
    //     Post-heal the button is gone (cart empty) → absence == pass; we only
    //     click if it somehow reappeared (pre-heal). Either way depth stays 1.
    if (await payBtn.isVisible().catch(() => false)) {
      await payBtn.click();
      // give the second enqueue a beat to land before reading depth
      await expect(page.getByText(OFFLINE_TOAST_RE).first()).toBeVisible({ timeout: 5_000 }).catch(() => {});
    }
    await expect.poll(() => readQueueDepth(page), { timeout: 10_000 }).toBe(1);

    // Teardown: nothing to wipe — the fresh browser context (and its IndexedDB)
    // is discarded on close. We never go back online on a live page, and the
    // route-abort on POST /api/admin/pos means no order ever reached the server,
    // so the fiscal chain is untouched.
  });
});
