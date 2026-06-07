// Round-3 CLUSTER-KIOSK-ERRORS-CRUD — explicit "change reflects in catalogue" proof.
// Round-1 captured the index count BEFORE create, so the create→index reflection
// was never directly shown. This drives create → re-fetch index (item present,
// count+1) → soft-delete → re-fetch index (gone). Self-cleaning: force-deletes its
// own throwaway row at the end (it has zero order history, so 409 guard not tripped).
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

const API = '/api/admin';
const APIKEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';

async function vuexToken(page) {
  return page.evaluate(() => {
    try {
      const vuex = JSON.parse(localStorage.getItem('vuex') || '{}');
      return vuex?.auth?.authToken || null;
    } catch (e) { return null; }
  });
}

async function api(page, token, method, url, body) {
  return page.evaluate(async ({ method, url, body, token, APIKEY }) => {
    const headers = { 'Accept': 'application/json', 'Authorization': 'Bearer ' + token, 'x-api-key': APIKEY };
    if (body) headers['Content-Type'] = 'application/json';
    const r = await fetch(url, { method, headers, body: body ? JSON.stringify(body) : undefined });
    let parsed = null; try { parsed = await r.json(); } catch (e) {}
    return { status: r.status, body: parsed };
  }, { method, url, body, token, APIKEY });
}

test('R3 catalogue reflects CREATE then soft-DELETE', async ({ page }) => {
  await loginAsAdmin(page);
  const token = await vuexToken(page);
  expect(token, 'admin token present').toBeTruthy();

  const uniq = 'R3-REFLECT-' + Date.now();

  // baseline index (live, non-deleted)
  const idx0 = await api(page, token, 'GET', API + '/item?per_page=200');
  const list0 = idx0.body?.data || idx0.body || [];
  const count0 = Array.isArray(list0) ? list0.length : 0;
  console.log('IDX_BEFORE count=' + count0);
  expect(idx0.status).toBe(200);

  // CREATE (field set mirrors the validated round-1 admin spec)
  const createBody = { name: uniq, item_category_id: 1, tax_id: 3, item_type: 1, price: 9.90, is_featured: 1, status: 5, order: 1 };
  const c = await api(page, token, 'POST', API + '/item', createBody);
  console.log('CREATE status=' + c.status + ' id=' + (c.body?.data?.id || c.body?.id) + ' err=' + (c.body?.message || ''));
  expect(c.status).toBe(201);
  const newId = c.body?.data?.id || c.body?.id;
  expect(newId).toBeTruthy();

  // index AFTER create — must contain the new item (reflection proof)
  const idx1 = await api(page, token, 'GET', API + '/item?per_page=200');
  const list1 = idx1.body?.data || idx1.body || [];
  const present1 = list1.some(i => i.id === newId || i.name === uniq);
  console.log('IDX_AFTER_CREATE count=' + list1.length + ' present=' + present1);
  expect(present1, 'new item visible in catalogue index after create').toBe(true);
  expect(list1.length).toBe(count0 + 1);

  // SOFT-DELETE
  const d = await api(page, token, 'DELETE', API + '/item/' + newId);
  console.log('SOFT_DELETE status=' + d.status);
  expect(d.status).toBe(202);

  // index AFTER delete — must be gone (reflection proof)
  const idx2 = await api(page, token, 'GET', API + '/item?per_page=200');
  const list2 = idx2.body?.data || idx2.body || [];
  const present2 = list2.some(i => i.id === newId || i.name === uniq);
  console.log('IDX_AFTER_DELETE count=' + list2.length + ' present=' + present2);
  expect(present2, 'item gone from catalogue index after soft-delete').toBe(false);
  expect(list2.length).toBe(count0);

  // CLEANUP — force-delete this throwaway row (no order history → guard allows it)
  const fd = await api(page, token, 'DELETE', API + '/item/' + newId + '?force=1');
  console.log('CLEANUP_FORCE_DELETE status=' + fd.status + ' (202=purged, 409=had-history-kept-soft)');
});
