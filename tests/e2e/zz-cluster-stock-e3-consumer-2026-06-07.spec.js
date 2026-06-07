// [CLUSTER-LOYALTY-STOCK E3 round-3 2026-06-07] Two-context STOCK LIVE SYNC proof
// on the CONSUMING surface (not just DB / not just in-process cache):
//   Context A (admin API)  : toggle item OOS via the real /admin/menu/availability/toggle
//   Context B (kiosk API)  : fetch /api/frontend/menu (the exact endpoint the borne renders)
//                            and assert the item flips is_available -> false -> true.
// This is what the borne actually consumes; the kiosk menu cache invalidation
// (InvalidateKioskMenuCacheOnItemAvailabilityChanged) must reflect through it.
const { test, expect } = require('@playwright/test');
const { loginAdmin } = require('./helpers/admin-auth');
const { loginKiosk } = require('./helpers/kiosk-auth');

const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766';
const ITEM_ID = 1; // "Menu (Frites + Boisson)" — branch 1, active

test.setTimeout(70000);

function findItem(payload, id) {
  const items = (payload && payload.data && payload.data.items) || [];
  return items.find((it) => Number(it.id) === id) || null;
}

test('E3: admin OOS toggle reflects in kiosk consuming menu API (before/after/restore)', async () => {
  const { apiContext: admin } = await loginAdmin({ baseURL: BASE });
  const { apiContext: kiosk } = await loginKiosk({ baseURL: BASE });

  const fetchMenuItem = async () => {
    const r = await kiosk.get('/api/frontend/menu');
    expect(r.status(), 'kiosk menu must be 200').toBe(200);
    return findItem(await r.json(), ITEM_ID);
  };
  const toggle = async (isAvailable) => {
    const r = await admin.post('/api/admin/menu/availability/toggle', {
      data: { item_id: ITEM_ID, branch_id: 1, is_available: isAvailable, unavailable_reason: isAvailable ? null : 'out_of_stock' },
    });
    expect([200, 201], `toggle ${isAvailable} ok`).toContain(r.status());
  };

  // BEFORE: available
  await toggle(true);
  let before = await fetchMenuItem();
  expect(before, 'item present in kiosk menu before').not.toBeNull();
  expect(before.is_available, 'item available before OOS').toBe(true);

  // TOGGLE OOS -> kiosk consuming menu must reflect
  await toggle(false);
  let after = await fetchMenuItem();
  expect(after, 'item still present in payload (rendered unavailable, not absent)').not.toBeNull();
  expect(after.is_available, 'item UNAVAILABLE in kiosk menu after OOS toggle').toBe(false);
  expect(after.unavailable_reason, 'reason surfaced').toBe('out_of_stock');

  // RESTORE -> reappears available
  await toggle(true);
  let restored = await fetchMenuItem();
  expect(restored.is_available, 'item available again after restore').toBe(true);

  await admin.dispose();
  await kiosk.dispose();
});
