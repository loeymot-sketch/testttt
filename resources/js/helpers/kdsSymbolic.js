/**
 * [KITCHEN-SYMBOLS 2026-06-28] Owner spec — symbolic composition for the kitchen.
 *
 * The kitchen screen (KDS) AND the printed kitchen ticket must read FAST. Instead
 * of "Viande : Poulet mariné / Sauce : Samouraï / Salade, Tomate, Oignon" the cook
 * gets ONE line of short codes:
 *
 *   Line 1 : [Support] | [Produit] | [Taille] | [Viande(s)] | [Crudités] | [Sauce(s)]
 *            e.g.  G | SANDWICH | P | STO | SAM
 *   Line 2 : paid supplements, full name, "+ Cheddar" (NO sauce — sauces live on Line 1)
 *   Line 3 : "MENU" (formule) or "F" (frites), nothing otherwise
 *
 * [MEGA-BORNE 2026-07-22 owner]
 *   - Sauce(s): the product's 1st (included) sauce AND its extra sauce(s) are written
 *     TOGETHER in the Line-1 Sauce(s) slot ("FRO MAY"); the extra sauce is NO LONGER a
 *     "+ Sauce supplémentaire" line. The menu/frites sauce stays on Line 2.
 *   - Tacos: the [Taille] slot is DROPPED (kitchen shows the meats instead — "K P").
 *
 * Empty slots are omitted. Tacos have no bread question but the cook still sees the
 * support first, defaulting to Galette (G) — a tacos is always a galette.
 *
 * The PHP twin (App\Services\Hardware\KitchenTicketSymbolicFormatter) MUST stay in
 * lockstep with this table so the printed ticket and the screen match exactly.
 */

import { categorize, isDrinkName, kdsVariationGroupValue, sanitizeKdsInstruction } from './kdsCustomization.js';
import { kdsInstructionVisualClass } from './kdsLineSemantics.js';

/** lowercase, strip diacritics, collapse spaces — for keyword matching. */
function normalize(s) {
    return String(s || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        // [TICKET-WIDTHSAFE 2026-07-01] Parité PHP : NFD ne décompose pas les ligatures →
        // « Œuf » → « oeuf » (symbole « OEU »), jamais « ŒUF »/« UF ».
        .replace(/œ/g, 'oe')
        .replace(/æ/g, 'ae')
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
    // [OWNER8 2026-07-06] Oignons CUITS → O̲ (O + U+0332 combining low line) —
    // AVANT /oignon/ (sinon le cru matcherait d'abord). Écran : rendu natif dans
    // {{ line.label }}. Ticket : EscPosCommandBuilder::encodeForPrinter traduit
    // X+U+0332 en soulignement matériel ESC - 1 X ESC - 0 (CP858 sans combining).
    // Jumeau STRICT : KitchenTicketSymbolicFormatter::CRUDITE_TABLE (même string).
    [/oignon.*cuit|cuit.*oignon/, 'O̲'],
    [/oignon/, 'O'],
];
/** Canonical print order of crudités → "STOO̲". */
const CRUDITE_ORDER = ['S', 'T', 'O', 'O̲'];

export function meatSymbol(name) {
    const n = normalize(name);
    // [OWNER 2026-07-31] Viande « mixte » dont le NOM contient plusieurs viandes (ex.
    // « Mixte (hachée + poulet) ») → affiche TOUTES ses lettres, pas seulement la 1ère (« K »).
    // Poulet (P) en tête si présent → « P K ». Contrat inchangé : 0 match = '', 1 viande = 1 lettre.
    // Jumeau STRICT : app/Services/Hardware/KitchenTicketSymbolicFormatter::meatSymbol (PHP).
    let syms = [];
    for (const [re, sym] of MEAT_TABLE) {
        if (re.test(n) && !syms.includes(sym)) syms.push(sym);
    }
    if (syms.length > 1 && syms.includes('P')) {
        syms = ['P', ...syms.filter(s => s !== 'P')];
    }
    return syms.join(' ');
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

/**
 * [MEGA-BORNE 2026-07-22 owner] Un TACOS n'affiche PAS de taille en cuisine : le nombre de
 * viandes (« K P ») porte l'info. Jumeau STRICT du PHP KitchenTicketSymbolicFormatter::isTacos()
 * (même regex sur le nom normalisé — parité écran↔ticket).
 */
function isTacos(name) {
    return /\btacos?\b/.test(normalize(name));
}

/**
 * Un item ADDON « Menu (Frites + Boisson) » / « Formule … » → affiché juste « MENU ».
 * [SYNC-BORNE 2026-07-01] Parité PHP : on NE matche QUE l'addon (menu + parenthèse, ou
 * « formule ») — surtout PAS un vrai produit "Menu Enfant Burger"/"Nuggets" qui DOIT
 * garder son identité sur l'écran cuisine.
 */
export function isMenuItem(name) {
    return /\bmenu\s*\(|\bformule\b/.test(normalize(name));
}

/**
 * Sauce(s) frites du menu (depuis l'instruction) → SYMBOLE(s) court(s) (Andalouse → AND).
 *
 * [MULTIFRITES 2026-07-18] owner : « si le client a plusieurs sortes pour les frites, on
 * pourra lui mettre ça ». La sauce frites est un canal GRATUIT (dip frites, aucun extra/
 * prix) — quand le client en choisit plusieurs, le wizard écrit « Sauce frites : Ketchup,
 * Mayonnaise » et l'écran KDS + le ticket cuisine doivent les montrer TOUTES (« KTP MAY »),
 * pas seulement la 1ère. On mappe CHAQUE sauce du segment (split virgule, ordre de
 * sélection préservé) — 1 seule sauce reste un symbole unique (rétro-compatible). Jumeau
 * PHP : KitchenTicketSymbolicFormatter::fritesSauceSymbol().
 */
export function fritesSauceSymbol(instruction) {
    if (typeof instruction !== 'string' || instruction === '') return '';
    const m = instruction.match(/sauce\s*frites\s*:\s*([^\n]+)/i);
    if (!m) return '';
    return splitSauceList(m[1]).map((n) => sauceSymbol(n)).filter(Boolean).join(' ');
}

/**
 * [MULTISAUCE 2026-07-18] Recover the NAME(s) of the extra sauce(s) that the FROZEN
 * wizards emit as a GENERIC, nameless "Sauce supplémentaire" item_extra. The identity
 * survives only in the free-text instruction:
 *   - caisse (pos-wizard.js:3805) : "… Sauce : <1ère>, <en plus…>" (1ère gratuite incluse)
 *   - borne/web (KioskWizardComponent.vue:2147) : "Sauces en plus : <en plus…>" (extras seuls)
 * "Sauce frites :" (dip frites gratuit, autre canal) n'est JAMAIS capté. Empty when
 * unparsable → callers render the generic label as before (retro-compatible). Price-
 * neutral display recovery. PHP twin: KitchenTicketSymbolicFormatter::extraSauceNames.
 */
export function extraSauceNames(instruction) {
    if (typeof instruction !== 'string' || instruction.trim() === '') return [];
    // Borne/web write ONLY the extras.
    let m = instruction.match(/sauces?\s+en\s+plus\s*:\s*([^\n.]+)/i)
        || instruction.match(/extra\s+sauces?\s*:\s*([^\n.]+)/i);
    if (m) return splitSauceList(m[1]);
    // Caisse writes ALL sauces (1st = free variation, rest = paid extras). "Sauce frites :"
    // never matches ("Sauce" is not immediately followed by ":"). Alternation avoids a
    // lookbehind (portability of the browser bundle).
    m = instruction.match(/(?:^|[^\p{L}])sauces?\s*:\s*([^\n]+)/iu);
    if (m) return splitSauceList(m[1]).slice(1);
    return [];
}

/**
 * [MULTIVIANDE 2026-07-24] Recover the NAME(s) of the extra meat(s) the FROZEN wizards emit
 * as a GENERIC, nameless "Viande supplémentaire" item_extra (@2,50) — otherwise the cook reads
 * "⭐ Viande supplémentaire ×N" and does NOT know WHICH meat. Strict MIRROR of extraSauceNames():
 * the identity survives only in the free-text instruction, on a DEDICATED extra-meat line the
 * wizards write like the sauce one ("Sauces en plus : …") : "Viandes en plus : <noms>" — the
 * EXTRAS ONLY (the base meats already live in the snapshot lines → Line 1, never re-listed here).
 * Tolerant to the "Viande(s) supplémentaire(s) : …" wording, accents (é/e) and case. The bare
 * "Viandes : X, Y" composition line is NEVER parsed. Empty when unparsable → callers keep the
 * generic label. Price-neutral. PHP twin: KitchenTicketSymbolicFormatter::extraViandeNames.
 */
export function extraViandeNames(instruction) {
    if (typeof instruction !== 'string' || instruction.trim() === '') return [];
    const m = instruction.match(/viandes?\s+en\s+plus\s*:\s*([^\n.]+)/i)
        || instruction.match(/viandes?\s+suppl[ée]mentaires?\s*:\s*([^\n.]+)/i);
    if (m) return splitViandeList(m[1]);
    return [];
}

/**
 * [MULTISAUCE 2026-07-18] Display label naming the generic "Sauce supplémentaire" with
 * the recovered sauce name(s). [MULTIVIANDE 2026-07-24] Same mirror for the generic "Viande
 * supplémentaire" (@2,50) → "Viande supplémentaire : Poulet, Merguez". Any already-named
 * extra is unchanged. PHP twin: KitchenTicketSymbolicFormatter::extraDisplayName.
 */
export function extraDisplayName(name, instruction) {
    const label = String(name || '');
    if (/sauce\s*suppl/i.test(label)) {
        const names = extraSauceNames(instruction);
        return names.length ? `${label} : ${names.join(', ')}` : label;
    }
    if (/viande\s*suppl/i.test(label)) {
        const names = extraViandeNames(instruction);
        return names.length ? `${label} : ${names.join(', ')}` : label;
    }
    return label;
}

/** Split a "A, B, C" sauce list → trimmed, non-empty names. */
function splitSauceList(raw) {
    return String(raw).split(',').map((s) => s.trim()).filter(Boolean);
}

/**
 * [MULTIVIANDE 2026-07-24] Split a "A, B, C" meat list → trimmed, "+"-stripped (legacy caisse
 * prefixes an extra with "+"), non-empty, DEDUPED (order-preserving). PHP twin:
 * KitchenTicketSymbolicFormatter::splitViandeList.
 */
function splitViandeList(raw) {
    const out = [];
    for (const token of String(raw).split(',')) {
        const name = token.trim().replace(/^\+\s*/, '').trim();
        if (name && !out.includes(name)) out.push(name);
    }
    return out;
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

/**
 * [T3-CUISINE 2026-07-05] Code produit 3 lettres (Cayenne→CAY, Terminator→TER). 3 lettres du
 * DERNIER mot significatif → distingue « Menu Enfant Burger » (BUR) de « ... Nuggets » (NUG).
 * PARITÉ STRICTE avec le PHP KitchenTicketSymbolicFormatter::produitCode() (ticket == écran).
 */
const CODE_GENERIC_WORDS = ['menu', 'enfant', 'formule', 'grande', 'grand', 'petite', 'petit', 'mini', 'maxi', 'moyenne', 'moyen', 'box'];
// [F-KITCHEN-BOL-BASE 2026-07-15] Mots-catégorie dont la BASE distinctive suit (Bol Frites/Bol Riz).
const CODE_BASE_WORDS = ['bol'];
function produitCode(produit) {
    const n = normalize(produit).replace(/[^a-z0-9 ]+/g, ' ').trim();
    if (!n) return '';
    const words = n.split(' ').filter(Boolean);
    // Premier mot SIGNIFICATIF : saute prefixes generiques + tailles/volumes (parite PHP).
    const significant = words.filter((w) => !CODE_GENERIC_WORDS.includes(w) && !/^\d+(cl|ml|l|g|kg)?$/.test(w));
    const base = significant[0] || words[0] || n;
    let code = base.slice(0, 3).toUpperCase();
    // [F-KITCHEN-BOL-BASE 2026-07-15 / P1] « Bol Frites »/« Bol Riz » → « BOL » ambigu pour le
    // cuisinier. Mot-base (bol) + 2e mot significatif → « BOL FRI »/« BOL RIZ » (parite PHP stricte).
    if (CODE_BASE_WORDS.includes(base) && significant[1]) {
        code += ' ' + significant[1].slice(0, 3).toUpperCase();
    }
    // [F-01 AUDIT CUISINIER 2026-08-01 · P0] « Chicken Burger » et « Menu Enfant Chicken Burger »
    // réduisaient tous deux à la MÊME ligne : le mot « enfant » était strippé comme générique, donc
    // la portion enfant (plus petite, frites + boisson incluses) devenait invisible sur l'écran ET
    // sur le ticket imprimé → mauvaise préparation garantie quand les deux sont dans la commande.
    // On garde le code distinctif (BUR/NUG, raison d'être du strip) et on REMET le marqueur enfant.
    // Parité stricte avec le PHP KitchenTicketSymbolicFormatter::produitCode().
    if (words.includes('enfant')) {
        code = 'ENF ' + code;
    }
    return code;
}

/** Split "Tacos M" → { produit: "TAC", taille: "M" }. Only M/L/XL trailing tokens. */
function produitAndSize(itemName) {
    const raw = String(itemName || '').trim();
    const m = raw.match(/\s+(XL|L|M)\s*$/i);
    if (m) {
        // [MEGA-BORNE 2026-07-22 owner] Tacos : on retire le token taille du NOM pour le code
        // produit (TAC) mais on NE renvoie PAS la taille (la cuisine l'ignore — jumeau PHP).
        const taille = isTacos(raw) ? '' : m[1].toUpperCase();
        return { produit: produitCode(raw.slice(0, m.index).trim()), taille };
    }
    return { produit: produitCode(raw), taille: '' };
}

/**
 * Decompose an order item into the symbolic slots.
 * @returns {{category, support, produit, taille, viandes:string[], crudites:string, sauces:string[], supplements:string[], menu:string}}
 */
export function buildSymbolic(orderItem) {
    const category = categorize(orderItem);
    const { produit, taille: nameSize } = produitAndSize(orderItem?.item_name);
    // [MEGA-BORNE 2026-07-22 owner] Tacos : aucune taille (produitAndSize l'a déjà retirée du
    // NOM) — on neutralise aussi une éventuelle taille portée par une VARIATION (garde plus bas).
    const isTacosItem = isTacos(orderItem?.item_name);

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
                // Tacos : taille ignorée en cuisine (le nombre de viandes porte l'info).
                if (!isTacosItem) taille = taille || String(value).toUpperCase();
                break;
            default:
                break;
        }
    }

    // [MEGA-BORNE 2026-07-22 owner] La/les sauce(s) EN PLUS du produit (extras génériques dont le
    // nom ne survit que dans l'instruction) remontent dans le slot Sauce(s) de la ligne 1, À CÔTÉ
    // de la 1ère incluse (« FRO MAY »). La sauce FRITES du menu reste en ligne 2. Jumeau PHP mainLine.
    for (const extraSauce of extraSauceNames(orderItem?.instruction)) {
        const sym = sauceSymbol(extraSauce);
        if (sym) sauces.push(sym);
    }

    // [MEGA-BORNE 2026-07-22 owner] La sauce EN PLUS remonte en ligne 1 (slot Sauce) DÈS QUE son nom
    // est récupérable (extraSauceNames non vide) → plus de ligne « + Sauce supplémentaire ». Sinon
    // (legacy non parsable) on GARDE le libellé générique (ne pas perdre une sauce payée). Jumeau PHP.
    const extraSaucesFolded = extraSauceNames(orderItem?.instruction).length > 0;
    for (const e of readExtras(orderItem)) {
        const name = extraName(e);
        if (!name) continue;
        const cs = cruditeSymbol(name);
        const price = Number(e?.unit_price ?? e?.line_total ?? 0) || 0;
        // Only FREE garnitures (price 0) fold into the crudités slot; a paid extra
        // that happens to match (e.g. "Oignons frits" 0,90) is a supplement.
        if (cs && price <= 0) {
            crud.add(cs);
        } else if (extraSaucesFolded && /sauce\s*suppl/i.test(name)) {
            // La sauce en plus générique remonte en ligne 1 (slot Sauce) → pas de supplément.
            continue;
        } else {
            const q = parseInt(e?.quantity, 10);
            const suffix = (Number.isFinite(q) && q > 1) ? ` ×${q}` : '';
            // [MULTIVIANDE 2026-07-24] Name the generic "Viande supplémentaire" with the
            // recovered meat name(s) so the KDS supplement line tells the cook WHICH meat
            // (mirror of the client ticket + the PHP supplementLines). Others unchanged.
            supplements.push(`+ ${extraDisplayName(name, orderItem?.instruction)}${suffix}`);
        }
    }

    // Owner rule: tacos (and any galette product) show the support first, default G.
    if (!support && (category === 'taco' || /galette/.test(normalize(orderItem?.item_name)))) {
        support = 'G';
    }

    // Line 3: a full formule → "MENU"; a PARTIAL formule → "FRITES"/"BOISSON" (a lone
    // frites-only or boisson-only is NOT a full menu — the kitchen must not serve the
    // whole formule = revenue leak); frites+boisson together = the full menu = "MENU";
    // a lone frites add-on → "F"; else nothing.
    const addons = readAddons(orderItem);
    let menu = '';
    let hasFull = false, hasFrites = false, hasBoisson = false;
    for (const a of addons) {
        const role = String(a?.role || '').toLowerCase();
        if (!role.startsWith('menu_')) continue;
        if (role === 'menu_frites') hasFrites = true;
        else if (role === 'menu_boisson') hasBoisson = true;
        else hasFull = true; // menu_full / menu_formule / future menu_*
    }
    if (hasFull || (hasFrites && hasBoisson)) {
        menu = 'MENU';
    } else if (hasFrites) {
        menu = 'FRITES';
    } else if (hasBoisson) {
        menu = 'BOISSON';
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
 * [W3-FIX-C 2026-07-06] Addon boisson (role 'drink' / 'menu_boisson' / *boisson*) —
 * le cuisinier PRÉPARE les boissons : elles doivent être visibles écran + ticket.
 * Jumeau STRICT du PHP KitchenTicketSymbolicFormatter::drinkLines().
 */
function isDrinkAddonRole(role) {
    const r = String(role || '').toLowerCase();
    return r === 'drink' || r === 'menu_boisson' || r.includes('boisson');
}

/** Lignes boisson d'un item ("1× Coca-Cola 33cl") depuis ses addons. */
function drinkAddonLabels(orderItem) {
    const out = [];
    for (const a of readAddons(orderItem)) {
        if (!isDrinkAddonRole(a?.role)) continue;
        const name = addonName(a);
        if (!name) continue;
        // [CLUSTER-6 2026-07-11] Ne PAS émettre le conteneur de formule : role
        // 'menu_boisson' porte parfois le nom du conteneur (« Menu (Frites + Boisson) »),
        // qui n'est PAS une boisson. La garde isDrinkName rejette « menu/formule », la
        // vraie boisson (« Coca 33cl ») passe. Jumeau PHP drinkLines (isDrinkItem).
        if (!isDrinkName(name)) continue;
        const q = parseInt(a?.quantity, 10);
        const qty = Number.isFinite(q) && q > 0 ? q : 1;
        out.push(`${qty}× ${name}`);
    }
    return out;
}

/**
 * [W3-FIX-A 2026-07-06] Note client sanitisée → ligne type:'instruction' (rendue par
 * KdsOrderLine.vue). sanitizeKdsInstruction garde les notes libres (« oignons cuits »,
 * « BOISSON: Coca-Cola 33cl » du wizard caisse) et strip l'écho compo du wizard.
 */
function instructionLine(orderItem) {
    const note = sanitizeKdsInstruction(orderItem?.instruction, orderItem?.item_name);
    if (note.length === 0) return null;
    return { type: 'instruction', label: note, visualClass: kdsInstructionVisualClass(note) };
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
        // [W3-FIX-C] Boisson de la formule visible sous le badge MENU.
        for (const d of drinkAddonLabels(orderItem)) {
            lines.push({ type: 'menu_child', label: d });
        }
        // [W3-FIX-A] Note client visible aussi sur un item Menu/Formule.
        const menuNote = instructionLine(orderItem);
        if (menuNote) {
            lines.push(menuNote);
        }
        if (hasAllergen) {
            lines.push({ type: 'allergen', codes: allergenCodes });
        }
        return { category: s.category, hasAllergen, lines };
    }

    lines.push({
        type: 'symbolic-main',
        qty: orderItem?.quantity ?? 1,
        // [W3-FIX-C 2026-07-06] Item BOISSON (Coca standalone #5456) → nom COMPLET :
        // « COC » cryptique ne dit pas au cuisinier QUELLE boisson préparer.
        // Jumeau PHP : OrderReceiptEscPosRenderer::renderKitchenTicket (isDrinkItem).
        label: s.category === 'drink'
            ? String(orderItem?.item_name || '').trim()
            : symbolicMainLine(orderItem),
        category: s.category,
        hasAllergen,
    });

    // [KITCHEN-MENU 2026-06-30] Ordre owner (identique au ticket imprimé) :
    // ligne 2 = MENU (: sauce frites symbole) PUIS les suppléments.
    if (s.menu) {
        // [CLUSTER-2 2026-07-11] MENU et FRITES portent une sauce frites → l'annoter en
        // symbole (« MENU : ALG », « FRITES : ALG »). BOISSON n'a pas de sauce frites.
        const menuLabel = (s.menu === 'MENU' || s.menu === 'FRITES') && fritesSym
            ? `${s.menu} : ${fritesSym}`
            : s.menu;
        lines.push({ type: 'symbolic-menu', label: menuLabel });
    }

    for (const sup of s.supplements) {
        // [K2-KDS 2026-07-05] Supplément payant signalé par une ÉTOILE ⭐ (écran → emoji OK)
        // + affiché en gras jaune (CSS) → repérage immédiat par le cuisinier.
        lines.push({ type: 'supplement', label: String(sup).replace(/^\+\s*/, '⭐ ') });
    }

    // [W3-FIX-C 2026-07-06] Addons boisson (role drink / menu_boisson) → lignes visibles
    // « 1× Boisson Seule » (type menu_child, déjà rendu par KdsOrderLine.vue).
    for (const d of drinkAddonLabels(orderItem)) {
        lines.push({ type: 'menu_child', label: d });
    }

    // [W3-FIX-A 2026-07-06] Note client (« oignons cuits », « BOISSON: X » du POS) après
    // les suppléments — le ticket imprimé l'avait (** note), l'écran V2 la perdait.
    const note = instructionLine(orderItem);
    if (note) {
        lines.push(note);
    }

    if (hasAllergen) {
        lines.push({ type: 'allergen', codes: allergenCodes });
    }

    return { category: s.category, hasAllergen, lines };
}
