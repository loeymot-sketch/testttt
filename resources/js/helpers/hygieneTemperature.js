/**
 * hygieneTemperature.js — [CAYENNE HYGIÈNE 2026-10-17] Fonctions PURES de l'écran des relevés de température : lecture de
 * la saisie et aperçu du verdict. Le verdict qui FAIT FOI est celui du serveur (App\Services\Hygiene\TemperatureRules) ; cet
 * aperçu applique les mêmes seuils pour dire tout de suite « conforme » ou « il faut dire ce qui a été fait ».
 */

/** Un moins tapé en tête de saisie (« -2 », « −18 ») : le signe est un FAIT que l'opérateur a exprimé, pas du bruit. */
const TYPED_MINUS = /^\s*[−–—-]\s*/;
export function hasTypedMinus(raw) { return TYPED_MINUS.test(String(raw ?? '')); }
/** Saisie sans son moins de tête (la valeur numérique reste lue par parseTemperature). */
export function stripTypedMinus(raw) { return String(raw ?? '').replace(TYPED_MINUS, ''); }

/** « 3,5 » / « 3.5 » → 3.5 ; vide ou illisible → null. Le signe se règle par le bouton ± OU par un moins tapé (le composant bascule alors le bouton : jamais ignoré). */
export function parseTemperature(raw, intDigits = 2) {
    // Le signe est porté par le bouton ± : un moins en tête est toléré, un moins AU MILIEU (« 3-5 ») est illisible, jamais « 35 ».
    const s = String(raw ?? '').trim().replace(/^[−–—-]/, '').replace(',', '.').trim();
    if (s === '' || !new RegExp(`^\\d{1,${intDigits}}(\\.\\d{0,1})?$`).test(s)) return null;
    const n = Number(s);
    return Number.isFinite(n) ? n : null;
}

/** Même règle que TemperatureRules::evaluate côté serveur (bornes incluses, arrondi à la décimale du thermomètre). */
export function previewVerdict(value, min, max) {
    const v = Math.round(value * 10) / 10;
    if (max !== null && max !== undefined && v > Number(max)) return { status: 'alert', reason: 'too_warm' };
    if (min !== null && min !== undefined && v < Number(min)) return { status: 'alert', reason: 'too_cold' };
    return { status: 'ok', reason: null };
}

/** 3 → « 3,0 » ; −18 → « −18,0 » (vrai signe moins). */
export function formatTemperature(v) {
    const n = Number(v);
    if (!Number.isFinite(n)) return '';
    return n.toFixed(1).replace('.', ',').replace('-', '−');
}

/** Seuils lisibles : « 0 à +4 °C », « ≤ −18 °C ». */
export function limitText(min, max) {
    const f = (v) => `${v > 0 ? '+' : ''}${formatTemperature(v).replace(/,0$/, '')}`;
    const hasMin = min !== null && min !== undefined;
    const hasMax = max !== null && max !== undefined;
    if (hasMin && hasMax) return `${f(Number(min))} à ${f(Number(max))} °C`;
    if (hasMax) return `≤ ${f(Number(max))} °C`;
    if (hasMin) return `≥ ${f(Number(min))} °C`;
    return 'sans seuil';
}

/**
 * Courbe d'un équipement : relevés (du plus ancien au plus récent) → coordonnées d'un graphique SVG, avec les seuils en lignes
 * pointillées. Pur : l'écran n'a plus qu'à dessiner. Les relevés annulés n'y figurent pas (ils restent au journal).
 *
 * @param {Array<{value_c:number, recorded_at:string, status:string, voided?:boolean}>} readings
 * @returns {{empty:boolean, w:number, h:number, line:string, dots:Array, lines:Array, ticks:Array, alerts:number, count:number, minV:?number, maxV:?number}}
 */
export function chartModel(readings, min, max, w = 600, h = 190) {
    const pts = (readings || [])
        .filter((r) => !r.voided && Number.isFinite(Number(r.value_c)) && Number.isFinite(Date.parse(r.recorded_at)))
        .map((r) => ({ v: Number(r.value_c), t: Date.parse(r.recorded_at), alert: r.status === 'alert', at: r.recorded_at }))
        .sort((a, b) => a.t - b.t);
    const empty = { empty: true, w, h, line: '', dots: [], lines: [], ticks: [], alerts: 0, count: 0, minV: null, maxV: null };
    if (!pts.length) return empty;

    const hasMin = min !== null && min !== undefined && min !== '';
    const hasMax = max !== null && max !== undefined && max !== '';
    const bounds = [...pts.map((p) => p.v), ...(hasMin ? [Number(min)] : []), ...(hasMax ? [Number(max)] : [])];
    let lo = Math.min(...bounds);
    let hi = Math.max(...bounds);
    const pad = Math.max(1, (hi - lo) * 0.12);
    lo -= pad;
    hi += pad;
    const L = 40;
    const R = 10;
    const T = 10;
    const B = 22;
    const x = (t) => (pts.length === 1 || pts[pts.length - 1].t === pts[0].t ? L + (w - L - R) / 2 : L + ((t - pts[0].t) / (pts[pts.length - 1].t - pts[0].t)) * (w - L - R));
    const y = (v) => T + (1 - (v - lo) / (hi - lo)) * (h - T - B);
    const r1 = (n) => Math.round(n * 10) / 10;
    const dots = pts.map((p) => ({ x: r1(x(p.t)), y: r1(y(p.v)), alert: p.alert, value: p.v, at: p.at }));
    const lines = [];
    if (hasMax) lines.push({ y: r1(y(Number(max))), label: `max ${formatTemperature(max).replace(/,0$/, '')}`, kind: 'max' });
    if (hasMin) lines.push({ y: r1(y(Number(min))), label: `min ${formatTemperature(min).replace(/,0$/, '')}`, kind: 'min' });
    const ticks = [lo + pad, (lo + hi) / 2, hi - pad].map((v) => ({ y: r1(y(v)), label: formatTemperature(v).replace(/,0$/, '') }));
    return {
        empty: false, w, h, dots, lines, ticks,
        line: dots.map((d, i) => `${i ? 'L' : 'M'}${d.x} ${d.y}`).join(' '),
        alerts: pts.filter((p) => p.alert).length, count: pts.length,
        minV: Math.min(...pts.map((p) => p.v)), maxV: Math.max(...pts.map((p) => p.v)),
    };
}
