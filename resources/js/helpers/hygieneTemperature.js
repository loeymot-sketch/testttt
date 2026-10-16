/**
 * hygieneTemperature.js — [CAYENNE HYGIÈNE 2026-10-17] Fonctions PURES de l'écran des relevés de température : lecture de
 * la saisie et aperçu du verdict. Le verdict qui FAIT FOI est celui du serveur (App\Services\Hygiene\TemperatureRules) ; cet
 * aperçu applique les mêmes seuils pour dire tout de suite « conforme » ou « il faut dire ce qui a été fait ».
 */

/** « 3,5 » / « 3.5 » → 3.5 ; vide ou illisible → null. Le signe est porté par le bouton ±, jamais par la frappe. */
export function parseTemperature(raw) {
    const s = String(raw ?? '').replace(/[−–—-]/g, '').replace(',', '.').trim();
    if (s === '' || !/^\d{1,2}(\.\d{0,1})?$/.test(s)) return null;
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
