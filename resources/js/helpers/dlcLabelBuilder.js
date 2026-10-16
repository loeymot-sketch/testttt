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
 * @param {{name:string, openedAt:string, expiresAt:string, storage?:string, by?:string,
 *          shelfValue?:number, shelfUnit?:string}} d
 */
export function dlcLabelBlocks(d) {
    const blocks = [
        { t: 'title', text: String(d.name || '').toUpperCase() },
        { t: 'rule' },
        { t: 'pair', left: 'Ouvert le', right: formatDlcDate(d.openedAt), size: 'md' },
        { t: 'line', text: `À CONSOMMER AVANT\n${formatDlcDate(d.expiresAt)}`, size: 'lg', box: true, align: 'center' },
    ];
    const foot = [
        shelfLifeText(d.shelfValue, d.shelfUnit) ? `Conservation ${shelfLifeText(d.shelfValue, d.shelfUnit)}` : '',
        d.storage || '',
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
 * Les DLC à surveiller d'après le journal : imprimées, échues depuis moins de 24 h ou à échoir dans les 48 h,
 * dédoublonnées (un produit imprimé 3 fois d'un coup = une ligne), les plus pressées d'abord.
 */
export function watchList(history, nowMs, limit = 8) {
    const seen = new Set();
    const out = [];
    (history || []).forEach((h) => {
        if (!['printed', 'unconfirmed'].includes(h.status) || !h.expires_at || !h.product_name) return;
        const exp = Date.parse(h.expires_at);
        if (!Number.isFinite(exp)) return;
        if (exp < nowMs - 24 * 3600 * 1000 || exp > nowMs + 48 * 3600 * 1000) return;
        const key = `${h.product_name}|${Math.round(exp / 60000)}`;
        if (seen.has(key)) return;
        seen.add(key);
        out.push({ id: h.id, name: h.product_name, expiresMs: exp, copies: h.copies, ...expiryStatus(exp, nowMs) });
    });
    return out.sort((a, b) => a.expiresMs - b.expiresMs).slice(0, limit);
}
