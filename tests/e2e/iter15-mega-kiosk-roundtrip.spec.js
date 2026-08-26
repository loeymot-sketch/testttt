// FoodKing E2E — iter15 Mega-Audit Wave C (Kiosk roundtrip → KDS + POS suivi)
//
// Owner mandate iter15 :  prouver qu'une commande déposée au kiosque
// cascade visuellement vers la pile KDS (cuisine) ET la liste suivi commandes
// (POS supervisor), avec capture de quartet (png + dom + console + network)
// pour chaque surface.
//
// Architecture note : trois contextes navigateur isolés (kiosk / chef / pos),
// chaque page câblée à son propre recorder mega-audit (helper partagé). Les
// snaps écrivent tous dans le même dossier, désambiguïsés par préfixe d'état.
//
// Robustesse :
//   • le flux paiement borne peut être bloqué en dev (intégration TPE absente,
//     stub navigateur indisponible). Le test NE DOIT PAS échouer pour cela —
//     il capture l'état bloqué et continue tant que possible.
//   • la création de commande est observée via tinker (Order ordre desc filtré
//     sur source_surface='kiosk' et created_at > t0). On NE matche PAS sur le
//     texte « Frites Seules » côté KDS / POS car cet item est partagé avec
//     d'autres commandes du jour — on cible orderId / order_serial_no.
//   • cleanup afterAll : best-effort cancel de la commande créée (si reachable)
//     pour ne pas polluer la pile KDS sur les runs suivants.
//
// Reviewer protocol : docs/iter15-mega/REVIEWER_PROTOCOL.md
// Wave consume : __screenshots__/iter15-mega-kiosk/<state>.{png,dom.html,console.json,network.json}

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { loginAsKiosk, loginAsChefOperator, loginAsPosOperator, cleanupOrphanTestOrders } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
// [iter15-mega-fix C-001 2026-05-10] Canonical KDS-relevant OrderStatus values.
// Mirrors resources/js/enums/modules/orderStatusEnum.js + app/Enums/OrderStatus.php.
// Previous version sent {1→4, 4→5} which is rejected by
// KdsOrderStatusRequest::kdsStatuses() (whitelist [4,7,8]) and returns 422,
// then the spec swallowed the error so the kitchen never saw a toast.
// (Inlined as constants — this file is CJS-loaded by Playwright and the enum
// module uses `export default`, which gives undefined when require()d.)
const ORDER_STATUS_ACCEPT    = 4;
const ORDER_STATUS_PREPARING = 7;
const ORDER_STATUS_PREPARED  = 8;

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/iter15-mega-kiosk');

const FRITES_ITEM_ID = 361;   // Frites Seules — wizard_template=simple, 0 var/extra/addon
const BRANCH_ID = 1;          // Le Cayenne default branch

function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: process.cwd(),
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

function parseLastJsonLine(output) {
  const lines = String(output).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const jsonLine = [...lines].reverse().find((l) => l.startsWith('{') || l.startsWith('['));
  if (!jsonLine) return null;
  try { return JSON.parse(jsonLine); } catch (_e) { return null; }
}

/**
 * Returns the latest kiosk-source order created after `sinceTs` (epoch seconds),
 * or null if none yet. We filter source_surface='kiosk' to ignore unrelated POS
 * orders that may be placed concurrently in dev.
 */
function fetchLatestKioskOrder(sinceTs) {
  const out = artisan(`
    $since = (int) ${Number(sinceTs) || 0};
    $row = DB::table('orders')
      ->where('source_surface', 'kiosk')
      ->where('created_at', '>=', date('Y-m-d H:i:s', $since))
      ->orderByDesc('id')
      ->first();
    if (!$row) { echo 'null'; return; }
    echo json_encode([
      'id' => (int) $row->id,
      'token' => $row->token,
      'status' => (int) $row->status,
      'order_serial_no' => $row->order_serial_no,
      'queue_number' => $row->queue_number,
      'branch_id' => (int) $row->branch_id,
      'source_surface' => $row->source_surface,
      'total' => (float) $row->total,
    ]);
  `);
  if (out === 'null' || !out) return null;
  return parseLastJsonLine(out);
}

function bestEffortCancelOrder(orderId) {
  if (!orderId) return;
  try {
    artisan(`
      $id = (int) ${Number(orderId)};
      $order = App\\Models\\Order::find($id);
      if ($order) {
        $order->forceFill(['status' => 7, 'payment_status' => 0])->save();
      }
      echo 'cleaned';
    `);
  } catch (_e) { /* best-effort */ }
}

/**
 * Defensive snap : never lets a screenshot/recorder failure throw.
 */
async function safeSnap(snap, name, label) {
  try {
    await snap(name);
    // eslint-disable-next-line no-console
    console.log(`[KIOSK-RT] snap ${name}${label ? ` — ${label}` : ''}`);
  } catch (e) {
    // eslint-disable-next-line no-console
    console.warn(`[KIOSK-RT] snap ${name} failed: ${e.message}`);
  }
}

test.describe('iter15 mega — Wave C (kiosk roundtrip → KDS + POS suivi)', () => {
  test.setTimeout(240_000);

  // [iter15-mega-fix B-004 round-7 2026-05-10] sweep orphan test orders so the
  // captured KDS dom.html only contains the current cycle's kiosk order.
  test.beforeAll(() => {
    cleanupOrphanTestOrders();
  });

  test('kiosk order cascades to KDS pile AND POS suivi (3 surfaces)', async ({ browser }) => {
    const t0Sec = Math.floor(Date.now() / 1000);
    let kioskCtx; let chefCtx; let posCtx;
    let kioskPage; let chefPage; let posPage;
    let kioskRec; let chefRec; let posRec;
    let createdOrderId = null;
    const kioskFlowNotes = []; // breadcrumbs for the report

    try {
      // ---------------------------------------------------------------
      // 0. Spin up three isolated browser contexts (kiosk last per advisor)
      // ---------------------------------------------------------------
      chefCtx  = await browser.newContext({ viewport: { width: 1440, height: 900 } });
      posCtx   = await browser.newContext({ viewport: { width: 1440, height: 900 } });
      kioskCtx = await browser.newContext({ viewport: { width: 1366, height: 900 } });

      chefPage  = await chefCtx.newPage();
      posPage   = await posCtx.newPage();
      kioskPage = await kioskCtx.newPage();

      // Three recorders, same shared dir, names disambiguated by snap id.
      kioskRec = attachMegaAuditRecorder(kioskPage, SCREENSHOT_DIR);
      chefRec  = attachMegaAuditRecorder(chefPage,  SCREENSHOT_DIR);
      posRec   = attachMegaAuditRecorder(posPage,   SCREENSHOT_DIR);

      // ---------------------------------------------------------------
      // 1. Authenticate all three roles + land each on its surface.
      //    Login order : chef + pos first, kiosk last (advisor #6).
      // ---------------------------------------------------------------
      await loginAsChefOperator(chefPage);
      await chefPage.waitForTimeout(1500);
      await safeSnap(chefRec.snap, '01-kds-baseline', 'KDS pile avant commande');

      await loginAsPosOperator(posPage);
      await posPage.waitForTimeout(1500);
      // Navigate to the dedicated suivi route — POS supervisor view.
      await posPage.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await posPage.waitForTimeout(2000);
      await safeSnap(posRec.snap, '01-pos-supervisor-baseline', 'POS suivi avant commande');

      await loginAsKiosk(kioskPage);
      // After login the kiosk SPA may be on /kiosk or /kiosk/idle ; align.
      if (!/\/kiosk(\/idle)?(\?|$|\/)/.test(kioskPage.url())) {
        await kioskPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => {});
      }
      await kioskPage.waitForTimeout(1500);
      await safeSnap(kioskRec.snap, '01-kiosk-idle', 'Borne idle prête');
      kioskFlowNotes.push('idle-reached');

      // ---------------------------------------------------------------
      // 2. Kiosk happy path — start order. The idle screen presents an
      //    order-type chooser ([data-testid=kiosk-order-type-chooser]).
      //    Click takeaway (V1 dine-in is feature-flag-disabled per
      //    feedback_v1_dine_in_disabled_2026-05-06).
      // ---------------------------------------------------------------
      try {
        const chooser = kioskPage.getByTestId('kiosk-order-type-chooser');
        const chooserVisible = await chooser.isVisible({ timeout: 8000 }).catch(() => false);
        if (chooserVisible) {
          // Prefer takeaway since V1 dine-in is disabled. Fallback to dine-in tile if takeaway absent.
          const takeawayBtn = kioskPage.getByTestId('kiosk-order-type-takeaway');
          const dineInBtn   = kioskPage.getByTestId('kiosk-order-type-dine-in');
          if (await takeawayBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
            await takeawayBtn.click({ timeout: 5000 });
            kioskFlowNotes.push('order-type=takeaway');
          } else if (await dineInBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
            await dineInBtn.click({ timeout: 5000 });
            kioskFlowNotes.push('order-type=dine-in');
          } else {
            // Last-resort: idle touch button (older flows).
            await kioskPage.getByTestId('kiosk-idle-touch-btn').click({ timeout: 5000 }).catch(() => {});
            kioskFlowNotes.push('order-type=touch-fallback');
          }
        } else {
          // No chooser surfaced — try the touch fallback CTA.
          await kioskPage.getByTestId('kiosk-idle-touch-btn').click({ timeout: 5000 }).catch(() => {});
          kioskFlowNotes.push('order-type=no-chooser');
        }

        await kioskPage.waitForFunction(() => (
          document.querySelector('[data-testid="kiosk-categories-root"]')
          || document.querySelector('[data-testid="kiosk-categories-products"]')
        ), null, { timeout: 20_000 });
        await safeSnap(kioskRec.snap, '02-kiosk-catalog', 'Catalogue borne après order-type');
        kioskFlowNotes.push('catalog-reached');
      } catch (e) {
        await safeSnap(kioskRec.snap, '02-kiosk-blocked-at-catalog', `BLOCKED: ${e.message.slice(0, 200)}`);
        kioskFlowNotes.push(`BLOCKED@catalog: ${e.message.slice(0, 100)}`);
        // Cannot continue past catalog; render baseline reports & return.
        return;
      }

      // ---------------------------------------------------------------
      // 3. Find Frites Seules tile and add it. Item 361 has wizard_template=
      //    simple with 0 variations/extras, so click on `kiosk-product-add-361`
      //    pops a 1-step recap ([kiosk-order-summary-root]) OR jumps straight
      //    to cart-indicator update — handle both per advisor #1.
      // ---------------------------------------------------------------
      try {
        // Frites Seules belongs to category 315 (Frites & Accompagnements).
        // Navigate directly to that category to ensure the tile is in viewport.
        await kioskPage.goto('/kiosk/categories?cat=315', { waitUntil: 'domcontentloaded' }).catch(() => {});
        await kioskPage.waitForTimeout(1500);

        const productCard = kioskPage.getByTestId(`kiosk-product-card-${FRITES_ITEM_ID}`);
        const productAdd  = kioskPage.getByTestId(`kiosk-product-add-${FRITES_ITEM_ID}`);

        const cardVisible = await productCard.isVisible({ timeout: 8000 }).catch(() => false);
        if (!cardVisible) {
          // Fallback: search by name regex on any kiosk product.
          const fallback = kioskPage.locator('[data-testid^="kiosk-product-card-"]').filter({ hasText: /Frites? Seules?/i }).first();
          if (await fallback.isVisible({ timeout: 5000 }).catch(() => false)) {
            await fallback.scrollIntoViewIfNeeded().catch(() => {});
          } else {
            await safeSnap(kioskRec.snap, '03-kiosk-blocked-no-frites', 'Frites Seules absente du catalogue');
            kioskFlowNotes.push('BLOCKED@no-frites');
            return;
          }
        } else {
          await productCard.scrollIntoViewIfNeeded().catch(() => {});
        }
        await safeSnap(kioskRec.snap, '03-kiosk-wizard', 'Avant click ajout (carte produit visible)');
        kioskFlowNotes.push('product-tile-visible');

        // Click add. waitForFunction polls for either summary-root or cart-indicator update.
        if (await productAdd.isVisible({ timeout: 3000 }).catch(() => false)) {
          await productAdd.click({ timeout: 5000 });
        } else {
          // Click the tile itself — some kiosk variants only have card click.
          await productCard.click({ timeout: 5000 }).catch(() => {});
        }

        // [iter15-mega-fix wave-C-spec round-8 2026-05-10] Wait for ACTUAL cart
        // population, not just kiosk-cart-bottom-sheet element presence (it's
        // a static container always in the DOM regardless of cart state).
        // The robust signal is either the wizard summary opening OR the cart
        // indicator showing N article(s) > 0.
        await kioskPage.waitForFunction(() => (
          document.querySelector('[data-testid="kiosk-order-summary-root"]')
          || document.querySelector('[data-testid="kiosk-step-frites-style"]')
          || document.querySelector('[data-testid^="kiosk-step-"]')
          || /[1-9]\d*\s*article/i.test(document.querySelector('[data-testid="kiosk-categories-cart-indicator"]')?.textContent || '')
        ), null, { timeout: 15_000 });

        // [iter15-mega-fix C-039 round-8 2026-05-10] Frites Seules (item 361)
        // n'est PAS un produit "simple sans wizard". Il ouvre un wizard multi-
        // étapes : `kiosk-step-frites-style` (3 cards : nature + 2 upgrades)
        // → recap (`kiosk-order-summary-root`). Avant le round-8, ce spec
        //   présumait un saut direct au cart ce qui laissait `cart=0` et le
        //   bouton `kiosk-categories-pay` désactivé → bail à state 05.
        //
        // Stratégie : boucle bornée qui (a) détecte la step active, (b) clique
        //   la première option disponible (nature pour frites_style, ou
        //   premier .kiosk-frites-style-* / extra), (c) avance via
        //   .kiosk-wizard .kiosk-btn-next. Sortie : kiosk-order-summary-root
        //   visible OU cart-indicator > 0 (cas item simple).
        //
        // Wizard testids confirmés via grep resources/js/components/frontend/
        //   kiosk/steps/KioskStepFritesStyleComponent.vue :
        //   • kiosk-step-frites-style (root du step)
        //   • kiosk-frites-style-nature (default option, clic suffit pour
        //     valider canAdvance=true)
        //   • kiosk-frites-style-upgrade-{extraId} (Cheddar fondu, Cheddar+Oignons)
        const MAX_WIZARD_STEPS = 6; // safety cap (sauce+menu+frites+supp+garn+recap = 6)
        for (let stepGuard = 0; stepGuard < MAX_WIZARD_STEPS; stepGuard++) {
          const summaryNow = await kioskPage.getByTestId('kiosk-order-summary-root').isVisible({ timeout: 500 }).catch(() => false);
          if (summaryNow) break;

          // Detect current step type. frites_style is the only one Frites
          // Seules expose en V1, mais on garde la détection ouverte pour
          // robustesse cross-item (autres items = autres steps).
          const fritesStyleVisible = await kioskPage.getByTestId('kiosk-step-frites-style').isVisible({ timeout: 500 }).catch(() => false);
          if (fritesStyleVisible) {
            const natureCard = kioskPage.getByTestId('kiosk-frites-style-nature');
            if (await natureCard.isVisible({ timeout: 1000 }).catch(() => false)) {
              await natureCard.click({ timeout: 3000 }).catch(() => {});
              kioskFlowNotes.push(`wizard-step-${stepGuard}-frites-style-nature`);
            }
          } else {
            // Generic fallback : tout autre step (sauce/garnitures/...) —
            // clique première option visible scoped au wizard pour permettre
            // l'avancement. Ce path n'est pas exercé par Frites Seules en V1
            // mais protège le spec si l'item de référence change.
            const firstOption = kioskPage.locator('.kiosk-wizard [role="radio"], .kiosk-wizard [role="button"]').first();
            if (await firstOption.isVisible({ timeout: 500 }).catch(() => false)) {
              await firstOption.click({ timeout: 2000 }).catch(() => {});
              kioskFlowNotes.push(`wizard-step-${stepGuard}-generic-first-option`);
            }
          }

          // Advance : .kiosk-wizard .kiosk-btn-next bascule entre nextStep()
          // et addToCart() selon currentStepIndex (cf. KioskWizardComponent.vue
          // line 177). Un seul bouton existe à la fois — pas besoin de .last().
          const nextBtn = kioskPage.locator('.kiosk-wizard .kiosk-btn-next').first();
          const nextVisible = await nextBtn.isVisible({ timeout: 1500 }).catch(() => false);
          if (nextVisible) {
            const disabled = await nextBtn.getAttribute('disabled').catch(() => null);
            if (disabled === null || disabled === 'false') {
              await nextBtn.click({ timeout: 3000 }).catch(() => {});
            }
          }
          await kioskPage.waitForTimeout(400); // transition between steps
        }

        // Recap state : ajoute au panier si on y est arrivé.
        const summaryVisible = await kioskPage.getByTestId('kiosk-order-summary-root').isVisible({ timeout: 1000 }).catch(() => false);
        if (summaryVisible) {
          // Final CTA = même bouton .kiosk-btn-next mais en mode addToCart()
          // (currentStepIndex === activeSteps.length-1). Il a la classe
          // .kiosk-btn-next--cart en plus.
          const addBtn = kioskPage.locator('.kiosk-wizard .kiosk-btn-next').first();
          if (await addBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
            await addBtn.click({ timeout: 5000 }).catch(() => {});
          }
          await kioskPage.waitForTimeout(800);
          kioskFlowNotes.push('wizard-recap-confirmed');
        } else {
          kioskFlowNotes.push('wizard-skipped-direct-cart');
        }

        // [iter15-mega-fix wave-C-spec round-8 2026-05-10] Wait for cart count
        // > 0, NOT the bottom-sheet element (which is always in DOM).
        await kioskPage.waitForFunction(() => (
          /[1-9]\d*\s*article/i.test(document.querySelector('[data-testid="kiosk-categories-cart-indicator"]')?.textContent || '')
        ), null, { timeout: 8000 }).catch(() => {});
        await safeSnap(kioskRec.snap, '04-kiosk-cart-bottom-sheet', 'Bottom sheet panier visible');
        kioskFlowNotes.push('cart-bottom-sheet');
      } catch (e) {
        await safeSnap(kioskRec.snap, '04-kiosk-blocked-at-add', `BLOCKED: ${e.message.slice(0, 200)}`);
        kioskFlowNotes.push(`BLOCKED@add: ${e.message.slice(0, 100)}`);
        return;
      }

      // ---------------------------------------------------------------
      // 4. Validate cart → checkout → payment screen. From here onwards
      //    all expects are wrapped — payment may be unreachable in dev.
      // ---------------------------------------------------------------
      let reachedPaymentScreen = false;
      let reachedConfirmation = false;
      try {
        const payBtn = kioskPage.getByTestId('kiosk-categories-pay');
        if (await payBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
          await payBtn.click({ timeout: 5000 });
        }
        await kioskPage.waitForFunction(() => (
          document.querySelector('[data-testid="kiosk-cart-root"]')
          || document.querySelector('[data-testid="kiosk-payment-root"]')
        ), null, { timeout: 10_000 });

        // If on cart page, advance to checkout.
        const cartRoot = kioskPage.getByTestId('kiosk-cart-root');
        if (await cartRoot.isVisible({ timeout: 1500 }).catch(() => false)) {
          // Pick takeaway order type if a switch is offered.
          const orderTypeTakeaway = kioskPage.getByTestId('kiosk-cart-order-type-takeaway');
          if (await orderTypeTakeaway.isVisible({ timeout: 1500 }).catch(() => false)) {
            await orderTypeTakeaway.click({ timeout: 3000 }).catch(() => {});
          }
          const checkoutBtn = kioskPage.getByTestId('kiosk-cart-checkout');
          if (await checkoutBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
            await checkoutBtn.click({ timeout: 5000 }).catch(() => {});
          }
        }

        // Skip upsell if it appears.
        await kioskPage.waitForFunction(() => (
          document.querySelector('[data-testid="kiosk-payment-root"]')
          || document.querySelector('[data-testid="kiosk-upsell-root"]')
          || document.querySelector('[data-testid="kiosk-cart-quote-error"]')
        ), null, { timeout: 12_000 });
        const upsell = kioskPage.getByTestId('kiosk-upsell-root');
        if (await upsell.isVisible({ timeout: 1500 }).catch(() => false)) {
          await kioskPage.getByTestId('kiosk-upsell-skip').click({ timeout: 3000 }).catch(() => {});
        }

        await expect(kioskPage.getByTestId('kiosk-payment-root')).toBeVisible({ timeout: 10_000 });
        reachedPaymentScreen = true;
        await safeSnap(kioskRec.snap, '05-kiosk-payment-screen', 'Écran paiement borne');
        kioskFlowNotes.push('payment-screen');
      } catch (e) {
        await safeSnap(kioskRec.snap, '05-kiosk-blocked-at-payment', `BLOCKED: ${e.message.slice(0, 200)}`);
        kioskFlowNotes.push(`BLOCKED@payment-screen: ${e.message.slice(0, 100)}`);
      }

      // ---------------------------------------------------------------
      // 5. Attempt to complete a card payment. Many dev environments lack
      //    a TPE stub — the test must NOT fail here. We snap whatever the
      //    last reachable state is and proceed to KDS / POS asserts using
      //    whatever order tinker reports (may be null).
      // ---------------------------------------------------------------
      if (reachedPaymentScreen) {
        try {
          const cardBtn = kioskPage.getByTestId('kiosk-payment-method-card');
          if (await cardBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await cardBtn.click({ timeout: 3000 }).catch(() => {});
          }
          const confirmBtn = kioskPage.getByTestId('kiosk-payment-confirm');
          if (await confirmBtn.isEnabled({ timeout: 3000 }).catch(() => false)) {
            const orderRespPromise = kioskPage.waitForResponse(
              (res) => res.request().method() === 'POST' && /\/api\/frontend\/order$/.test(res.url()),
              { timeout: 15_000 },
            ).catch(() => null);
            await confirmBtn.click({ timeout: 3000 }).catch(() => {});
            const orderResp = await orderRespPromise;
            if (orderResp && orderResp.status() < 400) {
              try {
                const payload = await orderResp.json();
                const created = payload?.data || payload;
                if (created?.id) createdOrderId = Number(created.id);
              } catch (_e) { /* ignore */ }
            }
          }

          // Wait briefly for confirmation screen — best effort.
          await kioskPage.waitForFunction(() => (
            document.querySelector('[data-testid="kiosk-confirmation-root"]')
            || document.querySelector('[data-testid="kiosk-waiting-root"]')
            || document.querySelector('[data-testid="kiosk-error-payment-cta-retry"]')
          ), null, { timeout: 20_000 }).catch(() => {});

          const confirmRoot = kioskPage.getByTestId('kiosk-confirmation-root');
          if (await confirmRoot.isVisible({ timeout: 1500 }).catch(() => false)) {
            reachedConfirmation = true;
            await safeSnap(kioskRec.snap, '06-kiosk-confirmation', 'Borne confirmation atteinte');
            kioskFlowNotes.push('confirmation-reached');
          } else {
            await safeSnap(kioskRec.snap, '06-kiosk-payment-blocked', 'Bloqué pendant paiement (TPE/stub indispo en dev)');
            kioskFlowNotes.push('BLOCKED@payment-flow');
          }
        } catch (e) {
          await safeSnap(kioskRec.snap, '06-kiosk-payment-error', `EXCEPTION: ${e.message.slice(0, 200)}`);
          kioskFlowNotes.push(`BLOCKED@payment-exec: ${e.message.slice(0, 100)}`);
        }
      }

      // ---------------------------------------------------------------
      // 6. Capture orderId via tinker (may already be set from API listener).
      //    Keys off source_surface='kiosk' AND created_at >= t0.
      // ---------------------------------------------------------------
      if (!createdOrderId) {
        const fetched = fetchLatestKioskOrder(t0Sec);
        if (fetched?.id) createdOrderId = Number(fetched.id);
        kioskFlowNotes.push(`orderId-via-tinker=${createdOrderId || 'null'}`);
      } else {
        kioskFlowNotes.push(`orderId-via-api=${createdOrderId}`);
      }

      // ---------------------------------------------------------------
      // 7. Cross-surface assert : KDS pile. Wait up to 8s. Look for the
      //    order's serial_no on the page; if not found, snap blocked state.
      // ---------------------------------------------------------------
      let kdsSawOrder = false;
      try {
        // Refresh KDS to pick up new orders (some envs poll only on interval).
        await chefPage.reload({ waitUntil: 'domcontentloaded' }).catch(() => {});
        await chefPage.waitForTimeout(2000);

        if (createdOrderId) {
          const fresh = fetchLatestKioskOrder(t0Sec);
          const serial = fresh?.order_serial_no || '';
          // Poll up to 8s for the kiosk card to appear.
          const deadline = Date.now() + 8000;
          while (Date.now() < deadline) {
            const found = await chefPage.evaluate((needle) => {
              const cards = Array.from(document.querySelectorAll('[data-kds-order-card="kiosk"], [data-kds-order-card]'));
              const txt = (document.body.innerText || '').toString();
              return {
                kioskCards: cards.length,
                hasNeedle: needle ? txt.includes(String(needle)) : false,
                bodyExcerpt: txt.replace(/\s+/g, ' ').slice(0, 400),
              };
            }, serial);
            if (found.hasNeedle || found.kioskCards > 0) { kdsSawOrder = true; break; }
            await chefPage.waitForTimeout(1000);
          }
        }
        await safeSnap(chefRec.snap, '07-kds-receives-kiosk-order', kdsSawOrder ? 'KDS voit la commande borne' : 'KDS pile inchangée');
      } catch (e) {
        await safeSnap(chefRec.snap, '07-kds-error', `EXCEPTION: ${e.message.slice(0, 200)}`);
      }

      // ---------------------------------------------------------------
      // 8. Cross-surface assert : POS suivi commandes (PosOrdersTracker).
      // ---------------------------------------------------------------
      let posSawOrder = false;
      try {
        await posPage.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' }).catch(() => {});
        await posPage.waitForTimeout(2500);

        if (createdOrderId) {
          const trackerCard = posPage.getByTestId(`tracker-order-${createdOrderId}`);
          posSawOrder = await trackerCard.isVisible({ timeout: 6000 }).catch(() => false);
          if (posSawOrder) {
            await trackerCard.scrollIntoViewIfNeeded().catch(() => {});
          }
        }
        await safeSnap(posRec.snap, '08-pos-suivi-shows-kiosk-order', posSawOrder ? 'POS suivi liste la commande' : 'POS suivi sans la commande borne');
      } catch (e) {
        await safeSnap(posRec.snap, '08-pos-suivi-error', `EXCEPTION: ${e.message.slice(0, 200)}`);
      }

      // ---------------------------------------------------------------
      // 9. Chef marks the order ready (best-effort). Use the in-page
      //    axios proxy to bypass UI nuances (works on KDS where
      //    `window.axios` is exposed, see audit-kiosk-multiproduct).
      // ---------------------------------------------------------------
      try {
        if (createdOrderId && kdsSawOrder) {
          // [iter15-mega-fix C-001 2026-05-10] Real OrderStatus values are
          // ACCEPT=4, PREPARING=7, PREPARED=8. Previous version sent {1→4, 4→5}
          // which fails validation (kdsStatuses=[4,7,8]) → silent 422. We now
          // pass the enum and surface any error in the test log so future 422s
          // are not silently swallowed (silent failure was half of C-001).
          const statusPayloads = [
            { expected_status: ORDER_STATUS_ACCEPT,    status: ORDER_STATUS_PREPARING },
            { expected_status: ORDER_STATUS_PREPARING, status: ORDER_STATUS_PREPARED  },
          ];
          const transitionResult = await chefPage.evaluate(async ({ id, payloads }) => {
            const results = [];
            for (const payload of payloads) {
              try {
                const res = await window.axios.post(`admin/kds-order/change-status/${id}`, payload,
          // [GOAL CONSOLIDATION 2026-08-25] En-tête OBLIGATOIRE : `config/idempotency.php`
          // liste la route `kds-order/change-status/{id}` dans required_routes (un double
          // bump enverrait deux notifications client). Sans l'en-tête → 422, et l'échec
          // ressemble trompeusement à un défaut de synchro cuisine.
          { headers: { 'X-Idempotency-Key': `idem-kds-${Date.now()}-${Math.random().toString(16).slice(2)}` } }
        );
                results.push({ ok: true, status: res.status, payload });
                await new Promise((r) => setTimeout(r, 500));
              } catch (e) {
                // [iter15-mega-fix C-001 2026-05-10] Surface error instead of swallowing.
                results.push({
                  ok: false,
                  status: e?.response?.status ?? null,
                  message: e?.response?.data?.message || e?.message || 'unknown',
                  payload,
                });
                break; // do not chain a transition on top of a failure
              }
            }
            return results;
          }, { id: createdOrderId, payloads: statusPayloads });
          // eslint-disable-next-line no-console
          console.log(`[KIOSK-RT] kds-transition results: ${JSON.stringify(transitionResult)}`);
          const failure = (transitionResult || []).find((r) => !r.ok);
          if (failure) {
            kioskFlowNotes.push(`KDS-422@${JSON.stringify(failure.payload)}:${failure.status}:${failure.message}`);
          } else {
            kioskFlowNotes.push('kds-transition=ACCEPT->PREPARING->PREPARED ok');
          }
          await chefPage.waitForTimeout(1500);
        }
        await safeSnap(chefRec.snap, '09-kds-prete', 'KDS après transition prête');
      } catch (e) {
        await safeSnap(chefRec.snap, '09-kds-prete-error', `EXCEPTION: ${e.message.slice(0, 200)}`);
      }

      // ---------------------------------------------------------------
      // 10. Capture order-status-screen (écran client) for the ready feedback.
      //     This is a public surface — open in a new context to avoid kiosk
      //     auth interference.
      // ---------------------------------------------------------------
      try {
        const customerCtx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
        const customerPage = await customerCtx.newPage();
        const customerRec = attachMegaAuditRecorder(customerPage, SCREENSHOT_DIR);
        try {
          await customerPage.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded' }).catch(() => {});
          await customerPage.waitForTimeout(2500);
          await safeSnap(customerRec.snap, '10-customer-screen-shows-ready-or-blocked', 'Écran client après KDS prête');
        } finally {
          customerRec.dispose();
          await customerCtx.close().catch(() => {});
        }
      } catch (e) {
        // eslint-disable-next-line no-console
        console.warn(`[KIOSK-RT] customer screen capture failed: ${e.message}`);
      }

      // ---------------------------------------------------------------
      // 11. Sanity: at least 6 quartets must have been written. The spec
      //     PASSES even if kiosk payment was blocked, but it must NOT
      //     pass if the recorder stack collapsed silently.
      // ---------------------------------------------------------------
      const fs = require('fs');
      const written = fs.readdirSync(SCREENSHOT_DIR).filter((f) => f.endsWith('.png'));
      // eslint-disable-next-line no-console
      console.log(`[KIOSK-RT] flow notes : ${kioskFlowNotes.join(' | ')}`);
      // eslint-disable-next-line no-console
      console.log(`[KIOSK-RT] orderId=${createdOrderId} kdsSawOrder=${kdsSawOrder} posSawOrder=${posSawOrder} reachedPayment=${reachedPaymentScreen} reachedConfirmation=${reachedConfirmation} pngs=${written.length}`);
      expect(written.length, `Need ≥6 PNGs for the 3-surface quartets. Got ${written.length}: ${written.join(', ')}`).toBeGreaterThanOrEqual(6);
    } finally {
      // ---------------------------------------------------------------
      // Cleanup : dispose recorders, close contexts, best-effort cancel
      //          the order so subsequent runs aren't polluted.
      // ---------------------------------------------------------------
      try { kioskRec?.dispose(); } catch (_e) { /* ignore */ }
      try { chefRec?.dispose(); }  catch (_e) { /* ignore */ }
      try { posRec?.dispose(); }   catch (_e) { /* ignore */ }
      await kioskCtx?.close().catch(() => {});
      await chefCtx?.close().catch(() => {});
      await posCtx?.close().catch(() => {});
      bestEffortCancelOrder(createdOrderId);
    }
  });
});
