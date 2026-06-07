// [GOAL-100pct 08-ADMIN 2026-06-07] Admin/Dashboard validation — KPI truth,
// dashboard XHR capture, CRUD persistence + abuse, tax_id NULL reproduction.
// READ-ONLY round: drives real admin endpoints against the DISPOSABLE clone
// (foodking_e2e :8766). No source edits, no fixes. All mutations on the clone.
const { test, expect, request: pwRequest } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

const API = '/api/admin';
const API_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';

async function vuexToken(page) {
  return page.evaluate(() => {
    try {
      const vuex = JSON.parse(localStorage.getItem('vuex') || '{}');
      return vuex?.auth?.authToken || null;
    } catch (e) { return null; }
  });
}

// Drive an admin API call using the page's own fetch (carries cookies + we add bearer).
async function apiCall(page, token, method, url, body, extraHeaders = {}) {
  return page.evaluate(async ({ method, url, body, token, extraHeaders }) => {
    const headers = {
      'Accept': 'application/json',
      'Authorization': 'Bearer ' + token,
      'x-api-key': 'b6d68vy2-m7g5-20r0-5275-h103w73453q120',
      ...extraHeaders,
    };
    let payload;
    if (body !== undefined && body !== null) {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(body);
    }
    const res = await fetch(url, { method, headers, body: payload });
    let json = null; let txt = null;
    try { json = await res.clone().json(); } catch (e) { try { txt = await res.text(); } catch (_) {} }
    return { status: res.status, json, txt: txt ? txt.slice(0, 300) : null, ct: res.headers.get('content-type') };
  }, { method, url, body, token, extraHeaders });
}

test.describe('08-ADMIN dashboard + CRUD', () => {
  test.setTimeout(240000);

  test('KPI capture + CRUD lifecycle + abuse', async ({ page }) => {
    const captured = {};
    page.on('response', async (res) => {
      const u = res.url();
      const m = u.match(/\/dashboard\/(total-sales|total-orders|total-customers|total-menu-items|realtime-report)/);
      if (m && res.request().method() === 'GET') {
        try { captured[m[1]] = { status: res.status(), body: await res.json() }; } catch (e) {}
      }
    });

    await loginAsAdmin(page);
    await page.goto('/admin/dashboard', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);
    const token = await vuexToken(page);
    console.log('TOKEN_PRESENT=' + (token ? 'yes' : 'no'));
    console.log('CAPTURED_KPI=' + JSON.stringify(captured));

    // Explicitly drive total-customers (not always on initial render)
    const tc = await apiCall(page, token, 'GET', API + '/dashboard/total-customers');
    console.log('TOTAL_CUSTOMERS=' + JSON.stringify(tc.json));

    // ===== INDEX: catalogue listing =====
    const idx = await apiCall(page, token, 'GET', API + '/item?per_page=100');
    const itemCount = idx.json?.data?.length ?? idx.json?.meta?.total ?? 'n/a';
    console.log('ITEM_INDEX_status=' + idx.status + ' count=' + itemCount + ' total_meta=' + (idx.json?.meta?.total));

    // ===== Category listing (under setting prefix) =====
    const cats = await apiCall(page, token, 'GET', API + '/setting/item-category?per_page=100');
    console.log('CATEGORY_INDEX_status=' + cats.status + ' count=' + (cats.json?.data?.length) + ' total=' + (cats.json?.meta?.total));

    // ===== CRUD-1 CREATE item (valid) =====
    const uniq = 'E2E-ADMIN-' + Date.now();
    const createBody = {
      name: uniq, item_category_id: 1, tax_id: 3, item_type: 1, price: 9.90,
      is_featured: 1, status: 5, order: 1,
    };
    const c1 = await apiCall(page, token, 'POST', API + '/item', createBody);
    const newId = c1.json?.data?.id;
    console.log('CREATE_OK status=' + c1.status + ' id=' + newId + ' name=' + (c1.json?.data?.name) + ' err=' + (c1.json?.message || ''));

    // ===== CRUD-2 UPDATE item (price + tax) =====
    let u1 = { status: 'skip' };
    if (newId) {
      u1 = await apiCall(page, token, 'PUT', API + '/item/' + newId, { ...createBody, price: 14.50, tax_id: 1 });
      console.log('UPDATE_OK status=' + u1.status + ' newprice=' + (u1.json?.data?.price) + ' newtax=' + (u1.json?.data?.tax_id) + ' err=' + (u1.json?.message || ''));
    }

    // ===== ABUSE-1 negative price =====
    const a1 = await apiCall(page, token, 'POST', API + '/item', { ...createBody, name: uniq + '-NEG', price: -5 });
    console.log('ABUSE_NEG_PRICE status=' + a1.status + ' msg=' + (a1.json?.errors?.price || a1.json?.message || JSON.stringify(a1.json)).toString().slice(0,120));

    // ===== ABUSE-2 zero price =====
    const a2 = await apiCall(page, token, 'POST', API + '/item', { ...createBody, name: uniq + '-ZERO', price: 0 });
    console.log('ABUSE_ZERO_PRICE status=' + a2.status + ' msg=' + (a2.json?.errors?.price || a2.json?.message || '').toString().slice(0,120));

    // ===== ABUSE-3 missing required name =====
    const a3 = await apiCall(page, token, 'POST', API + '/item', { item_category_id: 1, tax_id: 3, item_type: 1, price: 5, is_featured: 1, status: 5, order: 1 });
    console.log('ABUSE_NO_NAME status=' + a3.status + ' msg=' + (a3.json?.errors?.name || a3.json?.message || '').toString().slice(0,120));

    // ===== ABUSE-4 duplicate name =====
    const a4 = await apiCall(page, token, 'POST', API + '/item', createBody);
    console.log('ABUSE_DUP_NAME status=' + a4.status + ' msg=' + (a4.json?.errors?.name || a4.json?.message || '').toString().slice(0,120));

    // ===== ABUSE-5 non-numeric price =====
    const a5 = await apiCall(page, token, 'POST', API + '/item', { ...createBody, name: uniq + '-NAN', price: 'abc' });
    console.log('ABUSE_NAN_PRICE status=' + a5.status + ' msg=' + (a5.json?.errors?.price || a5.json?.message || '').toString().slice(0,120));

    // ===== ABUSE-6 invalid category (not_in:0) =====
    const a6 = await apiCall(page, token, 'POST', API + '/item', { ...createBody, name: uniq + '-CAT0', item_category_id: 0 });
    console.log('ABUSE_CAT0 status=' + a6.status + ' msg=' + (a6.json?.errors?.item_category_id || a6.json?.message || '').toString().slice(0,120));

    // ===== CRUD-3 CREATE category (under setting prefix) =====
    const catName = 'E2E-CAT-' + Date.now();
    const cc = await apiCall(page, token, 'POST', API + '/setting/item-category', { name: catName, status: 5, order: 99 });
    const newCatId = cc.json?.data?.id;
    console.log('CAT_CREATE status=' + cc.status + ' id=' + newCatId + ' err=' + (cc.json?.message || JSON.stringify(cc.json?.errors||'')).toString().slice(0,160));
    // edit + delete the category
    if (newCatId) {
      const cu = await apiCall(page, token, 'PUT', API + '/setting/item-category/' + newCatId, { name: catName + '-EDIT', status: 5, order: 99 });
      console.log('CAT_UPDATE status=' + cu.status + ' name=' + (cu.json?.data?.name));
      const cd = await apiCall(page, token, 'DELETE', API + '/setting/item-category/' + newCatId);
      console.log('CAT_DELETE status=' + cd.status);
    }

    // ===== CRUD-4 DELETE the created item (soft) =====
    let d1 = { status: 'skip' };
    if (newId) {
      d1 = await apiCall(page, token, 'DELETE', API + '/item/' + newId);
      console.log('DELETE_ITEM status=' + d1.status);
    }

    // ===== ABUSE-7 DELETE an item referenced by an order (orphan/FK history guard) =====
    // Find a LIVE item that has order_items history, force-delete to hit the 409 guard.
    const idxData = idx.json?.data || [];
    let histItemId = idxData.length ? idxData[idxData.length - 1].id : null;
    if (histItemId) {
      const histDel = await apiCall(page, token, 'DELETE', API + '/item/' + histItemId + '?force=1');
      console.log('DELETE_HIST_FORCE id=' + histItemId + ' status=' + histDel.status + ' error_key=' + JSON.stringify(histDel.json?.error ?? null) + ' msg=' + (histDel.json?.message || '').toString().slice(0,100));
    }

    // ===== tax_id NULL reproduction in catalogue (show a live item; 6 NULL items soft-deleted on clone) =====
    const liveShowId = idxData.length ? idxData[0].id : 1;
    const showLive = await apiCall(page, token, 'GET', API + '/item/show/' + liveShowId);
    console.log('SHOW_LIVE id=' + liveShowId + ' status=' + showLive.status + ' name=' + (showLive.json?.data?.name) + ' tax_id=' + JSON.stringify(showLive.json?.data?.tax_id));

    // ===== EOD PDF (fiscal-gated button) — admin has it =====
    const eod = await apiCall(page, token, 'POST', API + '/dashboard/eod-pdf');
    console.log('EOD_PDF status=' + eod.status + ' ct=' + eod.ct + ' bodylen=' + (eod.txt ? eod.txt.length : 'binary/stream'));

    // ===== Customers index + CREATE =====
    const cust = await apiCall(page, token, 'GET', API + '/customer?paginate=1&per_page=50');
    console.log('CUSTOMER_INDEX status=' + cust.status + ' count=' + (cust.json?.data?.length) + ' total=' + (cust.json?.meta?.total));
    const custPhone = '06' + String(Date.now()).slice(-8);
    const custCreate = await apiCall(page, token, 'POST', API + '/customer', { name: 'E2E Client ' + Date.now(), email: 'e2e' + Date.now() + '@test.fr', phone: custPhone, country_code: '+33', status: 5, password: 'secret123', password_confirmation: 'secret123' });
    const newCustId = custCreate.json?.data?.id;
    console.log('CUSTOMER_CREATE_VALID status=' + custCreate.status + ' id=' + newCustId + ' err=' + (custCreate.json?.message || JSON.stringify(custCreate.json?.errors||'')).toString().slice(0,160));
    // edit the new customer
    if (newCustId) {
      const cuU = await apiCall(page, token, 'PUT', API + '/customer/' + newCustId, { name: 'E2E Client EDIT', email: custCreate.json?.data?.email, phone: custPhone, country_code: '+33', status: 5 });
      console.log('CUSTOMER_UPDATE status=' + cuU.status + ' name=' + (cuU.json?.data?.name));
    }

    // ===== Loyalty: consult points balance (frontend loyalty endpoint, top-level /api/loyalty) =====
    const loyalty = await apiCall(page, token, 'GET', '/api/loyalty/balance?phone=' + custPhone);
    console.log('LOYALTY_BALANCE status=' + loyalty.status + ' body=' + JSON.stringify(loyalty.json).slice(0,180));
    const loyaltyCfg = await apiCall(page, token, 'GET', '/api/loyalty/config');
    console.log('LOYALTY_CONFIG status=' + loyaltyCfg.status + ' body=' + JSON.stringify(loyaltyCfg.json).slice(0,160));

    // ===== Users / staff index (administrators) =====
    const users = await apiCall(page, token, 'GET', API + '/administrator?paginate=1&per_page=20');
    console.log('USERS_INDEX status=' + users.status + ' count=' + (users.json?.data?.length) + ' total=' + (users.json?.meta?.total));
    // roles index (under setting)
    const roles = await apiCall(page, token, 'GET', API + '/setting/role?paginate=1&per_page=50');
    console.log('ROLES_INDEX status=' + roles.status + ' count=' + (roles.json?.data?.length));
    // tax index (catalogue dependency, under setting)
    const taxes = await apiCall(page, token, 'GET', API + '/setting/tax?paginate=1&per_page=50');
    console.log('TAX_INDEX status=' + taxes.status + ' count=' + (taxes.json?.data?.length));
    // stock catalog overview (rupture dashboard data)
    const rupture = await apiCall(page, token, 'GET', API + '/stock/catalog-overview');
    console.log('STOCK_OVERVIEW status=' + rupture.status + ' ct=' + rupture.ct + ' keys=' + (rupture.json ? Object.keys(rupture.json).join(',').slice(0,120) : 'n/a'));

    // ===== STAFF CREATE + ROLE ASSIGN (employee, role_id required) =====
    const empPhone = '07' + String(Date.now()).slice(-8);
    const empCreate = await apiCall(page, token, 'POST', API + '/employee', {
      name: 'E2E Staff ' + Date.now(), email: 'staff' + Date.now() + '@lecayenne.fr',
      password: 'StaffPass1234', password_confirmation: 'StaffPass1234',
      phone: empPhone, country_code: '+33', status: 5, role_id: 7, branch_id: 1, // POS Operator
    });
    const empId = empCreate.json?.data?.id;
    console.log('STAFF_CREATE status=' + empCreate.status + ' id=' + empId + ' err=' + (empCreate.json?.message || JSON.stringify(empCreate.json?.errors||'')).toString().slice(0,180));
    // abuse: create staff without role_id -> must 422
    const empNoRole = await apiCall(page, token, 'POST', API + '/employee', {
      name: 'E2E NoRole ' + Date.now(), email: 'norole' + Date.now() + '@lecayenne.fr',
      password: 'StaffPass1234', password_confirmation: 'StaffPass1234', country_code: '+33', status: 5, branch_id: 1,
    });
    console.log('STAFF_NO_ROLE status=' + empNoRole.status + ' msg=' + (empNoRole.json?.errors?.role_id || empNoRole.json?.message || '').toString().slice(0,100));

    // ===== EXPORT buttons (must be a real file, not SPA-catchall HTML) =====
    const expItem = await apiCall(page, token, 'GET', API + '/item/export');
    console.log('EXPORT_ITEM status=' + expItem.status + ' ct=' + expItem.ct);
    const expCust = await apiCall(page, token, 'GET', API + '/customer/export');
    console.log('EXPORT_CUSTOMER status=' + expCust.status + ' ct=' + expCust.ct);
    const expEmp = await apiCall(page, token, 'GET', API + '/employee/export');
    console.log('EXPORT_EMPLOYEE status=' + expEmp.status + ' ct=' + expEmp.ct);

    // ===== STOCK TOGGLE WRITE (mark a live item OOS then back in stock) =====
    const toggleItemId = idxData.length ? idxData[0].id : 1;
    const toggleOff = await apiCall(page, token, 'POST', API + '/menu/availability/toggle', { item_id: toggleItemId, branch_id: 1, is_available: false, unavailable_reason: 'e2e-test' });
    console.log('STOCK_TOGGLE_OFF id=' + toggleItemId + ' status=' + toggleOff.status + ' body=' + JSON.stringify(toggleOff.json).slice(0,120));
    const toggleOn = await apiCall(page, token, 'POST', API + '/menu/availability/toggle', { item_id: toggleItemId, branch_id: 1, is_available: true });
    console.log('STOCK_TOGGLE_ON id=' + toggleItemId + ' status=' + toggleOn.status);

    // ===== Order history index (historique /admin/historique) — REAL SPA params =====
    // Pagination: paginate=1 + per_page (else OrderService::list returns ALL via get('*'))
    const hist = await apiCall(page, token, 'GET', API + '/order-history?paginate=1&per_page=10');
    console.log('ORDER_HIST_PAGED status=' + hist.status + ' count=' + (hist.json?.data?.length) + ' meta_total=' + (hist.json?.meta?.total) + ' last_page=' + (hist.json?.meta?.last_page) + ' per_page=' + (hist.json?.meta?.per_page));
    if (hist.json?.data?.[0]) {
      const o0 = hist.json.data[0];
      console.log('ORDER0 keys=' + Object.keys(o0).filter(k=>/fiscal|serial|status|total|origin|source|date/i.test(k)).join(','));
      console.log('ORDER0 serial=' + o0.order_serial_no + ' fiscal=' + JSON.stringify(o0.fiscal_sequence_no ?? null) + ' source_surface=' + JSON.stringify(o0.source_surface));
    }
    // status filter (status=5 ACTIVE) with pagination
    const histStatus = await apiCall(page, token, 'GET', API + '/order-history?paginate=1&per_page=5&status=5');
    console.log('ORDER_HIST_STATUS=5 status=' + histStatus.status + ' count=' + (histStatus.json?.data?.length) + ' meta_total=' + (histStatus.json?.meta?.total));
    // status filter status=10 (different) to confirm filter changes result set
    const histStatus10 = await apiCall(page, token, 'GET', API + '/order-history?paginate=1&per_page=5&status=10');
    console.log('ORDER_HIST_STATUS=10 status=' + histStatus10.status + ' meta_total=' + (histStatus10.json?.meta?.total));
    // source_surface filter (kiosk)
    const histKiosk = await apiCall(page, token, 'GET', API + '/order-history?paginate=1&per_page=5&source_surface=kiosk');
    console.log('ORDER_HIST_KIOSK status=' + histKiosk.status + ' meta_total=' + (histKiosk.json?.meta?.total));
    // date-range filter (today)
    const today = new Date().toISOString().slice(0,10);
    const histDate = await apiCall(page, token, 'GET', API + '/order-history?paginate=1&per_page=5&from_date=' + today + '&to_date=' + today);
    console.log('ORDER_HIST_DATE today=' + today + ' status=' + histDate.status + ' meta_total=' + (histDate.json?.meta?.total));
    // historique detail "voir" on first order
    if (hist.json?.data?.[0]) {
      const oid = hist.json.data[0].id;
      const det = await apiCall(page, token, 'GET', API + '/order-history/show/' + oid);
      console.log('ORDER_HIST_SHOW id=' + oid + ' status=' + det.status + ' has_items=' + (Array.isArray(det.json?.data?.order_items) ? det.json.data.order_items.length : (det.json?.data ? 'obj' : 'no')));
    }

    expect(c1.status).toBeLessThan(300);
  });
});
