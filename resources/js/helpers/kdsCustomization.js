/**
 * [kds/sprint-2 F-3] Adaptive customization renderer — the single source of
 * truth that converts an OrderItem (post-T07 composition_snapshot or legacy
 * item_variations/item_extras/item_addons) into a flat list of typed display
 * nodes consumed by <KdsOrderLine>.
 *
 * Vue templates MUST NOT branch per-category — they iterate `renderItem().lines`.
 * Adding a new category = one branch here + (optionally) one i18n group key.
 *
 * Per-category contract (DESIGN_SPEC_KDS_V2 §3.1):
 *   SANDWICH    : header + grouped variations (Pain/Crudités/Sauce/Cuisson)
 *                 + extras (yellow italic supplement)
 *                 + addons (menu_full/menu_frites/menu_boisson → menu_child)
 *   TACO/BURGER : same as sandwich, omit Pain group
 *   ASSIETTE    : header + comma-joined variations on one "Avec :" line
 *   MENU_FORMULE: header + addons indented as children
 *   SIDE/DRINK/DESSERT/OTHER: header + extras only, no variations grouping
 */

import { kdsInstructionVisualClass } from './kdsLineSemantics.js';

// Group keys are surfaced to i18n via `label.kds_group_<key>`.
// Heuristic-keyword regex per group. The first match wins.
const GROUP_PATTERNS = [
    { key: 'bread', re: /\bpain\b|\bbread\b/i },
    { key: 'crudites', re: /crudit|crudity|salade|legum|vegetab/i },
    { key: 'sauce', re: /\bsauce\b/i },
    { key: 'cooking', re: /cuisson|cook|temperature/i },
    { key: 'drink', re: /boisson|drink|beverage/i },
    { key: 'size', re: /\btaille\b|\bsize\b|\bportion\b/i },
];

function classifyGroup(variationName, attributeName) {
    const name = `${variationName || ''} ${attributeName || ''}`;
    for (const g of GROUP_PATTERNS) {
        if (g.re.test(name)) {
            return g.key;
        }
    }
    return 'other';
}

// [W6-ADV B-1 2026-07-06] Détection boisson robuste — le match noms ratait 8/15
// boissons actives DB (« Hawaï 33cl » — régression du renommage Fanta Hawai —,
// Orangina, Capri-Sun, Tropico, Ice Tea, Fuze Tea, Perrier, Oasis) → codes
// cryptiques « 1× HAW » en cuisine. Couverture : marques réelles de la carte +
// « boisson » générique + token VOLUMÉTRIQUE (« 33cl », « 50 cl », « 1L »,
// « 1,5l » — seuls les liquides sont nommés au volume sur la carte).
// Jumeau STRICT : KitchenTicketSymbolicFormatter::isDrinkItem (PHP).
const DRINK_NAME_RE = /coca|fanta|sprite|\beau\b|coke|water|\bjus\b|\bthé?\b|menthe|caf[eé]|boisson|oasis|orangina|capri|tropico|ice[ -]?tea|fuze|perrier|hawa[iï]/;
const DRINK_VOLUME_RE = /\b\d{1,2} ?cl\b|\b\d(?:[.,]\d)? ?l\b/;

/**
 * Nom de produit = BOISSON ? Applique la MÊME chaîne de gardes que categorize()
 * (un nom qui matche une catégorie antérieure — menu/sandwich/…/dessert — n'est
 * JAMAIS une boisson : « Gâteau » contient « eau », « Menu (Frites + Boisson) »
 * contient « boisson », « frites + boisson » est un libellé de formule, et
 * /glace/ ne matche PAS « glaçons » (ç≠c)). Jumeau STRICT du PHP
 * KitchenTicketSymbolicFormatter::isDrinkItem.
 *
 * @param {string} rawName
 * @returns {boolean}
 */
export function isDrinkName(rawName) {
    const n = String(rawName || '').toLowerCase().trim();
    if (
        /menu|formule/.test(n) ||
        /sandwich|kafteji|brick/.test(n) ||
        /\btacos?\b/.test(n) ||
        /burger|cheeseburger|double cheese/.test(n) ||
        /assiette|couscous|ojja|lablabi/.test(n) ||
        /frite|onion ring/.test(n) ||
        /dessert|crepe|crêpe|gateau|gâteau|glace|tiramisu/.test(n)
    ) {
        return false;
    }
    return DRINK_NAME_RE.test(n) || DRINK_VOLUME_RE.test(n);
}

/**
 * Classify an item into a category. Server can override this by populating
 * a future `items.kds_category` column; until then, name-based fallback.
 *
 * @param {object} orderItem
 * @returns {'sandwich'|'taco'|'burger'|'assiette'|'menu_formule'|'side'|'drink'|'dessert'|'other'}
 */
export function categorize(orderItem) {
    const declared = String(orderItem?.kds_category || '').toLowerCase();
    if (
        [
            'sandwich',
            'taco',
            'burger',
            'assiette',
            'menu_formule',
            'side',
            'drink',
            'dessert',
            'other',
        ].includes(declared)
    ) {
        return declared;
    }
    const name = String(orderItem?.item_name || '').toLowerCase();
    if (/menu|formule/.test(name)) {
        return 'menu_formule';
    }
    if (/sandwich|kafteji|brick/.test(name)) {
        return 'sandwich';
    }
    if (/\btacos?\b/.test(name)) {
        return 'taco';
    }
    if (/burger|cheeseburger|double cheese/.test(name)) {
        return 'burger';
    }
    if (/assiette|couscous|ojja|lablabi/.test(name)) {
        return 'assiette';
    }
    if (/frite|onion ring/.test(name)) {
        return 'side';
    }
    // [#4] Dessert BEFORE drink, and short drink tokens anchored with \b. An
    // unanchored 'eau' matched 'gâteau'/'plateau'/'château', mislabeling desserts
    // as drinks (the dessert branch was unreachable for those names).
    if (/dessert|crepe|crêpe|gateau|gâteau|glace|tiramisu/.test(name)) {
        return 'dessert';
    }
    // [W6-ADV B-1] isDrinkName rejoue les gardes ci-dessus (mêmes regex, même ordre)
    // puis matche marques + volumes — factorisé pour l'extraction boisson-de-formule.
    if (isDrinkName(name)) {
        return 'drink';
    }
    return 'other';
}

function readVariations(orderItem) {
    // Post-T07: composition_snapshot.lines wins (richer shape with attribute_name).
    const snap = orderItem?.composition_snapshot;
    if (snap && Array.isArray(snap.lines) && snap.lines.length > 0) {
        return snap.lines;
    }
    return Array.isArray(orderItem?.item_variations) ? orderItem.item_variations : [];
}

function readExtras(orderItem) {
    const snap = orderItem?.composition_snapshot;
    if (snap && Array.isArray(snap.extras) && snap.extras.length > 0) {
        return snap.extras;
    }
    return Array.isArray(orderItem?.item_extras) ? orderItem.item_extras : [];
}

function readAddons(orderItem) {
    const snap = orderItem?.composition_snapshot;
    if (snap && Array.isArray(snap.addons) && snap.addons.length > 0) {
        return snap.addons;
    }
    return Array.isArray(orderItem?.item_addons) ? orderItem.item_addons : [];
}

function variationLabel(v) {
    return v?.name || v?.variation_name || '';
}

/**
 * [POS-WIZARD-COMPO-AUDIT 2026-06-23 P2-B] Shape-agnostic GROUP/VALUE accessor.
 *
 * The composition has two on-wire shapes that INVERT the meaning of
 * `variation_name`:
 *   - snapshot (composition_snapshot.lines, OrderItemResource): `attribute_name`
 *     = GROUP ("Viande 1"), `variation_name` = VALUE ("Poulet mariné"), no `name`.
 *   - legacy (item_variations column, KDSOrderItemsResource / kiosk payload):
 *     `variation_name` = GROUP, `name` = VALUE.
 *
 * Rendering a snapshot line with the legacy template `{{variation_name}}:
 * {{name}}` produced "Poulet mariné: " (group dropped, value mislabeled). This
 * helper resolves {group, value} correctly for BOTH shapes (discriminant =
 * presence of `attribute_name`), so the KDS order-cards/print AND the legacy
 * items-board render identically and correctly.
 */
export function kdsVariationGroupValue(v) {
    if (!v || typeof v !== 'object') {
        return { group: '', value: '' };
    }
    const attr = v.attribute_name;
    if (attr !== null && attr !== undefined && String(attr) !== '') {
        // snapshot shape
        return {
            group: String(attr),
            value: String(v.variation_name ?? v.name ?? ''),
        };
    }
    // legacy shape
    return {
        group: String(v.variation_name ?? ''),
        value: String(v.name ?? ''),
    };
}

/** "GROUP: VALUE" (or just the value when there is no group) — never a dangling colon. */
export function kdsVariationLine(v) {
    const { group, value } = kdsVariationGroupValue(v);
    if (group && value) {
        return `${group}: ${value}`;
    }
    return value || group || '';
}

function extraLabel(e) {
    return e?.name || e?.extra_name || '';
}

function addonLabel(a) {
    return a?.addon_name || a?.name || '';
}

// Compo markers that the STRUCTURED render (composition_snapshot SSOT) already
// shows. "Sauce :" (group + space-colon) is compo; "↳ Sauce frites: X" is an
// extra (no space before ':', kept by the ↳-prefix rule below before this runs).
const KDS_COMPO_LINE_RE = /(^|\s)(Viandes?|Sauce|Suppl[ée]ment|Pain|Galette)\s*:/i;
// [VIANDE-TICKET 2026-08-03 · RED P1 FOOD-SAFETY] « Viandes/Sauces en plus : … » = compo
// repliée dans la ligne extra nommée → on strippe le SEGMENT, jamais la ligne (la borne
// joint tout par '. ' : une note client/ALLERGIE peut partager la ligne). Le segment
// s'arrête à '.' ou '|' pour préserver la suite. Parité : cleanInstruction PHP.
const KDS_EN_PLUS_SEGMENT_RE = /(Viandes?|Sauces?)\s+en\s+plus\s*:\s*[^\n.|]*/gi;

/**
 * [W6-ADV C-P1-1 2026-07-06] La BORNE écrit la boisson de formule DANS la ligne
 * « Pain : Pain. Formule : Menu complet (frites + boisson) (Hawaï 33cl). Sauce
 * frites : Algérienne » (UNE seule ligne — shape réel #5533) que le sanitizer
 * droppe entière (anti double-menu / compo) → la boisson mourait avec (ni KDS ni
 * ticket, le cuisinier ne savait pas quelle boisson préparer). On EXTRAIT le(s)
 * segment(s) entre parenthèses validés boisson (isDrinkName — la garde rejette
 * « (frites + boisson) », libellé de formule) et on les émet au format CAISSE
 * « BOISSON: X », canal déjà préservé par le sanitize et affiché KDS + ticket.
 * Jumeau STRICT : KitchenTicketSymbolicFormatter::extractFormuleDrinkLines.
 *
 * @param {string} raw
 * @returns {string[]} lignes « BOISSON: X » (peut être vide)
 */
function extractFormuleDrinkLines(raw) {
    const out = [];
    for (const ln of String(raw).split('\n')) {
        if (!/formule\s*:/i.test(ln)) continue;
        for (const m of ln.matchAll(/\(([^()]+)\)/g)) {
            const seg = m[1].trim();
            if (seg !== '' && isDrinkName(seg)) {
                out.push(`BOISSON: ${seg}`);
            }
        }
    }
    return out;
}

/**
 * Sanitize the free-text `instruction` for KDS / kitchen-ticket display.
 *
 * The FROZEN pos-wizard.js (buildTicketInstruction) writes the FULL composition
 * into `instruction`: line0 = PRODUCT NAME (uppercased), line1 = compo blob
 * ("Viandes : X Sauce : Y - crudités"), then UNIQUE extras ("+ Menu (…)",
 * "↳ Sauce frites: X", "[note]") and any free client note. The KDS already
 * renders the composition STRUCTURALLY from composition_snapshot (the SSOT), so
 * echoing the raw instruction DOUBLES it — and on legacy bridge-divergent rows
 * shows TWO contradictory sauces for one product (kitchen makes the wrong food).
 *
 * This keeps ONLY what the structured render does NOT carry: the formule child
 * ("+ …"), the "↳ Sauce frites: X" choice (exists ONLY here, never in the
 * snapshot), bracketed notes, and free client notes. It drops the product-name
 * line and the compo blob. pos-wizard.js (the writer) is FROZEN and untouched —
 * this is a display-only sanitiser.
 *
 * @param {string} raw     orderItem.instruction
 * @param {string} itemName orderItem.item_name (to strip the echoed name line)
 * @returns {string} cleaned instruction (may be empty → caller emits no line)
 */
export function sanitizeKdsInstruction(raw, itemName) {
    if (typeof raw !== 'string') {
        return '';
    }
    const name = String(itemName || '').trim().toUpperCase();
    // [FOOD-SAFETY 2026-06-27] pos-wizard.js (frozen) wrappe la note libre caissier
    // en [...] ; une note multi-ligne « Allergie:\n- gluten\n- arachide » devient
    // « [Allergie:\n- gluten\n- arachide] ». Sans suivi d'état, les lignes « - X »
    // de continuation étaient confondues avec un retrait de compo (l.« ^-\s ») et
    // DROPPÉES → les allergènes disparaissaient du ticket cuisine imprimé. On garde
    // donc toute ligne tant qu'une note crochet ouverte n'est pas refermée par « ] ».
    let insideBracketNote = false;
    // [RED 2026-08-03] Strip des segments « en plus » AVANT le filtrage ligne (cf. regex).
    const preStripped = raw.replace(KDS_EN_PLUS_SEGMENT_RE, '');
    const kept = preStripped.split('\n').filter((ln) => {
        const t = ln.trim().replace(/^[.|\s]+/, '').trim();
        if (t === '') return false;
        if (name && t.toUpperCase() === name) return false; // echoed product name (exact)
        // [KITCHEN 2026-06-30] Ligne 100% MAJUSCULES (sans chiffre) = écho du nom produit
        // écrit par le wizard (« TACOS », « SANDWICH CAYENNE ») → drop (la L1 le montre déjà).
        if (!insideBracketNote && t.length >= 2 && /^[A-ZÀ-Ÿ][A-ZÀ-Ÿ '\-]*$/.test(t)) return false;
        if (insideBracketNote) {                            // continuation d'une note libre multi-ligne → KEEP
            if (t.includes(']')) insideBracketNote = false;
            return true;
        }
        if (/^\[/.test(t)) {                                // bracketed note → KEEP
            if (!t.includes(']')) insideBracketNote = true; // ouvre une note multi-ligne
            return true;
        }
        // [KITCHEN-MENU 2026-06-30] Menu + sauce frites sont rendus par la ligne
        // symbolique « MENU : SYM » → on les retire ici (anti double-menu / verbeux).
        if (/sauce\s*frites|menu\s*\(\s*frites|^\+\s*menu\b/i.test(t)) return false;
        if (/^[+↳]/.test(t)) return true;                   // autres formule / notes → KEEP
        if (/^-\s/.test(t)) return false;                   // bare crudités-removal (structured covers it)
        if (KDS_COMPO_LINE_RE.test(t)) return false;        // compo blob "Viandes : … Sauce : …" → DROP (dup)
        return true;                                        // free client note → KEEP
    });
    // [KITCHEN-NOPRICE 2026-06-30] Cuisine sans prix : retire « (+2,00 €) » / « (+2,50) »
    // des notes conservées (parité avec KitchenTicketSymbolicFormatter::cleanInstruction).
    const cleaned = kept.map((l) =>
        // [RED 2026-08-03] + séparateurs orphelins en tête (résidus du strip « en plus »)
        // — parité avec le push $t nettoyé côté PHP.
        l.replace(/\s*\(\s*\+?\s*(?:€|EUR)?\s*\d+[.,]\d{1,2}\s*(?:€|EUR)?\s*\)/g, '').trim().replace(/^[.|\s]+/, '').trim()
    );
    // [W6-ADV C-P1-1] Boisson de formule borne extraite AVANT le drop de sa ligne —
    // dédupliquée si la caisse a déjà écrit sa propre ligne « BOISSON: X ».
    for (const d of extractFormuleDrinkLines(raw)) {
        if (!cleaned.some((l) => l.toLowerCase() === d.toLowerCase())) {
            cleaned.push(d);
        }
    }
    return cleaned.join('\n').trim();
}

/**
 * Render an order item into a flat typed line list.
 *
 * Line types:
 *   'header'        — the item itself (qty + name + allergen icon)
 *   'variation'     — grouped or single variation row (Pain / Crudités / …)
 *   'variation-flat'— the assiette "Avec : a, b, c" one-liner
 *   'supplement'    — paid extra "+ Cheddar"
 *   'addon'         — generic addon (rare)
 *   'menu_child'    — addon with role startsWith 'menu_'
 *   'instruction'   — free-text note (classified note/exclusion/allergen)
 *   'allergen'      — explicit allergens_snapshot codes block
 *
 * @param {object} orderItem
 * @returns {{ category: string, hasAllergen: boolean, lines: Array<object> }}
 */
export function renderItem(orderItem) {
    const category = categorize(orderItem);
    const lines = [];

    const allergenCodes = Array.isArray(orderItem?.allergens_snapshot)
        // [#8] Coerce to string BEFORE filtering — numeric allergen codes (the
        // backend hash uses strval) were silently dropped, suppressing the
        // allergen line AND the orange card border. Food-safety: never drop a
        // non-empty code regardless of its JS type.
        ? orderItem.allergens_snapshot
            .map((c) => (c === null || c === undefined ? '' : String(c)))
            .filter((c) => c.length > 0)
        : [];
    const itemAllergen = allergenCodes.length > 0;

    lines.push({
        type: 'header',
        qty: orderItem?.quantity ?? 1,
        label: orderItem?.item_name || '',
        category,
        hasAllergen: itemAllergen,
    });

    const vars = readVariations(orderItem);
    if (vars.length > 0) {
        if (category === 'assiette') {
            const joined = vars
                .map((v) => {
                    const q = parseInt(v?.quantity, 10);
                    const prefix = Number.isFinite(q) && q > 1 ? `${q} ` : '';
                    return `${prefix}${variationLabel(v)}`;
                })
                .filter((s) => s.trim().length > 0)
                .join(', ');
            if (joined.length > 0) {
                lines.push({ type: 'variation-flat', group: 'avec', label: joined });
            }
        } else {
            // Group by classified attribute name. SANDWICH renders Pain, others omit.
            const byGroup = new Map();
            for (const v of vars) {
                const g = classifyGroup(v?.variation_name, v?.attribute_name);
                if (g === 'bread' && (category === 'taco' || category === 'burger')) {
                    // taco/burger: skip the Pain group (no bread choice)
                    continue;
                }
                if (!byGroup.has(g)) {
                    byGroup.set(g, []);
                }
                byGroup.get(g).push(variationLabel(v));
            }
            for (const [group, labels] of byGroup.entries()) {
                lines.push({
                    type: 'variation',
                    group,
                    label: labels.filter((l) => l.length > 0).join(', '),
                });
            }
        }
    }

    // Paid supplements — yellow italic, "+" prefix.
    for (const e of readExtras(orderItem)) {
        const label = extraLabel(e);
        if (!label) continue;
        const q = parseInt(e?.quantity, 10);
        const suffix = Number.isFinite(q) && q > 1 ? ` ×${q}` : '';
        lines.push({ type: 'supplement', label: `+ ${label}${suffix}` });
    }

    // Menu Formule children (composition_snapshot.addons[].role startsWith 'menu_').
    for (const a of readAddons(orderItem)) {
        const label = addonLabel(a);
        if (!label) continue;
        const role = String(a?.role || '').toLowerCase();
        const isMenuChild = role.startsWith('menu_');
        const q = parseInt(a?.quantity, 10);
        const qtyPrefix = Number.isFinite(q) && q > 1 ? `${q}× ` : '';
        lines.push({
            type: isMenuChild ? 'menu_child' : 'addon',
            label: `${qtyPrefix}${label}`,
            role,
        });
    }

    // Free-text instruction — sanitized (strip the compo duplicate the
    // structured render already shows, keep unique extras), then keyword-classified.
    const instruction = sanitizeKdsInstruction(orderItem?.instruction, orderItem?.item_name);
    if (instruction.length > 0) {
        lines.push({
            type: 'instruction',
            label: instruction,
            visualClass: kdsInstructionVisualClass(instruction),
        });
    }

    // Allergens_snapshot codes (separate from instruction-keyword detection).
    if (itemAllergen) {
        lines.push({ type: 'allergen', codes: allergenCodes });
    }

    return { category, hasAllergen: itemAllergen, lines };
}

/**
 * Aggregate `hasAllergen` across an entire order — used to flip the card-level
 * orange border override regardless of age.
 *
 * @param {Array} orderItems
 * @returns {boolean}
 */
export function orderHasAnyAllergen(orderItems) {
    if (!Array.isArray(orderItems)) {
        return false;
    }
    for (const it of orderItems) {
        const codes = Array.isArray(it?.allergens_snapshot) ? it.allergens_snapshot : [];
        // [#8] Coerce — numeric/non-string codes count as allergens too (food safety).
        if (codes.some((c) => c !== null && c !== undefined && String(c).length > 0)) {
            return true;
        }
    }
    return false;
}
