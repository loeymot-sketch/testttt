// D2 VAGUE F — caisse post-heals 1440×900 puis 1366×768
// PRIORITÉ P0: pavé du modal « Encaisser la commande borne » — chaque touche (1-9,0,00,C) cliquée,
// chiffre affiché, AUCUN déclenchement de Confirmer (ADV-F-P0-1 + compaction bbb79630d).
import { BASE, boot, quartet, OUT } from './_d2-F-lib.mjs';
import path from 'path';

const { browser, page, consoleLog, netLog } = await boot({ width: 1440, height: 900 });

// Détection de tout POST confirm (ne doit JAMAIS arriver pendant le test pavé)
const confirmPosts = [];
page.on('request', (r) => {
  if (r.method() === 'POST' && /counter-collect\/\d+\/confirm/.test(r.url())) confirmPosts.push(r.url());
});

// login caisse
await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
await page.waitForSelector('input[autocomplete="email"]', { timeout: 15000 });
await page.fill('input[autocomplete="email"]', 'bm.t2admin@lecayenne.fr');
await page.fill('input[type="password"]', '123456');
await page.click('button[type="submit"]');
await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 20000 });
await page.waitForTimeout(2500);

await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3500);

// Overlay session caisse ? (GAP-08 re-jugement si présent)
const overlay = page.locator('[data-testid="cash-session-overlay"]');
if (await overlay.isVisible().catch(() => false)) {
  const ovInfo = await page.evaluate(() => {
    const o = document.querySelector('[data-testid="cash-session-overlay"]');
    return {
      inputs: Array.from(o.querySelectorAll('input')).map((i) => ({ ph: i.placeholder, type: i.type, visible: i.offsetHeight > 0 })),
      displays: Array.from(o.querySelectorAll('[class*="display"], [class*="amount"]')).map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 40)).filter(Boolean).slice(0, 5),
      buttons: Array.from(o.querySelectorAll('button')).map((b) => b.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean),
    };
  });
  console.log('OVERLAY:', JSON.stringify(ovInfo, null, 1));
  await quartet(page, consoleLog, netLog, 'F2-04a-caisse-session-overlay');
  const amountInput = overlay.locator('input').first();
  if (await amountInput.isVisible().catch(() => false)) await amountInput.fill('50').catch(() => {});
  const openBtn = overlay.locator('button', { hasText: /ouvrir|démarrer|commencer/i }).first();
  if (await openBtn.isVisible().catch(() => false)) { await openBtn.click(); await page.waitForTimeout(2500); }
} else console.log('OVERLAY: absent (session déjà ouverte)');

// ===== F2-04 POS main + GAP-04 (couleurs Encaisser) =====
await page.waitForSelector('.pos-v5-tile', { timeout: 25000 }).catch(() => {});
await page.waitForTimeout(2000);
const posProbe = await page.evaluate(() => {
  const cs = (el) => el ? getComputedStyle(el).backgroundColor : null;
  const drawerBtn = document.querySelector('.kiosk-cash-collect-btn');
  const panelBtn = document.querySelector('.pos-shortcuts__cta--cash');
  const dayBadges = Array.from(document.querySelectorAll('[data-testid^="kiosk-cash-day-badge-"]')).map((e) => e.innerText.trim());
  return {
    drawerCollectBg: cs(drawerBtn), drawerCollectText: drawerBtn?.innerText?.trim() ?? null,
    panelCollectBg: cs(panelBtn), panelCollectText: panelBtn?.innerText?.replace(/\s+/g, ' ')?.trim()?.slice(0, 60) ?? null,
    queueDayBadges: dayBadges.slice(0, 8),
    queueCards: Array.from(document.querySelectorAll('[data-testid^="pos-shortcut-encaisser-"]')).length,
  };
});
console.log('POS-PROBE:', JSON.stringify(posProbe, null, 1));
await quartet(page, consoleLog, netLog, 'F2-04-caisse-pos');

// ===== ouvrir le modal encaissement =====
async function openModal() {
  const enc = page.locator('[data-testid^="pos-shortcut-encaisser-"]').first();
  if (await enc.isVisible().catch(() => false)) await enc.click();
  else await page.locator('button:has-text("Encaisser")').first().click();
  await page.waitForSelector('[data-testid="pos-counter-collect-modal"]', { timeout: 10000 });
  await page.waitForTimeout(1500);
}

async function modalStructureProbe() {
  return page.evaluate(() => {
    const modal = document.querySelector('[data-testid="pos-counter-collect-modal"]');
    if (!modal) return { found: false };
    const body = modal.querySelector('.cc-modal-body');
    const footer = modal.querySelector('.cc-modal-footer');
    const fr = footer?.getBoundingClientRect();
    const confirm = modal.querySelector('[data-testid="pos-counter-collect-confirm"]');
    const cr = confirm?.getBoundingClientRect();
    return {
      found: true,
      viewport: { w: innerWidth, h: innerHeight },
      bodyScroll: body ? { sh: body.scrollHeight, ch: body.clientHeight, scrolls: body.scrollHeight > body.clientHeight } : null,
      footerPosition: footer ? getComputedStyle(footer).position : null,
      footerVisible: fr ? fr.bottom <= innerHeight + 1 : null,
      confirmVisible: cr ? cr.bottom <= innerHeight + 1 && cr.top >= 0 : null,
      confirmDisabled: confirm ? confirm.disabled : null,
      dayBadge: modal.querySelector('[data-testid="pos-counter-collect-day-badge"]')?.innerText?.trim() ?? null,
      heroTotal: modal.querySelector('[data-testid="pos-counter-collect-total"]')?.innerText?.trim() ?? null,
    };
  });
}

// hit-test toutes touches
async function hitTest() {
  return page.evaluate(() => {
    const modal = document.querySelector('[data-testid="pos-counter-collect-modal"]');
    if (!modal) return { found: false };
    const keys = Array.from(modal.querySelectorAll('button')).filter((b) => /^[0-9]$|^00$|^[,.]$|^C$/.test(b.innerText.trim()));
    const res = keys.map((k) => {
      const r = k.getBoundingClientRect();
      const cx = Math.round(r.left + r.width / 2), cy = Math.round(r.top + r.height / 2);
      const el = document.elementFromPoint(cx, cy);
      const ok = el === k || k.contains(el);
      let interceptor = null;
      if (!ok && el) interceptor = (el.closest('button')?.innerText || el.className || el.tagName).toString().replace(/\s+/g, ' ').slice(0, 50);
      return { key: k.innerText.trim(), cy, h: Math.round(r.height), clickable: ok, interceptor };
    });
    return { found: true, n: res.length, keys: res, blocked: res.filter((x) => !x.clickable).map((x) => x.key + '←' + x.interceptor) };
  });
}

// test intégrité pavé: clique CHAQUE touche, lit la valeur, vérifie modal ouvert + 0 POST confirm
async function keypadIntegrity(tag) {
  const seq = ['C', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '00', 'C'];
  const log = [];
  for (const key of seq) {
    const postsBefore = confirmPosts.length;
    let err = null;
    try {
      await page.locator(`[data-testid="pos-counter-collect-modal"] button`, { hasText: new RegExp(`^${key}$`) }).first().click({ timeout: 4000, force: false });
    } catch (e) { err = e.message.split('\n')[0].slice(0, 100); }
    await page.waitForTimeout(250);
    const state = await page.evaluate(() => ({
      value: document.querySelector('[data-testid="pos-counter-collect-received-input"]')?.value ?? null,
      modalOpen: !!document.querySelector('[data-testid="pos-counter-collect-modal"]'),
    }));
    log.push({ key, value: state.value, modalOpen: state.modalOpen, confirmFired: confirmPosts.length > postsBefore, err });
    if (!state.modalOpen) break;
  }
  console.log(`KEYPAD-${tag}:`, JSON.stringify(log, null, 1));
  return log;
}

// --- 1440×900 ---
await openModal();
console.log('STRUCT-1440:', JSON.stringify(await modalStructureProbe(), null, 1));
console.log('HIT-1440:', JSON.stringify(await hitTest(), null, 1));
// avant: zone à risque = rangée du bas + footer
await page.screenshot({ path: path.join(OUT, 'F2-05-keypad-bottomrow-1440-avant.png'), clip: { x: 360, y: 520, width: 720, height: 380 } }).catch(() => {});
const log1440 = await keypadIntegrity('1440x900');
await page.screenshot({ path: path.join(OUT, 'F2-05-keypad-bottomrow-1440-apres.png'), clip: { x: 360, y: 520, width: 720, height: 380 } }).catch(() => {});
await quartet(page, consoleLog, netLog, 'F2-05-caisse-encaissement-modal-1440x900');

// fermer proprement
await page.locator('[data-testid="pos-counter-collect-cancel"]').click().catch(() => page.keyboard.press('Escape'));
await page.waitForTimeout(1200);

// --- 1366×768 ---
await page.setViewportSize({ width: 1366, height: 768 });
await page.waitForTimeout(1000);
await openModal();
console.log('STRUCT-1366:', JSON.stringify(await modalStructureProbe(), null, 1));
console.log('HIT-1366:', JSON.stringify(await hitTest(), null, 1));
await page.screenshot({ path: path.join(OUT, 'F2-05b-keypad-bottomrow-1366-avant.png'), clip: { x: 320, y: 400, width: 720, height: 368 } }).catch(() => {});
const log1366 = await keypadIntegrity('1366x768');
await page.screenshot({ path: path.join(OUT, 'F2-05b-keypad-bottomrow-1366-apres.png'), clip: { x: 320, y: 400, width: 720, height: 368 } }).catch(() => {});
await quartet(page, consoleLog, netLog, 'F2-05b-caisse-encaissement-modal-1366x768');
await page.locator('[data-testid="pos-counter-collect-cancel"]').click().catch(() => page.keyboard.press('Escape'));
await page.waitForTimeout(1000);

console.log('TOTAL POSTS CONFIRM PENDANT TESTS PAVÉ:', confirmPosts.length, confirmPosts);

// --- retour 1440×900 pour le reste ---
await page.setViewportSize({ width: 1440, height: 900 });

// ===== F2-06 file encaissement (badges date) =====
await page.goto(BASE + '/admin/encaissement', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3500);
const encProbe = await page.evaluate(() => {
  const badges = Array.from(document.querySelectorAll('[data-testid^="enc-day-badge-"]')).map((e) => ({ id: e.getAttribute('data-testid'), txt: e.innerText.trim() }));
  const cards = Array.from(document.querySelectorAll('[class*="enc-card"], [class*="card"]')).slice(0, 30);
  const numbers = (document.body.innerText.match(/[A-Z]\d{4}/g) || []).slice(0, 20);
  return {
    headline: (document.body.innerText.match(/\d+ en attente[^\n]*/) || [null])[0],
    dayBadges: badges.slice(0, 10),
    queueNumbersInOrder: numbers,
  };
});
console.log('ENCAISSEMENT:', JSON.stringify(encProbe, null, 1));
await quartet(page, consoleLog, netLog, 'F2-06-caisse-file-encaissement');

// ===== F2-07 modal Session active (GAP-07 Clôturer outline) =====
await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3000);
const caisseBtn = page.locator('button:has-text("Caisse")').first();
if (await caisseBtn.isVisible().catch(() => false)) {
  await caisseBtn.click();
  await page.waitForTimeout(1800);
  const sessProbe = await page.evaluate(() => {
    const dialogs = Array.from(document.querySelectorAll('[role="dialog"], [class*="modal"], [class*="dialog"]')).filter((d) => d.offsetHeight > 80);
    const dlg = dialogs.find((d) => /session|caisse/i.test(d.innerText)) || dialogs[0];
    if (!dlg) return { found: false };
    const cloturer = Array.from(dlg.querySelectorAll('button')).find((b) => /clôturer/i.test(b.innerText));
    const cs = cloturer ? getComputedStyle(cloturer) : null;
    return {
      found: true,
      buttons: Array.from(dlg.querySelectorAll('button')).map((b) => { const s = getComputedStyle(b); return { t: b.innerText.replace(/\s+/g, ' ').trim().slice(0, 40), bg: s.backgroundColor, border: s.borderColor, color: s.color }; }).filter((x) => x.t),
      cloturerStyle: cs ? { bg: cs.backgroundColor, border: cs.border.slice(0, 60), color: cs.color } : null,
    };
  });
  console.log('SESSION-DIALOG:', JSON.stringify(sessProbe, null, 1));
  await quartet(page, consoleLog, netLog, 'F2-07-caisse-session-dialog');
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(800);
} else console.log('WARN: bouton Caisse introuvable');

// ===== F2-08 show (GAP-09/GAP-12) =====
await page.goto(BASE + '/admin/pos-orders', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3000);
const showHref = await page.evaluate(() => document.querySelector('a[href*="pos-orders/show/"]')?.getAttribute('href') ?? null);
if (showHref) {
  await page.goto(showHref.startsWith('http') ? showHref : BASE + showHref, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3000);
  const showProbe = await page.evaluate(() => {
    const txt = document.body.innerText;
    return {
      numeroLigne: (txt.match(/N° Commande:[^\n]+/) || [null])[0],
      refInterne: (txt.match(/Référence interne[^\n]+/) || [null])[0],
      instruction: (txt.match(/Instruction:[^\n]+/) || [null])[0],
      boutons: Array.from(document.querySelectorAll('button, a.btn')).map((e) => e.innerText.replace(/\s+/g, ' ').trim()).filter((t) => t && t.length < 40).slice(0, 14),
    };
  });
  console.log('SHOW:', JSON.stringify(showProbe, null, 1));
  await quartet(page, consoleLog, netLog, 'F2-08-caisse-show');
}

// ===== F2-09 cash-overview (GAP-13) =====
await page.goto(BASE + '/admin/cash-overview', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3500);
const coProbe = await page.evaluate(() => {
  const txt = document.body.innerText.replace(/\s+/g, ' ');
  return {
    grandTotal: (txt.match(/GRAND TOTAL[^€]*€/i) || [null])[0],
    reconciliation: (txt.match(/(session en cours)[^€]*€/i) || [null])[0],
    aVenir: /\(à venir\)/i.test(txt),
    periodHints: (txt.match(/du \d{2}\/\d{2}[^.]{0,40}/g) || []).slice(0, 3),
  };
});
console.log('CASH-OVERVIEW:', JSON.stringify(coProbe, null, 1));
await quartet(page, consoleLog, netLog, 'F2-09-caisse-cash-overview');

await browser.close();
console.log('F2-caisse DONE — confirmPosts total:', confirmPosts.length);
