/**
 * [KITCHEN-SYMBOLS 2026-06-28] Owner spec — symbolic composition for the kitchen.
 *
 * The kitchen screen (KDS) AND the printed kitchen ticket must read FAST. Instead
 * of "Viande : Poulet mariné / Sauce : Samouraï / Salade, Tomate, Oignon" the cook
 * gets ONE line of short codes:
 *
 *   Line 1 : [Support] | [Produit] | [Taille] | [Viande(s)] | [Crudités] | [Sauce(s)]
 *            e.g.  G | SANDWICH | P | STO | SAM
 *   Line 2 : paid supplements, full name, "+ Cheddar"
 *   Line 3 : "MENU" (formule) or "F" (frites), nothing otherwise
 *
 * Empty slots are omitted. Tacos have no bread question but the cook still sees the
 * support first, defaulting to Galette (G) — a tacos is always a galette.
 *
 * The PHP twin (App\Services\Hardware\KitchenTicketSymbolicFormatter) MUST stay in
 * lockstep with this table so the printed ticket and the screen match exactly.
 */

import { categorize, kdsVariationGroupValue } from './kdsCustomization.js';

/** lowercase, strip diacritics, collapse spaces — for keyword matching. */
function normalize(s) {
    return String(s || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
}

// ── Symbol tables (owner) ────────────────────────────────────────────────────

const MEAT_TABLE = [
    [/hach|steak|b[oe]uf/, 'K'],
    [/poulet/, 'P'],
    [/tender/, 'Tender'],
    [/nugget/, 'Nug'],
    [/mexic/, 'Mex'],
    [/fricadelle/, 'Frec'],
    [/cordon/, 'Cordon'],
];

const SAUCE_TABLE = [
    [/mayo/, 'MAY'],
    [/samou/, 'SAM'],
    [/hannibal/, 'HAN'],
    [/curry/, 'CURY'],
    [/andalouse/, 'AND'],
    [/blanche/, 'BL'],
    [/ketchup/, 'KTP'],
    [/burger/, 'Burg'],
    [/algerien/, 'ALG'],
    [/barbecue|bbq/, 'BBQ'],
    [/harissa/, 'HAR'],
    [/fromage/, 'FRO'],
    [/spicy/, 'SPI'],
];

const CRUDITE_TABLE = [
    [/salade/, 'S'],
    [/tomate/, 'T'],
    [/oignon/, 'O'],
];
/** Canonical print order of crudités → "STO". */
const CRUDITE_ORDER = ['S', 'T', 'O'];

export function meatSymbol(name) {
    const n = normalize(name);
    for (const [re, sym] of MEAT_TABLE) {
        if (re.test(n)) return sym;
    }
    return '';
}

export function sauceSymbol(name) {
    const n = normalize(name);
    for (const [re, sym] of SAUCE_TABLE) {
        if (re.test(n)) return sym;
    }
    // Unlisted sauce → 3-letter uppercase code (strip a leading "sauce ").
    return n.replace(/^sauce\s+/, '').slice(0, 3).toUpperCase();
}

export function cruditeSymbol(name) {
    const n = normalize(name);
    for (const [re, sym] of CRUDITE_TABLE) {
        if (re.test(n)) return sym;
    }
    return '';
}

export function supportSymbol(name) {
    const n = normalize(name);
    if (/galette/.test(n)) return 'G';
    if (/pain/.test(n)) return 'S';
    return '';
}

/** Un item « Menu (Frites + Boisson) » / « Formule … » → affiché juste « MENU » en cuisine. */
export function isMenuItem(name) {
    return /\bmenu\b|formule/.test(normalize(name));
}

/** Sauce frites du menu (depuis l'instruction) → SYMBOLE court (Andalouse → AND). */
export function fritesSauceSymbol(instruction) {
    if (typeof instruction !== 'string' || instruction === '') return '';
    const m = instruction.match(/sauce\s*frites\s*:\s*([^\n]+)/i);
    return m ? sauceSymbol(m[1].trim()) : '';
}

// ── Composition decomposition ────────────────────────────────────────────────

function readVariations(orderItem) {
    const snap = orderItem?.composition_snapshot;
    if (snap && Array.isArray(snap.lines) && snap.lines.length > 0) return snap.lines;
    return Array.isArray(orderItem?.item_variations) ? orderItem.item_variations : [];
}

function readExtras(orderItem) {
    const snap = orderItem?.composition_snapshot;
    if (snap && Array.isArray(snap.extras) && snap.extras.length > 0) return snap.extras;
    return Array.isArray(orderItem?.item_extras) ? orderItem.item_extras : [];
}

function readAddons(orderItem) {
    const snap = orderItem?.composition_snapshot;
    if (snap && Array.isArray(snap.addons) && snap.addons.length > 0) return snap.addons;
    return Array.isArray(orderItem?.item_addons) ? orderItem.item_addons : [];
}

function extraName(e) {
    return e?.extra_name || e?.name || '';
}

function addonName(a) {
    return a?.addon_name || a?.name || '';
}

/** Classify a Line-1 slot from its GROUP (with value-based fallback). */
function classifyVariation(group, value) {
    const g = normalize(group);
    if (/viande|meat/.test(g)) return 'meat';
    if (/sauce/.test(g)) return 'sauce';
    if (/pain|galette|support|bread/.test(g)) return 'support';
    if (/taille|size|portion/.test(g)) return 'size';
    if (meatSymbol(value)) return 'meat';
    return 'other';
}

/** Split "Tacos M" → { produit: "TACOS", taille: "M" }. Only M/L/XL trailing tokens. */
function produitAndSize(itemName) {
    const raw = String(itemName || '').trim();
    const m = raw.match(/\s+(XL|L|M)\s*$/i);
    if (m) {
        return { produit: raw.slice(0, m.index).trim().toUpperCase(), taille: m[1].toUpperCase() };
    }
    return { produit: raw.toUpperCase(), taille: '' };
}

/**
 * Decompose an order item into the symbolic slots.
 * @returns {{category, support, produit, taille, viandes:string[], crudites:string, sauces:string[], supplements:string[], menu:string}}
 */
export function buildSymbolic(orderItem) {
    const category = categorize(orderItem);
    const { produit, taille: nameSize } = produitAndSize(orderItem?.item_name);

    let support = '';
    let taille = nameSize;
    const viandes = [];
    const sauces = [];
    const crud = new Set();
    const supplements = [];

    for (const v of readVariations(orderItem)) {
        const gv = kdsVariationGroupValue(v);
        let value = gv.value;
        // Defensive: a snapshot line with attribute_name=null falls into the legacy
        // branch (value=name=undefined). Recover the value from variation_name so a
        // meat/sauce never silently vanishes from the kitchen line (food safety).
        if (!value && (v?.name === undefined || v?.name === null || v?.name === '') && v?.variation_name) {
            value = String(v.variation_name);
        }
        if (!value) continue;
        switch (classifyVariation(gv.group, value)) {
            case 'meat':
                viandes.push(meatSymbol(value) || normalize(value).slice(0, 3).toUpperCase());
                break;
            case 'sauce':
                sauces.push(sauceSymbol(value));
                break;
            case 'support':
                support = supportSymbol(value) || support;
                break;
            case 'size':
                taille = taille || String(value).toUpperCase();
                break;
            default:
                break;
        }
    }

    for (const e of readExtras(orderItem)) {
        const name = extraName(e);
        if (!name) continue;
        const cs = cruditeSymbol(name);
        const price = Number(e?.unit_price ?? e?.line_total ?? 0) || 0;
        // Only FREE garnitures (price 0) fold into the crudités slot; a paid extra
        // that happens to match (e.g. "Oignons frits" 0,90) is a supplement.
        if (cs && price <= 0) {
            crud.add(cs);
        } else {
            const q = parseInt(e?.quantity, 10);
            const suffix = Number.isFinite(q) && q > 1 ? ` ×${q}` : '';
            supplements.push(`+ ${name}${suffix}`);
        }
    }

    // Owner rule: tacos (and any galette product) show the support first, default G.
    if (!support && (category === 'taco' || /galette/.test(normalize(orderItem?.item_name)))) {
        support = 'G';
    }

    // Line 3: a full formule → "MENU"; a lone frites add-on → "F"; nothing otherwise.
    const addons = readAddons(orderItem);
    let menu = '';
    if (addons.some((a) => String(a?.role || '').toLowerCase().startsWith('menu_'))) {
        menu = 'MENU';
    } else if (addons.some((a) => /frite/.test(normalize(addonName(a))))) {
        menu = 'F';
    }

    const crudites = CRUDITE_ORDER.filter((c) => crud.has(c)).join('');

    return { category, support, produit, taille, viandes, crudites, sauces, supplements, menu };
}

/** Build the single Line-1 string ("G | SANDWICH | P | STO | SAM"). */
export function symbolicMainLine(orderItem) {
    const s = buildSymbolic(orderItem);
    return [
        s.support,
        s.produit,
        s.taille,
        s.viandes.join(' '),
        s.crudites,
        s.sauces.join(' '),
    ]
        .filter((x) => x && x.length > 0)
        .join(' | ');
}

/**
 * Render an order item into the typed line list consumed by <KdsOrderLine>.
 * Mirrors the shape of kdsCustomization.renderItem() but uses the symbolic format.
 */
export function renderItemSymbolic(orderItem) {
    const s = buildSymbolic(orderItem);
    const lines = [];

    const allergenCodes = Array.isArray(orderItem?.allergens_snapshot)
        ? orderItem.allergens_snapshot
            .map((c) => (c === null || c === undefined ? '' : String(c)))
            .filter((c) => c.length > 0)
        : [];
    const hasAllergen = allergenCodes.length > 0;
    const fritesSym = fritesSauceSymbol(orderItem?.instruction);

    // [KITCHEN-MENU 2026-06-30] Un item Menu/Formule → juste « MENU » (+ sauce frites
    // en symbole), AUCUN prix ni « Frites + Boisson » : c'est frites + boisson, rien à
    // préparer de plus côté cuisine.
    if (isMenuItem(orderItem?.item_name)) {
        lines.push({
            type: 'symbolic-main',
            qty: orderItem?.quantity ?? 1,
            label: fritesSym ? `MENU : ${fritesSym}` : 'MENU',
            category: s.category,
            hasAllergen,
        });
        if (hasAllergen) {
            lines.push({ type: 'allergen', codes: allergenCodes });
        }
        return { category: s.category, hasAllergen, lines };
    }

    lines.push({
        type: 'symbolic-main',
        qty: orderItem?.quantity ?? 1,
        label: symbolicMainLine(orderItem),
        category: s.category,
        hasAllergen,
    });

    for (const sup of s.supplements) {
        lines.push({ type: 'supplement', label: sup });
    }

    if (s.menu) {
        // « MENU » attaché au produit → enrichi de la sauce frites en symbole si dispo.
        const menuLabel = s.menu === 'MENU' && fritesSym ? `MENU : ${fritesSym}` : s.menu;
        lines.push({ type: 'symbolic-menu', label: menuLabel });
    }

    if (hasAllergen) {
        lines.push({ type: 'allergen', codes: allergenCodes });
    }

    return { category: s.category, hasAllergen, lines };
}
