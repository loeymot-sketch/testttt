// FoodKing E2E — RED TEAM R1 RETRY ciblée add-to-cart + idempotency probe
// =====================================================================
// Cascade fix : la 1re ronde ne sélectionnait pas le bon bouton add-to-cart
// (sélecteur générique). Cette retry utilise .pos-v5-item-add-cta + tente
// plusieurs tuiles pour trouver une qui n'a pas de variations obligatoires.
// Puis exécute le double-click idempotency probe sur pos-payment-confirm.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('./helpers/login');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/red-team-r1-pos-2026-05-07');
const FINDINGS_FILE = path.join(SHOT_DIR, 'findings-retry.json');
const DOM_FILE = path.join(SHOT_DIR, 'dom-snapshots-retry.json');

const findings = [];
const domSnapshots = {};

function record(step, slug, severity, note, screenshot) {
    findings.push({ step, slug, severity, note, screenshot, ts: new Date().toISOString() });
    fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
}
function recordDom(key, payload) {
    domSnapshots[key] = payload;
    fs.writeFileSync(DOM_FILE, JSON.stringify(domSnapshots, null, 2));
}
async function snap(page, slug) {
    const fullPath = path.join(SHOT_DIR, `retry-${slug}.png`);
    await page.screenshot({ path: fullPath, fullPage: true }).catch(() => {});
    return path.relative(process.cwd(), fullPath);
}

test.describe.configure({ mode: 'serial' });

test.describe('RED TEAM R1 — RETRY add-to-cart + idempotency 2026-05-07', () => {
    test.setTimeout(180_000);

    test('RETRY — Find a tile that adds without variations (canAddToCart=true direct)', async ({ page }) => {
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

        await loginAsPosOperator(page);
        await page.waitForTimeout(3_500);

        const tiles = page.locator('.pos-v5-grid > *');
        const tCount = await tiles.count();
        let added = false;
        let wizardProbed = false;

        for (let i = 0; i < Math.min(15, tCount); i++) {
            await tiles.nth(i).click({ timeout: 3_000 }).catch(() => {});
            await page.waitForTimeout(900);

            // Probe wizard a11y on first iteration only
            if (!wizardProbed) {
                const wiz = await page.evaluate(() => {
                    const modal = document.querySelector('.pos-v5-item-modal.active, .pos-v4-item-wizard-modal.active');
                    if (!modal) return { found: false };
                    return {
                        found: true,
                        role: modal.getAttribute('role'),
                        ariaModal: modal.getAttribute('aria-modal'),
                        ariaLabel: modal.getAttribute('aria-label'),
                        focusInside: modal.contains(document.activeElement),
                        activeId: document.activeElement?.id,
                        activeTag: document.activeElement?.tagName,
                    };
                });
                recordDom('wizard-a11y-strict', wiz);
                wizardProbed = true;
            }

            const addCta = page.locator('.pos-v5-item-add-cta');
            const visible = await addCta.isVisible({ timeout: 1_500 }).catch(() => false);
            if (!visible) {
                // Fermer le wizard si ouvert puis suivant
                await page.keyboard.press('Escape').catch(() => {});
                await page.waitForTimeout(400);
                continue;
            }
            const isEnabled = await addCta.isEnabled().catch(() => false);
            const ctaProbe = await addCta.evaluate((el) => ({
                disabled: el.disabled,
                ariaDisabled: el.getAttribute('aria-disabled'),
                hasTestid: el.getAttribute('data-testid'),
                text: el.textContent.trim().slice(0, 60),
            }));
            recordDom(`tile-${i}-add-cta`, ctaProbe);

            if (isEnabled) {
                await addCta.click();
                await page.waitForTimeout(900);
                added = true;
                break;
            }
            // disabled = variations obligatoires manquantes → fermer et essayer suivant
            await page.keyboard.press('Escape').catch(() => {});
            await page.waitForTimeout(400);
        }

        const shot = await snap(page, 'after-add-attempts');

        const cart = await page.evaluate(() => {
            const items = document.querySelectorAll('.pos-v5-cart-item');
            const grand = document.querySelector('[data-testid="pos-grand-total"]')?.textContent.trim();
            const payBtn = document.querySelector('[data-testid="pos-v5-pay"]');
            return {
                count: items.length,
                grand,
                payVisible: !!payBtn && payBtn.offsetParent !== null,
                payDisabled: payBtn?.disabled,
            };
        });
        recordDom('cart-after-add', cart);

        if (added && cart.count > 0) {
            record(0, 'retry-add-cart', 'OK', `Tuile ajoutée — cart count=${cart.count}, total=${cart.grand}, payVisible=${cart.payVisible}`, shot);
        } else {
            record(0, 'retry-add-cart', 'P1', `Aucune tuile parmi 15 testées n'a un .pos-v5-item-add-cta enabled au load wizard — TOUTES exigent sélections. UX caissier rush : friction max.`, shot);
        }

        // Faille confirmée : pas de testid sur le CTA add-to-cart
        const ctaTestidProbe = await page.evaluate(() => {
            const all = document.querySelectorAll('.pos-v5-item-add-cta');
            return Array.from(all).map(el => ({ testid: el.getAttribute('data-testid'), ariaLabel: el.getAttribute('aria-label') }));
        });
        if (ctaTestidProbe.length && ctaTestidProbe.every(c => !c.testid)) {
            record(0, 'retry-add-cart', 'P2', `pos-v5-item-add-cta n'a AUCUN data-testid — sentinel testabilité manquante (ItemComponent.vue:296)`, shot);
        }

        // Si cart non-vide, probe pay-btn behavior
        if (cart.count > 0 && cart.payVisible) {
            const payBtn = page.locator('[data-testid="pos-v5-pay"]');
            await payBtn.click();
            await page.waitForTimeout(1_200);
            const shotPay = await snap(page, 'payment-modal-real');

            // Modal paiement — probe accessibility + cash mode
            const modalProbe = await page.evaluate(() => {
                const dialogs = document.querySelectorAll('[role="dialog"], .modal.active, .pos-v5-payment-modal.active');
                return {
                    dialogCount: dialogs.length,
                    confirmBtn: !!document.querySelector('[data-testid="pos-payment-confirm"]'),
                    confirmDisabled: document.querySelector('[data-testid="pos-payment-confirm"]')?.disabled,
                    confirmAriaBusy: document.querySelector('[data-testid="pos-payment-confirm"]')?.getAttribute('aria-busy'),
                    cashMode: !!document.querySelector('[data-testid="pos-payment-mode-cash"]'),
                };
            });
            recordDom('payment-modal-probe', modalProbe);

            // IDEMPOTENCY DOUBLE-CLICK PROBE — capture all POSTs
            const apiCalls = [];
            page.on('request', (req) => {
                if (/\/api\//.test(req.url()) && req.method() === 'POST') {
                    apiCalls.push({
                        url: req.url(),
                        idempotency: req.headers()['x-idempotency-key'] || req.headers()['idempotency-key'] || null,
                    });
                }
            });

            const cash = page.locator('[data-testid="pos-payment-mode-cash"]');
            if (await cash.isVisible({ timeout: 2_000 }).catch(() => false)) {
                await cash.click();
                await page.waitForTimeout(500);
            }

            const confirm = page.locator('[data-testid="pos-payment-confirm"]');
            const confirmVisible = await confirm.isVisible({ timeout: 3_000 }).catch(() => false);
            if (confirmVisible) {
                // Race double-click
                await Promise.all([
                    confirm.click({ force: true }).catch(() => {}),
                    confirm.click({ force: true }).catch(() => {}),
                ]);
                await page.waitForTimeout(3_500);
                const shotSubmit = await snap(page, 'after-double-submit-real');
                recordDom('idempotency-posts', apiCalls);

                const orderPosts = apiCalls.filter(c => /order|payment|pos/i.test(c.url));
                const dedup = orderPosts.length;
                const idemKeys = orderPosts.map(p => p.idempotency).filter(Boolean);
                const uniqueKeys = [...new Set(idemKeys)];

                if (dedup === 0) {
                    record(0, 'idempotency', 'P2', `Aucun POST order/payment détecté — submit silently dropped ?`, shotSubmit);
                } else if (dedup === 1) {
                    record(0, 'idempotency', 'OK', `Double-click → 1 seul POST (frontend dedupe via :disabled). Idempotency-Key: ${idemKeys[0] ? 'présente' : 'ABSENTE'}`, shotSubmit);
                } else if (dedup > 1 && uniqueKeys.length === 1) {
                    record(0, 'idempotency', 'OK', `Double-click → ${dedup} POSTs MAIS même Idempotency-Key (${uniqueKeys[0]}) → backend dédup`, shotSubmit);
                } else if (dedup > 1 && uniqueKeys.length > 1) {
                    record(0, 'idempotency', 'P0', `Double-click → ${dedup} POSTs avec Idempotency-Keys DIFFÉRENTES (${uniqueKeys.length} unique) → DOUBLE COMMANDE possible`, shotSubmit);
                }
                if (orderPosts.some(p => !p.idempotency)) {
                    record(0, 'idempotency', 'P0', `${orderPosts.filter(p=>!p.idempotency).length} POST(s) SANS X-Idempotency-Key header — protection backend bypassée`, shotSubmit);
                }

                // After confirmation : probe receipt
                await page.waitForTimeout(1_000);
                const receiptProbe = await page.evaluate(() => {
                    const r = document.querySelector('[class*="receipt"], [class*="ticket"]');
                    return {
                        receiptVisible: !!r && r.offsetParent !== null,
                        receiptText: r?.textContent.slice(0, 200),
                    };
                });
                recordDom('receipt-after-submit', receiptProbe);
                const shotReceipt = await snap(page, 'receipt-after-submit');
                if (!receiptProbe.receiptVisible) {
                    record(0, 'receipt', 'P2', `Pas de receipt visible 4.5s après submit — UX feedback manquant ou erreur silencieuse`, shotReceipt);
                } else {
                    record(0, 'receipt', 'OK', `Receipt visible post-submit — text=${receiptProbe.receiptText?.slice(0, 80)}`, shotReceipt);
                }
            }
        }

        if (errors.length) {
            const crit = errors.filter(e => /TypeError|ReferenceError|Cannot read|is not a function/i.test(e));
            if (crit.length) record(0, 'js-errors', 'P0', `Critical JS errors: ${crit.slice(0,3).join(' | ').slice(0, 400)}`, '');
        }
    });

    // Strict modal count probe
    test('RETRY — Strict modal count (.modal.active or role=dialog only)', async ({ page }) => {
        await loginAsPosOperator(page);
        await page.waitForTimeout(3_500);
        // Open one tile
        await page.locator('.pos-v5-grid > *').nth(0).click().catch(() => {});
        await page.waitForTimeout(1_200);

        const strict = await page.evaluate(() => {
            const activeModals = document.querySelectorAll('.modal.active');
            const roleDialogs = document.querySelectorAll('[role="dialog"]');
            const visibleModals = Array.from(activeModals).filter((m) => m.offsetParent !== null);
            return {
                activeModalsCount: activeModals.length,
                visibleActiveModalsCount: visibleModals.length,
                roleDialogCount: roleDialogs.length,
                samples: Array.from(visibleModals).map(m => ({ id: m.id, classes: m.className.slice(0, 80) })),
            };
        });
        recordDom('strict-modal-probe', strict);
        const shot = await snap(page, 'strict-modal-state');

        if (strict.visibleActiveModalsCount > 1) {
            record(0, 'modal-strict', 'P1', `${strict.visibleActiveModalsCount} modals .modal.active simultanément visibles — leak ou stack`, shot);
        } else {
            record(0, 'modal-strict', 'OK', `${strict.visibleActiveModalsCount} modal active visible (probe stricte). roleDialog=${strict.roleDialogCount}`, shot);
        }
    });
});
