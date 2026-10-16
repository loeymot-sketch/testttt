/**
 * orderLabelBuilder.js — [ETIQUETTES 2026-10-16] Commande → étiquettes, UNE PAR PRODUIT (et par unité).
 *
 * Demande du propriétaire : sur chaque étiquette, en haut le numéro de commande, puis le nom du
 * produit, puis sa personnalisation EN CLAIR (sauces, crudités, menu ou seul, frites, suppléments —
 * pas les symboles du ticket cuisine), et un numéro « k/N » pour savoir combien de produits compte
 * la commande et lequel on tient en main.
 *
 * AUCUNE règle de composition n'est réécrite ici : tout ce qui sait lire une ligne de commande
 * (formules repliées, sauces en plus, sauce frites, boissons de formule, crudités) vient des helpers
 * de l'écran cuisine. Ils ont déjà coûté des incidents réels ; une seconde lecture finirait par diverger
 * et l'étiquette mentirait à la cuisine.
 *
 * Fonctions pures : aucune dépendance au navigateur, testées sur des commandes réelles de la base.
 */
import { collapseBundledAddonItems } from './kdsBundledAddons.js';
import { categorize, isDrinkName, kdsVariationGroupValue, sanitizeKdsInstruction } from './kdsCustomization.js';
import {
    buildSymbolic,
    classifyVariation,
    cruditeSymbol,
    drinkAddonLabels,
    EXTRA_SANS_NOM,
    extraDisplayName,
    extraName,
    friesSauceNamesForOrder,
    productSauceNames,
    readExtras,
    readVariations,
} from './kdsSymbolic.js';
import { KDS_SOURCE, kdsSourceFromSurface } from './kdsSource.js';

/** Plafond de sécurité : une quantité aberrante ne doit pas vider le rouleau. */
export const MAX_UNITS_PER_LINE = 30;

const SOURCE_LABEL = Object.freeze({
    [KDS_SOURCE.POS]: 'Caisse',
    [KDS_SOURCE.KIOSK]: 'Borne',
    [KDS_SOURCE.DELIVERY]: 'Livraison',
    [KDS_SOURCE.ONLINE]: 'Web',
    [KDS_SOURCE.APP]: 'Application',
    [KDS_SOURCE.DINE_IN]: 'Sur place',
    [KDS_SOURCE.UBER]: 'UBER EATS',
    [KDS_SOURCE.PHONE]: 'Téléphone',
});

function norm(s) {
    return String(s || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/œ/gi, 'oe')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * Le wizard caisse écrit parfois plusieurs rubriques sur UNE ligne, séparées par « · »
 * (« Boisson menu: Fanta · Sauce frites: Samouraï »). Les lecteurs de sauces s'arrêtent au retour à la
 * ligne : sans cette mise à plat, la rubrique suivante se retrouverait collée à la dernière sauce.
 */
export function normalizeInstruction(raw) {
    return typeof raw === 'string' ? raw.replace(/\s*[·•]\s*/g, '\n') : '';
}

/** « Viande 2 » → « Viande » ; « Sauce (1ère Gratuite) » → « Sauce » ; « Type de Pain » inchangé. */
function cleanGroup(group) {
    return String(group || '')
        .replace(/\([^)]*\)/g, '')
        .replace(/\s+\d+\s*$/, '')
        .replace(/\s+/g, ' ')
        .trim();
}

function unique(list) {
    const seen = new Set();
    const out = [];
    list.forEach((v) => {
        const key = norm(v);
        if (key && !seen.has(key)) { seen.add(key); out.push(v); }
    });
    return out;
}

function plural(count, one, many) {
    return count > 1 ? many : one;
}

/** « Sauce Samouraï » → « Samouraï » : le mot « Sauce » figure déjà devant la liste. */
function sauceName(value) {
    const v = String(value || '').trim().replace(/^sauces?\s+(?:d['’]\s*|de\s+|du\s+)?/i, '').trim();
    return v ? v.charAt(0).toUpperCase() + v.slice(1) : String(value || '').trim();
}

const NEGATION_RE = /^(sans|pas\s+d[eu']|no|without|w\/o)\b/i;

/** Boissons de formule d'une ligne : canal addon, rubrique « Boisson menu: X » / « BOISSON: X », « Formule : … (X) ». */
function drinksOfItem(item) {
    const out = [];
    drinkAddonLabels(item).forEach((l) => out.push(String(l).replace(/^\d+\s*[x×]\s*/, '').trim()));
    String(item.instruction || '').split('\n').forEach((line) => {
        const m = line.match(/^\s*(?:↳\s*)?boissons?(?:\s+menu)?\s*:\s*(.+)$/i);
        if (m) out.push(m[1].trim());
        if (/formule\s*:/i.test(line)) {
            for (const p of line.matchAll(/\(([^()]+)\)/g)) {
                const seg = p[1].trim();
                if (seg && isDrinkName(seg)) out.push(seg);
            }
        }
    });
    return unique(out.filter(Boolean));
}

/**
 * Description en clair d'UNE ligne de commande.
 *
 * @param {object} rawItem  ligne telle que servie par KDSOrderDetailsResource / OrderItemResource
 * @param {{allergenLabel?:(code:string)=>string}} [ctx]
 */
export function describeItem(rawItem, ctx = {}) {
    const item = { ...rawItem, instruction: normalizeInstruction(rawItem?.instruction) };
    const name = String(item.manual_label || item.item_name || '').trim();
    const category = categorize(item);
    const tacos = /\btacos?\b/i.test(name);

    const supports = [];
    const meats = [];
    const sauces = [];
    const sizes = [];
    const others = new Map();

    readVariations(item).forEach((v) => {
        const gv = kdsVariationGroupValue(v);
        let value = gv.value;
        if (!value && v && v.variation_name) value = String(v.variation_name); // même repli que la cuisine
        if (!value) return;
        switch (classifyVariation(gv.group, value)) {
            case 'meat': meats.push(value); break;
            case 'sauce': sauces.push(sauceName(value)); break;
            case 'support': if (!tacos) supports.push(value); break;
            case 'size': if (!tacos) sizes.push(value); break;
            default: {
                const g = cleanGroup(gv.group) || 'Option';
                if (!others.has(g)) others.set(g, []);
                others.get(g).push(value);
            }
        }
    });

    // Sauces en plus du produit (extras génériques dont le nom ne vit que dans l'instruction).
    const productExtraSauces = productSauceNames(item).map(sauceName);
    const allSauces = [...sauces, ...productExtraSauces];
    const friesSauces = friesSauceNamesForOrder(item);

    // Extras : crudités gratuites, retraits, suppléments payants.
    const crudites = [];
    const garnitures = [];
    const without = [];
    const supplements = [];
    let saucesExpliquees = productExtraSauces.length + Math.max(0, friesSauces.length - 1);
    readExtras(item).forEach((e) => {
        const label = extraName(e) || EXTRA_SANS_NOM;
        const price = Number(e?.unit_price ?? e?.line_total ?? 0) || 0;
        const qty = Math.max(1, parseInt(e?.quantity, 10) || 1);
        if (NEGATION_RE.test(label)) { without.push(label.replace(NEGATION_RE, '').replace(/^[\s:]+/, '').trim() || label); return; }
        if (cruditeSymbol(label) && price <= 0) { crudites.push(label); return; }
        if (/sauce\s*suppl/i.test(label)) {
            const reste = Math.max(0, qty - saucesExpliquees);
            saucesExpliquees = Math.max(0, saucesExpliquees - qty);
            if (reste > 0) supplements.push(`${label}${reste > 1 ? ` ×${reste}` : ''}`);
            return;
        }
        if (price <= 0) { garnitures.push(label); return; }
        const display = extraDisplayName(label, item.instruction);
        supplements.push(`${display}${qty > 1 && display === label ? ` ×${qty}` : ''}`);
    });

    // Formule : « menu » ou « seul ».
    const drinks = drinksOfItem(item);
    const badge = buildSymbolic(item).menu; // 'MENU' | 'FRITES' | 'BOISSON' | 'F' | ''
    let menu = null;
    if (badge === 'MENU') {
        menu = { kind: 'menu', text: `MENU : frites + ${drinks.length ? drinks.join(', ') : 'boisson'}` };
    } else if (badge === 'FRITES' || badge === 'F') {
        menu = { kind: 'frites', text: 'AVEC FRITES' };
    } else if (badge === 'BOISSON') {
        menu = { kind: 'boisson', text: `AVEC BOISSON${drinks.length ? ` : ${drinks.join(', ')}` : ''}` };
    } else if (!/\b(menu|formule|bols?|frites?|dessert|glace|tiramisu)\b/i.test(name)
        && (supports.length > 0 || meats.length > 0 || /tacos?|burger|sandwich|galette|panini|wrap/i.test(name))) {
        // « Menu Enfant … » est déjà un menu : jamais « seul ». Les frites, bols et desserts n'ont pas de formule.
        menu = { kind: 'seul', text: 'SEUL (sans menu)' };
    }

    const details = [];
    if (supports.length) details.push({ kind: 'support', text: supports.join(', ') });
    if (sizes.length) details.push({ kind: 'size', text: `Taille : ${sizes.join(', ')}` });
    if (meats.length) details.push({ kind: 'meat', text: `${plural(meats.length, 'Viande', 'Viandes')} : ${meats.join(', ')}` });
    if (allSauces.length) details.push({ kind: 'sauce', count: allSauces.length, text: `${plural(allSauces.length, 'Sauce', 'Sauces')} : ${allSauces.join(', ')}` });
    if (crudites.length) details.push({ kind: 'crudites', count: crudites.length, text: `Crudités : ${crudites.join(', ')}` });
    if (garnitures.length) details.push({ kind: 'garniture', text: `Garniture : ${garnitures.join(', ')}` });
    others.forEach((values, group) => details.push({ kind: 'other', text: `${group} : ${values.join(', ')}` }));
    if (without.length) details.push({ kind: 'without', text: `SANS : ${without.join(', ')}` });
    // Écrite quel que soit le badge : une sauce frites annoncée sans formule détectée ne doit pas disparaître.
    if (friesSauces.length) details.push({ kind: 'fries_sauce', count: friesSauces.length, text: `Sauce frites : ${friesSauces.join(', ')}` });
    // Menu ou frites SANS sauce lue dans la commande : la ligne est écrite quand même. Une étiquette muette sur la sauce des
    // frites laisse la cuisine deviner ; « non précisée » dit qu'il faut demander au client plutôt que de servir sans sauce.
    else if (menu && (menu.kind === 'menu' || menu.kind === 'frites')) details.push({ kind: 'fries_sauce', missing: true, text: 'Sauce frites : non précisée' });

    // Notes du client. Trois sources, dédoublonnées :
    //   1. la note de la BORNE : « … . Note: allergie arachide » — écrite sur la MÊME ligne que la composition, que le
    //      nettoyeur de la cuisine jette EN ENTIER (il y voit « Pain: », « Sauce frites: »…) : la note partait avec ;
    //   2. la note du CAISSIER entre crochets « [sans glaçons] » ;
    //   3. le reste de ce que garde le nettoyeur (notes libres).
    // Une note peut porter une ALLERGIE : elle ne doit jamais se perdre en silence.
    const supplementKeys = new Set(supplements.map((s) => norm(s).replace(/\s*x\d+$/, '')));
    const notes = [];
    const pushNote = (text) => {
        const t = String(text || '').trim().replace(/\s*\(\s*\+?\s*(?:€|EUR)?\s*\d+[.,]\d{1,2}\s*(?:€|EUR)?\s*\)\s*$/, '').trim();
        if (t && !notes.some((n) => norm(n) === norm(t))) notes.push(t);
    };

    const kioskNote = /(?:^|[\n.]\s*)Note\s*:\s*([^\n]+)/i.exec(item.instruction);
    if (kioskNote) pushNote(kioskNote[1]);

    let insideBracket = false;
    sanitizeKdsInstruction(item.instruction, name, drinkAddonLabels(item))
        .split('\n')
        .forEach((raw) => {
            const line = raw.trim();
            if (!line) return;
            const opensBracket = line.startsWith('[');
            const inBracketNote = insideBracket || opensBracket;
            if (opensBracket && !line.includes(']')) insideBracket = true;
            else if (insideBracket && line.includes(']')) insideBracket = false;
            const text = line.replace(/^\[/, '').replace(/\]$/, '').replace(/^[+↳]\s*/, '').trim();
            if (!text) return;
            // La rubrique « Boisson menu: X » est déjà écrite sur la ligne menu — mais une NOTE de caissier qui commence
            // par « Boisson : … » (entre crochets) reste une note.
            if (!inBracketNote && /^boissons?(\s+menu)?\s*:/i.test(text)) return;
            if (supplementKeys.has(norm(text))) return;
            pushNote(text.replace(/^Note\s*:\s*/i, ''));
        });

    const codes = Array.isArray(item.allergens_snapshot)
        ? item.allergens_snapshot.map((c) => (c === null || c === undefined ? '' : String(c))).filter(Boolean)
        : [];
    const allergenLabel = ctx.allergenLabel || ((c) => c);
    const allergens = codes.map((c) => allergenLabel(c));

    return {
        name,
        category,
        isDrink: category === 'drink' || isDrinkName(name),
        menu,
        details,
        supplements,
        notes,
        allergens,
    };
}

function defaultFormatTime(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    try {
        return new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Paris' }).format(d);
    } catch (_) {
        return '';
    }
}

/**
 * Numéro affiché sur le papier : le MÊME que sur l'écran cuisine (`queue_number`, à défaut l'id).
 */
export function orderNumber(order) {
    return String(order?.queue_number || order?.id || '');
}

export function orderSourceLabel(order) {
    return SOURCE_LABEL[kdsSourceFromSurface(order?.source_surface)] || '';
}

/**
 * Toutes les étiquettes d'une commande, numérotées « k/N ».
 *
 * @param {object} order
 * @param {{includeDrinks?:boolean, allergenLabel?:Function, formatTime?:Function}} [opts]
 * @returns {{orderId:number, number:string, total:number, labels:Array<object>, skipped:string[]}}
 */
export function buildOrderLabels(order, opts = {}) {
    const includeDrinks = Boolean(opts.includeDrinks);
    const formatTime = opts.formatTime || defaultFormatTime;
    const items = collapseBundledAddonItems(order?.order_items);

    const units = [];
    const skipped = [];
    items.forEach((raw) => {
        const desc = describeItem(raw, { allergenLabel: opts.allergenLabel });
        if (!desc.name) return;
        if (desc.isDrink && !includeDrinks) { skipped.push(desc.name); return; }
        const qty = Math.min(MAX_UNITS_PER_LINE, Math.max(1, parseInt(raw?.quantity, 10) || 1));
        for (let u = 0; u < qty; u++) units.push({ desc, itemId: raw?.id ?? null });
    });

    const total = units.length;
    const number = orderNumber(order);
    const customer = order?.customer && order.customer.name ? String(order.customer.name).trim() : '';
    const sub = [orderSourceLabel(order), formatTime(order?.created_at_iso), customer,
        order?.scheduled_hm ? `pour ${order.scheduled_hm}` : '', order?.table_name || '']
        .filter(Boolean).join(' · ');

    const labels = units.map((u, i) => ({
        key: `${order?.id}:${i + 1}`,
        orderId: order?.id,
        number,
        index: i + 1,
        total,
        sub,
        ...u.desc,
    }));

    return { orderId: order?.id, number, total, labels, skipped };
}

/** « Crudités : Salade, Tomate » → « Crudités (2) : Salade, Tomate » : le nombre se lit sans compter. */
function withCount(d) {
    if (!d.count || !/^[^:]+ : /.test(d.text)) return d.text;
    return d.text.replace(/^([^:]+?) : /, `$1 (${d.count}) : `);
}

/** Spécification d'étiquette → blocs de mise en page (voir labelLayout.js). */
export function orderLabelBlocks(label) {
    const blocks = [
        { t: 'band', left: `N° ${label.number}`, right: `${label.index}/${label.total}` },
    ];
    if (label.sub) blocks.push({ t: 'sub', text: label.sub });
    blocks.push({ t: 'title', text: label.name.toUpperCase() });
    if (label.menu) {
        blocks.push({ t: 'line', text: label.menu.text, size: 'lg', box: label.menu.kind !== 'seul', align: 'center' });
    }
    label.details.forEach((d) => blocks.push({ t: 'line', text: withCount(d), size: 'md' }));
    // Les suppléments (payants) ont leur propre rubrique : on voit d'un coup d'œil combien et lesquels, sans les confondre
    // avec les crudités ou les sauces incluses.
    if (label.supplements.length) {
        blocks.push({ t: 'line', text: `SUPPLÉMENTS (${label.supplements.length}) :`, size: 'md' });
        label.supplements.forEach((s) => blocks.push({ t: 'line', text: s, prefix: '+ ', size: 'md', indent: 14 }));
    }
    if (label.notes.length) {
        blocks.push({ t: 'line', text: label.notes.join(' / '), prefix: 'NOTE : ', size: 'md', box: true });
    }
    if (label.allergens.length) {
        blocks.push({ t: 'line', text: label.allergens.join(', '), prefix: 'ALLERGÈNES : ', size: 'md', box: true });
    }
    return blocks;
}
