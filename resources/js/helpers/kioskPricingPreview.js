/**
 * Kiosk Phase 9.1.3 — SSOT pricing preview client avec debounce.
 *
 * Motif (invariant §1.1 du prompt maître + AUDIT_KIOSK_GLOBAL_2026-04-18.md
 * finding #3). Jusqu'ici le wizard calculait `runningTotal` en local via
 * `calculateKioskRunningTotal()` (helper pur), ce qui :
 *   - dérivait des prix exposés dans `GET /menu` (pas la vérité ultime),
 *   - divergeait silencieusement du total backend calculé par `PricingService`
 *     côté `/order` / `/pricing/preview` (risque découverte tardive à la
 *     caisse = perte revenu + rupture de confiance client).
 *
 * Ce helper expose une API minimale :
 *
 *    const preview = createKioskPricingPreview(options?)
 *    preview.request({ items, coupon_code?, kiosk_promo_code? })   // debounced
 *      → Promise<{ total, lines, discount, raw } | null>   // null si erreur douce
 *    preview.cancel()                                              // flush pending debounce
 *
 * Invariants respectés :
 *   - Aucun prix envoyé côté client : on ne passe QUE (item_id, quantity,
 *     item_variations[].id, item_extras[].id, instruction, coupon_code,
 *     kiosk_promo_code). `branch_id` est résolu serveur-side via `KioskMachine`.
 *   - Debounce 400 ms (`KIOSK_HARDWARE.DEBOUNCE_PRICING_PREVIEW_MS` → 400) pour
 *     éviter la storm de requêtes pendant que le client ajuste ses sélections.
 *   - Abort de la requête précédente dès qu'une nouvelle est émise (axios
 *     CancelToken) → pas de race condition d'affichage.
 *   - Erreurs réseau / 4xx : le helper renvoie `null` et log console — le
 *     consommateur peut retomber sur `calculateKioskRunningTotal()` en fallback
 *     (dégradé gracieux, jamais de total à 0 affiché par erreur).
 *
 * @module kioskPricingPreview
 */

import { MINI_RECAP } from '../config/kioskHardware';

/**
 * Résout l'axios utilisé par l'app (bootstrap injecte `window.axios`).
 * Permet d'importer le helper dans des tests Vitest sans side-effects.
 */
function resolveAxios(override) {
    if (override) return override;
    if (typeof window !== 'undefined' && window.axios) return window.axios;
    return null;
}

/**
 * Nettoie un payload wizard (ce que reçoit `calculateKioskRunningTotal`) pour
 * produire un élément `items[]` SSOT-friendly accepté par `/pricing/preview`.
 *
 * Contrat de la route (voir `PricingPreviewRequest`) :
 *   items[].item_id              (int, requis)
 *   items[].quantity             (int, requis)
 *   items[].instruction          (string|null, ≤ 255)
 *   items[].item_variations[].id (int, requis si array)
 *   items[].item_extras[].id     (int, requis si array)
 *
 * On REJETTE toute clé de prix (convert_price, price, total, …) — la liste
 * blanche `validated()` côté serveur strip déjà, mais on évite un round-trip
 * pour rien quand on sait localement qu'un champ est interdit.
 */
export function normalizeKioskPricingPreviewItem(raw = {}) {
    const itemId = parseInt(raw.item_id ?? raw.id, 10);
    if (!Number.isFinite(itemId) || itemId < 1) return null;

    const quantity = Math.max(1, parseInt(raw.quantity, 10) || 1);

    const rawVariations = Array.isArray(raw.item_variations) ? raw.item_variations : [];
    const rawExtras = Array.isArray(raw.item_extras) ? raw.item_extras : [];

    const item_variations = rawVariations
        .map((v) => {
            const id = parseInt(v?.id ?? v, 10);
            return Number.isFinite(id) && id > 0 ? { id } : null;
        })
        .filter(Boolean);

    const item_extras = rawExtras
        .map((e) => {
            const id = parseInt(e?.id ?? e, 10);
            return Number.isFinite(id) && id > 0 ? { id } : null;
        })
        .filter(Boolean);

    const instruction = typeof raw.instruction === 'string' ? raw.instruction.slice(0, 255) : '';

    return {
        item_id: itemId,
        quantity,
        instruction,
        item_variations,
        item_extras,
    };
}

/**
 * Construit le payload POST — items uniquement + coupons éventuels. Pas de
 * `branch_id`, pas de prix, pas de `customer_token`.
 */
export function buildKioskPricingPreviewPayload({ items, coupon_code, kiosk_promo_code }) {
    const normalized = (Array.isArray(items) ? items : [])
        .map(normalizeKioskPricingPreviewItem)
        .filter(Boolean);

    const payload = { items: normalized };

    if (typeof coupon_code === 'string' && coupon_code.trim() !== '') {
        payload.coupon_code = coupon_code.trim().slice(0, 64);
    }
    if (typeof kiosk_promo_code === 'string' && kiosk_promo_code.trim() !== '') {
        payload.kiosk_promo_code = kiosk_promo_code.trim().slice(0, 64);
    }

    return payload;
}

/**
 * Factory — crée une instance debounce dédiée au contexte (wizard, cart, …).
 *
 * @param {Object} [options]
 * @param {Object} [options.axios]    instance axios custom (tests)
 * @param {number} [options.debounceMs]  override debounce (défaut 400 ms)
 * @param {string} [options.endpoint] défaut 'frontend/pricing/preview'
 * @param {Function} [options.onError] callback erreur (log dev, pas de PII)
 * @returns {{ request: Function, cancel: Function, destroy: Function }}
 */
export function createKioskPricingPreview(options = {}) {
    const client = resolveAxios(options.axios);
    const debounceMs = Number.isFinite(options.debounceMs)
        ? Math.max(0, options.debounceMs)
        : MINI_RECAP.PREVIEW_DEBOUNCE_MS;
    const endpoint = options.endpoint || 'frontend/pricing/preview';
    const timeoutMs = Number.isFinite(options.timeoutMs) ? Math.max(250, options.timeoutMs) : 3000;
    const maxAttempts = Number.isFinite(options.maxAttempts) ? Math.max(1, options.maxAttempts) : 3;
    const backoffBaseMs = Number.isFinite(options.backoffBaseMs) ? Math.max(50, options.backoffBaseMs) : 250;

    let timer = null;
    let currentCancel = null; // axios cancel fn (ou AbortController.abort)
    let destroyed = false;

    const clearTimer = () => {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const cancelCurrent = () => {
        if (currentCancel) {
            try { currentCancel(); } catch (_) { /* noop */ }
            currentCancel = null;
        }
    };

    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    async function postWithRetry(payload, attempt = 1) {
        if (destroyed) return null;

        let config = {};
        let timeoutId = null;
        let didTimeout = false;

        if (typeof AbortController !== 'undefined') {
            const controller = new AbortController();
            currentCancel = () => controller.abort();
            config.signal = controller.signal;
            timeoutId = setTimeout(() => {
                didTimeout = true;
                controller.abort();
            }, timeoutMs);
        } else if (client.CancelToken) {
            const source = client.CancelToken.source();
            currentCancel = () => source.cancel('pricing-preview:newer-request');
            config.cancelToken = source.token;
            timeoutId = setTimeout(() => {
                didTimeout = true;
                source.cancel('pricing-preview:timeout');
            }, timeoutMs);
        }

        try {
            const res = await client.post(endpoint, payload, config);
            currentCancel = null;
            if (timeoutId) clearTimeout(timeoutId);

            const data = res && res.data;
            if (!data || data.status !== true || !data.data) {
                return null;
            }

            const result = data.data;
            return {
                total: Number(result.grand_total ?? result.total ?? 0) || 0,
                lines: Array.isArray(result.lines) ? result.lines : [],
                discount: Number(result.discount ?? 0) || 0,
                raw: result,
            };
        } catch (err) {
            currentCancel = null;
            if (timeoutId) clearTimeout(timeoutId);

            const isCancel = err && (err.name === 'CanceledError'
                || err.name === 'AbortError'
                || (client.isCancel && client.isCancel(err)));

            const shouldRetry = !destroyed && attempt < maxAttempts && (!isCancel || didTimeout);
            if (shouldRetry) {
                await wait(backoffBaseMs * (2 ** (attempt - 1)));
                return postWithRetry(payload, attempt + 1);
            }

            if (!isCancel && options.onError) options.onError(err);
            if (didTimeout && options.onError) options.onError(new Error(`pricing preview timeout after ${timeoutMs}ms`));
            return null;
        }
    }

    function request(payloadArgs) {
        if (destroyed) return Promise.resolve(null);

        clearTimer();
        cancelCurrent();

        return new Promise((resolve) => {
            timer = setTimeout(async () => {
                timer = null;

                if (!client) {
                    // Pas d'axios disponible (ex: storybook, dev sans bootstrap).
                    if (options.onError) options.onError(new Error('axios unavailable'));
                    resolve(null);
                    return;
                }

                const payload = buildKioskPricingPreviewPayload(payloadArgs || {});
                if (!payload.items || payload.items.length === 0) {
                    resolve(null);
                    return;
                }

                resolve(await postWithRetry(payload));
            }, debounceMs);
        });
    }

    function cancel() {
        clearTimer();
        cancelCurrent();
    }

    function destroy() {
        destroyed = true;
        cancel();
    }

    return { request, cancel, destroy };
}

export default createKioskPricingPreview;
