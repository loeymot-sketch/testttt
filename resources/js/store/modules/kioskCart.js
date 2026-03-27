import axios from "axios";
import { saveOrder, getPendingCount, startAutoSync } from "../../helpers/kioskOfflineQueue";

// Source identique à sourceEnum.WEB (pas de valeur KIOSK côté frontend)
const SOURCE_KIOSK = 5;

// Map kiosk UI payment strings → PaymentGateway numeric IDs stored in DB
// cash=1 (CASH_ON_DELIVERY), card=4 (CARD), tr=5 (TICKET_RESTAURANT)
const PAYMENT_METHOD_MAP = { cash: 1, card: 4, tr: 5 };

export const kioskCart = {
    namespaced: true,
    state: {
        items: [],
        orderRef: null,
        queueNumber: null,
        upsellShown: false,
        loyaltyCustomer: null,
        loyaltyDiscount: 0,
        branchId: null,
        idempotencyKey: null,
        kioskToken: null,
        kioskMachineId: null,
        paymentMethod: null,
        // [GAP-22-1] Sur place (25=KIOSK) ou à emporter (10=TAKEAWAY)
        orderType: 25,
    },
    getters: {
        items: (state) => state.items,
        count: (state) => state.items.reduce((sum, i) => sum + i.quantity, 0),
        kioskToken: (state) => state.kioskToken,
        isAuthenticated: (state) => !!state.kioskToken,
        orderType: (state) => state.orderType,
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
        branchId: (state) => state.branchId,
        isEmpty: (state) => state.items.length === 0,
        total: (state, getters) => Math.max(0, getters.subtotal - state.loyaltyDiscount),
    },
    mutations: {
        ADD_ITEM(state, item) {
            const existing = state.items.findIndex(i =>
                i.item_id === item.item_id &&
                JSON.stringify(i.item_variations) === JSON.stringify(item.item_variations) &&
                JSON.stringify(i.item_extras) === JSON.stringify(item.item_extras)
            );
            if (existing >= 0) {
                const qty = state.items[existing].quantity + (item.quantity || 1);
                state.items[existing].quantity = qty;
                // [KIOSK-17] Keep line total in sync when merging identical items
                const base = parseFloat(state.items[existing].convert_price) || 0;
                const varE = parseFloat(state.items[existing].item_variation_total) || 0;
                const ext  = parseFloat(state.items[existing].item_extra_total) || 0;
                state.items[existing].total = parseFloat(((base + varE + ext) * qty).toFixed(2));
            } else {
                const newItem = { ...item, quantity: item.quantity || 1 };
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
            state.items.splice(index, 1);
        },
        UPDATE_QUANTITY(state, { index, quantity }) {
            if (quantity <= 0) {
                state.items.splice(index, 1);
            } else {
                state.items[index].quantity = quantity;
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
            state.loyaltyCustomer = customer;
            state.loyaltyDiscount = discount || 0;
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
            state.orderType = orderType || 25;
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
            state.orderType = 25;
        },
    },
    actions: {
        /**
         * Authenticate this kiosk machine against the backend.
         * Stores the Sanctum token in state (persisted via vuex-persistedstate).
         * The app.js interceptor will pick it up automatically.
         */
        async kioskLogin({ commit }, { username, password }) {
            const res = await axios.post('auth/kiosk-login', {
                username: String(username || '').trim(),
                password,
            });
            const token = res?.data?.token;
            const machineId = res?.data?.kiosk?.id || null;
            if (!token) throw new Error('No token received');
            commit('SET_KIOSK_TOKEN', { token, machineId });
            return res.data;
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
         */
        popItem({ commit, state }, index) {
            const item = state.items[index];
            if (!item) return null;
            commit('REMOVE_ITEM', index);
            return { ...item };
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
        // [GAP-22-1] Store the order type chosen by the customer (sur place / à emporter)
        setOrderType({ commit }, orderType) {
            commit('SET_ORDER_TYPE', orderType);
        },
        reset({ commit }) {
            commit('RESET');
        },
        submitOrder({ commit, state, getters }, { branchId, orderType, paymentMethod } = {}) {
            return new Promise((resolve, reject) => {
                const resolvedBranchId = branchId || state.branchId;
                const subtotal = getters.subtotal;
                const total = getters.total;

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

                // Construire chaque ligne selon le contrat de CheckoutComponent / OrderRequest
                const orderItems = state.items.map(item => {
                    const itemPrice = parseFloat(item.convert_price) || 0;
                    const itemVariationTotal = item.item_variation_total || 0;
                    const itemExtraTotal = item.item_extra_total || 0;
                    const totalPrice = (itemPrice + itemVariationTotal + itemExtraTotal) * item.quantity;

                    // [AUDIT-P0] Normalize kiosk wizard shape to the array format expected by
                    // FrontendOrderService (foreach $var->id) and KDS template (v-for variation_name/name).
                    //
                    // Wizard stores:  item_variations = { variations: { attrId: varId }, names: { label: name } }
                    //                 item_extras     = { extras: [id1, id2], names: ['Tomate', ...] }
                    //
                    // Server expects: item_variations = [{ id: varId, variation_name: label, name: name }]
                    //                 item_extras     = [{ id: extraId, name: extraName }]
                    const rawVariations = item.item_variations || {};
                    const variationsObj = rawVariations.variations || {};
                    const variationNames = rawVariations.names || {};
                    const normalizedVariations = Object.entries(variationsObj)
                        .filter(([, varId]) => varId)
                        .map(([attrId, varId]) => {
                            const label = Object.keys(variationNames).find(k =>
                                variationNames[k] !== undefined
                            ) || '';
                            // Match label to attrId by position (names object keys align with variations keys)
                            const attrKeys = Object.keys(variationsObj);
                            const idx = attrKeys.indexOf(String(attrId));
                            const nameKeys = Object.keys(variationNames);
                            const variationName = nameKeys[idx] || '';
                            const name = variationNames[variationName] || '';
                            return { id: parseInt(varId), variation_name: variationName, name };
                        });

                    const rawExtras = item.item_extras || {};
                    const extrasArr = Array.isArray(rawExtras.extras) ? rawExtras.extras : [];
                    const extrasNames = Array.isArray(rawExtras.names) ? rawExtras.names : [];
                    const normalizedExtras = extrasArr.map((extraId, idx) => ({
                        id: parseInt(extraId),
                        name: extrasNames[idx] || '',
                    }));

                    return {
                        item_id: item.item_id,
                        item_price: item.convert_price,
                        branch_id: resolvedBranchId,
                        instruction: item.instruction || '',
                        quantity: item.quantity,
                        discount: item.discount || 0,
                        total_price: totalPrice,
                        item_variation_total: itemVariationTotal,
                        item_extra_total: itemExtraTotal,
                        item_variations: normalizedVariations,
                        item_extras: normalizedExtras,
                    };
                });

                const orderPayload = {
                    branch_id: resolvedBranchId,
                    // [GAP-22-1] Use the order type chosen by the customer (sur place=25, à emporter=10)
                    // Falls back to state.orderType (set via setOrderType action), then to passed param, then to KIOSK=25
                    order_type: orderType || state.orderType || 25,
                    subtotal: subtotal,
                    discount: state.loyaltyDiscount || 0,
                    // [SPLASH LOYALTY] Send loyalty_code so backend can validate and deduct points server-side
                    loyalty_code: state.loyaltyCustomer?.loyalty_code || null,
                    delivery_charge: 0,
                    total: total,
                    is_advance_order: 0,
                    source: SOURCE_KIOSK,
                    payment_method: PAYMENT_METHOD_MAP[paymentMethod] ?? PAYMENT_METHOD_MAP.cash,
                    coupon_id: null,
                    address_id: null,
                    delivery_time: null,
                    token: null,
                    items: JSON.stringify(orderItems),
                };

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
                        // [FIX-54-3] Preserve original idempotency key for offline replay
                        const localKey = saveOrder(orderPayload, idempotencyKey);
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
