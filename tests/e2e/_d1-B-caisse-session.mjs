// VAGUE B dispute — cœur mutations caisse: session (fond) → 2 encaissements borne
// → no-sale → mouvements → refund → clôture comptage (écart) — quartet par état
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login, gotoPos, bodyText } from './_d1-B-lib.mjs';

const L = mkLogger('b1-caisse');
const { browser, page, state } = await boot();
const api = []; // toutes les réponses cash-drawer / collect / refund
page.on('response', async (r) => {
  const u = r.url();
  if (/cash-drawer|counter|collect|refund|no-sale|nosale|open-drawer/i.test(u) && ['POST', 'PUT'].includes(r.request().method())) {
    let body = null;
    try { body = await r.json(); } catch { /* non-json */ }
    api.push({ method: r.request().method(), status: r.status(), url: u.replace(BASE, ''), body });
    L(`API ${r.request().method()} ${r.status()} ${u.replace(BASE, '').slice(0, 110)} :: ${JSON.stringify(body)?.slice(0, 260)}`);
  }
});

const dlg = (tid) => page.locator(`[data-testid="${tid}"]`);

async function openSessionDialog() {
  // le dialog peut s'auto-ouvrir (PosComponent auto-open quand aucune session)
  const already = await dlg('cash-session-overlay').isVisible().catch(() => false);
  if (already) { L('dialog session déjà ouvert (auto-open POS)'); return; }
  await dlg('pos-cash-session-open').click();
  await page.waitForTimeout(1200);
}
async function closeSessionDialog() {
  await dlg('cash-session-close').click().catch(() => {});
  await page.waitForTimeout(600);
}

await login(page, L);
await gotoPos(page, L);
await page.waitForTimeout(1500);
await snap(page, state, 'b1-00-pos-landing');

// Panel "À encaisser borne" — état initial
const shortcuts = await page.locator('[data-testid^="pos-shortcut-encaisser-"]').allTextContents().catch(() => []);
L(`shortcuts encaisser borne: ${JSON.stringify(shortcuts.map(s => s.replace(/\s+/g, ' ').trim()).slice(0, 6))}`);

// ── 1. SESSION ─────────────────────────────────────────────────────────────
await openSessionDialog();
let isActive = await dlg('cash-session-active-view').isVisible().catch(() => false);
let isOpenForm = await dlg('cash-session-open-form').isVisible().catch(() => false);
L(`dialog initial: active=${isActive} openForm=${isOpenForm}`);
await snap(page, state, 'b1-01-session-dialog-initial');

if (isActive) {
  // une session traîne (seed/parallèle) → clôture propre au montant attendu puis réouverture
  const expected = await dlg('cash-session-stat-expected').innerText().catch(() => '?');
  L(`session préexistante: expected="${expected}" → clôture au montant attendu (écart 0)`);
  await dlg('cash-session-go-close').click();
  await page.waitForTimeout(900);
  const exp = await dlg('cash-session-close-expected').innerText();
  const expNum = parseFloat(exp.replace(/[^\d,.-]/g, '').replace(',', '.'));
  await dlg('cash-session-closing-input').fill(String(expNum.toFixed(2)));
  await page.waitForTimeout(600);
  await snap(page, state, 'b1-01b-preexisting-close');
  await dlg('cash-session-close-submit').click();
  await page.waitForTimeout(2500);
  await snap(page, state, 'b1-01c-preexisting-closed');
  // dialog devrait basculer en open-form
}

isOpenForm = await dlg('cash-session-open-form').isVisible().catch(() => false);
if (!isOpenForm) { await closeSessionDialog(); await openSessionDialog(); }
isOpenForm = await dlg('cash-session-open-form').isVisible().catch(() => false);
L(`open-form visible: ${isOpenForm}`);

// fond de caisse 100,00 €
await dlg('cash-session-opening-input').fill('100');
await page.waitForTimeout(400);
const openDisplay = await dlg('cash-session-opening-display').innerText().catch(() => '?');
L(`fond saisi → display="${openDisplay.replace(/\s+/g, ' ')}"`);
await snap(page, state, 'b1-02-session-open-form');
await dlg('cash-session-open-submit').click();
await page.waitForTimeout(2500);
isActive = await dlg('cash-session-active-view').isVisible().catch(() => false);
const statOpening = await dlg('cash-session-stat-opening').innerText().catch(() => '?');
const statExpected = await dlg('cash-session-stat-expected').innerText().catch(() => '?');
L(`session ouverte: active=${isActive} opening="${statOpening}" expected="${statExpected}"`);
await snap(page, state, 'b1-03-session-active');
await closeSessionDialog();

// ── 2. ENCAISSEMENTS BORNE ×2 ──────────────────────────────────────────────
// ordre 4516 (A0001 3,80) compte exact ; ordre 4519 (A0003 3,80) reçu 5,00 → rendu 1,20
async function encaisser(orderId, received, snapPrefix) {
  // les shortcuts portent l'id de commande dans le testid
  let btn = page.locator(`[data-testid="pos-shortcut-encaisser-${orderId}"]`);
  if (!(await btn.count())) {
    L(`WARN shortcut encaisser-${orderId} introuvable — fallback premier shortcut`);
    btn = page.locator('[data-testid^="pos-shortcut-encaisser-"]').first();
  }
  if (!(await btn.count())) { L(`FAIL aucun shortcut encaisser pour ${orderId}`); return false; }
  await btn.click();
  await page.waitForTimeout(2000);
  const modal = await dlg('pos-counter-collect-modal').isVisible().catch(() => false);
  const total = await dlg('pos-counter-collect-total').innerText().catch(() => '?');
  L(`encaisse ${orderId}: modal=${modal} total="${total.replace(/\s+/g, ' ')}"`);
  // mode espèces
  const cashMode = page.locator('[data-testid="pos-counter-collect-mode-cash"]');
  if (await cashMode.count()) { await cashMode.click(); await page.waitForTimeout(500); }
  const modes = await page.locator('[data-testid^="pos-counter-collect-mode-"]').allTextContents();
  L(`modes: ${JSON.stringify(modes.map(m => m.trim()))}`);
  if (received !== null) {
    const inp = dlg('pos-counter-collect-received-input');
    if (await inp.count()) {
      await inp.fill(String(received));
      await page.waitForTimeout(500);
      const change = await dlg('pos-counter-collect-change').innerText().catch(() => '(pas de rendu affiché)');
      L(`reçu=${received} → rendu="${change.replace(/\s+/g, ' ')}"`);
    } else L('WARN pas d input montant reçu');
  }
  await snap(page, state, `${snapPrefix}-modal`);
  await dlg('pos-counter-collect-confirm').click();
  await page.waitForTimeout(3000);
  await snap(page, state, `${snapPrefix}-after`);
  return true;
}

await encaisser(4516, 3.80, 'b1-04-encaisse-4516');
await encaisser(4519, 5.00, 'b1-05-encaisse-4519');

// ── 3. NO-SALE (tiroir hors-vente) ─────────────────────────────────────────
const noSale = dlg('pos-no-sale');
L(`no-sale present=${await noSale.count()}`);
if (await noSale.count()) {
  await noSale.click();
  await page.waitForTimeout(2000);
  await snap(page, state, 'b1-06-no-sale');
}

// ── 4. MOUVEMENTS (table dialog) ───────────────────────────────────────────
await openSessionDialog();
await dlg('cash-session-view-movements').click().catch(() => L('WARN bouton mouvements introuvable'));
await page.waitForTimeout(1500);
const rows = await page.locator('[data-testid="cash-session-movement-row"]').allTextContents();
L(`mouvements (${rows.length}):`);
rows.forEach((r, i) => L(`  mvt#${i + 1}: ${r.replace(/\s+/g, ' | ').trim().slice(0, 160)}`));
await snap(page, state, 'b1-07-movements');
// retour vue active + stats fraîches
await page.locator('.cash-session-dialog__btn--ghost:has-text("Retour"), .cash-session-dialog__actions button').first().click().catch(() => {});
await page.waitForTimeout(900);
const statExpected2 = await dlg('cash-session-stat-expected').innerText().catch(() => '?');
const statMvt = await dlg('cash-session-stat-mvt-count').innerText().catch(() => '?');
L(`stats après encaissements: expected="${statExpected2}" mvts="${statMvt}"`);
await snap(page, state, 'b1-08-session-active-stats');
await closeSessionDialog();

// ── 5. REFUND commande 4519 (payée espèces) ────────────────────────────────
await page.goto(BASE + '/admin/pos-orders/show/4519', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2500);
if (page.url().includes('/login')) { await login(page, L); await page.goto(BASE + '/admin/pos-orders/show/4519', { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(2500); }
await snap(page, state, 'b1-09-order-4519-show-paid');
const txt0 = await bodyText(page);
L(`show 4519 avant refund: statut-paiement=${JSON.stringify((txt0.match(/Payé|Non payé|Remboursé|En attente/g) || []).slice(0, 5))}`);
const refundBtn = dlg('pos-order-refund-open');
L(`refund btn present=${await refundBtn.count()}`);
if (await refundBtn.count()) {
  await refundBtn.click();
  await page.waitForTimeout(1200);
  const mTotal = await dlg('pos-refund-modal-total').innerText().catch(() => '?');
  const mMethod = await dlg('pos-refund-modal-payment-method').innerText().catch(() => '?');
  const mWarn = await dlg('pos-refund-modal-warning').innerText().catch(() => '(pas de warning)');
  L(`refund modal: total="${mTotal.replace(/\s+/g, ' ')}" méthode="${mMethod}" warning="${mWarn.replace(/\s+/g, ' ').slice(0, 140)}"`);
  await snap(page, state, 'b1-10-refund-modal');
  // confirm désactivé tant que raison < 5 chars ?
  const disabledBefore = await dlg('pos-refund-modal-confirm').isDisabled().catch(() => null);
  L(`refund confirm disabled avant raison: ${disabledBefore}`);
  await dlg('pos-refund-modal-reason').fill('Test remboursement dispute round-1 — article rendu');
  await page.waitForTimeout(500);
  await snap(page, state, 'b1-11-refund-modal-filled');
  await dlg('pos-refund-modal-confirm').click();
  await page.waitForTimeout(3500);
  await snap(page, state, 'b1-12-refund-after');
  // recharge show → miroir / badge
  await page.goto(BASE + '/admin/pos-orders/show/4519', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  const txt1 = await bodyText(page);
  L(`show 4519 après refund: marqueurs=${JSON.stringify((txt1.match(/REMBOURS\S*|Remboursé|remboursé|miroir|avoir/gi) || []).slice(0, 8))}`);
  const negs = (txt1.match(/-\s?\d+[.,]\d{2}\s?€|−\s?\d+[.,]\d{2}\s?€/g) || []).slice(0, 8);
  L(`montants négatifs visibles: ${JSON.stringify(negs)}`);
  await snap(page, state, 'b1-13-order-4519-after-refund');
}

// ── 6. CLÔTURE avec comptage (écart volontaire +0,50) ──────────────────────
await gotoPos(page, L);
await page.waitForTimeout(1200);
await openSessionDialog();
const expBefore = await dlg('cash-session-stat-expected').innerText().catch(() => '?');
L(`avant clôture: expected="${expBefore}"`);
await dlg('cash-session-go-close').click();
await page.waitForTimeout(900);
const expClose = await dlg('cash-session-close-expected').innerText();
const expNum = parseFloat(expClose.replace(/[^\d,.-]/g, '').replace(',', '.'));
L(`close-form expected="${expClose.replace(/\s+/g, ' ')}" → num=${expNum}`);
// d'abord comptage exact → variance affichée doit être 0
await dlg('cash-session-closing-input').fill(expNum.toFixed(2));
await page.waitForTimeout(600);
const var0 = await dlg('cash-session-close-variance').innerText().catch(() => '?');
L(`comptage exact ${expNum.toFixed(2)} → variance affichée="${var0.replace(/\s+/g, ' ')}"`);
await snap(page, state, 'b1-14-close-variance-zero');
// puis +0,50 → variance +0,50 ?
const counted = (expNum + 0.5).toFixed(2);
await dlg('cash-session-closing-input').fill(counted);
await page.waitForTimeout(600);
const varPlus = await dlg('cash-session-close-variance').innerText().catch(() => '?');
const reasonVisible = await dlg('cash-session-reason-input').isVisible().catch(() => false);
L(`comptage ${counted} → variance affichée="${varPlus.replace(/\s+/g, ' ')}" raison-visible=${reasonVisible}`);
if (reasonVisible) await dlg('cash-session-reason-input').fill('Écart test +0,50 € — dispute round-1 vague B');
await page.waitForTimeout(400);
await snap(page, state, 'b1-15-close-variance-050');
await dlg('cash-session-close-submit').click();
await page.waitForTimeout(3000);
await snap(page, state, 'b1-16-close-submitted');
const afterTxt = await bodyText(page);
L(`après clôture, dialog: ${JSON.stringify(afterTxt.slice(0, 0))}`);
const openFormBack = await dlg('cash-session-open-form').isVisible().catch(() => false);
L(`open-form re-visible après clôture: ${openFormBack}`);

fs.writeFileSync(OUT + '_b1-api-responses.json', JSON.stringify(api, null, 2));
L.flush();
await browser.close();
console.log('DONE');
