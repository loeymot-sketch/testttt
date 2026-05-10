import axios from "axios";
import {
    getPendingCount,
    markStaleItems,
    saveOrder,
    startAutoSync,
} from "../../helpers/kioskOfflineQueue";
import { isSnapshotStale, loadSnapshot } from "../../helpers/kioskMenuCache";
import { KIOSK_ERROR_CODES, normalizeKioskErrorCode } from "../../helpers/kioskAnalytics";

// Source identique à sourceEnum.WEB (pas de valeur KIOSK côté frontend)
const SOURCE_KIOSK = 5;
const KIOSK_IS_ADVANCE_ORDER_NO = 10;

// [iter15-mega-fix C-037/D5-003 round-7 2026-05-10] Module-scoped in-flight
// guard — see `kioskLogin` action below for the full rationale. Module scope
// (not store state) is intentional : the coalescing window is the lifetime of
// the network call, never persisted, never replayed across reloads, and the
// store's reactivity does not need to track it.
let _inFlightKioskLogin = null;

const PAYMENT_METHOD_MAP = { cash: 1, card: 4, tr: 5 };
const ELECTRONIC_PAYMENT_METHODS = new Set(['card', 'tr']);
const MAX_ITEM_QTY = window.foodkingConfig?.maxItemQty ?? 20;
export const KIOSK_ORDER_TYPES = Object.freeze({ KIOSK: 25, TAKEAWAY: 10 });
const KIOSK_ORDER_TYPE_VALUES = new Set(Object.values(KIOSK_ORDER_TYPES));

const KIOSK_ERROR_ROUTES = Object.freeze({
    [KIOSK_ERROR_CODES.NETWORK]: 'kiosk.error.network',
    [KIOSK_ERROR_CODES.MENU_UNAVAILABLE]: 'kiosk.error.menu-unavailable',
    [KIOSK_ERROR_CODES.PRODUCT_REMOVED]: 'kiosk.error.product-removed',
    [KIOSK_ERROR_CODES.PAYMENT_REFUSED]: 'kiosk.error.payment-refused',
});

export function normalizeKioskOrderType(orderType) {
    const value = Number(orderType);
    return Number.isInteger(value) && KIOSK_ORDER_TYPE_VALUES.has(value) ? value : null;
}

export function assertExplicitKioskOrderType(orderType) {
    const normalized = normalizeKioskOrderType(orderType);
    if (normalized !== null) return normalized;

    const error = new Error('KIOSK_ORDER_TYPE_REQUIRED');
    error.code = 'KIOSK_ORDER_TYPE_REQUIRED';
    throw error;
}

function cleanKioskErrorPayload(payload = {}) {
    const cleaned = {};
    Object.entries(payload || {}).forEach(([key, value]) => {
        if (key === 'router' || key === '$router') return;
        if (value !== undefined && value !== null && value !== '') cleaned[key] = value;
    });
    return cleaned;
}

function kioskErrorQuery(code, payload = {}) {
    const cleaned = cleanKioskErrorPayload(payload);
    if (code === KIOSK_ERROR_CODES.PRODUCT_REMOVED) {
        return {
            ...(cleaned.productName || cleaned.name ? { name: cleaned.productName || cleaned.name } : {}),
            ...(cleaned.itemId || cleaned.item_id ? { item_id: cleaned.itemId || cleaned.item_id } : {}),
        };
    }

    if (code === KIOSK_ERROR_CODES.PAYMENT_REFUSED) {
        return {
            ...(cleaned.errorCode || cleaned.code ? { code: cleaned.errorCode || cleaned.code } : {}),
            ...(cleaned.orderId || cleaned.order_id ? { order_id: cleaned.orderId || cleaned.order_id } : {}),
        };
    }

    return {};
}

export function resolveKioskErrorRoute(code, payload = {}) {
    const normalized = normalizeKioskErrorCode(code);
    const query = kioskErrorQuery(normalized, payload);

    return {
        name: KIOSK_ERROR_ROUTES[normalized],
        ...(Object.keys(query).length ? { query } : {}),
    };
}

export function goToKioskError(code, payload = {}) {
    const route = resolveKioskErrorRoute(code, payload);
    const router = payload?.router || payload?.$router || null;

    if (router?.push) {
        return router.push(route).catch(() => route);
    }

    return route;
}

function sanitizeKioskOrderItem(item) {
    const sanitized = {
        item_id: item.item_id,
        instruction: item.instruction || '',
        quantity: item.quantity,
        item_variations: sanitizeKioskOrderModifiers(item.item_variations),
        item_extras: sanitizeKioskOrderModifiers(item.item_extras),
    };

    if (Array.isArray(item.item_addons) && item.item_addons.length > 0) {
        sanitized.item_addons = sanitizeKioskOrderModifiers(item.item_addons);
    }

    return sanitized;
}

function sanitizeKioskOrderModifiers(rows) {
    return (Array.isArray(rows) ? rows : [])
        .map((row) => {
            const id = Number.parseInt(row?.id ?? row, 10);
            if (!Number.isFinite(id) || id < 1) return null;

            const sanitized = { id };

            if (typeof row?.name === 'string' && row.name !== '') {
                sanitized.name = row.name;
            }
            if (typeof row?.variation_name === 'string' && row.variation_name !== '') {
                sanitized.variation_name = row.variation_name;
            }
            if (typeof row?.role === 'string' && row.role !== '') {
                sanitized.role = row.role;
            }

            const quantity = Number.parseInt(row?.quantity, 10);
            if (Number.isFinite(quantity) && quantity > 1) {
                sanitized.quantity = quantity;
            }

            return sanitized;
        })
        .filter(Boolean);
}

export function buildKioskQuotePayload(state, { orderType, paymentMethod, requireExplicitOrderType = false } = {}) {
    const normalizedOrderType = normalizeKioskOrderType(orderType ?? state.orderType);
    const resolvedOrderType = normalizedOrderType
        ?? (requireExplicitOrderType ? assertExplicitKioskOrderType(orderType ?? state.orderType) : KIOSK_ORDER_TYPES.KIOSK);

    return {
        // branch_id is intentionally omitted: kiosk quotes resolve it server-side from KioskMachine.
        order_type: resolvedOrderType,
        loyalty_code: state.loyaltyCustomer?.loyalty_code || null,
        // Promo code remains non-financial metadata; the backend recomputes the discount.
        kiosk_promo_code: state.promoCode || null,
        is_advance_order: KIOSK_IS_ADVANCE_ORDER_NO,
        source: SOURCE_KIOSK,
        payment_method: PAYMENT_METHOD_MAP[paymentMethod] ?? 0,
        items: JSON.stringify(state.items.map(sanitizeKioskOrderItem)),
    };
}

export function buildKioskOrderPayload(state, { orderType, paymentMethod, quote, requireExplicitOrderType = false } = {}) {
    const resolvedPaymentMethod = paymentMethod ?? state.paymentMethod;

    const payload = buildKioskQuotePayload(state, {
        orderType,
        paymentMethod: resolvedPaymentMethod,
        requireExplicitOrderType,
    });
    payload.payment_method = PAYMENT_METHOD_MAP[resolvedPaymentMethod] ?? PAYMENT_METHOD_MAP.cash;

    if (quote?.quote_token && quote?.signature) {
        payload.quote_token = quote.quote_token;
        payload.quote_signature = quote.signature;
        payload.subtotal = quote.subtotal;
        payload.discount = quote.discount;
        payload.delivery_charge = quote.delivery_charge;
        payload.total = quote.total_ttc;
    }

    return payload;
}

export const kioskCart = {
    namespaced: true,
    state: {
        items: [],
        orderRef: null,
        queueNumber: null,
        upsellShown: false,
        loyaltyCustomer: null,
        loyaltyDiscount: 0,
        // Kiosk Phase 9.1.6 — Code promo branch-scoped (kiosk_promos) ou
        // coupon global. Validé lecture-seule via POST /promo/validate, la
        // consommation réelle (increment uses_count) n'intervient qu'à la
        // création de commande côté serveur (SSOT).
        promoCode: null,
        promoDiscount: 0,
        promoMeta: null,      // { type: 'percent'|'amount', value, message, kind: 'kiosk'|'coupon' }
        promoError: null,     // string i18n key OU message serveur (affiché sous l'input)
        promoLoading: false,
        branchId: null,
        idempotencyKey: null,
        kioskToken: null,
        kioskMachineId: null,
        paymentMethod: null,
        // [K-02] Sur place (25=KIOSK) ou à emporter (10=TAKEAWAY) must be explicit.
        orderType: null,
        orderQuote: null,
        // [P-MEGA-05] Édition d'une ligne du panier : on garde l'index +
        // un snapshot complet (incluant `_wizardSelections`) pour rouvrir
        // le wizard pré-rempli, REMPLACER en place à la validation, et
        // restaurer la ligne en cas d'abandon. Aucune suppression
        // intermédiaire — résilient à un close du wizard ou à un crash.
        editingCartIndex: null,
        editingCartSnapshot: null,
    },
    getters: {
        items: (state) => state.items,
        snapshot: (state) => state.items.map((line) => ({ ...line })),
        count: (state) => state.items.reduce((sum, i) => sum + i.quantity, 0),
        kioskToken: (state) => state.kioskToken,
        isAuthenticated: (state) => !!state.kioskToken,
        orderType: (state) => state.orderType,
        hasExplicitOrderType: (state) => normalizeKioskOrderType(state.orderType) !== null,
        orderQuote: (state) => state.orderQuote,
        subtotal: (state) =>
            state.items.reduce((sum, i) => {
                const base = parseFloat(i.convert_price) || 0;
                const varExtra = parseFloat(i.item_variation_total) || 0;
                const extras   = parseFloat(i.item_extra_total) || 0;
                return sum + (base + varExtra + extras) * i.quantity;
            }, 0),
        orderRef: (state) => state.orderRef,
        queueNumber: (state) => state.queueNumber,
        upsellShown: (state) => state.upsellShown,
        loyaltyCustomer: (state) => state.loyaltyCustomer,
        loyaltyDiscount: (state) => state.loyaltyDiscount,
        editingCartIndex: (state) => state.editingCartIndex,
        editingCartSnapshot: (state) => state.editingCartSnapshot,
        isEditingCart: (state) => state.editingCartIndex !== null,
        promoCode: (state) => state.promoCode,
        promoDiscount: (state) => state.promoDiscount,
        promoMeta: (state) => state.promoMeta,
        promoError: (state) => state.promoError,
        promoLoading: (state) => state.promoLoading,
        branchId: (state) => state.branchId,
        isEmpty: (state) => state.items.length === 0,
        // Kiosk Phase 9.1.6 — Total cumule loyalty + promo, jamais négatif.
        // SSOT : ce total reste purement local (affichage). La vérité finale
        // est recalculée serveur par PricingService à POST /frontend/order.
        total: (state, getters) => Math.max(
            0,
            getters.subtotal - state.loyaltyDiscount - state.promoDiscount,
        ),
    },
    mutations: {
        ADD_ITEM(state, item) {
            state.orderQuote = null;
            const existing = state.items.findIndex(i =>
                i.item_id === item.item_id &&
                JSON.stringify(i.item_variations) === JSON.stringify(item.item_variations) &&
                JSON.stringify(i.item_extras) === JSON.stringify(item.item_extras) &&
                (i.instruction || '') === (item.instruction || '')
            );
            if (existing >= 0) {
                const qty = Math.min(state.items[existing].quantity + (item.quantity || 1), MAX_ITEM_QTY);
                state.items[existing].quantity = qty;
                // [KIOSK-17] Keep line total in sync when merging identical items
                const base = parseFloat(state.items[existing].convert_price) || 0;
                const varE = parseFloat(state.items[existing].item_variation_total) || 0;
                const ext  = parseFloat(state.items[existing].item_extra_total) || 0;
                state.items[existing].total = parseFloat(((base + varE + ext) * qty).toFixed(2));
            } else {
                // [PHASE9 W-P1-5 FIX] Clamp quantity even for new lines (previously
                // only merged lines were clamped, creating an asymmetric contract
                // where a fresh-line quantity=99 was shipped as-is to server).
                const rawQty = Number(item.quantity || 1);
                const newItem = {
                    ...item,
                    quantity: Math.max(1, Math.min(Number.isFinite(rawQty) ? Math.floor(rawQty) : 1, MAX_ITEM_QTY)),
                };
                // Ensure total is always present
                if (!newItem.total) {
                    const base = parseFloat(newItem.convert_price) || 0;
                    const varE = parseFloat(newItem.item_variation_total) || 0;
                    const ext  = parseFloat(newItem.item_extra_total) || 0;
                    newItem.total = parseFloat(((base + varE + ext) * newItem.quantity).toFixed(2));
                }
                state.items.push(newItem);
            }
        },
        REMOVE_ITEM(state, index) {
            state.orderQuote = null;
            state.items.splice(index, 1);
        },
        // [P-MEGA-05] Replace une ligne en place (préserve l'index, donc
        // l'ordre visuel et les coupons attachés à un slot précis).
        REPLACE_ITEM_AT(state, { index, item }) {
            if (index < 0 || index >= state.items.length) return;
            state.orderQuote = null;
            const rawQty = Number(item?.quantity || 1);
            const safeItem = {
                ...item,
                quantity: Math.max(1, Math.min(Number.isFinite(rawQty) ? Math.floor(rawQty) : 1, MAX_ITEM_QTY)),
            };
            if (!safeItem.total) {
                const base = parseFloat(safeItem.convert_price) || 0;
                const varE = parseFloat(safeItem.item_variation_total) || 0;
                const ext = parseFloat(safeItem.item_extra_total) || 0;
                safeItem.total = parseFloat(((base + varE + ext) * safeItem.quantity).toFixed(2));
            }
            state.items.splice(index, 1, safeItem);
        },
        SET_EDITING(state, { index, snapshot }) {
            state.editingCartIndex = Number.isInteger(index) ? index : null;
            state.editingCartSnapshot = snapshot ? JSON.parse(JSON.stringify(snapshot)) : null;
        },
        CLEAR_EDITING(state) {
            state.editingCartIndex = null;
            state.editingCartSnapshot = null;
        },
        UPDATE_QUANTITY(state, { index, quantity }) {
            state.orderQuote = null;
            if (quantity <= 0) {
                state.items.splice(index, 1);
            } else {
                state.items[index].quantity = Math.min(quantity, MAX_ITEM_QTY);
                // [KIOSK-17] Keep line total in sync when quantity changes
                const base = parseFloat(state.items[index].convert_price) || 0;
                const varE = parseFloat(state.items[index].item_variation_total) || 0;
                const ext  = parseFloat(state.items[index].item_extra_total) || 0;
                state.items[index].total = parseFloat(((base + varE + ext) * quantity).toFixed(2));
            }
        },
        SET_ORDER_REF(state, { orderId, queueNumber }) {
            state.orderRef = orderId;
            state.queueNumber = queueNumber;
        },
        SET_UPSELL_SHOWN(state, val) {
            state.upsellShown = val;
        },
        SET_LOYALTY(state, { customer, discount }) {
            state.orderQuote = null;
            state.loyaltyCustomer = customer;
            state.loyaltyDiscount = discount || 0;
        },
        SET_ORDER_QUOTE(state, quote) {
            state.orderQuote = quote || null;
        },
        CLEAR_ORDER_QUOTE(state) {
            state.orderQuote = null;
        },
        // Kiosk Phase 9.1.6 — Promo state mutations.
        SET_PROMO_LOADING(state, value) {
            state.promoLoading = !!value;
        },
        SET_PROMO(state, { code, discount, meta }) {
            state.orderQuote = null;
            state.promoCode = code || null;
            state.promoDiscount = Math.max(0, parseFloat(discount) || 0);
            state.promoMeta = meta || null;
            state.promoError = null;
        },
        SET_PROMO_ERROR(state, message) {
            state.orderQuote = null;
            state.promoError = message || null;
            state.promoDiscount = 0;
            state.promoMeta = null;
        },
        CLEAR_PROMO(state) {
            state.orderQuote = null;
            state.promoCode = null;
            state.promoDiscount = 0;
            state.promoMeta = null;
            state.promoError = null;
            state.promoLoading = false;
        },
        SET_BRANCH(state, branchId) {
            state.branchId = branchId;
        },
        SET_IDEMPOTENCY_KEY(state, key) {
            state.idempotencyKey = key;
        },
        SET_KIOSK_TOKEN(state, { token, machineId }) {
            state.kioskToken = token || null;
            // [GAP-34-2] Re-inject kiosk token into Echo auth headers after kiosk login.
            if (typeof window !== 'undefined' && typeof window._refreshEchoAuth === 'function') {
                window._refreshEchoAuth();
            }
            state.kioskMachineId = machineId || null;
        },
        CLEAR_KIOSK_TOKEN(state) {
            state.kioskToken = null;
            state.kioskMachineId = null;
        },
        SET_PAYMENT_METHOD(state, method) {
            state.paymentMethod = method || null;
        },
        // [GAP-22-1] Set order type: 25=KIOSK (sur place), 10=TAKEAWAY (à emporter)
        SET_ORDER_TYPE(state, orderType) {
            state.orderQuote = null;
            state.orderType = normalizeKioskOrderType(orderType);
        },
        /**
         * [P1] Remove cart lines whose menu row is now unavailable (Echo ItemAvailabilityChanged).
         */
        SET_CART_LINES(state, items) {
            state.orderQuote = null;
            state.items = Array.isArray(items) ? items : [];
        },
        RESET(state) {
            state.items = [];
            state.orderRef = null;
            state.queueNumber = null;
            state.upsellShown = false;
            state.loyaltyCustomer = null;
            state.loyaltyDiscount = 0;
            state.idempotencyKey = null;
            state.paymentMethod = null;
            state.orderType = null;
            state.orderQuote = null;
            // Kiosk Phase 9.1.6 — promo reset au retour idle (comme loyalty).
            state.promoCode = null;
            state.promoDiscount = 0;
            state.promoMeta = null;
            state.promoError = null;
            state.promoLoading = false;
            // [P-MEGA-05] Toujours clear l'édition au reset (idle, logout).
            state.editingCartIndex = null;
            state.editingCartSnapshot = null;
        },
    },
    actions: {
        /**
         * Authenticate this kiosk machine against the backend.
         * Stores the Sanctum token in state (persisted via vuex-persistedstate).
         * The app.js interceptor will pick it up automatically.
         *
         * [iter15-mega-fix C-037/D5-003 round-7 2026-05-10] Coalesce concurrent
         * calls. Three call sites can race on a fresh kiosk context :
         *   1. router beforeEnter `requireKioskAuth` (kioskRoutes.js)
         *   2. `KioskLoginComponent.startAutoLogin()` mounted hook
         *   3. `app.js` 401 response interceptor — fires PER 401, so a single
         *      catalog mount that triggers 7 parallel `/frontend/menu` (the
         *      symptom in `03-kiosk-blocked-no-frites.network.json`) would
         *      dispatch 7 concurrent kioskLogin's.
         *
         * Each concurrent login deletes prior `kiosk-token` rows in
         * KioskMachineLoginController::login (DB transaction line 96), so the
         * loser tokens are immediately revoked → the in-flight retried
         * requests carry stale Bearers → terminal 401 (because
         * `__retry401Kiosk` blocks a second retry on the same request). By
         * sharing one in-flight Promise we get exactly one server-side
         * rotation, and every dependent call (menu, kiosk-event, Echo auth)
         * waits on the same token.
         */
        async kioskLogin({ commit, state }, { username, password }) {
            if (_inFlightKioskLogin) {
                return _inFlightKioskLogin;
            }
            _inFlightKioskLogin = (async () => {
                try {
                    const res = await axios.post('auth/kiosk-login', {
                        username: String(username || '').trim(),
                        password,
                    });
                    const token = res?.data?.token;
                    const machineId = res?.data?.kiosk?.id || null;
                    if (!token) throw new Error('No token received');
                    commit('SET_KIOSK_TOKEN', { token, machineId });
                    return res.data;
                } finally {
                    // Always clear, success OR failure — otherwise a single
                    // failed login would permanently block further attempts.
                    _inFlightKioskLogin = null;
                }
            })();
            return _inFlightKioskLogin;
        },
        async kioskLogout({ commit, state }) {
            try {
                if (state.kioskToken) {
                    await axios.post('auth/kiosk-logout');
                }
            } catch (_) { /* best-effort */ }
            commit('CLEAR_KIOSK_TOKEN');
            commit('RESET');
        },
        addItem({ commit }, item) {
            commit('ADD_ITEM', item);
        },
        removeItem({ commit }, index) {
            commit('REMOVE_ITEM', index);
        },
        /**
         * Remove an item by index and return it (used to pre-populate wizard on edit).
         * @deprecated Use startEditingCartItem to preserve the line during wizard reopen.
         */
        popItem({ commit, state }, index) {
            const item = state.items[index];
            if (!item) return null;
            commit('REMOVE_ITEM', index);
            return { ...item };
        },
        /**
         * [P-MEGA-05] Démarre l'édition d'une ligne du panier sans la
         * supprimer. Le wizard ouvert ensuite consume `editingCartSnapshot`
         * pour restaurer les sélections, et appellera `replaceEditingCartItem`
         * à la validation. Si l'utilisateur abandonne, `cancelEditingCartItem`
         * laisse la ligne intacte.
         */
        startEditingCartItem({ commit, state }, index) {
            const snapshot = state.items[index];
            if (!snapshot) return false;
            commit('SET_EDITING', { index, snapshot });
            return true;
        },
        cancelEditingCartItem({ commit }) {
            commit('CLEAR_EDITING');
        },
        /**
         * Replace la ligne en édition par un nouveau cartItem produit par
         * le wizard, et clear l'état d'édition. Si plus rien n'est en
         * édition (déjà annulé), fallback sur addItem pour ne PAS perdre
         * l'item construit.
         */
        replaceEditingCartItem({ commit, state }, item) {
            if (state.editingCartIndex === null || state.editingCartIndex === undefined) {
                commit('ADD_ITEM', item);
            } else {
                commit('REPLACE_ITEM_AT', { index: state.editingCartIndex, item });
            }
            commit('CLEAR_EDITING');
        },
        updateQuantity({ commit }, payload) {
            commit('UPDATE_QUANTITY', payload);
        },
        markUpsellShown({ commit }) {
            commit('SET_UPSELL_SHOWN', true);
        },
        setBranch({ commit }, branchId) {
            commit('SET_BRANCH', branchId);
        },
        setLoyalty({ commit }, payload) {
            commit('SET_LOYALTY', payload);
        },
        // Kiosk Phase 9.1.6 — Valide un code promo en lecture-seule via
        // POST /api/frontend/promo/validate. Renvoie la meta côté appelant
        // pour affichage ; stocke dans state si valide, nettoie sinon.
        //
        // Invariants :
        //  - `branch_id` n'est JAMAIS envoyé (lu serveur via KioskMachine).
        //  - `cart_total` est envoyé pour permettre au serveur d'appliquer
        //    `min_cart` mais le montant de discount reste calculé serveur.
        //  - Aucune écriture DB ici (pas d'increment uses_count) : la
        //    consommation réelle intervient uniquement à /frontend/order.
        async validatePromo({ commit, getters }, rawCode) {
            const code = String(rawCode || '').trim();
            if (!code) {
                commit('SET_PROMO_ERROR', 'kiosk.promo.error.empty');
                return { valid: false, reason: 'empty' };
            }
            commit('SET_PROMO_LOADING', true);
            try {
                const cartTotal = getters.subtotal || 0;
                const res = await axios.post('frontend/promo/validate', {
                    code,
                    cart_total: cartTotal,
                });
                const data = res?.data?.data || {};
                const valid = !!(res?.data?.status);
                if (valid) {
                    commit('SET_PROMO', {
                        code,
                        discount: parseFloat(data.discount || 0) || 0,
                        meta: {
                            type: data.type || null,
                            value: data.value ?? null,
                            kind: data.kind || null,
                            message: res.data.message || null,
                        },
                    });
                    return { valid: true, data };
                }
                commit('SET_PROMO_ERROR', res?.data?.message || 'kiosk.promo.error.invalid');
                return { valid: false, message: res?.data?.message || null };
            } catch (err) {
                const message = err?.response?.data?.message || 'kiosk.promo.error.network';
                commit('SET_PROMO_ERROR', message);
                return { valid: false, message };
            } finally {
                commit('SET_PROMO_LOADING', false);
            }
        },
        clearPromo({ commit }) {
            commit('CLEAR_PROMO');
        },
        // [GAP-22-1] Store the order type chosen by the customer (sur place / à emporter)
        setOrderType({ commit }, orderType) {
            commit('SET_ORDER_TYPE', orderType);
        },
        async quoteOrder({ commit, state }, { orderType, paymentMethod } = {}) {
            // [test-e2e/pos-kds-sync round-3 E-002 P0] silent-error visibility:
            // /frontend/order/quote requires the kiosk:order Sanctum bearer. Pre-fix,
            // any caller (KioskCartComponent.proceedToUpsell, payment refresh, an
            // offline-queue replay racing pre-hydration) that fired this action before
            // the kiosk login had populated state.kioskToken would emit a 401 with
            // empty Authorization header — captured in audit Wave E state 04 with
            // zero DOM signal. We now gate on token presence and abort with a typed
            // error BEFORE issuing the network call. The audit-friendly console.debug
            // keeps the trail visible to engineers without leaking to operators.
            if (!state.kioskToken) {
                if (typeof console !== 'undefined' && typeof console.debug === 'function') {
                    console.debug('[kioskCart] quoteOrder skipped — kioskToken absent (auth not yet hydrated)');
                }
                const error = new Error('KIOSK_QUOTE_NO_TOKEN');
                error.code = 'KIOSK_QUOTE_NO_TOKEN';
                throw error;
            }
            const explicitOrderType = assertExplicitKioskOrderType(orderType ?? state.orderType);
            const payload = buildKioskQuotePayload(state, {
                orderType: explicitOrderType,
                paymentMethod,
                requireExplicitOrderType: true,
            });
            const res = await axios.post('frontend/order/quote', payload);
            const quote = res?.data?.data;
            if (!quote || quote.total_ttc === undefined || !quote.quote_token || !quote.signature) {
                const error = new Error('KIOSK_QUOTE_INVALID');
                error.code = 'KIOSK_QUOTE_INVALID';
                throw error;
            }
            commit('SET_ORDER_QUOTE', quote);
            return quote;
        },
        /**
         * Drop lines for items marked unavailable in kioskMenu (real-time rupture / 86).
         */
        pruneUnavailableLines({ state, commit, rootState }) {
            const menuItems = rootState.kioskMenu?.items || [];
            const byId = new Map(menuItems.map((i) => [parseInt(i.id, 10), i]));
            const filtered = state.items.filter((line) => {
                const mid = parseInt(line.item_id, 10);
                const m = byId.get(mid);
                if (!m) return true;
                if (m.is_available === false) return false;
                const st = m.status !== undefined && m.status !== null ? parseInt(m.status, 10) : null;
                if (st === 0 || st === 2) return false;
                return true;
            });
            if (filtered.length !== state.items.length) {
                commit('SET_CART_LINES', filtered);
            }
        },
        async pruneOfflineQueueOnAvailabilityChanged({ state }, { itemId, branchId } = {}) {
            return markStaleItems({
                itemId,
                branchId: branchId ?? state.branchId ?? null,
            });
        },
        reset({ commit }) {
            commit('RESET');
        },
        submitOrder({ commit, state }, { orderType, paymentMethod, quote } = {}) {
            return new Promise((resolve, reject) => {
                const explicitOrderType = assertExplicitKioskOrderType(orderType ?? state.orderType);

                loadSnapshot().then(snap => {
                    if (snap && isSnapshotStale(snap.savedAt)) {
                        console.warn('[Kiosk] Menu snapshot is stale (>4h). Server will recalculate prices at order time (SSOT).');
                    }
                }).catch(() => {});

                // Store payment method for receipt printing
                commit('SET_PAYMENT_METHOD', paymentMethod || 'cash');

                // [SPLASH SECURITY] Generate idempotency key once per session cart.
                // Stored in state so retries/double-tap reuse the same key.
                let idempotencyKey = state.idempotencyKey;
                if (!idempotencyKey) {
                    idempotencyKey = ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
                        (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));
                    commit('SET_IDEMPOTENCY_KEY', idempotencyKey);
                }

                const orderPayload = buildKioskOrderPayload(state, {
                    orderType: explicitOrderType,
                    paymentMethod,
                    quote,
                    requireExplicitOrderType: true,
                });

                axios.post('frontend/order', orderPayload, {
                    headers: { 'X-Idempotency-Key': idempotencyKey },
                }).then((res) => {
                    const orderId = res.data?.data?.id || res.data?.id;
                    const queueNumber = res.data?.data?.queue_number || res.data?.queue_number;
                    commit('SET_ORDER_REF', { orderId, queueNumber });
                    resolve(res);
                }).catch((err) => {
                    // [SPLASH OFFLINE MODE] If network is unavailable, queue locally.
                    // The order will be synced automatically when connectivity returns.
                    const isNetworkError = !err.response || err.response?.status >= 500;
                    if (isNetworkError) {
                        if (ELECTRONIC_PAYMENT_METHODS.has(paymentMethod)) {
                            const offlinePaymentError = new Error('KIOSK_OFFLINE_ELECTRONIC_PAYMENT_REFUSED');
                            offlinePaymentError.code = 'KIOSK_OFFLINE_ELECTRONIC_PAYMENT_REFUSED';
                            reject(offlinePaymentError);
                            return;
                        }

                        // [FIX-54-3] Preserve original idempotency key for offline replay.
                        // The replay layer regenerates a fresh quote before POST /frontend/order
                        // so expired quote tokens cannot strand queued cash orders.
                        // Queue metadata keeps the kiosk branch for stale invalidation only.
                        // The backend payload still resolves branch_id server-side from KioskMachine.
                        const offlinePayload = { ...orderPayload };
                        delete offlinePayload.quote_token;
                        delete offlinePayload.quote_signature;
                        delete offlinePayload.subtotal;
                        delete offlinePayload.discount;
                        delete offlinePayload.delivery_charge;
                        delete offlinePayload.total;
                        const localKey = saveOrder(offlinePayload, idempotencyKey, {
                            branchId: state.branchId ?? null,
                        });
                        // Start background sync so it retries when network comes back
                        // [AUDIT-P0] Pass config (headers) so syncQueue can send X-Idempotency-Key
                        startAutoSync((url, data, config) => axios.post(url, data, config || {}));
                        // Return a synthetic "offline" response so the UI can proceed
                        const offlineRes = {
                            data: {
                                data: {
                                    id: localKey,
                                    queue_number: '—',
                                    _offline: true,
                                },
                            },
                        };
                        commit('SET_ORDER_REF', { orderId: localKey, queueNumber: '—' });
                        resolve(offlineRes);
                    } else {
                        reject(err);
                    }
                });
            });
        },

        /**
         * Return how many orders are queued offline (for UI badge).
         */
        getOfflinePendingCount() {
            return getPendingCount();
        },
        fetchOrderStatus(_, orderId) {
            // Utiliser l'endpoint show comme frontendOrder/show
            return new Promise((resolve, reject) => {
                axios.get(`frontend/order/show/${orderId}`).then(resolve).catch(reject);
            });
        },
        fetchUpsellItems({ state }) {
            return new Promise((resolve) => {
                // [SPLASH MERCHANDISING] Use smart kiosk-upsell endpoint
                // Sends item IDs in cart so backend can suggest complementary items
                const itemIds = state.items.map(i => i.item_id).join(',');
                const url = itemIds
                    ? `frontend/item/kiosk-upsell?item_ids=${itemIds}&limit=6`
                    : 'frontend/item/kiosk-upsell?limit=6';
                axios
                    .get(url)
                    .then(resolve)
                    .catch(() => resolve({ data: { data: [] } }));
            });
        },
    },
};
