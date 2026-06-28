/**
 * kioskPrinter.js — FoodKing Kiosk Thermal Printer Helper
 *
 * Architecture:
 *   1. Electron (Windows kiosk): passes par kioskHardware.printReceipt/printEscPos
 *      (service unifié ajouté en Phase 5.1 — contrat `{ok, error?}`, stub auto en dev).
 *   2. Web fallback: uses window.print() with a pre-rendered receipt DOM element
 *
 * ESC/POS command set (subset used):
 *   ESC @ = initialize printer
 *   ESC a N = alignment (0=left, 1=center, 2=right)
 *   ESC E 1 = bold on, ESC E 0 = bold off
 *   ESC ! N = font size (bit 4 = double height, bit 5 = double width)
 *   GS V 0 = cut paper (full cut)
 *   LF = line feed
 *
 * [PHASE-6.2] Plus d'import direct de `window.borne.*` — tout passe par `kioskHardware`.
 */
import { KIOSK_HARDWARE } from '../config/kioskHardware';
import kioskHardware from '../services/kioskHardware';

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

const ESC = '\x1B';
const GS  = '\x1D';
const LF  = '\x0A';

const CMD = {
  INIT:         ESC + '@',
  ALIGN_LEFT:   ESC + 'a\x00',
  ALIGN_CENTER: ESC + 'a\x01',
  ALIGN_RIGHT:  ESC + 'a\x02',
  BOLD_ON:      ESC + 'E\x01',
  BOLD_OFF:     ESC + 'E\x00',
  DOUBLE_SIZE:  ESC + '!\x30',  // double width + double height
  NORMAL_SIZE:  ESC + '!\x00',
  CUT:          GS  + 'V\x00',
};

const RECEIPT_WIDTH = 32; // chars for 58mm printer (48 for 80mm)

/**
 * Pad a string to fill a line of RECEIPT_WIDTH chars.
 * left: left-aligned text, right: right-aligned text
 */
function padLine(left, right, width = RECEIPT_WIDTH) {
  const available = width - right.length;
  return left.substring(0, available).padEnd(available) + right;
}

function centerText(text, width = RECEIPT_WIDTH) {
  const pad = Math.max(0, Math.floor((width - text.length) / 2));
  return ' '.repeat(pad) + text;
}

function separator(char = '-', width = RECEIPT_WIDTH) {
  return char.repeat(width);
}

/**
 * Build an array of ESC/POS command strings for a kiosk receipt.
 *
 * @param {Object} receipt
 * @param {string}   receipt.restaurantName
 * @param {string}   receipt.queueNumber     e.g. "A042"
 * @param {string}   receipt.orderDate       e.g. "24/03/2026 14:32"
 * @param {Array}    receipt.items           [{name, quantity, unitPrice, extras, instruction}]
 * @param {number}   receipt.subtotal
 * @param {number}   receipt.discount
 * @param {number}   receipt.total
 * @param {string}   receipt.paymentMethod   e.g. "Carte bancaire"
 * @param {string}   [receipt.thankYou]      custom thank-you message
 * @param {number}   [receipt.loyaltyPointsEarned]
 * @param {string}   [receipt.loyaltyCustomerName]
 * @returns {string[]} array of ESC/POS command strings (one per "line block")
 */
export function buildEscPosReceipt(receipt) {
  const lines = [];
  const labels = receipt.labels || {};

  lines.push(CMD.INIT);

  // ── Header ──────────────────────────────────────────────────────────────
  lines.push(CMD.ALIGN_CENTER);
  lines.push(CMD.DOUBLE_SIZE);
  lines.push(CMD.BOLD_ON);
  lines.push(receipt.restaurantName || 'Restaurant');
  lines.push(LF);
  lines.push(CMD.NORMAL_SIZE);
  lines.push(CMD.BOLD_OFF);
  lines.push(receipt.orderDate || '');
  lines.push(LF);
  lines.push(separator());
  lines.push(LF);

  // ── Queue number ─────────────────────────────────────────────────────────
  lines.push(CMD.ALIGN_CENTER);
  lines.push(CMD.BOLD_ON);
  lines.push(labels.queueNumberTitle || 'YOUR NUMBER');
  lines.push(LF);
  lines.push(CMD.DOUBLE_SIZE);
  lines.push(receipt.queueNumber || '---');
  lines.push(LF);
  lines.push(CMD.NORMAL_SIZE);
  lines.push(CMD.BOLD_OFF);
  lines.push(separator());
  lines.push(LF);

  // ── Items ────────────────────────────────────────────────────────────────
  lines.push(CMD.ALIGN_LEFT);
  (receipt.items || []).forEach(item => {
    const price = formatEur((parseFloat(item.unitPrice) || 0) * (item.quantity || 1));
    const label = `${item.quantity}x ${item.name}`;
    lines.push(CMD.BOLD_ON);
    lines.push(padLine(label, price));
    lines.push(CMD.BOLD_OFF);
    lines.push(LF);

    // Extras / customizations
    if (item.instruction) {
      const instrLines = item.instruction.split('. ');
      instrLines.forEach(l => {
        if (l.trim()) {
          lines.push('  > ' + l.trim().substring(0, RECEIPT_WIDTH - 4));
          lines.push(LF);
        }
      });
    }
  });

  lines.push(separator());
  lines.push(LF);

  // ── Totals ───────────────────────────────────────────────────────────────
  lines.push(CMD.ALIGN_LEFT);

  if (receipt.discount && receipt.discount > 0) {
    lines.push(padLine(labels.subtotal || 'Subtotal', formatEur(receipt.subtotal)));
    lines.push(LF);
    lines.push(padLine(labels.discount || 'Loyalty discount', '-' + formatEur(receipt.discount)));
    lines.push(LF);
  }

  lines.push(CMD.BOLD_ON);
  lines.push(padLine(labels.total || 'TOTAL', formatEur(receipt.total)));
  lines.push(LF);
  lines.push(CMD.BOLD_OFF);

  if (receipt.paymentMethod) {
    lines.push(padLine(labels.payment || 'Payment', receipt.paymentMethod));
    lines.push(LF);
  }

  if (receipt.loyaltyPointsEarned > 0 && receipt.loyaltyCustomerName) {
    lines.push(separator());
    lines.push(LF);
    lines.push(CMD.ALIGN_CENTER);
    lines.push(CMD.BOLD_ON);
    lines.push(labels.loyalty || 'LOYALTY');
    lines.push(LF);
    lines.push(CMD.BOLD_OFF);
    lines.push(CMD.NORMAL_SIZE);
    const shortName = String(receipt.loyaltyCustomerName).slice(0, 18);
    lines.push(`+${receipt.loyaltyPointsEarned} pts — ${shortName}`);
    lines.push(LF);
  }

  lines.push(separator());
  lines.push(LF);

  // ── Footer ───────────────────────────────────────────────────────────────
  lines.push(CMD.ALIGN_CENTER);
  lines.push(receipt.thankYou || 'Thank you for your order!');
  lines.push(LF);
  lines.push(labels.seeYouSoon || 'See you soon!');
  lines.push(LF + LF + LF);

  // ── Cut ──────────────────────────────────────────────────────────────────
  lines.push(CMD.CUT);

  return lines;
}

/**
 * Print a kiosk receipt.
 *
 * Priority order:
 *   1. Electron bridge via window.borne.printReceipt(orderData)  ← real Electron IPC
 *   2. Legacy bridge via window.borne.printEscPos(lines[])        ← fallback
 *   3. Browser window.print() with CSS @media print               ← web fallback
 *
 * @param {Object} receipt  — same shape as buildReceiptData()
 * @param {string} [printElementId='kiosk-print-receipt']  — DOM id for web fallback
 * @returns {Promise<{method: 'electron'|'electron-escpos'|'browser'|'none', error?: string}>}
 */
export async function printReceipt(receipt, printElementId = 'kiosk-print-receipt') {
  // [PHASE-6.2] Électron bridge — via kioskHardware (pas d'accès direct window.borne).
  //
  // `kioskHardware.printReceipt` et `printEscPos` retournent le contrat {ok, error?}
  // (runSafe dans le service enrobe les throws en fail). Ils renvoient également
  // `{ok: false, error: 'printer_unavailable'}` si la méthode bridge n'existe pas
  // — on traite ce cas comme "fall-through" vers la méthode suivante.
  const isBridge = kioskHardware.isKioskBridge();

  if (isBridge) {
    const orderData = {
      queue_number:    receipt.queueNumber,
      order_serial_no: receipt.queueNumber,
      total:           receipt.total,
      restaurant_name: receipt.restaurantName,
      items: (receipt.items || []).map(i => ({
        name:        i.name,
        quantity:    i.quantity,
        total_price: (i.unitPrice || 0) * (i.quantity || 1),
        instruction: i.instruction || null,
      })),
      payment_method: receipt.paymentMethod || '',
      loyalty_points_earned: receipt.loyaltyPointsEarned || 0,
      loyalty_customer_name: receipt.loyaltyCustomerName || '',
    };
    const maxAttempts = Math.max(1, KIOSK_HARDWARE.PRINTER_RETRY_MAX || 1);
    const retryDelayMs = KIOSK_HARDWARE.PRINTER_RETRY_MS || 0;

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
      const r = await kioskHardware.printReceipt(orderData);
      if (r?.ok) {
        const raw = r.data || r;
        // [BORNE-PRINT 2026-06-27] Traiter `ok` comme imprimé SAUF si le main Electron
        // signale explicitement success:false (job échoué mais IPC transporté). L'ancien
        // `|| r.ok` court-circuitait TOUJOURS en succès (la branche warn + fallback ESC/POS
        // était du code mort → un échec d'impression était rapporté comme imprimé).
        if (raw?.success === false) {
          console.warn('[kioskPrinter] printReceipt returned non-success:', raw);
          // → ne PAS retourner electron ; tenter le fallback ESC/POS ci-dessous.
        } else {
          return { method: 'electron' };
        }
      } else if (r?.error && r.error !== 'printer_unavailable') {
        console.warn('[kioskPrinter] printReceipt failed:', r.error);
      }

      const lines = buildEscPosReceipt(receipt);
      const rEsc = await kioskHardware.printEscPos(lines);
      if (rEsc?.ok) return { method: 'electron-escpos' };
      if (rEsc?.error && rEsc.error !== 'printer_unavailable') {
        console.warn('[kioskPrinter] printEscPos failed:', rEsc.error);
      }

      if (attempt < maxAttempts && retryDelayMs > 0) {
        await sleep(retryDelayMs);
      }
    }
  }

  // ── Browser window.print() fallback — UNIQUEMENT hors bridge (dev / navigateur /
  // Chrome-kiosk). [BORNE-PRINT 2026-06-27] Sur la VRAIE borne Electron, un échec
  // bridge ne doit PAS retomber sur window.print() : #kiosk-print-receipt existe
  // toujours, donc l'ancien code masquait l'échec en { method:'browser' } (faux
  // « Imprimé », filet on-screen jamais montré, reportPrinterFailure jamais appelé,
  // et window.print() pouvait ouvrir un dialogue OS bloquant sur écran tactile).
  // On laisse l'échec remonter pour déclencher printFailed + reportPrinterFailure.
  if (!isBridge) {
    // [BORNE-LOCAL-BRIDGE 2026-06-28] Pont WinUSB local d'abord : impression
    // thermique SILENCIEUSE sur le SK1-31 (POST 127.0.0.1:9100), sans dialogue
    // navigateur. Fall-through vers window.print() si le pont est absent/échoue.
    const bridge = await printViaLocalBridge(receipt);
    if (bridge) return bridge;

    const el = document.getElementById(printElementId);
    if (el && typeof window.print === 'function') {
      try {
        window.print();
        return { method: 'browser' };
      } catch (err) {
        return { method: 'none', error: err.message };
      }
    }
  }

  return { method: 'none', error: isBridge ? 'kiosk_printer_failed' : 'No print method available' };
}

/**
 * Report a printer failure to the backend hardware log.
 * Non-blocking — never throws.
 */
export function reportPrinterFailure(orderId, errorMessage) {
    try {
        const axios = window.axios;
        if (!axios) return;
        axios.post('frontend/kiosk-event', {
            type: 'printer_failure',
            details: `order_id=${orderId} | error=${errorMessage || 'unknown'}`,
        }).catch(() => {});
    } catch (_) {}
}

/**
 * Format a number as EUR currency string for receipt (e.g. "12.50 EUR")
 */
function formatEur(amount) {
  return (parseFloat(amount) || 0).toFixed(2) + ' EUR';
}

/**
 * Build a receipt data object from kiosk cart state + order response.
 *
 * @param {Object} opts
 * @param {string}  opts.restaurantName
 * @param {string}  opts.queueNumber
 * @param {Array}   opts.cartItems      — Vuex kioskCart items
 * @param {number}  opts.subtotal
 * @param {number}  opts.discount
 * @param {number}  opts.total
 * @param {string}  opts.paymentMethod
 * @param {number}  [opts.loyaltyPointsEarned]
 * @param {string}  [opts.loyaltyCustomerName]
 * @returns {Object}
 */
export function buildReceiptData({
  restaurantName,
  queueNumber,
  cartItems,
  subtotal,
  discount,
  total,
  paymentMethod,
  loyaltyPointsEarned = 0,
  loyaltyCustomerName = '',
  labels = {},
}) {
  const now = new Date();
  const pad = n => String(n).padStart(2, '0');
  const orderDate = `${pad(now.getDate())}/${pad(now.getMonth() + 1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;

  const items = (cartItems || []).map(item => ({
    name:      item.name || 'Article',
    quantity:  item.quantity || 1,
    unitPrice: (parseFloat(item.convert_price) || 0)
               + (parseFloat(item.item_variation_total) || 0)
               + (parseFloat(item.item_extra_total) || 0),
    instruction: item.instruction || null,
  }));

  return {
    restaurantName: restaurantName || 'Restaurant',
    queueNumber:    queueNumber    || '---',
    orderDate,
    items,
    subtotal:       parseFloat(subtotal) || 0,
    discount:       parseFloat(discount) || 0,
    total:          parseFloat(total)    || 0,
    paymentMethod:  paymentMethod || '',
    loyaltyPointsEarned: parseInt(loyaltyPointsEarned, 10) || 0,
    loyaltyCustomerName: loyaltyCustomerName || '',
    labels,
  };
}

// ── [BORNE-LOCAL-BRIDGE 2026-06-28] Pont d'impression WinUSB local ───────────
// Sur la VRAIE borne (Chrome plein écran, PAS Electron), le SK1-31 n'a aucune file
// d'impression Windows (driver WinUSB only). Un petit pont local (bridge.js / node-usb)
// écoute sur http://127.0.0.1:9100 et écrit l'ESC/POS directement au SK1-31. La borne
// POSTe le ticket au pont au lieu de window.print(). `http://127.0.0.1` est un secure
// context → fetch autorisé depuis une page HTTPS (exempté du blocage mixed-content).
export const LOCAL_BRIDGE_URL = 'http://127.0.0.1:9100';

// Le pont envoie ses octets en 'binary' (Latin-1) et le codepage du SK1-31 est
// imprévisible → on ASCII-fold (accents retirés, € → EUR) pour garantir un ticket
// LISIBLE quel que soit le codepage (V1 pragmatique : « Méga » → « Mega »).
function asciiFold(s) {
  return String(s == null ? '' : s)
    .normalize('NFD').replace(/[̀-ͯ]/g, '') // diacritiques
    .replace(/€/g, 'EUR').replace(/[^\x20-\x7E]/g, ' ')
    .replace(/[ \t]{2,}/g, ' ').trim();
}

function frEur(amount) {
  return (parseFloat(amount) || 0).toFixed(2).replace('.', ',') + ' EUR';
}

/**
 * Mappe un reçu (buildReceiptData) vers le payload JSON du pont :
 * {title, subtitle, order, lines:[], total, footer}. ASCII codepage-safe.
 */
export function buildBridgePayload(receipt) {
  receipt = receipt || {};
  const width = RECEIPT_WIDTH;
  const lines = [];
  (receipt.items || []).forEach(item => {
    const qty = item.quantity || 1;
    const price = frEur((parseFloat(item.unitPrice) || 0) * qty);
    lines.push(padLine(`${qty}x ${asciiFold(item.name)}`, price, width));
    if (item.instruction) {
      String(item.instruction).split('. ').forEach(l => {
        const t = asciiFold(l);
        if (t) lines.push('  > ' + t.substring(0, width - 4));
      });
    }
  });
  if (receipt.discount && receipt.discount > 0) {
    lines.push(padLine('Remise', '-' + frEur(receipt.discount), width));
  }
  if (receipt.paymentMethod) {
    lines.push(padLine('Paiement', asciiFold(receipt.paymentMethod), width));
  }
  return {
    title: asciiFold(receipt.restaurantName) || 'LE CAYENNE',
    subtitle: asciiFold(receipt.orderDate),
    order: asciiFold(receipt.queueNumber),
    lines,
    total: frEur(receipt.total),
    footer: asciiFold(receipt.thankYou) || 'Merci et bon appetit !',
  };
}

function fetchWithTimeout(url, opts, timeoutMs) {
  if (typeof fetch !== 'function') return Promise.reject(new Error('no fetch'));
  const ctrl = typeof AbortController === 'function' ? new AbortController() : null;
  const t = ctrl ? setTimeout(() => ctrl.abort(), timeoutMs) : null;
  const o = Object.assign({}, opts);
  if (ctrl) o.signal = ctrl.signal;
  return fetch(url, o).finally(() => { if (t) clearTimeout(t); });
}

/** True si le pont local répond /health → "UP". Timeout court, jamais throw. */
export async function isLocalBridgeAvailable(timeoutMs = 800) {
  try {
    const res = await fetchWithTimeout(LOCAL_BRIDGE_URL + '/health', {}, timeoutMs);
    if (!res || !res.ok) return false;
    const txt = await res.text();
    return /UP/i.test(txt);
  } catch (_) { return false; }
}

/**
 * POSTe le ticket au pont local (ESC/POS → SK1-31). Renvoie {method:'local-bridge'}
 * sur succès, sinon null (fall-through vers window.print). Jamais throw.
 */
export async function printViaLocalBridge(receipt, timeoutMs = 4000) {
  try {
    const res = await fetchWithTimeout(LOCAL_BRIDGE_URL + '/', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(buildBridgePayload(receipt)),
    }, timeoutMs);
    return res && res.ok ? { method: 'local-bridge' } : null;
  } catch (_) { return null; }
}
