import axios from "axios";

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
    },
    getters: {
        items: (state) => state.items,
        count: (state) => state.items.reduce((sum, i) => sum + i.quantity, 0),
        kioskToken: (state) => state.kioskToken,
        isAuthenticated: (state) => !!state.kioskToken,
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
                state.items[existing].quantity += item.quantity || 1;
            } else {
                state.items.push({ ...item, quantity: item.quantity || 1 });
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
            state.kioskMachineId = machineId || null;
        },
        CLEAR_KIOSK_TOKEN(state) {
            state.kioskToken = null;
            state.kioskMachineId = null;
        },
        RESET(state) {
            state.items = [];
            state.orderRef = null;
            state.queueNumber = null;
            state.upsellShown = false;
            state.loyaltyCustomer = null;
            state.loyaltyDiscount = 0;
            state.idempotencyKey = null;
        },
    },
    actions: {
        /**
         * Authenticate this kiosk machine against the backend.
         * Stores the Sanctum token in state (persisted via vuex-persistedstate).
         * The app.js interceptor will pick it up automatically.
         */
        async kioskLogin({ commit }, { username, password }) {
            const res = await axios.post('auth/kiosk-login', { username, password });
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
        reset({ commit }) {
            commit('RESET');
        },
        submitOrder({ commit, state, getters }, { branchId, orderType, paymentMethod } = {}) {
            return new Promise((resolve, reject) => {
                const resolvedBranchId = branchId || state.branchId;
                const subtotal = getters.subtotal;
                const total = getters.total;

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
                        item_variations: item.item_variations || { variations: {}, names: {} },
                        item_extras: item.item_extras || { extras: [], names: [] },
                    };
                });

                axios.post('frontend/order', {
                    branch_id: resolvedBranchId,
                    order_type: orderType || 25, // OrderType::KIOSK = 25
                    subtotal: subtotal,
                    discount: state.loyaltyDiscount || 0,
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
                }, {
                    headers: { 'X-Idempotency-Key': idempotencyKey },
                }).then((res) => {
                    const orderId = res.data?.data?.id || res.data?.id;
                    const queueNumber = res.data?.data?.queue_number || res.data?.queue_number;
                    commit('SET_ORDER_REF', { orderId, queueNumber });
                    resolve(res);
                }).catch(reject);
            });
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
