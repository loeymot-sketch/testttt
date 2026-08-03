// FoodKing E2E — RED TEAM R2 KIOSK prise de commande
// =====================================================================
// Persona : auditeur senior sceptique, expert UX self-service / kiosk.
// Mission : challenger le verdict "PROD-READY" déjà émis par la blue team
//           sur le kiosk (cycles K0-K6 + MEGA-A à F).
// Approche : capture systématique de findings + DOM probes adversaires.
// Toutes les findings sont écrites *immédiatement* (durabilité).
// =====================================================================

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsKiosk } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(
  process.cwd(),
  'tests/e2e/screenshots/red-team-r2-kiosk-2026-05-07'
);
if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const FINDINGS_FILE = path.join(SHOT_DIR, 'findings.json');
const DOM_FILE = path.join(SHOT_DIR, 'dom-snapshots.json');

const findings = [];
const domSnapshots = {};

function record(step, slug, severity, note, screenshot) {
  findings.push({
    step,
    slug,
    severity,
    note,
    screenshot,
    ts: new Date().toISOString(),
  });
  fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
}

function recordDom(key, payload) {
  domSnapshots[key] = { ...payload, ts: new Date().toISOString() };
  fs.writeFileSync(DOM_FILE, JSON.stringify(domSnapshots, null, 2));
}

async function snap(page, slug) {
  const filename = `step-${String(slug).padStart(2, '0')}.png`;
  const fullPath = path.join(SHOT_DIR, filename);
  await page.screenshot({ path: fullPath, fullPage: true }).catch(() => {});
  return path.relative(process.cwd(), fullPath);
}

/**
 * After loginAsKiosk, the user lands on /kiosk/idle. We must select an order
 * type to unlock the catalog. Click the takeaway CTA (V1 dine-in is disabled
 * via flag, but takeaway is always present).
 */
async function startOrderFromIdle(page) {
  // If we're already past idle, no-op.
  const url = page.url();
  if (!/\/kiosk\/idle/.test(url)) return;

  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  if (await takeaway.isVisible({ timeout: 4_000 }).catch(() => false)) {
    await takeaway.click().catch(() => {});
  } else {
    // Fallback to dine-in
    const dineIn = page.locator('[data-testid="kiosk-order-type-dine-in"]');
    if (await dineIn.isVisible({ timeout: 1_000 }).catch(() => false)) {
      await dineIn.click().catch(() => {});
    }
  }
  await page.waitForTimeout(2_000);
}

/**
 * Read Vuex store from the live Vue 3 app.
 */
async function readStore(page, fn) {
  return page.evaluate((fnSrc) => {
    let store = null;
    const el = document.querySelector('#app');
    const inst = el && (el.__vue_app__ || el.__vueParentComponent);
    if (inst && inst.config && inst.config.globalProperties) {
      store = inst.config.globalProperties.$store;
    }
    if (!store) {
      const appComp = el && el.__vue_app__;
      if (appComp && appComp._context && appComp._context.config) {
        store = appComp._context.config.globalProperties.$store;
      }
    }
    if (!store) return { _error: 'no_store' };
    // eslint-disable-next-line no-new-func
    const fn = new Function('store', `return (${fnSrc})(store);`);
    try {
      return fn(store);
    } catch (e) {
      return { _error: String(e.message || e) };
    }
  }, fn.toString());
}

/**
 * Seed Vuex with a fake kiosk cart so cart/payment surfaces render.
 */
async function seedKioskCart(page) {
  await page.evaluate(() => {
    let store = null;
    const el = document.querySelector('#app');
    const inst = el && (el.__vue_app__ || el.__vueParentComponent);
    if (inst && inst.config && inst.config.globalProperties) {
      store = inst.config.globalProperties.$store;
    }
    if (!store) {
      const appComp = el && el.__vue_app__;
      if (appComp && appComp._context && appComp._context.config) {
        store = appComp._context.config.globalProperties.$store;
      }
    }
    if (!store) return;
    try {
      store.dispatch('kioskCart/reset');
    } catch (_) {}
    // Use addItem action so reactivity is properly triggered (Vuex)
    const items = [
      {
        item_id: 9001,
        name: 'Burger Big King',
        convert_price: 9.5,
        price: 9.5,
        quantity: 2,
        item_variations: [{ id: 1, name: 'Pain brioche' }],
        item_extras: [{ id: 11, name: 'Fromage', price: 1.0 }],
        item_variation_total: 0,
        item_extra_total: 1.0,
        total: (9.5 + 1.0) * 2,
        image: null,
      },
      {
        item_id: 9002,
        name: 'Frites maison',
        convert_price: 3.5,
        price: 3.5,
        quantity: 1,
        item_variations: [],
        item_extras: [],
        item_variation_total: 0,
        item_extra_total: 0,
        total: 3.5,
        image: null,
      },
    ];
    items.forEach((it) => {
      try {
        store.dispatch('kioskCart/addItem', it);
      } catch (_) {}
    });
    // Fallback if addItem signature differs
    const cs = store.state && store.state.kioskCart;
    if (cs && (!cs.items || cs.items.length === 0)) {
      cs.items = [
        {
          item_id: 9001,
          name: 'Burger Big King',
          convert_price: 9.5,
          price: 9.5,
          quantity: 2,
          item_variations: [{ id: 1, name: 'Pain brioche' }],
          item_extras: [{ id: 11, name: 'Fromage', price: 1.0 }],
          item_variation_total: 0,
          item_extra_total: 1.0,
          total: (9.5 + 1.0) * 2,
          image: null,
        },
        {
          item_id: 9002,
          name: 'Frites maison',
          convert_price: 3.5,
          price: 3.5,
          quantity: 1,
          item_variations: [],
          item_extras: [],
          item_variation_total: 0,
          item_extra_total: 0,
          total: 3.5,
          image: null,
        },
      ];
      cs.queueNumber = 'A042';
      cs.lastOrderId = 9999;
      cs.paymentMethod = 'card';
    }
  });
}

test.describe.configure({ mode: 'serial' });

test.describe('RED TEAM R2 — Kiosk prise de commande 2026-05-07', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try {
      clearFoodKingRateLimits();
    } catch (_) {}
  });

  // ------------------------------------------------------------------
  // STEP 01 — Login borne (auto-login)
  // ------------------------------------------------------------------
  test('01 — Login borne (auto-login silent)', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => {
      if (m.type() === 'error') errors.push(m.text());
    });

    await page.setViewportSize({ width: 1080, height: 1920 });
    await page.goto('/kiosk/login', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);

    const shot = await snap(page, '01-login-or-redirect');
    const url = page.url();
    const hasFormUsername = await page
      .locator('input[name="username"], input[type="text"]')
      .first()
      .isVisible({ timeout: 1_500 })
      .catch(() => false);

    recordDom('01-login', {
      url,
      hasFormUsername,
      jsErrors: errors.slice(0, 5),
    });

    if (/\/kiosk\/login/.test(url) && hasFormUsername) {
      record(
        1,
        'LK-LOGIN-VISIBLE',
        'INFO',
        `Login form visible — manual flow expected (config KIOSK_REQUIRE_MACHINE_LOGIN=true).`,
        shot
      );
    } else {
      record(
        1,
        'LK-LOGIN-AUTO',
        'INFO',
        `Auto-login silencieux OK — URL=${url}, no form visible.`,
        shot
      );
    }

    if (errors.length > 0) {
      record(
        1,
        'LK-CONSOLE-ERR',
        'P2',
        `${errors.length} console errors at boot: ${errors.slice(0, 3).join(' | ')}`,
        shot
      );
    }
  });

  // ------------------------------------------------------------------
  // STEP 02 — Token leak probe (LK1)
  // ------------------------------------------------------------------
  test('02 — Token leak probe (URL / localStorage / sessionStorage / cookies)', async ({
    page,
  }) => {
    await loginAsKiosk(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);

    const probe = await page.evaluate(() => {
      const ls = {};
      try {
        for (let i = 0; i < localStorage.length; i++) {
          const k = localStorage.key(i);
          ls[k] = String(localStorage.getItem(k) || '').slice(0, 200);
        }
      } catch (_) {}
      const ss = {};
      try {
        for (let i = 0; i < sessionStorage.length; i++) {
          const k = sessionStorage.key(i);
          ss[k] = String(sessionStorage.getItem(k) || '').slice(0, 200);
        }
      } catch (_) {}
      return {
        url: location.href,
        cookies: document.cookie,
        localStorageKeys: Object.keys(ls),
        sessionStorageKeys: Object.keys(ss),
        // raw dump truncated for grep
        ls,
        ss,
      };
    });

    const shot = await snap(page, '02-token-probe');
    recordDom('02-token-probe', probe);

    // Token shape : Sanctum personal access tokens look like "<id>|<40-60 chars>"
    const tokenRegex = /\d+\|[A-Za-z0-9]{30,80}/;
    const urlHasToken = tokenRegex.test(probe.url);
    const lsHasToken = Object.values(probe.ls).some((v) => tokenRegex.test(v));
    const ssHasToken = Object.values(probe.ss).some((v) => tokenRegex.test(v));
    const cookieHasToken = tokenRegex.test(probe.cookies);

    if (urlHasToken) {
      record(
        2,
        'LK1-URL-TOKEN-LEAK',
        'P0',
        `Token Sanctum visible dans l'URL : ${probe.url}`,
        shot
      );
    } else {
      record(
        2,
        'LK1-URL-CLEAN',
        'OK',
        `URL ne contient pas de token shape Sanctum (RÉFUTÉ).`,
        shot
      );
    }

    if (lsHasToken || ssHasToken) {
      record(
        2,
        'LK1-STORAGE-TOKEN',
        'P1',
        `Token kiosk présent dans Storage (LS=${lsHasToken}, SS=${ssHasToken}). Risque XSS = vol token + commandes au nom de la borne.`,
        shot
      );
    } else {
      record(
        2,
        'LK1-STORAGE-CLEAN',
        'INFO',
        `Aucun token shape Sanctum trouvé dans LS/SS. Vérifier via cookie httpOnly côté serveur.`,
        shot
      );
    }

    if (cookieHasToken) {
      record(
        2,
        'LK1-COOKIE-TOKEN',
        'INFO',
        `Token visible côté JS (cookie non-httpOnly).`,
        shot
      );
    }
  });

  // ------------------------------------------------------------------
  // STEP 03 — Surface accueil (idle screen)
  // ------------------------------------------------------------------
  test('03 — Surface accueil idle (DS Bold + tokens)', async ({ page }) => {
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);

    const shot = await snap(page, '03-idle-screen');
    const probe = await page.evaluate(() => {
      const root = document.querySelector('.kiosk-idle, [data-testid*="idle"]') || document.body;
      const fontFamily = getComputedStyle(root).fontFamily;
      const fontReady = document.fonts && document.fonts.check
        ? document.fonts.check('1em Fraunces')
        : 'no-fonts-api';
      // Touch targets : main CTA button minimum 44px (WCAG 2.5.8)
      const ctas = [...document.querySelectorAll('button')].slice(0, 5).map((b) => {
        const r = b.getBoundingClientRect();
        return {
          text: (b.textContent || '').trim().slice(0, 40),
          w: Math.round(r.width),
          h: Math.round(r.height),
          tooSmall: r.height < 44 || r.width < 44,
        };
      });
      return {
        url: location.href,
        rootClass: root.className,
        fontFamily,
        fontReady,
        ctas,
        bodyHasIdleClass: document.body.className,
      };
    });

    recordDom('03-idle', probe);

    if (probe.fontReady === false) {
      record(
        3,
        'DSK1-FRAUNCES-MISSING',
        'P2',
        `Fraunces font absent du document.fonts — fallback Georgia probable. Vérifier <link> Google Fonts ou self-host.`,
        shot
      );
    } else {
      record(
        3,
        'DSK1-FRAUNCES-READY',
        'OK',
        `Fraunces ready=${probe.fontReady}, fontFamily=${probe.fontFamily.slice(0, 80)}.`,
        shot
      );
    }

    const tooSmall = probe.ctas.filter((c) => c.tooSmall);
    if (tooSmall.length > 0) {
      record(
        3,
        'AK-CTA-TOUCH',
        'P2',
        `${tooSmall.length}/${probe.ctas.length} CTAs sous 44px (WCAG 2.5.8) : ${JSON.stringify(tooSmall)}`,
        shot
      );
    }
  });

  // ------------------------------------------------------------------
  // STEP 04 — Catégories
  // ------------------------------------------------------------------
  test('04 — Categories surface (catalog visible + a11y)', async ({ page }) => {
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    await startOrderFromIdle(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);

    const shot = await snap(page, '04-categories');
    const probe = await page.evaluate(() => {
      const cats = document.querySelectorAll('[data-testid^="kiosk-categories-sidebar-item-"]');
      const items = document.querySelectorAll('[data-testid^="kiosk-product-card-"]');
      return {
        url: location.href,
        catCount: cats.length,
        itemCount: items.length,
        firstCat: cats[0]?.getAttribute('data-testid') || cats[0]?.className || null,
        firstItem: items[0]?.getAttribute('data-testid') || items[0]?.className || null,
        hasMainNav: !!document.querySelector('nav, [role="navigation"]'),
      };
    });

    recordDom('04-categories', probe);

    if (probe.catCount === 0) {
      record(
        4,
        'CK-NO-CATEGORIES',
        'P1',
        `Aucune catégorie détectée sur /kiosk/categories — surface peut être vide / catalog non chargé.`,
        shot
      );
    } else {
      record(
        4,
        'CK-CATEGORIES-OK',
        'OK',
        `${probe.catCount} catégories, ${probe.itemCount} items affichés.`,
        shot
      );
    }
  });

  // ------------------------------------------------------------------
  // STEP 05 — Wizard kiosk a11y (WK1/WK2/WK3 — root role/aria-modal)
  // ------------------------------------------------------------------
  test('05 — Wizard kiosk a11y (root role/aria-modal/focus-trap)', async ({
    page,
  }) => {
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    await startOrderFromIdle(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);

    // The wizard renders as an INLINE OVERLAY .kiosk-wizard-overlay
    // (KioskCategoriesComponent.vue:278), not a route push.
    // Many products are direct-add (no options) — must iterate across
    // multiple categories AND multiple items per category to find one
    // with options that opens the wizard.
    const cats = page.locator('[data-testid^="kiosk-categories-sidebar-item-"]');
    const catCount = await cats.count();
    let wizardOpened = false;

    outer: for (let c = 0; c < Math.min(catCount, 8); c++) {
      await cats.nth(c).click({ timeout: 2_500 }).catch(() => {});
      await page.waitForTimeout(1_500);
      const items = page.locator('[data-testid^="kiosk-product-card-"]');
      const itemCount = await items.count();
      for (let i = 0; i < Math.min(itemCount, 6); i++) {
        const card = items.nth(i);
        const addBtn = card.locator('[data-testid^="kiosk-product-add-"]').first();
        if (await addBtn.isVisible({ timeout: 600 }).catch(() => false)) {
          await addBtn.click({ timeout: 2_500 }).catch(() => {});
        } else {
          await card.click({ timeout: 2_500 }).catch(() => {});
        }
        await page.waitForTimeout(1_500);
        const wizardVisible = await page.evaluate(
          () => !!document.querySelector('.kiosk-wizard-overlay .kiosk-wizard')
        );
        if (wizardVisible) {
          wizardOpened = true;
          break outer;
        }
      }
    }

    const shot = await snap(page, '05-wizard');
    const probe = await page.evaluate(() => {
      const wizardRoot = document.querySelector('.kiosk-wizard-overlay .kiosk-wizard') || document.querySelector('.kiosk-wizard');
      if (!wizardRoot) {
        return { found: false };
      }
      // Probe activeItem from Vuex / component to check allergen data
      let activeItemAllergens = null;
      let activeItemName = null;
      try {
        const el = document.querySelector('#app');
        const inst = el && el.__vue_app__;
        // Walk to find the categories instance with activeItem
        // Easier: read from component data via __vueParentComponent
        const overlay = document.querySelector('.kiosk-wizard-overlay');
        if (overlay && overlay.__vueParentComponent) {
          let comp = overlay.__vueParentComponent;
          while (comp) {
            const ai = comp.data && comp.data.activeItem;
            if (ai) {
              activeItemAllergens = Array.isArray(ai.allergens)
                ? ai.allergens.map((a) => a.code || a)
                : null;
              activeItemName = ai.name || null;
              break;
            }
            comp = comp.parent;
          }
        }
      } catch (_) {}

      return {
        found: true,
        url: location.href,
        role: wizardRoot.getAttribute('role'),
        ariaModal: wizardRoot.getAttribute('aria-modal'),
        ariaLabel: wizardRoot.getAttribute('aria-label'),
        ariaLabelledby: wizardRoot.getAttribute('aria-labelledby'),
        hasH1: !!wizardRoot.querySelector('h1'),
        hasFocusTrap: typeof wizardRoot._focusTrap !== 'undefined',
        activeElInside: wizardRoot.contains(document.activeElement),
        activeTag: document.activeElement?.tagName || null,
        activeId: document.activeElement?.id || null,
        // Allergens probe (AK1)
        hasAllergenBadge: !!wizardRoot.querySelector('[data-testid="kiosk-wizard-header-allergens"], .kiosk-wizard-header-allergens'),
        allergenVisible: (() => {
          const al = wizardRoot.querySelector('[data-testid="kiosk-wizard-header-allergens"], .kiosk-wizard-header-allergens');
          if (!al) return false;
          const r = al.getBoundingClientRect();
          return r.width > 0 && r.height > 0;
        })(),
        activeItemAllergens,
        activeItemName,
      };
    });

    recordDom('05-wizard', probe);

    if (!probe.found) {
      record(
        5,
        'WK-WIZARD-NOT-OPENED',
        'INFO',
        `Wizard non ouvert. Catalog peut nécessiter direct-add. URL=${page.url()}`,
        shot
      );
      return;
    }

    // WK1 — role missing
    if (probe.role !== 'dialog') {
      record(
        5,
        'WK1-NO-ROLE-DIALOG',
        'P0',
        `KioskWizardComponent.vue:2 root .kiosk-wizard n'a PAS role="dialog". role=${probe.role}. Équivalent au W1 fixé sur POS R1. Screen-reader ne signale pas l'overlay modal.`,
        shot
      );
    } else {
      record(5, 'WK1-OK', 'OK', `role=dialog présent`, shot);
    }

    // WK2 — aria-modal missing
    if (probe.ariaModal !== 'true') {
      record(
        5,
        'WK2-NO-ARIA-MODAL',
        'P0',
        `Root wizard n'a PAS aria-modal="true" (=${probe.ariaModal}). Équivalent W2 POS R1. Screen-reader peut atteindre éléments background.`,
        shot
      );
    } else {
      record(5, 'WK2-OK', 'OK', `aria-modal=true`, shot);
    }

    // WK3 — aria-labelledby / aria-label missing
    if (!probe.ariaLabel && !probe.ariaLabelledby) {
      record(
        5,
        'WK3-NO-ARIA-LABEL',
        'P1',
        `Root wizard n'a ni aria-label ni aria-labelledby. Screen-reader annonce un dialog anonyme.`,
        shot
      );
    } else {
      record(5, 'WK3-OK', 'OK', `aria-label=${probe.ariaLabel} / labelledby=${probe.ariaLabelledby}`, shot);
    }

    // Focus trap probe : the wizard renders inside an overlay. We expect
    // the wizard root to be the focus boundary.
    // First, make sure something inside the wizard is focused.
    await page.evaluate(() => {
      const wiz = document.querySelector('.kiosk-wizard-overlay .kiosk-wizard') || document.querySelector('.kiosk-wizard');
      const btn = wiz && wiz.querySelector('button:not([disabled])');
      if (btn) btn.focus();
    });
    // Tab around — if focus-trap is wired to wizard root, we should never escape.
    for (let t = 0; t < 25; t++) {
      await page.keyboard.press('Tab').catch(() => {});
    }
    const focusAfterTab = await page.evaluate(() => {
      const wizardRoot = document.querySelector('.kiosk-wizard-overlay .kiosk-wizard') || document.querySelector('.kiosk-wizard');
      return {
        activeInside: wizardRoot ? wizardRoot.contains(document.activeElement) : false,
        activeTag: document.activeElement?.tagName,
        activeText: (document.activeElement?.textContent || '').trim().slice(0, 50),
        activeOutsideClass: !wizardRoot?.contains(document.activeElement) ? document.activeElement?.className : null,
      };
    });
    const focusShot = await snap(page, '05b-wizard-focus-escaped');
    recordDom('05-wizard-focus-trap', focusAfterTab);
    if (!focusAfterTab.activeInside) {
      record(
        5,
        'WK4-NO-FOCUS-TRAP',
        'P0',
        `Après 25 Tabs, le focus a échappé du wizard (activeTag=${focusAfterTab.activeTag}, text="${focusAfterTab.activeText}", outsideClass="${focusAfterTab.activeOutsideClass}"). Le focus-trap n'est wired qu'à showAbandonConfirm (KioskWizardComponent.vue:2030-2068), pas au wizard root. Équivalent W4 POS R1. EAA 2025 — utilisateur clavier/screen-reader peut atteindre boutons d'arrière-plan (cart, abandon).`,
        focusShot
      );
    } else {
      record(
        5,
        'WK4-FOCUS-INSIDE',
        'OK',
        `Focus reste dans wizard après 25 Tabs (activeTag=${focusAfterTab.activeTag}). Focus-trap implicite.`,
        focusShot
      );
    }

    // AK1 — allergens visible mid-wizard
    // KsAllergenBadge.vue:3 has v-if="visibleAllergens.length > 0" — invisible
    // when product has no allergens declared. So "absent" only matters when
    // the product DOES carry allergen data.
    const hasAllergenData =
      Array.isArray(probe.activeItemAllergens) && probe.activeItemAllergens.length > 0;
    if (!probe.hasAllergenBadge && hasAllergenData) {
      record(
        5,
        'AK1-NO-ALLERGEN-BADGE',
        'P0',
        `Item "${probe.activeItemName}" a allergens=${JSON.stringify(probe.activeItemAllergens)} mais badge KsAllergenBadge ABSENT — VIOLATION EAA 2025 / FIC UE 1169.`,
        shot
      );
    } else if (probe.hasAllergenBadge && !probe.allergenVisible) {
      record(
        5,
        'AK1-ALLERGEN-BADGE-HIDDEN',
        'P0',
        `Badge allergens présent dans DOM mais display:none / 0×0 — invisible mid-wizard.`,
        shot
      );
    } else if (!hasAllergenData) {
      record(
        5,
        'AK1-NO-ALLERGEN-DATA',
        'P2',
        `Item "${probe.activeItemName}" : aucune donnée allergen (Array vide ou null). Badge correctement caché — MAIS le client n'a aucun moyen de vérifier "ce produit est sans allergènes" vs "données manquantes". UX risque pour PMR.`,
        shot
      );
    } else {
      record(
        5,
        'AK1-ALLERGEN-OK',
        'OK',
        `Badge allergens visible dans header wizard pour "${probe.activeItemName}" (codes=${JSON.stringify(probe.activeItemAllergens)}). RÉFUTÉ.`,
        shot
      );
    }
  });

  // ------------------------------------------------------------------
  // STEP 06 — Wizard CSS Fraunces probe
  // ------------------------------------------------------------------
  test('06 — Wizard typography & DS V1 Bold (Fraunces face really loaded?)', async ({
    page,
  }) => {
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    await startOrderFromIdle(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);

    // Open wizard via overlay (inline, see KioskCategoriesComponent.vue:278)
    const cats = page.locator('[data-testid^="kiosk-categories-sidebar-item-"]');
    const catCount = await cats.count();
    outer: for (let c = 0; c < Math.min(catCount, 8); c++) {
      await cats.nth(c).click({ timeout: 2_500 }).catch(() => {});
      await page.waitForTimeout(1_200);
      const items = page.locator('[data-testid^="kiosk-product-card-"]');
      const itemCount = await items.count();
      for (let i = 0; i < Math.min(itemCount, 5); i++) {
        const card = items.nth(i);
        const addBtn = card.locator('[data-testid^="kiosk-product-add-"]').first();
        if (await addBtn.isVisible({ timeout: 600 }).catch(() => false)) {
          await addBtn.click({ timeout: 2_500 }).catch(() => {});
        } else {
          await card.click({ timeout: 2_500 }).catch(() => {});
        }
        await page.waitForTimeout(1_400);
        const opened = await page.evaluate(() => !!document.querySelector('.kiosk-wizard-overlay .kiosk-wizard'));
        if (opened) break outer;
      }
    }

    const shot = await snap(page, '06-wizard-typography');
    const fontProbe = await page.evaluate(() => {
      const fontReady = document.fonts && document.fonts.check
        ? {
            fraunces: document.fonts.check('1em Fraunces'),
            frauncesBold: document.fonts.check('700 1em Fraunces'),
            frauncesItalic: document.fonts.check('italic 1em Fraunces'),
          }
        : 'no-fonts-api';

      const sample = document.querySelector('.kiosk-item-name, .kiosk-step-question, .kiosk-wizard h2, .kiosk-wizard h1');
      let computed = null;
      if (sample) {
        const cs = getComputedStyle(sample);
        computed = {
          fontFamily: cs.fontFamily,
          fontSize: cs.fontSize,
          fontWeight: cs.fontWeight,
          // Resolve actual face used
          actualFont: 'unresolved-without-document.fonts',
        };
      }

      // Check tokens-bold.css loaded via stylesheet enumeration
      let tokensBoldLoaded = false;
      try {
        for (const ss of [...document.styleSheets]) {
          if (ss.href && /tokens-bold\.css|app\.css/.test(ss.href)) {
            tokensBoldLoaded = true;
            break;
          }
        }
      } catch (_) {}

      // Read --kiosk-font-display CSS variable
      const cssVar = getComputedStyle(document.documentElement).getPropertyValue('--kiosk-font-display');

      return { fontReady, computed, tokensBoldLoaded, cssVar: cssVar.trim() };
    });

    recordDom('06-fonts', fontProbe);

    if (fontProbe.fontReady && fontProbe.fontReady.fraunces === false) {
      record(
        6,
        'DSK1-FRAUNCES-NOT-LOADED',
        'P1',
        `document.fonts.check('1em Fraunces')=false — la police n'est pas réellement chargée, fallback Georgia/serif. Le DS V1 Bold est dégradé visuellement.`,
        shot
      );
    } else if (fontProbe.fontReady && fontProbe.fontReady.fraunces === true) {
      record(
        6,
        'DSK1-FRAUNCES-LOADED',
        'OK',
        `Fraunces face loaded — bold=${fontProbe.fontReady.frauncesBold}, italic=${fontProbe.fontReady.frauncesItalic}.`,
        shot
      );
    }

    if (!fontProbe.cssVar || !/Fraunces/i.test(fontProbe.cssVar)) {
      record(
        6,
        'DSK1-CSS-VAR-MISSING',
        'P2',
        `--kiosk-font-display ne contient pas Fraunces : "${fontProbe.cssVar}". Tokens-bold.css peut-être pas appliqué sur cette surface.`,
        shot
      );
    }
  });

  // ------------------------------------------------------------------
  // STEP 07 — Cart surface
  // ------------------------------------------------------------------
  test('07 — Cart surface (seeded items, edit/quantity/total)', async ({
    page,
  }) => {
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    await startOrderFromIdle(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    await seedKioskCart(page);
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);

    const shot = await snap(page, '07-cart');
    const probe = await page.evaluate(() => {
      const root = document.querySelector('[data-testid="kiosk-cart-root"], .kiosk-cart');
      const items = document.querySelectorAll('[data-testid*="kiosk-cart-item"], .kiosk-cart-item');
      const total = document.querySelector('[data-testid="kiosk-cart-total"], .kiosk-cart-total');
      return {
        rootFound: !!root,
        itemCount: items.length,
        totalText: total ? total.textContent.trim().slice(0, 60) : null,
        url: location.href,
        // aria-live on summary
        summaryLive: document.querySelector('[data-testid="kiosk-cart-summary"]')?.getAttribute('aria-live'),
      };
    });

    recordDom('07-cart', probe);

    if (!probe.rootFound) {
      record(
        7,
        'CART-NOT-RENDERED',
        'P1',
        `kiosk-cart-root absent — surface cart probablement vide ou seed loupé.`,
        shot
      );
    } else if (probe.itemCount === 0) {
      record(
        7,
        'CART-EMPTY-AFTER-SEED',
        'P2',
        `Cart rendu mais 0 items après seed Vuex — réactivité Vue cassée ou store path différent.`,
        shot
      );
    } else {
      record(
        7,
        'CART-OK',
        'OK',
        `Cart rendu : ${probe.itemCount} items, total=${probe.totalText}, ariaLive=${probe.summaryLive}`,
        shot
      );
    }

    if (probe.summaryLive !== 'polite' && probe.summaryLive !== 'assertive') {
      record(
        7,
        'CART-NO-ARIA-LIVE',
        'P2',
        `kiosk-cart-summary sans aria-live (=${probe.summaryLive}). Total ne sera pas annoncé au screen-reader.`,
        shot
      );
    }
  });

  // ------------------------------------------------------------------
  // STEP 08 — Payment surface (variants TPE / cash)
  // ------------------------------------------------------------------
  test('08 — Payment surface variants (card / cash / tr) + offline banner', async ({
    page,
  }) => {
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    await startOrderFromIdle(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    await seedKioskCart(page);
    await page.goto('/kiosk/payment', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);

    const shot = await snap(page, '08-payment');
    const probe = await page.evaluate(() => {
      const url = location.href;
      const buttons = [...document.querySelectorAll('button')]
        .map((b) => b.textContent.trim())
        .filter((t) => t.length > 0 && t.length < 80);
      const offlineBanner = document.querySelector(
        '[data-testid="kiosk-offline-banner"], .kiosk-offline-banner, [data-testid*="offline"]'
      );
      const navOnline = navigator.onLine;
      // Method buttons
      const methodBtns = [...document.querySelectorAll('[data-testid*="payment-method"], [data-method]')]
        .map((b) => ({
          method: b.getAttribute('data-method') || b.getAttribute('data-testid'),
          text: b.textContent.trim().slice(0, 30),
          disabled: b.disabled,
        }));
      return {
        url,
        buttonCount: buttons.length,
        buttonsSample: buttons.slice(0, 12),
        navOnline,
        offlineBannerVisible: !!offlineBanner && offlineBanner.offsetParent !== null,
        methodBtns,
      };
    });

    recordDom('08-payment', probe);

    if (probe.methodBtns.length === 0) {
      record(
        8,
        'PAY-NO-METHOD-BTNS',
        'P2',
        `Pas de boutons data-testid/data-method détectés. Méthodes : ${probe.buttonsSample.join(' | ')}`,
        shot
      );
    } else {
      record(
        8,
        'PAY-METHODS',
        'OK',
        `${probe.methodBtns.length} méthodes : ${probe.methodBtns.map((m) => m.method).join(', ')}`,
        shot
      );
    }
  });

  // ------------------------------------------------------------------
  // STEP 09 — Auto-return / inactivity
  // ------------------------------------------------------------------
  test('09 — Inactivity auto-return (TK1)', async ({ page }) => {
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1_500);
    await startOrderFromIdle(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);

    const probe = await page.evaluate(() => {
      let store = null;
      const el = document.querySelector('#app');
      const inst = el && (el.__vue_app__ || el.__vueParentComponent);
      if (inst && inst.config && inst.config.globalProperties) {
        store = inst.config.globalProperties.$store;
      }
      const settings = store?.state?.kioskSettings || null;
      return {
        idleMs: settings?.idleMs ?? null,
        confirmMs: settings?.confirmMs ?? null,
        keys: settings ? Object.keys(settings) : [],
      };
    });

    recordDom('09-inactivity-config', probe);

    const shot = await snap(page, '09-inactivity');

    if (probe.idleMs === null) {
      record(
        9,
        'TK1-NO-IDLE-CONFIG',
        'P2',
        `kioskSettings.idleMs absent — fallback IDLE_FALLBACK_MS utilisé. Config kiosk peut être incomplète.`,
        shot
      );
    } else if (probe.idleMs > 180_000) {
      record(
        9,
        'TK1-IDLE-TOO-LONG',
        'P2',
        `idleMs=${probe.idleMs}ms (>3min). Trop long pour kiosk self-service — clients bloqués perdus longtemps.`,
        shot
      );
    } else {
      record(
        9,
        'TK1-IDLE-OK',
        'OK',
        `idleMs=${probe.idleMs}ms, confirmMs=${probe.confirmMs}ms.`,
        shot
      );
    }
  });

  // ------------------------------------------------------------------
  // STEP 10 — Offline queue (OK1)
  // ------------------------------------------------------------------
  test('10 — Offline queue probe (OK1 — kioskOfflineQueue)', async ({
    page,
    context,
  }) => {
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1_500);
    await startOrderFromIdle(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);

    // Probe the helper API surface from the page itself
    const apiProbe = await page.evaluate(async () => {
      // The helper is bundled — try to access via a known Vuex action or analytics
      // We check kioskCart store for offline-related state
      let store = null;
      const el = document.querySelector('#app');
      const inst = el && (el.__vue_app__ || el.__vueParentComponent);
      if (inst && inst.config && inst.config.globalProperties) {
        store = inst.config.globalProperties.$store;
      }
      const cart = store?.state?.kioskCart;
      const machine = store?.state?.kioskMachine;
      return {
        navOnline: navigator.onLine,
        cartKeys: cart ? Object.keys(cart) : [],
        offlineRelated: cart
          ? Object.keys(cart).filter((k) => /offline|queue|pending|stale/i.test(k))
          : [],
        machineKeys: machine ? Object.keys(machine) : [],
        // BroadcastChannel feature detection
        bcSupported: typeof BroadcastChannel !== 'undefined',
        idbSupported: typeof indexedDB !== 'undefined',
      };
    });

    recordDom('10-offline-api', apiProbe);

    // Now go offline and observe behaviour
    await context.setOffline(true);
    await page.waitForTimeout(2_500);
    const offlineProbe = await page.evaluate(() => ({
      navOnline: navigator.onLine,
      offlineBanner: !!document.querySelector(
        '[data-testid="kiosk-offline-banner"], .kiosk-offline-banner, [data-testid*="network"], [data-testid*="offline"]'
      ),
      bodyClassOffline: /offline/.test(document.body.className),
      visibleErrorScreen: document.querySelector('.kiosk-error-network, [data-testid*="error-network"]')?.offsetParent != null,
    }));
    const shotOffline = await snap(page, '10-offline-mode');
    recordDom('10-offline-state', offlineProbe);

    if (!offlineProbe.offlineBanner && !offlineProbe.bodyClassOffline && !offlineProbe.visibleErrorScreen) {
      record(
        10,
        'OK1-NO-OFFLINE-INDICATOR',
        'P1',
        `Mode offline activé via context.setOffline(true) mais AUCUN indicateur UI visible (banner, body class, error screen). Le client ne sait pas que le kiosk est offline.`,
        shotOffline
      );
    } else {
      record(
        10,
        'OK1-OFFLINE-INDICATOR',
        'OK',
        `Indicateur offline visible : banner=${offlineProbe.offlineBanner}, errorScreen=${offlineProbe.visibleErrorScreen}.`,
        shotOffline
      );
    }

    await context.setOffline(false);
    await page.waitForTimeout(1_500);
  });

  // ------------------------------------------------------------------
  // STEP 11 — Pusher Echo connection state
  // ------------------------------------------------------------------
  test('11 — Pusher Echo connection state probe', async ({ page }) => {
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1_500);
    await startOrderFromIdle(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);

    const probe = await page.evaluate(() => {
      const echo = window.Echo || null;
      const pusher = echo?.connector?.pusher || null;
      return {
        echoPresent: !!echo,
        pusherPresent: !!pusher,
        connectionState: pusher?.connection?.state || null,
        connectionKey: pusher?.key || null,
        // Connection lost banner detection
        connectionBanner: !!document.querySelector(
          '[data-testid*="connection-lost"], [data-testid*="pusher-disconnected"], .kiosk-connection-lost'
        ),
      };
    });

    const shot = await snap(page, '11-pusher');
    recordDom('11-pusher', probe);

    if (!probe.echoPresent) {
      record(
        11,
        'PUSHER-NO-ECHO',
        'P2',
        `window.Echo absent — kiosk ne reçoit pas events real-time (KDS, status order, menu invalidation).`,
        shot
      );
    } else if (probe.connectionState !== 'connected') {
      record(
        11,
        'PUSHER-NOT-CONNECTED',
        'P1',
        `Echo présent mais state=${probe.connectionState} (≠connected). Pas de banner UI signalant le problème.`,
        shot
      );
    } else {
      record(
        11,
        'PUSHER-CONNECTED',
        'OK',
        `Echo connecté (key=${String(probe.connectionKey).slice(0, 8)}...).`,
        shot
      );
    }
  });

  // ------------------------------------------------------------------
  // STEP 12 — Branch isolation probe (BK1)
  // ------------------------------------------------------------------
  test('12 — Branch isolation probe (BK1 — token kiosk branch lock)', async ({
    page,
  }) => {
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1_500);
    await startOrderFromIdle(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);

    // Probe the abilities of the current token
    const probe = await page.evaluate(async () => {
      let store = null;
      const el = document.querySelector('#app');
      const inst = el && (el.__vue_app__ || el.__vueParentComponent);
      if (inst && inst.config && inst.config.globalProperties) {
        store = inst.config.globalProperties.$store;
      }
      const machine = store?.state?.kioskMachine;
      const cart = store?.state?.kioskCart;
      // Try to call API for menu of branch 1 vs branch 2 if axios exposed
      let menuRes1 = null;
      let menuRes2 = null;
      try {
        const axios = window.axios;
        if (axios) {
          // Default kiosk menu call (uses token, branch is server-derived)
          const r1 = await axios.get('/api/frontend/menu').catch((e) => ({ status: e.response?.status, data: e.response?.data }));
          menuRes1 = { status: r1.status, branchId: r1.data?.branch_id || r1.data?.meta?.branch_id || null };
          // Try forcing branch_id=2 via query
          const r2 = await axios.get('/api/frontend/menu?branch_id=2').catch((e) => ({ status: e.response?.status, data: e.response?.data }));
          menuRes2 = { status: r2.status, branchId: r2.data?.branch_id || r2.data?.meta?.branch_id || null };
        }
      } catch (_) {}
      return {
        machineBranchId: machine?.branchId || machine?.branch_id || null,
        cartBranchId: cart?.branchId || cart?.branch_id || null,
        menuRes1,
        menuRes2,
      };
    });

    const shot = await snap(page, '12-branch-isolation');
    recordDom('12-branch-isolation', probe);

    // If forcing branch_id=2 via query gives back menu of branch 2, that's a leak
    if (
      probe.menuRes2 &&
      probe.menuRes2.status === 200 &&
      probe.menuRes2.branchId &&
      probe.menuRes1?.branchId &&
      probe.menuRes2.branchId !== probe.menuRes1.branchId
    ) {
      record(
        12,
        'BK1-BRANCH-ISOLATION-LEAK',
        'P0',
        `?branch_id=2 retourne menu d'une branch différente (${probe.menuRes2.branchId}) que la borne (${probe.menuRes1.branchId}). Une borne pourrait commander dans une autre branch.`,
        shot
      );
    } else if (
      probe.menuRes1?.branchId &&
      probe.menuRes2?.branchId &&
      probe.menuRes1.branchId === probe.menuRes2.branchId
    ) {
      record(
        12,
        'BK1-BRANCH-ISOLATION-OK',
        'OK',
        `?branch_id=2 ignoré, branch reste ${probe.menuRes1.branchId} (RÉFUTÉ).`,
        shot
      );
    } else {
      record(
        12,
        'BK1-NON-VALIDÉ',
        'INFO',
        `Probe non concluant : menuRes1=${JSON.stringify(probe.menuRes1)} menuRes2=${JSON.stringify(probe.menuRes2)}. Nécessite seconde borne réelle (DEMO=true requis pour gulshan1).`,
        shot
      );
    }
  });

  // ------------------------------------------------------------------
  // STEP 13 — Lockdown / black-screen guard (BK1, limitations)
  // ------------------------------------------------------------------
  test('13 — Lockdown probes (escape, contextmenu, devtools, navigation)', async ({
    page,
  }) => {
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1_500);
    await startOrderFromIdle(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);

    // Try to navigate to admin via URL — kiosk shouldn't allow it
    let navAdminBlocked = true;
    let navLandingUrl = null;
    try {
      await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded', timeout: 10_000 });
      await page.waitForTimeout(1_500);
      navLandingUrl = page.url();
      // If admin loaded, this is a leak
      navAdminBlocked = !/\/admin\/dashboard/.test(navLandingUrl) || !!document.querySelector;
    } catch (_) {}

    // Try right-click context menu
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1_500);
    const contextMenuProbe = await page.evaluate(() => {
      // Trigger contextmenu programmatically
      let prevented = false;
      const handler = (e) => {
        prevented = e.defaultPrevented;
      };
      document.addEventListener('contextmenu', handler, { once: true });
      const evt = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
      document.body.dispatchEvent(evt);
      return { prevented };
    });

    const shot = await snap(page, '13-lockdown');
    recordDom('13-lockdown', {
      navAdminBlocked,
      navLandingUrl,
      contextMenu: contextMenuProbe,
      note: 'Black-screen / Chromium kiosk flag = OS-level, non testable depuis la page (limitation honnête).',
    });

    if (navLandingUrl && /\/admin\//.test(navLandingUrl)) {
      record(
        13,
        'BK1-ADMIN-NAV-LEAK',
        'P1',
        `Navigation directe vers /admin/dashboard depuis kiosk URL (${navLandingUrl}) — un client pourrait taper l'URL si clavier physique branché ou si on-screen keyboard non désactivé.`,
        shot
      );
    } else {
      record(
        13,
        'BK1-ADMIN-NAV-OK',
        'INFO',
        `Navigation /admin/* depuis kiosk redirige (URL finale=${navLandingUrl}).`,
        shot
      );
    }

    record(
      13,
      'BK1-OS-LEVEL-GUARD',
      'INFO',
      `Black-screen guard / Chromium --kiosk flag = OS-level, non testable depuis Playwright (limitation honnête). L'URL reste accessible via clavier physique.`,
      shot
    );
  });

  // ------------------------------------------------------------------
  // STEP 14 — Confirmation / receipt screen
  // ------------------------------------------------------------------
  test('14 — Confirmation screen + auto-return timer', async ({ page }) => {
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    await startOrderFromIdle(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    await seedKioskCart(page);

    // Force orderRef to allow confirmation guard to pass
    await page.evaluate(() => {
      let store = null;
      const el = document.querySelector('#app');
      const inst = el && (el.__vue_app__ || el.__vueParentComponent);
      if (inst && inst.config && inst.config.globalProperties) {
        store = inst.config.globalProperties.$store;
      }
      const cs = store?.state?.kioskCart;
      if (cs) cs.orderRef = '9999';
    });

    await page.goto('/kiosk/confirmation?number=A042&total=22.00', {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(2_500);

    const shot = await snap(page, '14-confirmation');
    const probe = await page.evaluate(() => {
      const url = location.href;
      const queueNum = document.querySelector(
        '[data-testid="kiosk-confirmation-queue"], .kiosk-confirmation-queue, [data-testid*="queue-number"]'
      )?.textContent?.trim();
      const total = document.querySelector(
        '[data-testid="kiosk-confirmation-total"], .kiosk-confirmation-total'
      )?.textContent?.trim();
      const countdown = document.querySelector(
        '[data-testid*="countdown"], [data-testid*="auto-return"]'
      )?.textContent?.trim();
      return { url, queueNum, total, countdown, bodyText: document.body.innerText.slice(0, 200) };
    });

    recordDom('14-confirmation', probe);

    if (!probe.queueNum) {
      record(
        14,
        'CONF-NO-QUEUE',
        'P2',
        `Pas de numéro de file affiché sur confirmation (probe testid manquant). Body text : ${probe.bodyText}`,
        shot
      );
    } else {
      record(
        14,
        'CONF-OK',
        'OK',
        `Confirmation OK : queue=${probe.queueNum}, total=${probe.total}, countdown=${probe.countdown}`,
        shot
      );
    }
  });

  // ------------------------------------------------------------------
  // STEP 15 — Final synthesis
  // ------------------------------------------------------------------
  test('15 — Final synthesis (counts + dump)', async ({ page }) => {
    const counts = findings.reduce((acc, f) => {
      acc[f.severity] = (acc[f.severity] || 0) + 1;
      return acc;
    }, {});

    fs.writeFileSync(
      path.join(SHOT_DIR, 'synthesis.json'),
      JSON.stringify(
        {
          totals: counts,
          totalFindings: findings.length,
          ts: new Date().toISOString(),
          spec: 'red-team-r2-kiosk-prise-commande-2026-05-07',
        },
        null,
        2
      )
    );

    record(
      15,
      'SYNTHESIS',
      'INFO',
      `Synthese : ${JSON.stringify(counts)} sur ${findings.length} findings.`,
      null
    );

    expect(findings.length).toBeGreaterThan(10);
  });
});
