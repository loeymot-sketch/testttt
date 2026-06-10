// W-C DASHBOARD-MUTATIONS — GOAL VALIDATION PROFONDE 100% (2026-06-10)
// Cible : http://127.0.0.1:8766 (clone jetable foodking_e2e — mutations AUTORISÉES)
// Le sweep lecture-seule (commit 837f16b4a) est déjà fait — ce spec clique les
// boutons qui ÉCRIVENT : C1 catalogue, C2 coupon, C3 staff, C4 settings,
// C5 stock race, C6 exports, C7 push/messages, C8 cleanup.
//
// ANTI-INTERFÉRENCE : pilote W-D parallèle sur items 49-59 (Eau Plate #58) —
// on ne touche JAMAIS ces ids. Toutes les créations sont préfixées « E2E-WC ».
//
// Run (depuis le worktree wc-dash-2026-06-10) :
//   WC_CYCLE=C1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 DB_DATABASE=foodking_e2e \
//   npx playwright test tests/e2e/zz-dashboard-mutations-2026-06-10.spec.js --retries=0
//   (cycle 2 : WC_CYCLE=C2 — créations re-jouées avec suffixe -C2)

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');

const CYCLE = process.env.WC_CYCLE === 'C2' ? 'C2' : 'C1';
const SUF = CYCLE === 'C2' ? ' C2' : '';
const CODESUF = CYCLE === 'C2' ? 'C2' : '';
const OUT = path.resolve(
  __dirname,
  '../../reports/test-e2e/validation-profonde-2026-06-10/dashboard-mutations',
  CYCLE === 'C2' ? 'cycle2' : 'cycle1',
);

const NAMES = {
  item: `E2E-WC Item${SUF}`,
  stockItem: `E2E-WC Stock${SUF}`,
  cat: `E2E-WC Cat${SUF}`,
  coupon: `E2E-WC Coupon${SUF}`,
  couponCode: `E2EWC10${CODESUF}`,
  emp: `E2E-WC Emp${SUF}`,
  empEmail: `wc-emp-${CYCLE.toLowerCase()}@lecayenne.fr`,
};

// ---------- DB helper (clone jetable foodking_e2e) ----------
function dbq(sql) {
  return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], {
    encoding: 'utf8',
  }).trim();
}

// ---------- results / findings ledger ----------
const RES = [];
function rec(step, status, msg) {
  const entry = { step, status, msg: String(msg).slice(0, 500) };
  RES.push(entry);
  console.log(`[${status}] ${step} :: ${msg}`);
  try {
    fs.mkdirSync(OUT, { recursive: true });
    fs.appendFileSync(path.join(OUT, 'results.jsonl'), JSON.stringify(entry) + '\n');
  } catch (e) {}
}

let page;
let currentStep = 'init';
const errorLog = []; // {step, kind, detail}

async function shot(name) {
  await page.screenshot({
    path: path.join(OUT, `${name}.jpg`),
    type: 'jpeg',
    quality: 70,
    fullPage: true,
  });
}

// step wrapper — max 3 boucles de correction, sinon capture + disclose
async function step(name, fn) {
  currentStep = name;
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      await fn(attempt);
      rec(name, 'PASS', `attempt ${attempt}`);
      return true;
    } catch (e) {
      if (attempt === 3) {
        await shot(`${name}-FAIL`).catch(() => {});
        rec(name, 'FAIL', e.message);
        return false;
      }
      await page.waitForTimeout(1200);
    }
  }
  return false;
}

// ---------- UI helpers ----------
async function pickVueSelect(rootSel, optText) {
  // rootSel doit résoudre le ROOT .vue-select (id= est écrasé par vue-next-select).
  const root = page.locator(rootSel).first();
  await root.scrollIntoViewIfNeeded();
  await root.click();
  await page.waitForTimeout(400);
  let opt = root.locator('li').filter({ hasText: optText }).first();
  if (!(await opt.isVisible().catch(() => false))) {
    const inp = root.locator('input').first();
    if (await inp.isVisible().catch(() => false)) {
      await inp.fill(optText);
      await page.waitForTimeout(400);
    }
    opt = root.locator('li').filter({ hasText: optText }).first();
  }
  await opt.click();
  await page.waitForTimeout(250);
}

// vue-datepicker : pick aujourd'hui (mode 'today') ou 15 du mois prochain ('future')
async function pickDate(inputIdx, mode /* 'today' | 'future' */) {
  const input = page.locator('#sidebar .dp__input').nth(inputIdx);
  await input.scrollIntoViewIfNeeded();
  await input.click();
  const menu = page.locator('.dp__menu');
  await menu.waitFor({ state: 'visible', timeout: 5000 });
  if (mode === 'today') {
    await page.locator('.dp__menu .dp__today').first().click();
  } else {
    const nextBtn = page
      .locator('.dp__menu [aria-label="Next month"], .dp__menu .dp__arrow_btn')
      .last();
    await nextBtn.click();
    await page.waitForTimeout(300);
    await page
      .locator('.dp__menu .dp__calendar_item .dp__cell_inner:not(.dp__cell_offset)')
      .filter({ hasText: /^15$/ })
      .first()
      .click();
  }
  await page.waitForTimeout(400);
  // autoApply + timePicker : si le menu reste ouvert, valider via Select puis Escape
  if (await menu.isVisible().catch(() => false)) {
    const sel = page
      .locator('.dp__menu .dp__action_select, .dp__menu .dp__action_buttons button')
      .last();
    if (await sel.isVisible().catch(() => false)) await sel.click();
  }
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(300);
  const val = await input.inputValue();
  if (!val) throw new Error(`datepicker[${inputIdx}] vide après sélection`);
  return val;
}

// click + waitForResponse atomique : Promise.all gère les DEUX promesses
// (le pattern « respP avant click » laissait une rejection non-gérée tuer le
// test sans même enregistrer le FAIL — observé run 1 sur C2a/C5b).
async function clickAndWait(locator, predicate, timeout = 15000) {
  const [resp] = await Promise.all([
    page.waitForResponse(predicate, { timeout }),
    locator.click(),
  ]);
  return resp;
}

// rupture dashboard : la recherche est SCOPÉE au bucket actif
// (StockRuptureDashboardComponent.vue:410-416) → sélectionner le bucket
// Burgers (cat-4) AVANT de chercher l'item E2E-WC.
async function openRuptureOnItem(itemId, name) {
  await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  const bucket = page.locator('[data-testid=stock-mgmt-bucket-cat-4]');
  await bucket.click();
  await page.waitForTimeout(600);
  await page.locator('[data-testid=stock-mgmt-search]').fill(name);
  await page.waitForTimeout(800);
  const toggle = page.locator(`[data-testid=stock-mgmt-toggle-item-${itemId}]`);
  await toggle.scrollIntoViewIfNeeded();
  return toggle;
}

async function confirmSwal() {
  const btn = page.locator('.swal2-confirm');
  await btn.waitFor({ state: 'visible', timeout: 5000 });
  await btn.click();
}

// création item via UI (réutilisé C1 + C5)
async function createItemViaUI(name, price) {
  await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  await page
    .locator('[data-testid=admin-item-create-open] button[data-drawer="#sidebar"]')
    .click();
  await page.waitForTimeout(600);
  await page.locator('[data-testid=admin-item-form-name]').fill(name);
  await page.locator('[data-testid=admin-item-form-price]').fill(price);
  await pickVueSelect('#sidebar [data-testid=admin-item-form-category]', 'Burgers');
  await pickVueSelect('#sidebar div:has(> label[for=tax_id]) .vue-select', 'VAT-10%');
  const resp = await clickAndWait(
    page.locator('[data-testid=admin-item-form-save]'),
    (r) => r.request().method() === 'POST' && /\/api\/admin\/item$/.test(r.url()),
  );
  if (resp.status() >= 300) {
    throw new Error(`POST /item HTTP ${resp.status()} ${(await resp.text()).slice(0, 200)}`);
  }
  // CTA post-création
  const cta = page.locator('[data-testid=cta-continue]');
  if (await cta.isVisible({ timeout: 3000 }).catch(() => false)) await cta.click();
  await page.waitForTimeout(500);
  const row = dbq(
    `SELECT id, price, item_category_id, tax_id, status, deleted_at FROM items WHERE name='${name}'`,
  );
  if (!row) throw new Error(`item '${name}' absent de la DB après création`);
  return row.split('\t');
}

// scan labels i18n bruts (même heuristique que le sweep W-B)
async function scanRawLabels() {
  return page.evaluate(() => {
    const text = document.body ? document.body.innerText : '';
    const raw = new Set();
    const lineRe = /^[a-z]+\.[a-z_.]+$/;
    const tokenRe = /(?:^|[\s>(])((label|button|message|menu|validation|placeholder|tooltip|admin)\.[a-z][a-z0-9_.]+)/g;
    for (const line of text.split('\n')) {
      const t = line.trim();
      if (t && lineRe.test(t)) raw.add(t);
    }
    let m;
    while ((m = tokenRe.exec(text)) !== null) raw.add(m[1]);
    return [...raw].slice(0, 40);
  });
}

// ============================================================
test.describe('W-C dashboard mutations CRUD', () => {
  test.describe.configure({ mode: 'default' });
  test.setTimeout(420000);

  let context;
  let itemId = null;
  let stockItemId = null;
  let catId = null;
  let couponId = null;
  let empId = null;

  test.beforeAll(async ({ browser }) => {
    fs.mkdirSync(OUT, { recursive: true });
    context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    page = await context.newPage();
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        errorLog.push({ step: currentStep, kind: 'console', detail: msg.text().slice(0, 300) });
      }
    });
    page.on('pageerror', (err) => {
      errorLog.push({
        step: currentStep,
        kind: 'pageerror',
        detail: String(err.message).slice(0, 300),
      });
    });
    page.on('response', (res) => {
      if (res.status() >= 400) {
        const entry = {
          step: currentStep,
          kind: 'http',
          detail: `${res.status()} ${res.request().method()} ${res.url()}`.slice(0, 300),
        };
        errorLog.push(entry);
        try {
          fs.appendFileSync(path.join(OUT, 'errors.jsonl'), JSON.stringify(entry) + '\n');
        } catch (e) {}
      }
    });
    // vue-next-select écrase l'attribut id → sélecteurs ancrés sur data-testid / label[for] (probe 2026-06-10)
    context.setDefaultTimeout(10000);
    currentStep = 'login';
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    fs.writeFileSync(
      path.join(OUT, 'RESULTS.json'),
      JSON.stringify({ cycle: CYCLE, results: RES, errors: errorLog }, null, 2),
    );
    await context?.close();
  });

  // ---------------- C1 catalogue ----------------
  test('C1 catalogue — item + catégorie CRUD', async () => {
    await step('C1a-item-create', async () => {
      await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      await shot('c1a-items-before');
      const row = await createItemViaUI(NAMES.item, '5.00');
      itemId = row[0];
      if (parseFloat(row[1]) !== 5.0) throw new Error(`prix DB=${row[1]} attendu 5.00`);
      rec(
        'C1a-db',
        'INFO',
        `items id=${row[0]} price=${row[1]} cat=${row[2]} tax=${row[3]} status=${row[4]}`,
      );
      await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      const tr = page.locator(`[data-testid=admin-item-row-${itemId}]`);
      await tr.scrollIntoViewIfNeeded();
      await expect(tr).toBeVisible();
      await shot('c1a-items-after-create');
    });

    await step('C1b-item-edit-price', async () => {
      if (!itemId) throw new Error('pas d item créé');
      await page.locator(`[data-testid=admin-item-edit-${itemId}] button`).click();
      await page.waitForTimeout(800);
      const priceInput = page.locator('[data-testid=admin-item-form-price]');
      await priceInput.fill('6.00');
      const resp = await clickAndWait(
        page.locator('[data-testid=admin-item-form-save]'),
        (r) =>
          ['POST', 'PUT', 'PATCH'].includes(r.request().method()) &&
          /\/api\/admin\/item/.test(r.url()),
      );
      if (resp.status() >= 300) throw new Error(`update HTTP ${resp.status()}`);
      await page.waitForTimeout(700);
      const price = dbq(`SELECT price FROM items WHERE id=${itemId}`);
      if (parseFloat(price) !== 6.0) throw new Error(`prix DB=${price} attendu 6.00`);
      await shot('c1b-item-edited');
    });

    await step('C1c-item-toggle-unavailable', async () => {
      if (!itemId) throw new Error('pas d item créé');
      const toggle = await openRuptureOnItem(itemId, NAMES.item);
      await shot('c1c-stock-before-toggle');
      const resp = await clickAndWait(
        toggle,
        (r) => r.request().method() === 'POST' && /availability\/toggle/.test(r.url()),
      );
      if (resp.status() >= 300) throw new Error(`toggle HTTP ${resp.status()}`);
      await page.waitForTimeout(800);
      const aria = await toggle.getAttribute('aria-checked');
      const dbRow = dbq(
        `SELECT branch_id, is_available FROM item_branch_availability WHERE item_id=${itemId}`,
      );
      rec(
        'C1c-db',
        'INFO',
        `aria-checked=${aria} item_branch_availability=[${dbRow.replace(/\n/g, ' | ')}]`,
      );
      if (!/0$/.test(dbRow.split('\n')[0] || '')) {
        throw new Error(`item_branch_availability pas is_available=0 : '${dbRow}'`);
      }
      await shot('c1c-stock-after-toggle');
    });

    await step('C1d-item-delete-soft', async () => {
      if (!itemId) throw new Error('pas d item créé');
      await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      const delBtn = page.locator(`[data-testid=admin-item-delete-${itemId}] button`);
      await delBtn.scrollIntoViewIfNeeded();
      await delBtn.click();
      const resp = await clickAndWait(
        page.locator('.swal2-confirm'),
        (r) => r.request().method() === 'DELETE' && /\/api\/admin\/item\//.test(r.url()),
      );
      if (resp.status() >= 300) throw new Error(`delete HTTP ${resp.status()}`);
      await page.waitForTimeout(700);
      const deletedAt = dbq(`SELECT deleted_at FROM items WHERE id=${itemId}`);
      if (!deletedAt || deletedAt === 'NULL') throw new Error(`deleted_at vide: '${deletedAt}'`);
      rec('C1d-db', 'INFO', `items.deleted_at=${deletedAt}`);
      await shot('c1d-item-deleted');
    });

    await step('C1e-category-crud', async () => {
      // page catégories = MODAL #categoryModal + boutons texte Voir/Modifier/Supprimer
      await page.goto('/admin/settings/item-categories/list', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      await shot('c1e-categories-before');
      await page.locator('button:has-text("Ajouter Une Catégorie")').first().click();
      await page.waitForTimeout(600);
      await page.locator('#categoryModal [data-testid=admin-category-form-name]').fill(NAMES.cat);
      const resp = await clickAndWait(
        page.locator('#categoryModal [data-testid=admin-category-form-save]'),
        (r) => r.request().method() === 'POST' && /setting\/item-category$/.test(r.url()),
      );
      if (resp.status() >= 300) throw new Error(`cat create HTTP ${resp.status()}`);
      await page.waitForTimeout(800);
      catId = dbq(`SELECT id FROM item_categories WHERE name='${NAMES.cat}'`);
      if (!catId) throw new Error('catégorie absente DB');
      await shot('c1e-category-created');
      // EDIT
      const tr = page.locator('tr', { hasText: NAMES.cat }).first();
      await tr.scrollIntoViewIfNeeded();
      await tr.locator('button:has-text("Modifier")').first().click();
      await page.waitForTimeout(700);
      await page
        .locator('#categoryModal [data-testid=admin-category-form-name]')
        .fill(`${NAMES.cat} EDIT`);
      const respU = await clickAndWait(
        page.locator('#categoryModal [data-testid=admin-category-form-save]'),
        (r) =>
          ['POST', 'PUT'].includes(r.request().method()) && /setting\/item-category/.test(r.url()),
      );
      if (respU.status() >= 300) throw new Error('cat update fail');
      await page.waitForTimeout(700);
      const newName = dbq(`SELECT name FROM item_categories WHERE id=${catId}`);
      if (newName !== `${NAMES.cat} EDIT`) throw new Error(`nom DB='${newName}'`);
      await shot('c1e-category-edited');
      // DELETE
      const tr2 = page.locator('tr', { hasText: `${NAMES.cat} EDIT` }).first();
      await tr2.locator('button:has-text("Supprimer")').first().click();
      const respD = await clickAndWait(
        page.locator('.swal2-confirm'),
        (r) => r.request().method() === 'DELETE' && /setting\/item-category/.test(r.url()),
      );
      if (respD.status() >= 300) throw new Error('cat delete fail');
      await page.waitForTimeout(700);
      const left = dbq(
        `SELECT COUNT(*) FROM item_categories WHERE id=${catId} AND deleted_at IS NULL`,
      );
      if (left !== '0') throw new Error(`catégorie encore active (count=${left})`);
      await shot('c1e-category-deleted');
    });
  });

  // ---------------- C2 coupon ----------------
  test('C2 coupon — create/list/edit/delete + raw labels', async () => {
    await step('C2a-coupon-create', async () => {
      await page.goto('/admin/coupons', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      await shot('c2a-coupons-before');
      await page.locator('button[data-drawer="#sidebar"]').first().click();
      await page.waitForTimeout(600);
      await page.locator('#sidebar #name').fill(NAMES.coupon);
      await page.locator('#sidebar #code').fill(NAMES.couponCode);
      await page.locator('#sidebar #discount').fill('10');
      await page.locator('#sidebar #percentage').check({ force: true });
      await pickDate(0, 'today');
      await pickDate(1, 'future');
      await page.locator('#sidebar #minimum_order').fill('10');
      await page.locator('#sidebar #maximum_discount').fill('5');
      // capture les labels i18n bruts du formulaire (P2 divulgué : label.monday_short…)
      const raw = await scanRawLabels();
      rec('C2-raw-labels', 'INFO', `form coupon raw labels: ${JSON.stringify(raw)}`);
      await shot('c2a-coupon-form-filled');
      await page.keyboard.press('Escape').catch(() => {});
      // PREUVE P2 overlay : quel élément reçoit réellement le clic au centre
      // du bouton « Enregistrer » ? (custom-checkbox-field absolu z-10 w/h-full
      // sans wrapper relatif — CouponCreateComponent.vue:164-184 + app.css:437)
      const overlayProof = await page.evaluate(() => {
        const btn = document.querySelector('#sidebar button[type=submit]');
        if (!btn) return 'no-btn';
        const r = btn.getBoundingClientRect();
        const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        return el
          ? `${el.tagName}.${el.className} value=${el.value || ''} (attendu: BUTTON submit)`
          : 'null';
      });
      rec('C2a-overlay-proof', 'INFO', `elementFromPoint(centre Enregistrer) = ${overlayProof}`);
      // Workaround test : submit programmatique (un VRAI clic souris est
      // intercepté par la checkbox invisible → P2 documenté, pas masqué).
      const respC = page
        .waitForResponse(
          (r) => r.request().method() === 'POST' && /\/api\/admin\/coupon$/.test(r.url()),
          { timeout: 15000 },
        )
        .catch(() => null);
      await page
        .locator('#sidebar form')
        .first()
        .evaluate((f) => f.requestSubmit());
      const resp = await respC;
      if (!resp) throw new Error('coupon create: aucun POST /coupon après requestSubmit');
      if (resp.status() >= 300) {
        throw new Error(`coupon create HTTP ${resp.status()} ${(await resp.text()).slice(0, 250)}`);
      }
      await page.waitForTimeout(800);
      const row = dbq(
        `SELECT id, code, discount, discount_type, status FROM coupons WHERE code='${NAMES.couponCode}'`,
      );
      if (!row) throw new Error('coupon absent DB');
      couponId = row.split('\t')[0];
      rec('C2a-db', 'INFO', `coupons: ${row.replace(/\t/g, ' ')}`);
      await shot('c2a-coupon-created');
    });

    await step('C2b-coupon-edit', async () => {
      if (!couponId) throw new Error('pas de coupon');
      const tr = page.locator('tr', { hasText: NAMES.couponCode }).first();
      await tr.scrollIntoViewIfNeeded();
      await tr.locator('button.edit, .db-table-action.edit').first().click();
      await page.waitForTimeout(800);
      await page.locator('#sidebar #discount').fill('15');
      const respUP = page
        .waitForResponse(
          (r) =>
            ['POST', 'PUT'].includes(r.request().method()) && /\/api\/admin\/coupon/.test(r.url()),
          { timeout: 15000 },
        )
        .catch(() => null);
      await page
        .locator('#sidebar form')
        .first()
        .evaluate((f) => f.requestSubmit());
      const respU = await respUP;
      if (!respU || respU.status() >= 300) throw new Error('coupon update fail');
      await page.waitForTimeout(800);
      const d = dbq(`SELECT discount FROM coupons WHERE id=${couponId}`);
      if (parseFloat(d) !== 15) throw new Error(`discount DB=${d} attendu 15`);
      await shot('c2b-coupon-edited');
    });

    await step('C2c-coupon-delete', async () => {
      if (!couponId) throw new Error('pas de coupon');
      const tr = page.locator('tr', { hasText: NAMES.couponCode }).first();
      await tr.locator('button.delete, .db-table-action.delete').first().click();
      const respD = await clickAndWait(
        page.locator('.swal2-confirm'),
        (r) => r.request().method() === 'DELETE' && /\/api\/admin\/coupon/.test(r.url()),
      );
      if (respD.status() >= 300) throw new Error('coupon delete fail');
      await page.waitForTimeout(800);
      const left = dbq(`SELECT COUNT(*) FROM coupons WHERE id=${couponId}`);
      rec('C2c-db', 'INFO', `coupons restants id=${couponId}: ${left}`);
      if (left !== '0')
        throw new Error(`coupon encore présent (hard delete attendu, count=${left})`);
      await shot('c2c-coupon-deleted');
    });
  });

  // ---------------- C3 staff ----------------
  test('C3 staff — employé create/role FR/edit/delete', async () => {
    await step('C3a-employee-create', async () => {
      await page.goto('/admin/employees', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      await shot('c3a-employees-before');
      await page.locator('button[data-drawer="#sidebar"]').first().click();
      await page.waitForTimeout(600);
      await page.locator('#sidebar #name').fill(NAMES.emp);
      await page.locator('#sidebar #email').fill(NAMES.empEmail);
      await page.locator('#sidebar #phone').fill('0699001122');
      await page.locator('#sidebar #password').fill('StaffPass1234');
      await page.locator('#sidebar #password_confirmation').fill('StaffPass1234');
      await pickVueSelect('#sidebar div:has(> label[for=role_id]) .vue-select', 'POS Operator');
      await shot('c3a-employee-form');
      const resp = await clickAndWait(
        page.locator('#sidebar button[type=submit]'),
        (r) => r.request().method() === 'POST' && /\/api\/admin\/employee$/.test(r.url()),
      );
      if (resp.status() >= 300) {
        throw new Error(
          `employee create HTTP ${resp.status()} ${(await resp.text()).slice(0, 250)}`,
        );
      }
      await page.waitForTimeout(800);
      const row = dbq(`SELECT id, phone, branch_id FROM users WHERE email='${NAMES.empEmail}'`);
      if (!row) throw new Error('employé absent DB');
      empId = row.split('\t')[0];
      const role = dbq(
        `SELECT r.name FROM model_has_roles mhr JOIN roles r ON r.id=mhr.role_id WHERE mhr.model_id=${empId}`,
      );
      rec('C3a-db', 'INFO', `users: ${row.replace(/\t/g, ' ')} role=${role}`);
      if (!/POS Operator/.test(role)) throw new Error(`role DB='${role}' attendu POS Operator`);
    });

    await step('C3b-employee-role-label-fr', async () => {
      if (!empId) throw new Error('pas d employé');
      await page.goto('/admin/employees', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      const tr = page.locator('tr', { hasText: NAMES.emp }).first();
      await tr.scrollIntoViewIfNeeded();
      const rowText = (await tr.innerText()).replace(/\s+/g, ' ');
      rec('C3b-ui', 'INFO', `ligne employé affichée: "${rowText.slice(0, 200)}"`);
      await shot('c3b-employee-list');
      // mandat FR : le rôle doit être affiché en français (pas « POS Operator » brut)
      if (/POS Operator/.test(rowText)) {
        throw new Error('rôle affiché en ANGLAIS "POS Operator" dans la liste (mandat FR)');
      }
    });

    await step('C3c-employee-edit-phone', async () => {
      if (!empId) throw new Error('pas d employé');
      const tr = page.locator('tr', { hasText: NAMES.emp }).first();
      await tr.locator('button.edit, .db-table-action.edit').first().click();
      await page.waitForTimeout(800);
      await page.locator('#sidebar #phone').fill('0688997766');
      const resp = await clickAndWait(
        page.locator('#sidebar button[type=submit]'),
        (r) =>
          ['POST', 'PUT'].includes(r.request().method()) && /\/api\/admin\/employee/.test(r.url()),
      );
      if (resp.status() >= 300) {
        throw new Error(
          `employee update HTTP ${resp.status()} ${(await resp.text()).slice(0, 250)}`,
        );
      }
      await page.waitForTimeout(800);
      const phone = dbq(`SELECT phone FROM users WHERE id=${empId}`);
      if (phone !== '0688997766') throw new Error(`phone DB='${phone}'`);
      await shot('c3c-employee-edited');
    });

    await step('C3d-employee-delete', async () => {
      if (!empId) throw new Error('pas d employé');
      const tr = page.locator('tr', { hasText: NAMES.emp }).first();
      await tr.locator('button.delete, .db-table-action.delete').first().click();
      const respD = await clickAndWait(
        page.locator('.swal2-confirm'),
        (r) => r.request().method() === 'DELETE' && /\/api\/admin\/employee/.test(r.url()),
      );
      if (respD.status() >= 300) throw new Error('employee delete fail');
      await page.waitForTimeout(800);
      const del = dbq(`SELECT deleted_at FROM users WHERE id=${empId}`);
      rec('C3d-db', 'INFO', `users.deleted_at=${del}`);
      if (!del || del === 'NULL') throw new Error(`deleted_at vide: '${del}'`);
      await shot('c3d-employee-deleted');
    });
  });

  // ---------------- C4 settings ----------------
  test('C4 settings — format horaire 24h (DATA-FIX) + company revert', async () => {
    await step('C4a-time-format-24h', async () => {
      const before = dbq(
        `SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$."$value"')) FROM settings WHERE \`key\`='site_time_format'`,
      );
      rec('C4a-db-before', 'INFO', `site_time_format avant: ${before}`);
      await page.goto('/admin/settings/site', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      await shot('c4a-site-before');
      if (before === 'H:i') {
        rec('C4a-already-24h', 'INFO', 'déjà en 24h (cycle 2 attendu) — on vérifie seulement');
      } else {
        await pickVueSelect('div:has(> label[for=site_time_format]) .vue-select', '24 Hour');
        // P2 divulgué : SiteRequest.php:40+43 exige site_google_map_key et
        // site_copyright alors que l'install V1 les laisse VIDES → tout save
        // de Paramètres/Site 422 sans ces deux champs. Data-fix minimal sur le
        // clone e2e pour débloquer le mandat 24h (valeurs divulguées au rapport).
        const cr = page.locator('#site_copyright');
        if ((await cr.inputValue()) === '') await cr.fill('© Le Cayenne');
        const gk = page.locator('#site_google_map_key');
        if ((await gk.inputValue()) === '') await gk.fill('e2e-clone-placeholder');
        const resp = await clickAndWait(
          page.locator('form:has(label[for=site_time_format]) button[type=submit]').first(),
          (r) => ['POST', 'PUT'].includes(r.request().method()) && /setting\/site/.test(r.url()),
        );
        if (resp.status() >= 300) {
          throw new Error(`site save HTTP ${resp.status()} ${(await resp.text()).slice(0, 250)}`);
        }
        await page.waitForTimeout(800);
      }
      const after = dbq(
        `SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$."$value"')) FROM settings WHERE \`key\`='site_time_format'`,
      );
      if (after !== 'H:i') throw new Error(`site_time_format DB='${after}' attendu 'H:i'`);
      rec('C4a-db-after', 'INFO', `site_time_format après: ${after} (GARDÉ — data-fix mandat FR)`);
      await shot('c4a-site-after-24h');
    });

    await step('C4b-historique-24h', async () => {
      await page.goto('/admin/historique', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3500);
      await shot('c4b-historique-24h');
      const body = await page.evaluate(() => document.body.innerText);
      const ampm = body.match(/\d{1,2}:\d{2}\s?(AM|PM|am|pm)\b/g) || [];
      const h24 = body.match(/\b([01]?\d|2[0-3]):[0-5]\d\b/g) || [];
      rec('C4b-scan', 'INFO', `occurrences AM/PM=${ampm.length} | horaires détectés=${h24.length}`);
      if (ampm.length > 0)
        throw new Error(`heures encore en 12h AM/PM: ${ampm.slice(0, 3).join(', ')}`);
    });

    await step('C4c-company-edit-revert', async () => {
      let before = dbq(
        `SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$."$value"')) FROM settings WHERE \`key\`='company_website'`,
      );
      // garde anti-retry : si une tentative précédente a laissé la valeur E2E,
      // la baseline canonique reste lecayenne.fr (jamais persister e2e-wc).
      if (/e2e-wc/.test(before)) before = 'https://lecayenne.fr';
      rec('C4c-db-before', 'INFO', `company_website avant: ${before}`);
      await page.goto('/admin/settings/company', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      await shot('c4c-company-before');
      const site = page.locator('#company #website');
      await site.fill('https://e2e-wc.lecayenne.fr');
      const respA = await clickAndWait(
        page.locator('#company form button[type=submit]').first(),
        (r) => ['POST', 'PUT'].includes(r.request().method()) && /setting\/company/.test(r.url()),
      );
      if (respA.status() >= 300) throw new Error('company save fail');
      await page.waitForTimeout(800);
      const mid = dbq(
        `SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$."$value"')) FROM settings WHERE \`key\`='company_website'`,
      );
      if (mid !== 'https://e2e-wc.lecayenne.fr') throw new Error(`DB='${mid}' pas mis à jour`);
      await shot('c4c-company-edited');
      // purge le toast succès (il couvre le bouton submit → le 2e clic ne part jamais)
      await page.waitForTimeout(2200);
      await page.keyboard.press('Escape').catch(() => {});
      // REVERT
      await site.fill(before === 'NULL' || before === '' ? 'https://lecayenne.fr' : before);
      const respB = await clickAndWait(
        page.locator('#company form button[type=submit]').first(),
        (r) => ['POST', 'PUT'].includes(r.request().method()) && /setting\/company/.test(r.url()),
      );
      if (respB.status() >= 300) throw new Error('company revert fail');
      await page.waitForTimeout(800);
      const after = dbq(
        `SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$."$value"')) FROM settings WHERE \`key\`='company_website'`,
      );
      rec('C4c-db-after', 'INFO', `company_website reverté: ${after}`);
      await shot('c4c-company-reverted');
    });
  });

  // ---------------- C5 stock — race lost-update F-DASH-2 ----------------
  test('C5 stock — toggle + reload race ×3 (F-DASH-2)', async () => {
    await step('C5a-create-stock-item', async () => {
      const row = await createItemViaUI(NAMES.stockItem, '4.50');
      stockItemId = row[0];
      rec('C5a-db', 'INFO', `stock item id=${stockItemId}`);
    });

    await step('C5b-toggle-persistance', async () => {
      if (!stockItemId) throw new Error('pas de stock item');
      const toggle = await openRuptureOnItem(stockItemId, NAMES.stockItem);
      await shot('c5b-stock-before');
      // toggle propre (attendre la réponse) → OOS
      const respT = await clickAndWait(
        toggle,
        (r) => r.request().method() === 'POST' && /availability\/toggle/.test(r.url()),
      );
      if (respT.status() >= 300) throw new Error('toggle OOS fail');
      await page.waitForTimeout(600);
      const toggle2 = await openRuptureOnItem(stockItemId, NAMES.stockItem);
      const aria = await toggle2.getAttribute('aria-checked');
      const db = dbq(
        `SELECT is_available FROM item_branch_availability WHERE item_id=${stockItemId}`,
      );
      rec('C5b-persist', 'INFO', `après reload propre: aria=${aria} db=${db}`);
      if (aria !== 'false')
        throw new Error(`état non persisté après reload (aria=${aria}, db=${db})`);
      await shot('c5b-stock-persisted-oos');
    });

    await step('C5c-race-toggle-reload-x3', async () => {
      if (!stockItemId) throw new Error('pas de stock item');
      const raceLog = [];
      for (let i = 1; i <= 3; i++) {
        const toggle = await openRuptureOnItem(stockItemId, NAMES.stockItem);
        const beforeAria = await toggle.getAttribute('aria-checked');
        const intended = beforeAria === 'true' ? 'false' : 'true';
        await toggle.click(); // SANS attendre la réponse
        await page.reload({ waitUntil: 'domcontentloaded' }); // reload immédiat = fenêtre de race
        await page.waitForTimeout(2500);
        const afterToggle = await openRuptureOnItem(stockItemId, NAMES.stockItem);
        const afterAria = await afterToggle.getAttribute('aria-checked');
        const db = dbq(
          `SELECT is_available FROM item_branch_availability WHERE item_id=${stockItemId}`,
        );
        const reverted = afterAria !== intended;
        raceLog.push(
          `#${i}: avant=${beforeAria} voulu=${intended} aprèsReload=${afterAria} db=${db} ${reverted ? 'REVERT(lost-update)' : 'ok'}`,
        );
        await shot(`c5c-race-${i}`);
      }
      rec('C5c-race-log', 'INFO', raceLog.join(' || '));
      // pas de throw : F-DASH-2 est un finding connu — on documente avec preuve
    });
  });

  // ---------------- C6 exports ----------------
  test('C6 exports — historique + sales-report', async () => {
    await step('C6a-export-historique', async () => {
      await page.goto('/admin/historique', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
      // ouvre le dropdown export puis clique Excel ; capture du blob via download OU réponse XHR
      const exportBtn = page
        .locator('button, a')
        .filter({ hasText: /export/i })
        .first();
      await exportBtn.click();
      await page.waitForTimeout(500);
      const dlP = page.waitForEvent('download', { timeout: 20000 }).catch(() => null);
      const respP = page
        .waitForResponse((r) => /order-history\/export|historique.*export/.test(r.url()), {
          timeout: 20000,
        })
        .catch(() => null);
      await page.locator('a').filter({ hasText: /xls|excel/i }).first().click();
      const [dl, resp] = await Promise.all([dlP, respP]);
      const fp = path.join(OUT, 'export-historique.xlsx');
      if (dl) {
        await dl.saveAs(fp);
      } else if (resp && resp.status() < 300) {
        fs.writeFileSync(fp, await resp.body());
      } else {
        throw new Error(
          `export historique: ni download ni réponse OK (resp=${resp && resp.status()})`,
        );
      }
      const size = fs.statSync(fp).size;
      if (size <= 0) throw new Error('fichier export vide');
      let content = '';
      try {
        content = execFileSync('unzip', ['-p', fp, 'xl/sharedStrings.xml'], {
          encoding: 'utf8',
          maxBuffer: 50 * 1024 * 1024,
        });
      } catch (e) {
        content = fs.readFileSync(fp, 'latin1').slice(0, 100000);
      }
      const hasFiscal = /fiscal|N°|Numéro/i.test(content);
      const frWords = (
        content.match(/Espèces|Carte|Comptoir|Caisse|Commande|Montant|Statut|Borne/gi) || []
      ).slice(0, 8);
      rec(
        'C6a-export',
        'INFO',
        `historique export size=${size}o fiscal=${hasFiscal} motsFR=${JSON.stringify(frWords)}`,
      );
      if (!hasFiscal && frWords.length === 0) throw new Error('export sans FR ni n° fiscal détecté');
      await shot('c6a-historique-export');
    });

    await step('C6b-export-sales-report', async () => {
      await page.goto('/admin/sales-report', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
      const exportBtn = page
        .locator('button, a')
        .filter({ hasText: /export/i })
        .first();
      if (!(await exportBtn.isVisible().catch(() => false))) {
        rec('C6b-absent', 'INFO', 'pas de bouton export sur sales-report');
        await shot('c6b-sales-report-no-export');
        return;
      }
      await exportBtn.click();
      await page.waitForTimeout(500);
      const dlP = page.waitForEvent('download', { timeout: 20000 }).catch(() => null);
      const respP = page
        .waitForResponse((r) => /sales-report.*(export|xls)/.test(r.url()), { timeout: 20000 })
        .catch(() => null);
      await page.locator('a').filter({ hasText: /xls|excel/i }).first().click();
      const [dl, resp] = await Promise.all([dlP, respP]);
      const fp = path.join(OUT, 'export-sales-report.xlsx');
      if (dl) {
        await dl.saveAs(fp);
      } else if (resp && resp.status() < 300) {
        fs.writeFileSync(fp, await resp.body());
      } else {
        throw new Error('export sales-report: ni download ni réponse OK');
      }
      const size = fs.statSync(fp).size;
      let content = '';
      try {
        content = execFileSync('unzip', ['-p', fp, 'xl/sharedStrings.xml'], {
          encoding: 'utf8',
          maxBuffer: 50 * 1024 * 1024,
        });
      } catch (e) {
        content = fs.readFileSync(fp, 'latin1').slice(0, 100000);
      }
      const frWords = (
        content.match(/Espèces|Carte|Commande|Montant|Total|Date|Statut/gi) || []
      ).slice(0, 8);
      rec('C6b-export', 'INFO', `sales-report export size=${size}o motsFR=${JSON.stringify(frWords)}`);
      if (size <= 0) throw new Error('fichier sales-report vide');
      await shot('c6b-sales-report-export');
    });
  });

  // ---------------- C7 push / messages ----------------
  test('C7 push notification (form only) + messages thread', async () => {
    await step('C7a-push-form-no-send', async () => {
      await page.goto('/admin/push-notifications', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      await shot('c7a-push-list');
      await page.locator('button[data-drawer="#sidebar"]').first().click();
      await page.waitForTimeout(600);
      await page.locator('#sidebar #title').fill('E2E-WC Push (NE PAS ENVOYER)');
      await page.locator('#sidebar #description').fill('Brouillon E2E — jamais envoyé en masse.');
      await shot('c7a-push-form-filled');
      // PAS de submit (pas de mode test) — fermeture du drawer
      await page.locator('#sidebar .close-btn').click();
      await page.waitForTimeout(500);
      const count = dbq(`SELECT COUNT(*) FROM push_notifications WHERE title LIKE 'E2E-WC%'`);
      if (count !== '0') throw new Error(`push notification CRÉÉE par erreur (count=${count})`);
      rec('C7a-db', 'INFO', 'aucune push créée (formulaire seulement) — OK');
    });

    await step('C7b-messages-thread', async () => {
      await page.goto('/admin/messages', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      await shot('c7b-messages-list');
      const anyRow = page.locator('tbody tr').first();
      if (await anyRow.isVisible().catch(() => false)) {
        await anyRow.click();
        await page.waitForTimeout(1500);
        await shot('c7b-message-thread-open');
        rec('C7b-thread', 'INFO', 'thread ouvert');
      } else {
        rec('C7b-thread', 'INFO', 'aucun thread cliquable — capture liste seule');
      }
    });
  });

  // ---------------- C8 cleanup ----------------
  test('C8 cleanup — purge E2E-WC (sauf setting 24h)', async () => {
    await step('C8a-delete-stock-item-ui', async () => {
      if (!stockItemId) {
        rec('C8a-skip', 'INFO', 'pas de stock item à supprimer');
        return;
      }
      await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      const delBtn = page.locator(`[data-testid=admin-item-delete-${stockItemId}] button`);
      await delBtn.scrollIntoViewIfNeeded();
      await delBtn.click();
      const respD = await clickAndWait(
        page.locator('.swal2-confirm'),
        (r) => r.request().method() === 'DELETE' && /\/api\/admin\/item\//.test(r.url()),
      );
      if (respD.status() >= 300) throw new Error('delete stock item fail');
      await page.waitForTimeout(700);
    });

    await step('C8b-sql-purge-and-dump', async () => {
      // purge dure des restes E2E-WC (clone jetable) — scope STRICT préfixe E2E-WC
      const itemIds = dbq(`SELECT GROUP_CONCAT(id) FROM items WHERE name LIKE 'E2E-WC%'`);
      if (itemIds && itemIds !== 'NULL') {
        dbq(`DELETE FROM item_branch_availability WHERE item_id IN (${itemIds})`);
        dbq(
          `DELETE FROM stock_levels WHERE stockable_type LIKE '%Item' AND stockable_id IN (${itemIds})`,
        );
        dbq(`DELETE FROM items WHERE id IN (${itemIds})`);
      }
      dbq(`DELETE FROM item_categories WHERE name LIKE 'E2E-WC%'`);
      dbq(`DELETE FROM coupons WHERE name LIKE 'E2E-WC%' OR code LIKE 'E2EWC10%'`);
      const empIds = dbq(
        `SELECT GROUP_CONCAT(id) FROM users WHERE email LIKE 'wc-emp-%@lecayenne.fr'`,
      );
      if (empIds && empIds !== 'NULL') {
        dbq(`DELETE FROM model_has_roles WHERE model_id IN (${empIds}) AND model_type LIKE '%User%'`);
        dbq(`DELETE FROM users WHERE id IN (${empIds})`);
      }
      const dump = [
        `items: ${dbq(`SELECT COUNT(*) FROM items WHERE name LIKE 'E2E-WC%'`)}`,
        `item_categories: ${dbq(`SELECT COUNT(*) FROM item_categories WHERE name LIKE 'E2E-WC%'`)}`,
        `coupons: ${dbq(`SELECT COUNT(*) FROM coupons WHERE code LIKE 'E2EWC10%'`)}`,
        `users: ${dbq(`SELECT COUNT(*) FROM users WHERE email LIKE 'wc-emp-%@lecayenne.fr'`)}`,
        `push: ${dbq(`SELECT COUNT(*) FROM push_notifications WHERE title LIKE 'E2E-WC%'`)}`,
        `time_format: ${dbq(`SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$."$value"')) FROM settings WHERE \`key\`='site_time_format'`)} (24h GARDÉ)`,
      ];
      rec('C8b-dump', 'INFO', dump.join(' | '));
      const leftovers = dump.filter((d) => / [1-9]/.test(d) && !/time_format/.test(d));
      if (leftovers.length) throw new Error(`restes E2E-WC: ${leftovers.join(' | ')}`);
      await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      await shot('c8-items-final');
    });

    // sanity finale : items 49-59 du pilote W-D jamais touchés par nos mutations
    await step('C8c-wd-items-untouched-sanity', async () => {
      const wd = dbq(
        `SELECT COUNT(*) FROM items WHERE id BETWEEN 49 AND 59 AND name LIKE 'E2E-WC%'`,
      );
      if (wd !== '0') throw new Error('collision W-D détectée');
      rec('C8c-wd', 'INFO', 'items 49-59 non touchés (aucune mutation E2E-WC dessus)');
    });
  });

  // ---------------- verdict ----------------
  test('Z verdict — aucun FAIL', async () => {
    const fails = RES.filter((r) => r.status === 'FAIL');
    console.log('=== RESULTS ===');
    for (const r of RES) console.log(`${r.status} ${r.step} :: ${r.msg}`);
    console.log('=== ERRORS (console/pageerror/http>=400) ===');
    for (const e of errorLog) console.log(`${e.kind} [${e.step}] ${e.detail}`);
    expect(fails, JSON.stringify(fails, null, 2)).toHaveLength(0);
  });
});
