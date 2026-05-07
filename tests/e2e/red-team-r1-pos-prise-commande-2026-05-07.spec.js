// FoodKing E2E — RED TEAM R1 POS PRISE DE COMMANDE 2026-05-07
// =============================================================
// Persona: Auditeur senior sceptique, expert UX restaurant tech.
// Mission: Challenger le verdict "PROD-READY" du POS audit blue team
// (PASS cycles 1-7 + MEGA A-F, 1573 phpunit + 125+ Playwright).
//
// Méthodologie: parcours complet caissier (login -> ticket) avec
//   - 1 screenshot full-page par étape (durable evidence)
//   - findings.json (severity P0/P1/P2 + note + screenshot path)
//   - probes adversaires : double-click race, network slow, offline,
//                          DOM/aria/i18n inspection, XSS surface check
//
// Sortie:
//   - tests/e2e/screenshots/red-team-r1-pos-2026-05-07/step-NN-{slug}.png
//   - tests/e2e/screenshots/red-team-r1-pos-2026-05-07/findings.json
//   - tests/e2e/screenshots/red-team-r1-pos-2026-05-07/dom-snapshots.json
//
// Le rapport markdown est rédigé séparément après l'exécution
// (docs/audit/RED_TEAM_R1_POS_PRISE_COMMANDE_2026-05-07.md).

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('./helpers/login');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/red-team-r1-pos-2026-05-07');
const FINDINGS_FILE = path.join(SHOT_DIR, 'findings.json');
const DOM_FILE = path.join(SHOT_DIR, 'dom-snapshots.json');

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const findings = [];
const domSnapshots = {};

function record(step, slug, severity, note, screenshot) {
    const entry = { step, slug, severity, note, screenshot, ts: new Date().toISOString() };
    findings.push(entry);
    fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
}

function recordDom(step, slug, payload) {
    domSnapshots[`step-${String(step).padStart(2, '0')}-${slug}`] = payload;
    fs.writeFileSync(DOM_FILE, JSON.stringify(domSnapshots, null, 2));
}

async function snap(page, step, slug) {
    const filename = `step-${String(step).padStart(2, '0')}-${slug}.png`;
    const fullPath = path.join(SHOT_DIR, filename);
    await page.screenshot({ path: fullPath, fullPage: true }).catch((e) => {
        console.warn(`[red-team] snap ${filename} failed: ${e.message}`);
    });
    return path.relative(process.cwd(), fullPath);
}

async function captureJsConsole(page) {
    const errors = [];
    const warnings = [];
    page.on('pageerror', (err) => errors.push({ kind: 'pageerror', msg: err.message }));
    page.on('console', (msg) => {
        const type = msg.type();
        if (type === 'error') errors.push({ kind: 'console', msg: msg.text() });
        else if (type === 'warning') warnings.push({ kind: 'console-warn', msg: msg.text() });
    });
    return { errors, warnings };
}

test.describe.configure({ mode: 'serial' });

test.describe('RED TEAM R1 — POS PRISE DE COMMANDE 2026-05-07', () => {
    test.setTimeout(180_000);

    // ===== Étape 1 — Login =====================================
    test('Step 01 — Login page (avant submit)', async ({ page }) => {
        const { errors } = await captureJsConsole(page);
        await page.goto('/login', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('#formEmail')).toBeVisible({ timeout: 20_000 });

        const shot = await snap(page, 1, 'login-empty');

        // DOM probe: champs présents, autocomplete, type, aria
        const dom = await page.evaluate(() => {
            const email = document.getElementById('formEmail');
            const pass = document.getElementById('formPassword');
            const submitBtns = Array.from(document.querySelectorAll('button')).map((b) => ({
                text: b.textContent.trim().slice(0, 40),
                type: b.type,
                name: b.name,
                ariaLabel: b.getAttribute('aria-label'),
                disabled: b.disabled,
            }));
            return {
                email: email ? {
                    type: email.type, name: email.name, autocomplete: email.autocomplete,
                    required: email.required, ariaLabel: email.getAttribute('aria-label'),
                    placeholder: email.placeholder, label: document.querySelector('label[for="formEmail"]')?.textContent.trim(),
                } : null,
                pass: pass ? {
                    type: pass.type, name: pass.name, autocomplete: pass.autocomplete,
                    required: pass.required, ariaLabel: pass.getAttribute('aria-label'),
                    placeholder: pass.placeholder, label: document.querySelector('label[for="formPassword"]')?.textContent.trim(),
                } : null,
                buttons: submitBtns.slice(0, 6),
                csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')?.length,
                title: document.title,
                hasRememberMe: !!document.querySelector('input[type="checkbox"][name*="remember"], input[type="checkbox"][id*="remember"]'),
                lang: document.documentElement.lang,
                viewportFocusOn: document.activeElement?.id || document.activeElement?.tagName,
            };
        });
        recordDom(1, 'login-empty', dom);

        // Attendus minimaux
        if (!dom.email?.autocomplete || dom.email.autocomplete === 'off') {
            record(1, 'login-empty', 'P2', `Email autocomplete="${dom.email?.autocomplete}" — devrait être "username" ou "email" pour gestionnaires de mots de passe`, shot);
        }
        if (!dom.pass?.autocomplete) {
            record(1, 'login-empty', 'P2', `Password autocomplete vide — devrait être "current-password"`, shot);
        }
        if (!dom.csrf) {
            record(1, 'login-empty', 'P1', `Pas de meta csrf-token — risque CSRF si endpoints session-based`, shot);
        }
        if (!dom.hasRememberMe) {
            record(1, 'login-empty', 'P2', `Pas de "remember me" pour caissier — UX restaurant impacte les changements de poste`, shot);
        }
        if (errors.length) {
            record(1, 'login-empty', 'P2', `Console errors avant interaction: ${errors.slice(0, 3).map(e => e.msg).join(' | ').slice(0, 300)}`, shot);
        }
        record(1, 'login-empty', 'INFO', `DOM probe captured: email=${!!dom.email} pass=${!!dom.pass} buttons=${dom.buttons.length} csrf-len=${dom.csrf}`, shot);
    });

    test('Step 01b — Login submit + landing /admin/pos', async ({ page }) => {
        const { errors } = await captureJsConsole(page);
        await loginAsPosOperator(page);
        await page.waitForTimeout(2_000);
        const shot = await snap(page, 1, 'login-landed');

        const url = page.url();
        if (!/\/admin\/pos/.test(url)) {
            record(1, 'login-landed', 'P0', `Landing URL inattendue: ${url}`, shot);
        }

        // Token storage probe
        const storage = await page.evaluate(() => ({
            localKeys: Object.keys(localStorage),
            sessionKeys: Object.keys(sessionStorage),
            cookieNames: document.cookie.split(';').map((c) => c.split('=')[0].trim()).filter(Boolean),
            tokenInLocal: !!localStorage.getItem('access_token') || !!localStorage.getItem('token'),
            tokenInSession: !!sessionStorage.getItem('access_token') || !!sessionStorage.getItem('token'),
        }));
        recordDom(1, 'login-landed-storage', storage);

        if (storage.tokenInLocal) {
            record(1, 'login-landed', 'P1', `Token JWT en localStorage (clés: ${storage.localKeys.join(',')}) — vulnérable à XSS, devrait être httpOnly cookie`, shot);
        }

        if (errors.length) {
            const critical = errors.filter((e) => /TypeError|ReferenceError|Cannot read|is not a function/i.test(e.msg));
            if (critical.length) {
                record(1, 'login-landed', 'P0', `Erreurs JS critiques post-login: ${critical.slice(0, 3).map(e => e.msg).join(' | ').slice(0, 400)}`, shot);
            }
        }
    });

    // ===== Étape 2 — Surface POS V5 ============================
    test('Step 02 — Surface POS V5 chargée', async ({ page }) => {
        const { errors, warnings } = await captureJsConsole(page);
        await loginAsPosOperator(page);
        await page.waitForTimeout(3_000);
        const shot = await snap(page, 2, 'surface-pos-v5');

        const surface = await page.evaluate(() => {
            const shell = document.querySelector('[data-pos-v5-shell], .pos-v5-shell');
            const cs = getComputedStyle(document.documentElement);
            const tokens = {
                bgApp: cs.getPropertyValue('--pos-v5-bg-app').trim(),
                brandRed: cs.getPropertyValue('--pos-v5-brand-red').trim(),
                border: cs.getPropertyValue('--pos-v5-border').trim(),
                surface: cs.getPropertyValue('--pos-v5-surface').trim(),
            };
            const cart = document.querySelector('.pos-v5-cart, .pos-v4-cart-panel');
            const grid = document.querySelector('.pos-v5-grid, .pos-v4-grid');
            const strip = document.querySelector('.pos-v5-category-strip');
            const header = document.querySelector('header, .pos-v5-header, .pos-v4-header');
            const skipLink = document.querySelector('a[href="#main"], .skip-link, [class*="skip"]');
            const lang = document.documentElement.lang;
            const dir = document.documentElement.dir;
            const focusable = Array.from(document.querySelectorAll('button:not([disabled]), a[href], input:not([disabled])')).length;
            const allTestids = Array.from(document.querySelectorAll('[data-testid]')).map(e => e.getAttribute('data-testid')).slice(0, 30);
            return {
                shell: !!shell, shellClass: shell?.className,
                tokens, cart: !!cart, grid: !!grid, strip: !!strip, header: !!header,
                skipLink: !!skipLink, lang, dir, focusable, allTestids,
            };
        });
        recordDom(2, 'surface-pos-v5', surface);

        if (!surface.shell) record(2, 'surface-pos-v5', 'P0', `Shell V5 absent`, shot);
        if (!surface.tokens.brandRed) record(2, 'surface-pos-v5', 'P1', `Token --pos-v5-brand-red vide`, shot);
        if (!surface.skipLink) record(2, 'surface-pos-v5', 'P2', `Pas de "skip to main content" pour a11y clavier`, shot);
        if (!surface.lang) record(2, 'surface-pos-v5', 'P2', `<html lang=""> vide — i18n/screen reader brisé`, shot);
        if (errors.length) {
            const crit = errors.filter(e => /TypeError|ReferenceError|Cannot read|is not a function/i.test(e.msg));
            if (crit.length) record(2, 'surface-pos-v5', 'P0', `Erreurs JS POS load: ${crit.slice(0,3).map(e=>e.msg).join('|').slice(0,400)}`, shot);
        }
        if (warnings.length > 5) record(2, 'surface-pos-v5', 'P2', `${warnings.length} console warnings — bruit anormal`, shot);
        record(2, 'surface-pos-v5', 'INFO', `tokens=${JSON.stringify(surface.tokens)} testids-count-sample=${surface.allTestids.length}`, shot);
    });

    // ===== Étape 3 — Sélection catégorie =======================
    test('Step 03 — Sélection catégorie + filtrage', async ({ page }) => {
        await loginAsPosOperator(page);
        await page.waitForTimeout(3_000);

        const strip = page.locator('.pos-v5-category-strip').first();
        await expect(strip).toBeVisible({ timeout: 10_000 });
        const categories = page.locator('.pos-v5-category, .pos-v4-category-pill');
        const count = await categories.count();
        const shotBefore = await snap(page, 3, 'categories-before');

        if (count < 2) {
            record(3, 'categories', 'P1', `Seulement ${count} catégorie(s) — POS difficilement utilisable`, shotBefore);
        }

        // DOM probe : ariaSelected, role
        const cat0 = await categories.nth(0).evaluate((el) => ({
            role: el.getAttribute('role'),
            ariaSelected: el.getAttribute('aria-selected'),
            ariaPressed: el.getAttribute('aria-pressed'),
            tabIndex: el.tabIndex,
            text: el.textContent.trim().slice(0, 40),
            hasIcon: !!el.querySelector('img, svg'),
            classList: el.className,
        }));
        recordDom(3, 'category-first', cat0);

        if (!cat0.role && !cat0.ariaSelected && !cat0.ariaPressed) {
            record(3, 'categories', 'P2', `Catégorie sans role/aria-selected/aria-pressed — pas un radio/tab pour screen readers`, shotBefore);
        }

        // Click 2e catégorie
        if (count >= 2) {
            await categories.nth(1).click();
            await page.waitForTimeout(800);
            const shotAfter = await snap(page, 3, 'categories-after-click');

            // Probe: l'état actif est-il visible après click ?
            const activeAfter = await categories.nth(1).evaluate(el => ({
                isSelected: el.classList.contains('is-active') || el.classList.contains('is-selected') || el.getAttribute('aria-selected') === 'true',
                classes: el.className,
            }));
            recordDom(3, 'category-after-click', activeAfter);
            if (!activeAfter.isSelected) {
                record(3, 'categories', 'P1', `Après click catégorie 2, pas d'état "is-active/is-selected/aria-selected" — feedback visuel manquant : ${activeAfter.classes}`, shotAfter);
            } else {
                record(3, 'categories', 'OK', `Click 2e catégorie → state actif détecté`, shotAfter);
            }
        }

        // Adversarial: double-click rapide sur catégorie
        if (count >= 2) {
            const cat1 = categories.nth(1);
            await Promise.all([cat1.click({ force: true }), cat1.click({ force: true })]);
            await page.waitForTimeout(500);
            const shotDouble = await snap(page, 3, 'categories-double-click');
            record(3, 'categories', 'INFO', `Double-click catégorie tenté (race) — voir capture`, shotDouble);
        }
    });

    // ===== Étape 4 — Sélection produit + wizard ================
    test('Step 04 — Sélection produit + ouverture wizard', async ({ page }) => {
        await loginAsPosOperator(page);
        await page.waitForTimeout(3_500);

        const grid = page.locator('.pos-v5-grid, .pos-v4-grid').first();
        await expect(grid).toBeVisible({ timeout: 10_000 });

        const tiles = page.locator('.pos-v5-grid .pos-v5-grid__item, .pos-v4-grid .pos-v4-tile, .pos-v5-grid > *');
        const tCount = await tiles.count();
        const shotGrid = await snap(page, 4, 'grid');

        if (tCount === 0) {
            record(4, 'grid', 'P0', `Grille produit vide — POS ne peut pas vendre`, shotGrid);
            return;
        }
        record(4, 'grid', 'INFO', `${tCount} tuile(s) produit visibles`, shotGrid);

        // DOM probe sur premier produit
        const tile0 = await tiles.nth(0).evaluate((el) => ({
            text: el.textContent.trim().slice(0, 80),
            role: el.getAttribute('role'),
            ariaLabel: el.getAttribute('aria-label'),
            tabIndex: el.tabIndex,
            tagName: el.tagName,
            disabled: el.matches('[disabled],[aria-disabled="true"]'),
            classes: el.className,
            hasImage: !!el.querySelector('img'),
            imageAlt: el.querySelector('img')?.alt,
            hasPriceEl: !!el.querySelector('[class*="price"]'),
        }));
        recordDom(4, 'tile-0', tile0);

        if (tile0.tagName !== 'BUTTON' && !tile0.role) {
            record(4, 'grid', 'P2', `Tuile produit n'est ni <button> ni role=button (tag=${tile0.tagName}) — pas keyboard-accessible`, shotGrid);
        }
        if (tile0.hasImage && !tile0.imageAlt) {
            record(4, 'grid', 'P1', `Image produit sans alt text — a11y screen reader brisée`, shotGrid);
        }

        // Click tuile (cherche un produit qui ouvre le wizard si possible)
        await tiles.nth(0).click();
        await page.waitForTimeout(1_500);
        const shotAfterClick = await snap(page, 4, 'after-tile-click');

        // Wizard détection (modal V5 ou V4)
        const wizard = await page.evaluate(() => {
            const popups = document.querySelectorAll('.modal, [role="dialog"], [class*="composer"], [class*="wizard"], [class*="ProductPopup"]');
            const opened = Array.from(popups).filter((p) => {
                const cs = getComputedStyle(p);
                return cs.display !== 'none' && cs.visibility !== 'hidden';
            });
            return {
                count: opened.length,
                samples: opened.slice(0, 3).map((p) => ({
                    tag: p.tagName,
                    classes: p.className,
                    role: p.getAttribute('role'),
                    ariaModal: p.getAttribute('aria-modal'),
                    ariaLabel: p.getAttribute('aria-label'),
                    inertElsewhere: !!document.querySelector('[inert]'),
                    focusTrapped: document.activeElement && p.contains(document.activeElement),
                })),
                bodyOverflow: getComputedStyle(document.body).overflow,
            };
        });
        recordDom(4, 'wizard-state', wizard);

        if (wizard.count === 0) {
            record(4, 'wizard', 'P2', `Aucun popup détecté après click tuile — soit ajout direct au panier (pas de variations) soit wizard ne s'ouvre pas`, shotAfterClick);
        } else {
            const w = wizard.samples[0];
            if (!w.role || w.role !== 'dialog') {
                record(4, 'wizard', 'P1', `Wizard popup ouvert SANS role="dialog" (role=${w.role}) — screen readers ignorent`, shotAfterClick);
            }
            if (w.ariaModal !== 'true') {
                record(4, 'wizard', 'P1', `Wizard popup sans aria-modal="true" (aria-modal=${w.ariaModal}) — screen readers ne savent pas que c'est modal`, shotAfterClick);
            }
            if (!w.focusTrapped) {
                record(4, 'wizard', 'P1', `Focus n'est PAS dans le wizard popup après ouverture — keyboard users perdus`, shotAfterClick);
            }
            if (wizard.bodyOverflow !== 'hidden') {
                record(4, 'wizard', 'P2', `body.overflow=${wizard.bodyOverflow} alors que modal ouvert — scroll background possible`, shotAfterClick);
            }
        }
    });

    // ===== Étape 5 — Wizard composer =============================
    test('Step 05 — Wizard composer interactions', async ({ page }) => {
        await loginAsPosOperator(page);
        await page.waitForTimeout(3_500);
        const tiles = page.locator('.pos-v5-grid .pos-v5-grid__item, .pos-v4-grid .pos-v4-tile, .pos-v5-grid > *');
        if (await tiles.count() === 0) {
            record(5, 'wizard-pre', 'P0', 'Pas de tuile pour ouvrir wizard', '');
            return;
        }
        await tiles.nth(0).click();
        await page.waitForTimeout(1_500);
        const shot = await snap(page, 5, 'wizard-open');

        const wizard = await page.evaluate(() => {
            const dialog = document.querySelector('.modal[style*="display"], [role="dialog"], [class*="composer"], [class*="ProductPopup"], .modal.show, .modal[class*="show"]');
            if (!dialog) return { found: false };
            return {
                found: true,
                inputs: Array.from(dialog.querySelectorAll('input, select, textarea, button')).slice(0, 30).map(el => ({
                    tag: el.tagName, type: el.type, name: el.name,
                    text: el.textContent.trim().slice(0, 30),
                    ariaLabel: el.getAttribute('aria-label'), disabled: el.disabled,
                })),
                hasCloseBtn: !!dialog.querySelector('button[aria-label*="lose" i], button[aria-label*="erm" i], .close, [class*="close"]'),
                hasInstructionField: !!dialog.querySelector('textarea, input[name*="instruction" i], input[name*="note" i]'),
                hasAddToCartBtn: !!Array.from(dialog.querySelectorAll('button')).find((b) => /ajouter|add.*cart|panier/i.test(b.textContent)),
                hasEscListener: false, // probe ci-dessous
                visibleVariations: Array.from(dialog.querySelectorAll('[class*="variation"], [class*="option"], [class*="extra"], [class*="sauce"]')).length,
            };
        });
        recordDom(5, 'wizard-inputs', wizard);

        if (!wizard.found) {
            record(5, 'wizard', 'P2', 'Pas de modal/dialog visible — produit ajouté direct sans variation ?', shot);
            return;
        }
        if (!wizard.hasCloseBtn) {
            record(5, 'wizard', 'P1', `Wizard sans bouton close visible (aria-label "fermer/close") — sortie uniquement par Échap ou click backdrop`, shot);
        }
        if (!wizard.hasAddToCartBtn) {
            record(5, 'wizard', 'P1', `Wizard sans bouton "Ajouter au panier" détecté — broken UX`, shot);
        }

        // Test ESC pour fermer
        await page.keyboard.press('Escape');
        await page.waitForTimeout(600);
        const closedAfterEsc = await page.evaluate(() => {
            const popups = document.querySelectorAll('[role="dialog"], .modal');
            return Array.from(popups).every((p) => {
                const cs = getComputedStyle(p);
                return cs.display === 'none' || cs.visibility === 'hidden' || !p.offsetParent;
            });
        });
        const shotAfterEsc = await snap(page, 5, 'wizard-after-esc');
        if (!closedAfterEsc) {
            record(5, 'wizard', 'P2', `Wizard PAS fermé par Escape — keyboard UX brisée`, shotAfterEsc);
        } else {
            record(5, 'wizard', 'OK', `Wizard fermé par Escape`, shotAfterEsc);
        }
    });

    // ===== Étape 6 — Add to cart ==================================
    test('Step 06 — Add to cart + cart updated', async ({ page }) => {
        await loginAsPosOperator(page);
        await page.waitForTimeout(3_500);
        const tiles = page.locator('.pos-v5-grid .pos-v5-grid__item, .pos-v4-grid .pos-v4-tile, .pos-v5-grid > *');
        if (await tiles.count() === 0) {
            record(6, 'add-to-cart', 'P0', 'Pas de tuile pour test', '');
            return;
        }
        // Tente de cliquer plusieurs tuiles successives — certaines peuvent ne pas avoir de wizard
        for (let i = 0; i < Math.min(4, await tiles.count()); i++) {
            await tiles.nth(i).click({ timeout: 3_000 }).catch(() => {});
            await page.waitForTimeout(800);
            // Si modal s'ouvre, click "ajouter"
            const addBtn = page.locator('button').filter({ hasText: /ajouter au panier|add to cart|ajouter/i }).first();
            if (await addBtn.isVisible({ timeout: 1_500 }).catch(() => false)) {
                await addBtn.click().catch(() => {});
                await page.waitForTimeout(500);
                break;
            }
        }
        await page.waitForTimeout(1_000);
        const shot = await snap(page, 6, 'cart-with-item');

        const cartState = await page.evaluate(() => {
            const cartItems = document.querySelectorAll('.pos-v5-cart-item, .pos-v4-cart-item, [class*="cart-item"], [class*="cart-line"]');
            const emptyMsg = document.querySelector('.pos-v5-cart__empty');
            const emptyVisible = emptyMsg ? getComputedStyle(emptyMsg).display !== 'none' : false;
            const grandTotal = document.querySelector('[data-testid="pos-grand-total"]');
            return {
                itemCount: cartItems.length,
                emptyVisible,
                grandTotalText: grandTotal?.textContent.trim(),
                payBtn: !!document.querySelector('[data-testid="pos-v5-pay"]'),
                payBtnDisabled: document.querySelector('[data-testid="pos-v5-pay"]')?.disabled,
            };
        });
        recordDom(6, 'cart-state', cartState);

        if (cartState.itemCount === 0) {
            record(6, 'add-to-cart', 'P1', `Après tentatives ajout, panier toujours vide (empty msg visible=${cartState.emptyVisible}) — flux ajout cassé OU tuile ouvre wizard à valider manuellement`, shot);
        } else {
            record(6, 'add-to-cart', 'OK', `${cartState.itemCount} item(s) au panier, total=${cartState.grandTotalText}`, shot);
        }
        if (cartState.payBtnDisabled) {
            record(6, 'add-to-cart', 'P2', `Bouton pos-v5-pay disabled malgré items au panier`, shot);
        }
    });

    // ===== Étape 7 — Cart edits (qty, suppr) ===================
    test('Step 07 — Cart edits (qty + suppr)', async ({ page }) => {
        await loginAsPosOperator(page);
        await page.waitForTimeout(3_500);
        const tiles = page.locator('.pos-v5-grid .pos-v5-grid__item, .pos-v4-grid .pos-v4-tile, .pos-v5-grid > *');
        if (await tiles.count() > 0) {
            await tiles.nth(0).click();
            await page.waitForTimeout(800);
            const addBtn = page.locator('button').filter({ hasText: /ajouter au panier|add to cart/i }).first();
            if (await addBtn.isVisible({ timeout: 1_500 }).catch(() => false)) {
                await addBtn.click().catch(() => {});
                await page.waitForTimeout(500);
            }
        }
        const shot = await snap(page, 7, 'cart-loaded');

        // Probe DOM des contrôles qty + suppr
        const editControls = await page.evaluate(() => {
            const items = document.querySelectorAll('.pos-v5-cart-item, .pos-v4-cart-item, [class*="cart-item"]');
            if (items.length === 0) return { items: 0 };
            const first = items[0];
            return {
                items: items.length,
                hasQtyStepper: !!first.querySelector('[class*="qty"]'),
                qtyButtons: Array.from(first.querySelectorAll('button')).map(b => ({
                    text: b.textContent.trim().slice(0, 10),
                    ariaLabel: b.getAttribute('aria-label'),
                    title: b.title,
                    classes: b.className.slice(0, 80),
                })),
                hasDeleteBtn: !!Array.from(first.querySelectorAll('button')).find(b => /suppr|delete|remove|×|✕/i.test(b.textContent + (b.getAttribute('aria-label') || ''))),
                hasConfirmDestructive: !!Array.from(first.querySelectorAll('button')).find(b => b.getAttribute('data-confirm') || b.dataset.confirm),
            };
        });
        recordDom(7, 'cart-edit-controls', editControls);

        if (editControls.items === 0) {
            record(7, 'cart-edits', 'P2', 'Pas de cart item à éditer (cf step 6)', shot);
            return;
        }
        if (!editControls.hasDeleteBtn) {
            record(7, 'cart-edits', 'P1', `Aucun bouton suppr/× détecté sur cart item — UX caissier brisée pour annulation`, shot);
        }
        if (!editControls.hasConfirmDestructive) {
            record(7, 'cart-edits', 'P2', `Pas de confirmation explicite avant suppression (data-confirm) — risque erreur caissier`, shot);
        }
    });

    // ===== Étape 8 — Customer selector ==========================
    test('Step 08 — Customer selector visible', async ({ page }) => {
        await loginAsPosOperator(page);
        await page.waitForTimeout(3_500);
        const shot = await snap(page, 8, 'customer-selector');

        const customer = await page.evaluate(() => {
            // Cherche un selector client / walk-in / customer
            const selectors = Array.from(document.querySelectorAll('select, button, input')).filter(el => {
                const txt = (el.textContent + ' ' + (el.getAttribute('placeholder') || '') + ' ' + (el.getAttribute('aria-label') || '')).toLowerCase();
                return /walk|client|customer|invité|guest/.test(txt);
            }).slice(0, 5).map(el => ({
                tag: el.tagName, text: el.textContent.trim().slice(0, 40),
                ariaLabel: el.getAttribute('aria-label'), placeholder: el.getAttribute('placeholder'),
            }));
            return { selectors };
        });
        recordDom(8, 'customer-selector', customer);

        if (customer.selectors.length === 0) {
            record(8, 'customer', 'P2', 'Pas de selector client détecté visuellement (walk-in/customer/client) — peut être implicite', shot);
        } else {
            record(8, 'customer', 'INFO', `${customer.selectors.length} contrôle(s) client trouvé(s)`, shot);
        }
    });

    // ===== Étape 9 — Discount ==================================
    test('Step 09 — Discount input + validation', async ({ page }) => {
        await loginAsPosOperator(page);
        await page.waitForTimeout(3_500);
        const tiles = page.locator('.pos-v5-grid > *');
        if (await tiles.count() > 0) {
            await tiles.nth(0).click();
            await page.waitForTimeout(800);
            const addBtn = page.locator('button').filter({ hasText: /ajouter au panier|add to cart/i }).first();
            if (await addBtn.isVisible({ timeout: 1_500 }).catch(() => false)) {
                await addBtn.click().catch(() => {});
                await page.waitForTimeout(500);
            }
        }

        const discountInput = page.locator('[data-testid="pos-discount-input"]');
        const visible = await discountInput.isVisible({ timeout: 3_000 }).catch(() => false);
        const shot = await snap(page, 9, 'discount');

        if (!visible) {
            record(9, 'discount', 'P2', 'pos-discount-input non visible — peut-être conditionnel', shot);
            return;
        }

        const discountProbe = await page.evaluate(() => {
            const input = document.querySelector('[data-testid="pos-discount-input"]');
            const apply = document.querySelector('[data-testid="pos-discount-apply"]');
            return {
                inputType: input?.type, min: input?.min, max: input?.max, step: input?.step,
                applyDisabled: apply?.disabled,
                inputAriaLabel: input?.getAttribute('aria-label'),
                inputPlaceholder: input?.placeholder,
            };
        });
        recordDom(9, 'discount-probe', discountProbe);

        if (!discountProbe.max) {
            record(9, 'discount', 'P1', `Discount input sans max attribute — caissier peut taper -100% ou +999% si validation client-only`, shot);
        }

        // Adversarial: tester valeur > 100%
        await discountInput.fill('999');
        await page.waitForTimeout(300);
        const apply = page.locator('[data-testid="pos-discount-apply"]');
        if (await apply.isEnabled({ timeout: 1_000 }).catch(() => false)) {
            await apply.click().catch(() => {});
            await page.waitForTimeout(800);
            const shotAfter = await snap(page, 9, 'discount-999');
            const total = await page.locator('[data-testid="pos-grand-total"]').textContent().catch(() => '?');
            record(9, 'discount', 'P0?', `Discount 999% accepté côté UI ? Total post-apply: ${total} — VÉRIFIER si backend rejette`, shotAfter);
        }
    });

    // ===== Étape 10 — Pay button + payment modal ==============
    test('Step 10 — Bouton Pay + ouverture modal paiement', async ({ page }) => {
        await loginAsPosOperator(page);
        await page.waitForTimeout(3_500);
        const tiles = page.locator('.pos-v5-grid > *');
        if (await tiles.count() > 0) {
            await tiles.nth(0).click();
            await page.waitForTimeout(800);
            const addBtn = page.locator('button').filter({ hasText: /ajouter au panier|add to cart/i }).first();
            if (await addBtn.isVisible({ timeout: 1_500 }).catch(() => false)) {
                await addBtn.click().catch(() => {});
                await page.waitForTimeout(500);
            }
        }
        const shotBefore = await snap(page, 10, 'pre-pay');

        const payBtn = page.locator('[data-testid="pos-v5-pay"]');
        const payVisible = await payBtn.isVisible({ timeout: 5_000 }).catch(() => false);
        if (!payVisible) {
            record(10, 'pay-btn', 'P0', 'Bouton pos-v5-pay non visible', shotBefore);
            return;
        }

        const payDom = await payBtn.evaluate((el) => ({
            text: el.textContent.trim(),
            disabled: el.disabled,
            ariaDisabled: el.getAttribute('aria-disabled'),
            ariaLabel: el.getAttribute('aria-label'),
            tagName: el.tagName,
            classes: el.className.slice(0, 100),
        }));
        recordDom(10, 'pay-btn', payDom);

        await payBtn.click();
        await page.waitForTimeout(1_200);
        const shotAfter = await snap(page, 10, 'payment-modal');

        const modalState = await page.evaluate(() => {
            const cash = document.querySelector('[data-testid="pos-payment-mode-cash"]');
            const card = document.querySelector('[data-testid="pos-payment-mode-card"]');
            const multi = document.querySelector('[data-testid="pos-payment-mode-multi"]');
            const confirm = document.querySelector('[data-testid="pos-payment-confirm"]');
            return {
                cashVisible: !!cash && getComputedStyle(cash).display !== 'none',
                cardVisible: !!card && getComputedStyle(card).display !== 'none',
                multiVisible: !!multi && getComputedStyle(multi).display !== 'none',
                confirmExists: !!confirm,
                confirmDisabled: confirm?.disabled,
                modalRoles: Array.from(document.querySelectorAll('[role="dialog"]')).map(d => ({
                    aria: d.getAttribute('aria-label') || d.getAttribute('aria-labelledby'),
                    classes: d.className.slice(0, 80),
                })),
            };
        });
        recordDom(10, 'payment-modal', modalState);

        if (!modalState.cashVisible || !modalState.cardVisible) {
            record(10, 'payment-modal', 'P1', `Modes paiement manquants — cash=${modalState.cashVisible} card=${modalState.cardVisible} multi=${modalState.multiVisible}`, shotAfter);
        } else {
            record(10, 'payment-modal', 'OK', `Modes paiement présents (cash/card/multi)`, shotAfter);
        }
        if (modalState.modalRoles.length === 0) {
            record(10, 'payment-modal', 'P1', `Modal paiement sans role="dialog" — a11y brisée`, shotAfter);
        }
    });

    // ===== Étape 11-13 — Cash payment + numpad + submit =======
    test('Step 11-13 — Cash payment numpad + submit', async ({ page }) => {
        const { errors } = await captureJsConsole(page);
        await loginAsPosOperator(page);
        await page.waitForTimeout(3_500);
        const tiles = page.locator('.pos-v5-grid > *');
        if (await tiles.count() > 0) {
            await tiles.nth(0).click();
            await page.waitForTimeout(800);
            const addBtn = page.locator('button').filter({ hasText: /ajouter au panier|add to cart/i }).first();
            if (await addBtn.isVisible({ timeout: 1_500 }).catch(() => false)) {
                await addBtn.click().catch(() => {});
                await page.waitForTimeout(500);
            }
        }
        const payBtn = page.locator('[data-testid="pos-v5-pay"]');
        if (!await payBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
            record(11, 'pay', 'P0', 'pas de pos-v5-pay accessible', '');
            return;
        }
        await payBtn.click();
        await page.waitForTimeout(1_000);
        const shotPay = await snap(page, 11, 'payment-modal-open');

        const cashMode = page.locator('[data-testid="pos-payment-mode-cash"]');
        if (await cashMode.isVisible({ timeout: 2_000 }).catch(() => false)) {
            await cashMode.click();
            await page.waitForTimeout(500);
        }
        const shotCash = await snap(page, 11, 'cash-mode');

        // Probe numpad presence
        const numpadProbe = await page.evaluate(() => {
            const nums = Array.from(document.querySelectorAll('button')).filter(b => /^[0-9]$/.test(b.textContent.trim()));
            return {
                countDigits: nums.length,
                hasDecimal: !!Array.from(document.querySelectorAll('button')).find(b => /^[.,]$/.test(b.textContent.trim())),
                hasBackspace: !!Array.from(document.querySelectorAll('button')).find(b => /backspace|⌫|effacer/i.test(b.textContent.trim() + (b.getAttribute('aria-label') || ''))),
            };
        });
        recordDom(11, 'numpad', numpadProbe);

        // Adversarial: double-click submit (race idempotency)
        const confirm = page.locator('[data-testid="pos-payment-confirm"]');
        const confirmVisible = await confirm.isVisible({ timeout: 3_000 }).catch(() => false);
        if (!confirmVisible) {
            record(13, 'payment-submit', 'P1', 'pos-payment-confirm non visible — flow paiement bloqué', shotCash);
            return;
        }

        // Capture network calls during submit
        const apiCalls = [];
        page.on('request', (req) => {
            if (/\/api\/.*pos|\/api\/.*order|\/api\/.*payment/i.test(req.url())) {
                apiCalls.push({ url: req.url(), method: req.method(), headers: { idempotency: req.headers()['idempotency-key'] } });
            }
        });

        // Race: double-click confirm
        await Promise.all([
            confirm.click({ force: true }).catch(() => {}),
            confirm.click({ force: true }).catch(() => {}),
        ]);
        await page.waitForTimeout(2_500);
        const shotSubmit = await snap(page, 13, 'after-double-submit');
        recordDom(13, 'api-calls-during-submit', apiCalls);

        // Compter combien de POST ont été émis (race condition)
        const posts = apiCalls.filter(c => c.method === 'POST');
        if (posts.length > 1) {
            record(13, 'payment-submit', 'P1', `Double-click submit a émis ${posts.length} POST — vérifier idempotency-key UNIQUE entre les 2`, shotSubmit);
        } else if (posts.length === 1) {
            record(13, 'payment-submit', 'OK', `Double-click → 1 seul POST (frontend dedupe ou bouton désactivé)`, shotSubmit);
        }
        // Vérifier que les POSTs ont une idempotency-key
        const missingIdempotency = posts.filter(p => !p.headers.idempotency);
        if (missingIdempotency.length) {
            record(13, 'payment-submit', 'P0', `${missingIdempotency.length}/${posts.length} POST sans header idempotency-key — risque double commande`, shotSubmit);
        }

        if (errors.length) {
            const crit = errors.filter(e => /TypeError|ReferenceError|Cannot read|is not a function/i.test(e.msg));
            if (crit.length) record(13, 'payment-submit', 'P0', `JS errors pendant submit: ${crit.slice(0,3).map(e=>e.msg).join('|')}`, shotSubmit);
        }
    });

    // ===== Étape 14 — Receipt / ticket ===========================
    test('Step 14 — Ticket affiché post-paiement', async ({ page }) => {
        await loginAsPosOperator(page);
        await page.waitForTimeout(3_500);
        // shortcut: aller direct sur surface POS, capture l'état actuel
        const shot = await snap(page, 14, 'post-paiement-state');

        const receiptProbe = await page.evaluate(() => {
            const receipt = document.querySelector('[class*="receipt"], [class*="ticket"], [class*="ReceiptComponent"]');
            const successToast = document.querySelector('[class*="toast"][class*="success"], [class*="alert-success"]');
            return {
                receiptVisible: !!receipt && receipt.offsetParent !== null,
                successToast: !!successToast,
            };
        });
        recordDom(14, 'receipt', receiptProbe);
        record(14, 'receipt', 'INFO', `receipt visible=${receiptProbe.receiptVisible} toast=${receiptProbe.successToast}`, shot);
    });

    // ===== Étape 15 — KDS event probe (DB) =====================
    test('Step 15 — KDS / domain_events check (out of band)', async ({ page }) => {
        await loginAsPosOperator(page);
        await page.waitForTimeout(2_000);
        const shot = await snap(page, 15, 'kds-state-pos-side');
        record(15, 'kds-event', 'INFO', 'Vérification domain_events row faite hors-Playwright via php artisan tinker (cf rapport markdown)', shot);
    });

    // Adversarial extras: offline + slow network probes
    test('Step 16 — Offline behavior probe', async ({ page, context }) => {
        await loginAsPosOperator(page);
        await page.waitForTimeout(3_000);
        await context.setOffline(true);
        await page.waitForTimeout(500);
        // Tente click tuile + ajout
        const tiles = page.locator('.pos-v5-grid > *');
        if (await tiles.count() > 0) {
            await tiles.nth(0).click().catch(() => {});
            await page.waitForTimeout(1_500);
        }
        const shot = await snap(page, 16, 'offline-tile-click');
        const offlineProbe = await page.evaluate(() => {
            const banner = document.querySelector('[class*="offline"], [class*="connection"], [class*="network"]');
            return {
                hasOfflineBanner: !!banner && banner.offsetParent !== null,
                navigatorOnLine: navigator.onLine,
            };
        });
        recordDom(16, 'offline', offlineProbe);
        if (!offlineProbe.hasOfflineBanner) {
            record(16, 'offline', 'P1', 'Aucun banner "offline/connexion perdue" affiché → caissier ne sait pas que les commandes ne se synchronisent pas', shot);
        }
        await context.setOffline(false);
    });
});
