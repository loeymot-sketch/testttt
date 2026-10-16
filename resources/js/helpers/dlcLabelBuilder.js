/**
 * dlcLabelBuilder.js — [ETIQUETTES 2026-10-16] Étiquettes d'hygiène (DLC) et étiquette de test.
 *
 * Une étiquette DLC dit trois choses, lisibles à un mètre : QUEL produit, OUVERT quand, À CONSOMMER
 * AVANT quand. Les dates viennent du SERVEUR (une seule horloge, journalisée) — ce module ne fait que
 * les mettre en forme, dans le fuseau du restaurant.
 */
import { getMedia } from './brotherQl.js';

export const RESTAURANT_TZ = 'Europe/Paris';

/** « ven 16/10/2026 14:32 » — jour de la semaine, date complète, heure. */
export function formatDlcDate(iso, tz = RESTAURANT_TZ) {
    const d = iso instanceof Date ? iso : new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    try {
        const parts = new Intl.DateTimeFormat('fr-FR', {
            timeZone: tz, weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', hour12: false,
        }).formatToParts(d);
        const get = (type) => (parts.find((p) => p.type === type) || {}).value || '';
        const hour = get('hour') === '24' ? '00' : get('hour');
        return `${get('weekday').replace('.', '')} ${get('day')}/${get('month')}/${get('year')} ${hour}:${get('minute')}`;
    } catch (_) {
        return d.toISOString();
    }
}

/** « 48 h », « 3 jours » — durée de conservation lisible. */
export function shelfLifeText(value, unit) {
    const n = Number(value);
    if (!Number.isFinite(n) || n <= 0) return '';
    if (unit === 'days') return `${n} ${n > 1 ? 'jours' : 'jour'}`;
    return `${n} h`;
}

/**
 * Événements qui ouvrent une DLC secondaire — vocabulaire des logiciels HACCP et du guide PMS (« entamé le… »).
 * `verb` est imprimé devant la date de l'événement.
 */
export const DLC_ACTIONS = Object.freeze({
    opened: { key: 'opened', label: 'Ouverture', verb: 'Entamé le', short: 'Ouvert' },
    thawed: { key: 'thawed', label: 'Décongélation', verb: 'Décongelé le', short: 'Décongelé' },
    prepared: { key: 'prepared', label: 'Préparation', verb: 'Préparé le', short: 'Préparé' },
    cooked: { key: 'cooked', label: 'Cuisson', verb: 'Cuit le', short: 'Cuit' },
    vacuum: { key: 'vacuum', label: 'Mise sous vide', verb: 'Mis sous vide le', short: 'Sous vide' },
});
export const DLC_ACTION_KEYS = Object.keys(DLC_ACTIONS);

/** Les 14 allergènes à déclaration obligatoire (codes de l'application → libellé). */
export const DLC_ALLERGENS = Object.freeze([
    ['gluten', 'Gluten'], ['crustaces', 'Crustacés'], ['oeufs', 'Œufs'], ['poisson', 'Poisson'], ['arachides', 'Arachides'],
    ['soja', 'Soja'], ['lait', 'Lait'], ['fruits_a_coque', 'Fruits à coque'], ['celeri', 'Céleri'], ['moutarde', 'Moutarde'],
    ['sesame', 'Sésame'], ['sulfites', 'Sulfites'], ['lupin', 'Lupin'], ['mollusques', 'Mollusques'],
]);
const ALLERGEN_LABEL = Object.fromEntries(DLC_ALLERGENS);
export function allergenLabels(codes) {
    return (codes || []).map((c) => ALLERGEN_LABEL[c] || c);
}

/** Familles proposées (le restaurant peut en saisir une autre). */
export const DLC_CATEGORIES = Object.freeze([
    'Légumes & crudités', 'Fruits', 'Sauces', 'Viandes & volailles', 'Poissons', 'Charcuterie',
    'Fromages & produits laitiers', 'Épicerie', 'Préparations maison', 'Desserts',
]);

/** Températures de conservation usuelles. */
export const DLC_STORAGES = Object.freeze(['0 à +3 °C', '0 à +4 °C', '≤ −18 °C', 'Ambiante']);

const DAYS = ['DIM', 'LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM'];

/** { day: 'DIM', date: '18/10/2026', time: '14:32' } dans le fuseau du restaurant. */
export function dlcParts(iso, tz = RESTAURANT_TZ) {
    const d = iso instanceof Date ? iso : new Date(iso);
    if (Number.isNaN(d.getTime())) return { day: '', date: '—', time: '' };
    const parts = new Intl.DateTimeFormat('fr-FR', {
        timeZone: tz, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
    }).formatToParts(d);
    const get = (t) => (parts.find((p) => p.type === t) || {}).value || '';
    const local = new Date(Date.UTC(Number(get('year')), Number(get('month')) - 1, Number(get('day'))));
    const hour = get('hour') === '24' ? '00' : get('hour');
    return { day: DAYS[local.getUTCDay()], date: `${get('day')}/${get('month')}/${get('year')}`, time: `${hour}:${get('minute')}` };
}

/**
 * Durée lisible + date limite que donnerait un événement à l'instant `now` (aperçu AVANT impression ; la date imprimée,
 * elle, vient toujours du serveur).
 */
export function previewExpiry(action, now = new Date()) {
    const d = new Date(now.getTime());
    if (action.unit === 'hours') d.setTime(d.getTime() + action.value * 3600000);
    else d.setDate(d.getDate() + Number(action.value));
    return d;
}

/**
 * Étiquette DLC — au standard des logiciels HACCP : produit, famille, événement et sa date, DLC en grand avec le JOUR en
 * pastille, limitation par la DLC du fabricant, allergènes, conservation, lot, opérateur.
 *
 * @param {{name:string, openedAt:string, expiresAt:string, action?:string, category?:string, note?:string,
 *          storage?:string, by?:string, lot?:string, allergens?:string[], capped?:boolean, originalDlc?:string,
 *          shelfValue?:number, shelfUnit?:string}} d
 */
export function dlcLabelBlocks(d) {
    const action = DLC_ACTIONS[d.action] || DLC_ACTIONS.opened;
    const exp = dlcParts(d.expiresAt);
    const head = [d.category, d.note].filter(Boolean).join(' · ');
    const blocks = [{ t: 'title', text: String(d.name || '').toUpperCase() }];
    if (head) blocks.push({ t: 'sub', text: head });
    blocks.push({ t: 'rule' });
    blocks.push({ t: 'pair', left: action.verb, right: formatDlcDate(d.openedAt), size: 'md' });
    blocks.push({ t: 'dayline', day: exp.day, caption: 'À CONSOMMER JUSQU’AU', text: `${exp.date}  ${exp.time}` });
    if (d.capped) {
        const orig = d.originalDlc ? d.originalDlc.split('-').reverse().join('/') : '';
        blocks.push({ t: 'line', text: `Limitée à la DLC du fabricant${orig ? ` (${orig})` : ''}`, size: 'sm' });
    }
    const allergens = allergenLabels(d.allergens);
    if (allergens.length) blocks.push({ t: 'line', text: allergens.join(', '), prefix: 'ALLERGÈNES : ', size: 'md', box: true });
    const foot = [
        d.storage ? `Conserver ${d.storage}` : '',
        d.lot ? `Lot ${d.lot}` : '',
        d.by ? `Par ${d.by}` : '',
    ].filter(Boolean).join(' · ');
    if (foot) blocks.push({ t: 'sub', text: foot });
    return blocks;
}

/**
 * Étiquette de test : vérifie en 5 secondes le SENS (G à gauche, D à droite — un G à droite signale
 * un miroir), les accents français et la coupe.
 */
export function testLabelBlocks(mediaId, now = new Date()) {
    const media = getMedia(mediaId);
    return [
        { t: 'band', left: 'TEST', right: media.label },
        { t: 'pair', left: 'G <', right: '> D', size: 'lg' },
        { t: 'title', text: 'ABC 123 éèàçù Œuf' },
        { t: 'sub', text: `Imprimante prête · ${formatDlcDate(now)}` },
    ];
}

/**
 * Où en est une DLC ? Texte court et niveau d'urgence, pour la liste « À surveiller ».
 * @param {number} expiresMs  échéance (ms)
 * @param {number} nowMs      maintenant, à l'heure du serveur (ms)
 * @returns {{level:'expired'|'today'|'soon', text:string}}
 */
export function expiryStatus(expiresMs, nowMs) {
    const diffMin = Math.round((expiresMs - nowMs) / 60000);
    const hhmm = (ms) => new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit', timeZone: RESTAURANT_TZ }).format(new Date(ms));
    if (diffMin <= 0) {
        const late = Math.abs(diffMin);
        const how = late < 60 ? `${late} min` : late < 2880 ? `${Math.round(late / 60)} h` : `${Math.round(late / 1440)} j`;
        return { level: 'expired', text: `EXPIRÉE depuis ${how}` };
    }
    if (diffMin < 60) return { level: 'today', text: `expire dans ${diffMin} min (${hhmm(expiresMs)})` };
    const dayKey = (ms) => new Intl.DateTimeFormat('fr-CA', { timeZone: RESTAURANT_TZ }).format(new Date(ms)); // AAAA-MM-JJ
    const dayDiff = Math.round((Date.parse(`${dayKey(expiresMs)}T00:00:00Z`) - Date.parse(`${dayKey(nowMs)}T00:00:00Z`)) / 86400000);
    if (dayDiff <= 0) return { level: 'today', text: `expire aujourd’hui à ${hhmm(expiresMs)}` };
    if (dayDiff === 1) return { level: 'soon', text: `expire demain à ${hhmm(expiresMs)}` };
    const when = new Intl.DateTimeFormat('fr-FR', { timeZone: RESTAURANT_TZ, weekday: 'short', day: '2-digit', month: '2-digit' })
        .format(new Date(expiresMs)).replace('.', '');
    return { level: 'soon', text: dayDiff === 2 ? `expire après‑demain à ${hhmm(expiresMs)} (${when})` : `expire le ${when} à ${hhmm(expiresMs)}` };
}

/**
 * Les DLC à surveiller d'après le journal : imprimées, non « jetées », échues depuis moins de 24 h ou à échoir dans les
 * 72 h, regroupées par lot (même produit, même échéance), les plus pressées d'abord.
 */
export function watchList(history, nowMs, limit = 8) {
    const seen = new Set();
    const out = [];
    (history || []).forEach((h) => {
        if (!['printed', 'unconfirmed'].includes(h.status) || !h.expires_at || !h.product_name || h.discarded_at) return;
        const exp = Date.parse(h.expires_at);
        if (!Number.isFinite(exp)) return;
        if (exp < nowMs - 24 * 3600 * 1000 || exp > nowMs + 72 * 3600 * 1000) return;
        const key = `${h.product_name}|${Math.round(exp / 60000)}`;
        if (seen.has(key)) {
            // même produit, même échéance : un seul lot — on additionne et on garde tous les identifiants (pour « Jeté »)
            const lot = out.find((o) => o.key === key);
            lot.copies += Number(h.copies) || 1;
            lot.ids.push(h.id);
            return;
        }
        seen.add(key);
        out.push({ key, id: h.id, ids: [h.id], name: h.product_name, expiresMs: exp, copies: Number(h.copies) || 1, ...expiryStatus(exp, nowMs) });
    });
    return out.sort((a, b) => a.expiresMs - b.expiresMs).slice(0, limit);
}
