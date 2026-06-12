// D2 VAGUE E — étape 2 : trace cross-surface de la commande borne (CARTE)
// encaissement(identité+badge jour) → modal(héro) → confirm CARTE → show → receipt →
// historique → tracker → cash-overview → transactions → KDS
import fs from 'fs';
import { BASE, OUT, boot, quartet, makeLogger, login, gotoAdmin } from './_d2-E-lib.mjs';

const ORDER_ID = Number(process.env.E_ORDER_ID || 4520);
const QUEUE = process.env.E_QUEUE || 'A0009';
const SERIAL = process.env.E_SERIAL || '1206264520';
const TAG = process.env.E_TAG || 'E20';
const MODE = process.env.E_MODE || 'CARD';
const DO_COLLECT = process.env.E_SKIP_COLLECT !== '1';

const L = makeLogger(`${TAG}-caisse`);
const { browser, page, consoleBuf, netBuf } = await boot();

await login(page);
L(`login admin OK → ${page.url().replace(BASE, '')}`);

// --- 0) session caisse ---
await gotoAdmin(page, '/admin/pos');
await page.waitForTimeout(2500);
const openCaisse = page.locator('button:has-text("Ouvrir la caisse")');
if (await openCaisse.isVisible().catch(() => false)) {
  await openCaisse.click(); await page.waitForTimeout(3000); L('session caisse OUVERTE');
} else L('pas de modal ouverture → session déjà ouverte');
for (let i = 0; i < 3; i++) {
  const ov = page.locator('[data-testid="cash-session-overlay"]');
  if (!(await ov.isVisible().catch(() => false))) break;
  await page.locator('[data-testid="cash-session-close"]').click().catch(() => {});
  await page.waitForTimeout(1200);
}

// --- 1) cash-overview BASELINE ---
await gotoAdmin(page, '/admin/cash-overview');
await page.waitForTimeout(2500);
const cashScan = async () => page.evaluate(() => {
  const txt = document.body.innerText;
  return txt.split('\n').map((s) => s.trim()).filter((l) => /€/.test(l) || /Espèces|Carte|Ticket|Mobile|Total|CA |session|BORNE|CAISSE|WEB/i.test(l)).slice(0, 60);
});
const cashBefore = await cashScan();
L(`CASH-OVERVIEW BASELINE:\n  ${cashBefore.join('\n  ')}`);
await quartet(page, consoleBuf, netBuf, `${TAG}-00-cash-overview-baseline`);

// --- 2) encaissement : identité + badge jour + carte cible ---
await gotoAdmin(page, '/admin/encaissement');
await page.waitForTimeout(3000);
const cardInfo = await page.evaluate((q) => {
  const cards = Array.from(document.querySelectorAll('.enc-ticket'));
  const all = cards.map((c) => ({
    queue: c.querySelector('.enc-queue')?.innerText.trim() ?? null,
    origin: c.querySelector('.enc-origin-badge')?.innerText.trim() ?? null,
    customer: c.querySelector('.enc-ticket-customer')?.innerText.trim() ?? null,
    dayBadge: c.querySelector('[data-testid^="enc-day-badge"]')?.innerText.trim() ?? null,
    items: Array.from(c.querySelectorAll('.enc-ticket-items li')).map((li) => li.innerText.replace(/\s+/g, ' ').trim()),
    amount: c.querySelector('.enc-amount')?.innerText.trim() ?? null,
  }));
  return { count: cards.length, mine: all.find((c) => (c.queue || '').includes(q)) || null, all: all.slice(0, 12) };
}, QUEUE);
L(`ENCAISSEMENT: ${cardInfo.count} cartes, cible ${QUEUE} = ${JSON.stringify(cardInfo.mine)}`);
L(`ENCAISSEMENT toutes: ${JSON.stringify(cardInfo.all)}`);
await quartet(page, consoleBuf, netBuf, `${TAG}-01-encaissement-liste`);

if (DO_COLLECT && cardInfo.mine) {
  // --- 3) modal --- (cible la carte du JOUR : sans badge jour — les queue_numbers se dupliquent inter-jours)
  const todayCard = page.locator('.enc-ticket')
    .filter({ has: page.locator('.enc-queue', { hasText: QUEUE }) })
    .filter({ hasNot: page.locator('[data-testid^="enc-day-badge"]') });
  await todayCard.first().locator('.enc-collect-btn').click();
  await page.waitForTimeout(2000);
  const modalInfo = await page.evaluate(() => {
    const q = (s) => document.querySelector(s)?.innerText.replace(/\s+/g, ' ').trim() ?? null;
    return {
      hero: q('[data-testid="pos-counter-collect-total"]'),
      dayBadge: q('[data-testid="pos-counter-collect-day-badge"]'),
      modes: Array.from(document.querySelectorAll('[data-testid^="pos-counter-collect-mode-"]')).map((b) => b.getAttribute('data-testid') + ':' + b.innerText.replace(/\s+/g, ' ').trim().slice(0, 30)),
      header: q('[data-testid="pos-counter-collect-modal"] .cc-modal-header') || q('.cc-modal-header'),
      bodyHead: (q('[data-testid="pos-counter-collect-modal"]') || '').slice(0, 500),
    };
  });
  L(`MODAL: hero="${modalInfo.hero}" badge="${modalInfo.dayBadge}" header="${modalInfo.header}"`);
  L(`MODAL modes=${JSON.stringify(modalInfo.modes)}`);
  await quartet(page, consoleBuf, netBuf, `${TAG}-02-modal-encaissement`);

  // --- 4) mode + confirmer ---
  await page.locator(`[data-testid="pos-counter-collect-mode-${MODE}"]`).click().catch(() => L(`WARN mode ${MODE} non cliquable`));
  await page.waitForTimeout(800);
  if (MODE === 'CASH') {
    const heroNum = (modalInfo.hero || '').match(/(\d+[\d\s.,]*)/)?.[1]?.replace(/\s/g, '').replace(',', '.') || '5.00';
    await page.locator('[data-testid="pos-counter-collect-received-input"]').fill(String(parseFloat(heroNum)));
    await page.waitForTimeout(600);
    L(`reçu=${heroNum} rendu="${await page.locator('[data-testid="pos-counter-collect-change"]').innerText().catch(() => null)}"`);
  } else if (MODE === 'CARD') {
    const refInput = page.locator('[data-testid="pos-counter-collect-card-ref-input"]');
    if (await refInput.isVisible().catch(() => false)) { await refInput.fill('SUMUP-E2-TRACE'); L('réf carte saisie SUMUP-E2-TRACE'); }
  }
  await page.locator('[data-testid="pos-counter-collect-confirm"]').click();
  await page.waitForTimeout(4000);
  const toast = await page.evaluate(() => Array.from(document.querySelectorAll('[class*="toast"],[class*="alert"],[class*="notif"]')).map((e) => e.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean).slice(0, 5));
  L(`POST-CONFIRM toasts: ${JSON.stringify(toast)}`);
  await quartet(page, consoleBuf, netBuf, `${TAG}-03-apres-encaissement`);
} else if (DO_COLLECT) {
  L(`ANOMALIE: carte ${QUEUE} INTROUVABLE sur /admin/encaissement`);
}

// --- 5) show ---
await gotoAdmin(page, `/admin/pos-orders/show/${ORDER_ID}`);
await page.waitForTimeout(3000);
const showInfo = await page.evaluate(() => {
  const lines = document.body.innerText.split('\n').map((s) => s.trim()).filter(Boolean);
  const pick = (re) => lines.filter((l) => re.test(l)).slice(0, 10);
  return {
    montants: pick(/€/),
    statut: pick(/Statut|En attente|préparation|Prêt|Terminé|Livré|Annulé/i).slice(0, 8),
    paiement: pick(/Paiement|Payé|payer|Espèces|Carte|encaisser/i).slice(0, 8),
    client: pick(/Client|borne|Admin/i).slice(0, 6),
  };
});
L(`SHOW ${ORDER_ID}: montants=${JSON.stringify(showInfo.montants)}`);
L(`SHOW statut=${JSON.stringify(showInfo.statut)} paiement=${JSON.stringify(showInfo.paiement)}`);
L(`SHOW client=${JSON.stringify(showInfo.client)}`);
await quartet(page, consoleBuf, netBuf, `${TAG}-04-pos-orders-show`);

// --- 5b) receipt ---
const receiptBtn = page.locator('button:has-text("Imprimer"), a:has-text("Imprimer")').first();
if (await receiptBtn.isVisible().catch(() => false)) {
  await receiptBtn.click().catch(() => {});
  await page.waitForTimeout(2500);
  const receiptTxt = await page.evaluate(() => {
    const r = document.querySelector('[class*="receipt"], [id*="receipt"], [class*="invoice"]');
    return r ? r.innerText.split('\n').map((s) => s.trim()).filter(Boolean).slice(0, 70) : null;
  });
  L(`RECEIPT: ${JSON.stringify(receiptTxt)}`);
  await quartet(page, consoleBuf, netBuf, `${TAG}-05-receipt`);
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(800);
} else L('pas de bouton receipt visible sur show');

// --- 6) historique ---
await gotoAdmin(page, '/admin/historique');
await page.waitForTimeout(3000);
const histInfo = await page.evaluate((needle) => {
  const rows = Array.from(document.querySelectorAll('table tbody tr')).map((tr) => Array.from(tr.querySelectorAll('td,th')).map((td) => td.innerText.replace(/\s+/g, ' ').trim()));
  const head = Array.from(document.querySelectorAll('table thead th')).map((th) => th.innerText.replace(/\s+/g, ' ').trim());
  return { head, mine: rows.find((r) => r.join('|').includes(needle)) || null, first3: rows.slice(0, 3) };
}, QUEUE);
L(`HISTORIQUE head=${JSON.stringify(histInfo.head)}`);
L(`HISTORIQUE ligne ${QUEUE}=${JSON.stringify(histInfo.mine)}`);
if (!histInfo.mine) L(`HISTORIQUE first3=${JSON.stringify(histInfo.first3)}`);
await quartet(page, consoleBuf, netBuf, `${TAG}-06-historique`);

// --- 7) tracker ---
await gotoAdmin(page, '/admin/pos-orders-tracker');
await page.waitForTimeout(3000);
const trackInfo = await page.evaluate((needle) => {
  const cards = Array.from(document.querySelectorAll('[class*="card"], [class*="ticket"], [class*="tracker"]'))
    .filter((c) => c.innerText.includes(needle))
    .map((c) => c.innerText.replace(/\s+/g, ' ').trim().slice(0, 300));
  return { mine: cards.slice(0, 2), bodyHas: document.body.innerText.includes(needle) };
}, QUEUE);
L(`TRACKER contient ${QUEUE}=${trackInfo.bodyHas} carte=${JSON.stringify(trackInfo.mine)}`);
await quartet(page, consoleBuf, netBuf, `${TAG}-07-tracker`);

// --- 8) cash-overview APRÈS ---
await gotoAdmin(page, '/admin/cash-overview');
await page.waitForTimeout(2500);
const cashAfter = await cashScan();
L(`CASH-OVERVIEW APRÈS:\n  ${cashAfter.join('\n  ')}`);
await quartet(page, consoleBuf, netBuf, `${TAG}-08-cash-overview-apres`);

// --- 9) transactions ---
await gotoAdmin(page, '/admin/transactions');
await page.waitForTimeout(3000);
const txInfo = await page.evaluate((needles) => {
  const rows = Array.from(document.querySelectorAll('table tbody tr')).map((tr) => Array.from(tr.querySelectorAll('td')).map((td) => td.innerText.replace(/\s+/g, ' ').trim()));
  const head = Array.from(document.querySelectorAll('table thead th')).map((th) => th.innerText.trim());
  const mine = rows.filter((r) => needles.some((n) => r.join('|').includes(n)));
  return { head, mine: mine.slice(0, 4), first3: rows.slice(0, 3), total: rows.length };
}, [QUEUE, String(ORDER_ID), SERIAL]);
L(`TRANSACTIONS head=${JSON.stringify(txInfo.head)} rows=${txInfo.total}`);
L(`TRANSACTIONS lignes=${JSON.stringify(txInfo.mine)}`);
if (!txInfo.mine.length) L(`TRANSACTIONS first3=${JSON.stringify(txInfo.first3)}`);
await quartet(page, consoleBuf, netBuf, `${TAG}-09-transactions`);

// --- 10) KDS ---
await gotoAdmin(page, '/kds');
await page.waitForTimeout(3500);
const kdsInfo = await page.evaluate((needle) => {
  const has = document.body.innerText.includes(needle);
  const card = Array.from(document.querySelectorAll('[class*="card"],[class*="ticket"],[class*="order"]')).find((c) => c.innerText.includes(needle));
  return { has, card: card ? card.innerText.replace(/\s+/g, ' ').trim().slice(0, 400) : null };
}, QUEUE);
L(`KDS contient ${QUEUE}=${kdsInfo.has} carte=${JSON.stringify(kdsInfo.card)}`);
await quartet(page, consoleBuf, netBuf, `${TAG}-10-kds`);

fs.writeFileSync(`${OUT}_${TAG}-caisse.json`, JSON.stringify({ ORDER_ID, QUEUE, MODE, cashBefore, cardInfo, showInfo, histInfo, trackInfo, cashAfter, txInfo, kdsInfo }, null, 2));
L.flush();
await browser.close();
