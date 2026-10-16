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
