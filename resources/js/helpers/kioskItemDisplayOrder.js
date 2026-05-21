/**
 * Ordre d'affichage des articles borne dans une catégorie :
 * 1) prix affiché croissant (convert_price)
 * 2) à prix égal : taille type tacos M < L < XL < XXL (du plus petit au plus grand)
 * 3) champ admin sort si présent
 * 4) nom
 */

function norm(s) {
  return String(s || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '');
}

export function getKioskItemUnitPrice(item) {
  const v = parseFloat(item?.convert_price ?? item?.price ?? 0);
  return Number.isFinite(v) ? v : 0;
}

/**
 * Rang de taille pour le tie-break (plus petit nombre = affiché avant).
 * 0 = pas de taille reconnue (traité comme 999 dans le comparateur).
 */
export function kioskItemSizeRank(name) {
  const s = norm(name);

  if (/\b(xxxl|3xl)\b/.test(s)) return 70;
  if (/\b(xxl|2xl)\b/.test(s)) return 60;
  if (/\bxl\b/.test(s)) return 50;
  if (/\b(maxi|mega|giga|géant|geant)\b/.test(s)) return 75;

  if (/\b(large|grand)\b/.test(s)) return 40;
  // "Tacos L", "- L", "( L)" — pas "xl"
  if (/(^|[\s\-–—/(])l([\s\-–—)/]|$)/.test(s) && !/\bxl\b/.test(s)) return 40;

  if (/\b(moyen|medium|med)\b/.test(s)) return 30;
  if (/(^|[\s\-–—/(])m([\s\-–—)/]|$)/.test(s)) return 30;

  if (/\b(petit|petite|small|xs)\b/.test(s)) return 10;

  return 0;
}

function adminSortValue(item) {
  const n = parseInt(item?.sort, 10);
  return Number.isNaN(n) ? null : n;
}

export function compareKioskItemsDisplay(a, b) {
  // Wave Y A-001 — honour the admin-controlled `order` column FIRST so
  // signature items (e.g. Sandwich Cayenne order=1) always land before
  // upsell-style items (Frites Seules, Boisson Seule order=99/100) within
  // their category, regardless of price. Items without an explicit order
  // (or order=0) fall through to the legacy price/size/name ordering.
  const oa = Number.parseInt(a?.order, 10);
  const ob = Number.parseInt(b?.order, 10);
  const va = Number.isFinite(oa) && oa > 0 ? oa : Number.POSITIVE_INFINITY;
  const vb = Number.isFinite(ob) && ob > 0 ? ob : Number.POSITIVE_INFINITY;
  if (va !== vb) return va - vb;

  const pa = getKioskItemUnitPrice(a);
  const pb = getKioskItemUnitPrice(b);
  if (pa !== pb) return pa - pb;

  const ra = kioskItemSizeRank(a?.name);
  const rb = kioskItemSizeRank(b?.name);
  const ea = ra === 0 ? 999 : ra;
  const eb = rb === 0 ? 999 : rb;
  if (ea !== eb) return ea - eb;

  const sa = adminSortValue(a);
  const sb = adminSortValue(b);
  if (sa !== null && sb !== null && sa !== sb) return sa - sb;
  if (sa !== null && sb === null) return -1;
  if (sa === null && sb !== null) return 1;

  return norm(a?.name).localeCompare(norm(b?.name), 'fr', { sensitivity: 'base' });
}

/**
 * @param {Array<object>} items
 * @returns {Array<object>}
 */
export function sortKioskItemsForDisplay(items) {
  if (!Array.isArray(items) || items.length === 0) {
    return Array.isArray(items) ? [...items] : [];
  }
  return [...items].sort(compareKioskItemsDisplay);
}
