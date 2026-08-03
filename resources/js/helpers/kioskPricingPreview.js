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
 *     item_variations[].id/quantity, item_extras[].id/quantity,
 *     item_addons[].id/quantity, instruction, coupon_code, kiosk_promo_code).
 *     `branch_id` est résolu serveur-side via `KioskMachine`.
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
 *   items[].item_variations[].id       (int, requis si array)
 *   items[].item_variations[].quantity (int, optionnel)
 *   items[].item_extras[].id           (int, requis si array)
 *   items[].item_extras[].quantity     (int, optionnel)
 *   items[].item_addons[].id           (int, requis si array)
 *   items[].item_addons[].quantity     (int, optionnel)
 *
 * On REJETTE toute clé de prix (convert_price, price, total, …) — la liste
 * blanche `validated()` côté serveur strip déjà, mais on évite un round-trip
 * pour rien quand on sait localement qu'un champ est interdit.
 */
export function normalizeKioskPricingPreviewItem(raw = {}) {
    const itemId = parseInt(raw.item_id ?? raw.id, 10);
    if (!Number.isFinite(itemId) || itemId < 1) return null;

    const quantity = Math.max(1, parseInt(raw.quantity, 10) || 1);

    const item_variations = normalizePreviewModifiers(raw.item_variations);
    const item_extras = normalizePreviewModifiers(raw.item_extras);
    const item_addons = normalizePreviewModifiers(raw.item_addons);

    const instruction = typeof raw.instruction === 'string' ? raw.instruction.slice(0, 255) : '';

    const normalized = {
        item_id: itemId,
        quantity,
        instruction,
        item_variations,
        item_extras,
    };

    if (item_addons.length > 0) {
        normalized.item_addons = item_addons;
    }

    return normalized;
}

function normalizePreviewModifiers(rawRows) {
    return (Array.isArray(rawRows) ? rawRows : [])
        .map((row) => {
            const id = parseInt(row?.id ?? row, 10);
            if (!Number.isFinite(id) || id < 1) return null;

            const normalized = { id };
            const quantity = parseInt(row?.quantity, 10);
            if (Number.isFinite(quantity) && quantity > 1) {
                normalized.quantity = quantity;
            }

            return normalized;
        })
        .filter(Boolean);
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

                // [rush-100 WA-R1-05/06 heal round-2 2026-05-13] Skip preview
                // when payload is just base items with zero modifier selections.
                // At composer-step open the wizard's selections-watcher fires
                // with the base item and empty modifier arrays — calling
                // /pricing/preview at this point yields a 422 SSOT-exception
                // (item-level guard) AND has no useful effect (server total
                // would equal the locally-computed base price). The runningTotal
                // computed property already falls back to runningTotalLocal in
                // this state. Wait for the first real user selection to fire.
                const hasAnyModifier = payload.items.some((it) => {
                    const v = (it.item_variations || []).length;
                    const e = (it.item_extras || []).length;
                    const a = (it.item_addons || []).length;
                    return v + e + a > 0;
                });
                if (!hasAnyModifier) {
                    resolve(null);
                    return;
                }

                // AbortController si dispo (fetch / axios ≥ 0.22), sinon
                // CancelToken legacy.
                let config = {};
                if (typeof AbortController !== 'undefined') {
                    const controller = new AbortController();
                    currentCancel = () => controller.abort();
                    config.signal = controller.signal;
                } else if (client.CancelToken) {
                    const source = client.CancelToken.source();
                    currentCancel = () => source.cancel('pricing-preview:newer-request');
                    config.cancelToken = source.token;
                }

                try {
                    const res = await client.post(endpoint, payload, config);
                    currentCancel = null;

                    const data = res && res.data;
                    if (!data || data.status !== true || !data.data) {
                        resolve(null);
                        return;
                    }

                    const result = data.data;
                    resolve({
                        total: Number(result.grand_total ?? result.total ?? 0) || 0,
                        lines: Array.isArray(result.lines) ? result.lines : [],
                        discount: Number(result.discount ?? 0) || 0,
                        raw: result,
                    });
                } catch (err) {
                    currentCancel = null;
                    // Cancellations silencieuses, pas de log bruyant.
                    const isCancel = err && (err.name === 'CanceledError'
                        || err.name === 'AbortError'
                        || (client.isCancel && client.isCancel(err)));
                    // [FIX SIGNAL-JAUNE 2026-06-30] Un 422 = composition INCOMPLÈTE en cours :
                    // l'utilisateur n'a pas fini de choisir (il manque une viande/sauce requise,
                    // ex. « Sélectionnez au moins 1 Viande 2 »). C'est ATTENDU à chaque sélection
                    // intermédiaire d'un produit multi-attributs (Tacos L, Méga…), PAS une erreur.
                    // On ne déclenche le toast jaune « Tarif rafraîchi » QUE pour les VRAIES
                    // erreurs (réseau / 401 / 5xx). Le total local provisoire est de toute façon
                    // affiché, et le prix est scellé/vérifié au paiement (SSOT). Avant ce fix, le
                    // client voyait un signal jaune à chaque clic intermédiaire de composition.
                    const isIncompleteComposition = !!(err && err.response && err.response.status === 422);
                    if (!isCancel && !isIncompleteComposition && options.onError) options.onError(err);
                    resolve(null);
                }
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
