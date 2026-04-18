import _ from "lodash";
import { computePosCartLineDisplayTotal } from "../../helpers/posCartLineMath";

// Clé localStorage pour le panier POS
/**
 * v2 : lignes menu regroupées (`pos_line_addons`) — invalide l’ancien panier « 2 lignes » en localStorage
 * v3 [POS-9.1.9] : clé scopée par branch_id + user_id pour empêcher la fuite
 *                  d'un panier d'un caissier vers un autre sur la même machine
 *                  (POS-GA-F-41). Le panier non scopé n'est plus jamais lu ni écrit.
 */
const POS_CART_LEGACY_KEY = 'pos_cart_v2';
const POS_CART_KEY_PREFIX = 'pos_cart_v3';
const POS_CART_TTL_MS = 2 * 60 * 60 * 1000; // 2 heures

/**
 * Module-level scope for the current cashier session. Mutated via the
 * `setScope` action once the auth user is known (PosComponent mounted).
 */
let _scope = { branchId: null, userId: null };

function getScopedKey() {
    if (_scope.branchId == null || _scope.userId == null) return null;
    return `${POS_CART_KEY_PREFIX}:b${_scope.branchId}:u${_scope.userId}`;
}

function saveCartToStorage(state) {
    const key = getScopedKey();
    if (!key) return; // no scope yet → never persist (avoids cross-cashier leak)
    try {
        localStorage.setItem(key, JSON.stringify({
            lists: state.lists,
            subtotal: state.subtotal,
            discount: state.discount,
            savedAt: Date.now(),
            branchId: _scope.branchId,
            userId: _scope.userId,
        }));
    } catch (e) {
        // localStorage peut être indisponible (mode privé, quota dépassé).
        // [H.3.8 F-A16] console.warn removed — the cart re-save retry
        // loop would otherwise flood DevTools on every mutation. The
        // failure is inherently transient (quota, private-mode) and
        // non-fatal: on next mutation we try again.
    }
}

function loadCartFromStorage() {
    const key = getScopedKey();
    if (!key) return null;
    try {
        const raw = localStorage.getItem(key);
        if (!raw) return null;
        const data = JSON.parse(raw);
        if (!data || !data.savedAt) return null;
        // Cross-check scope: never restore a cart that does not match the
        // active branch/user (defence-in-depth against key collisions).
        if (
            (data.branchId != null && data.branchId !== _scope.branchId) ||
            (data.userId   != null && data.userId   !== _scope.userId)
        ) {
            return null;
        }
        // Expirer après 2h
        if (Date.now() - data.savedAt > POS_CART_TTL_MS) {
            localStorage.removeItem(key);
            return null;
        }
        return data;
    } catch (e) {
        return null;
    }
}

function clearCartFromStorage() {
    const key = getScopedKey();
    try {
        if (key) localStorage.removeItem(key);
        // Also wipe any leftover unscoped legacy key — it must never be read again.
        localStorage.removeItem(POS_CART_LEGACY_KEY);
    } catch (e) {}
}

/**
 * Internal helper exported for tests — applies a fresh scope and reloads.
 * Returns the loaded cart (or null) so the action can re-hydrate state.
 */
export function _applyPosCartScope(branchId, userId) {
    _scope.branchId = branchId == null ? null : Number(branchId);
    _scope.userId   = userId   == null ? null : Number(userId);
    // Always purge the legacy unscoped key on scope change.
    try { localStorage.removeItem(POS_CART_LEGACY_KEY); } catch (e) {}
    return loadCartFromStorage();
}

/** Addons regroupés sur la ligne principale (menu, frites, etc.) — signature pour fusion panier */
function normPosLineAddons(addons) {
    return Array.isArray(addons) ? addons : [];
}

function posLineAddonsSignature(addons) {
    const arr = normPosLineAddons(addons);
    if (arr.length === 0) return '_';
    // [M3 FIX] Include menu_extras in signature so lines with different frites options don't merge
    return arr
        .map((a) => {
            const extrasHash = Array.isArray(a.menu_extras) ? a.menu_extras.slice().sort().join(',') : '';
            return `${a.parent_addon_id}:${a.item_id}:${a.quantity}:${extrasHash}`;
        })
        .sort()
        .join('|');
}

/** Normalise un addon bundlé en préservant menu_extras et menu_restore */
function normPosLineAddon(a) {
    return {
        parent_addon_id: a.parent_addon_id,
        name: a.name,
        image: a.image || '',
        item_id: a.item_id,
        quantity: a.quantity,
        discount: a.discount || 0,
        currency_price: a.currency_price,
        convert_price: a.convert_price,
        item_variations: a.item_variations || { variations: {}, names: {} },
        item_extras: a.item_extras || { extras: [], names: [] },
        item_variation_total: a.item_variation_total || 0,
        item_extra_total: a.item_extra_total || 0,
        instruction: a.instruction || '',
        total_price: a.total_price || 0,
        menu_extras: Array.isArray(a.menu_extras) ? a.menu_extras : [],
        menu_restore: a.menu_restore || null,
    };
}

/** Forme canonique d'une ligne panier (évite duplication lists vs replaceCartLine) */
function shapePosListItem(pay) {
    return {
        discount: pay.discount,
        image: pay.image,
        instruction: pay.instruction,
        item_extra_total: pay.item_extra_total,
        item_extras: pay.item_extras,
        item_id: pay.item_id,
        item_variation_total: pay.item_variation_total,
        item_variations: pay.item_variations,
        name: pay.name,
        currency_price: pay.currency_price,
        convert_price: pay.convert_price,
        quantity: pay.quantity,
        pos_line_addons: normPosLineAddons(pay.pos_line_addons).map(normPosLineAddon),
        cart_display: pay.cart_display || '',
    };
}

export const posCart = {
    namespaced: true,
    state: (function() {
        // [POS-9.1.9] Initial state is ALWAYS empty — we have no scope yet
        // (auth is not known at module init). The cart is rehydrated from
        // localStorage only after PosComponent dispatches `setScope`.
        return {
            lists: [],
            subtotal: 0,
            discount: 0,
            restoredFromStorage: false,
        };
    })(),
    getters: {
        lists: function (state) {
            return state.lists;
        },
        subtotal: function (state) {
            return state.subtotal;
        },
        discount: function (state) {
            return state.discount;
        },
        restoredFromStorage: function (state) {
            return state.restoredFromStorage || false;
        }
    },
    actions: {
        lists: function (context, payload) {
            context.commit("lists", payload);
            context.commit("subtotal");
        },
        quantity: function (context, payload) {
            context.commit("quantity", payload);
            context.commit("subtotal");
            context.commit("discount",0);
        },
        deleteCartItem: function (context, payload) {
            context.commit("deleteCartItem", payload);
            context.commit("subtotal");
            context.commit("discount",0);
        },
        discount: function (context, payload) {
            context.commit("discount", payload);
        },
        destroyDiscount: function (context) {
            context.commit('discount', 0);
        },
        resetCart: function (context) {
            context.commit('resetCart');
        },
        acknowledgeRestore: function (context) {
            context.commit('acknowledgeRestore');
        },
        replaceCartLine: function (context, payload) {
            context.commit('replaceCartLine', payload);
            context.commit('subtotal');
        },
        /**
         * [POS-9.1.9] Bind the cart to the active cashier (branch + user).
         * Must be called by PosComponent as soon as the auth user is known
         * — before any add-to-cart action — to (a) load the previously saved
         * cart of THIS cashier on THIS branch and (b) start scoping all
         * future writes. Switching scope wipes the in-memory state to avoid
         * leaking lines from cashier A into cashier B's session.
         */
        setScope: function (context, payload) {
            const branchId = payload && payload.branchId != null ? payload.branchId : null;
            const userId   = payload && payload.userId   != null ? payload.userId   : null;
            const saved = _applyPosCartScope(branchId, userId);
            context.commit('hydrateFromScope', saved);
        },
    },
    mutations: {
        lists: function (state, payload) {
            if (payload.length > 0) {
                let isNew = false;
                let newChecker = [];
                let variationAndExtraChecker = [];
                _.forEach(payload, (pay) => {
                    if (state.lists.length === 0) {
                        isNew = true;
                    } else {
                        isNew = true;
                        _.forEach(state.lists, (list, listKey) => {
                            if (list.item_id === pay.item_id) {

                                // [N1+N8 FIX] Symmetric variation check: both stored and incoming must have
                                // identical key sets and values. Using typeof guard instead of "undefined" string.
                                const storedVars = (typeof state.lists[listKey].item_variations.variations === 'object' && state.lists[listKey].item_variations.variations !== null)
                                    ? state.lists[listKey].item_variations.variations : {};
                                const payVars = (typeof pay.item_variations.variations === 'object' && pay.item_variations.variations !== null)
                                    ? pay.item_variations.variations : {};
                                const storedVarKeys = Object.keys(storedVars);
                                const payVarKeys = Object.keys(payVars);
                                if (storedVarKeys.length !== payVarKeys.length) {
                                    // Different number of attributes → definitely different configs
                                    variationAndExtraChecker.push(false);
                                } else if (storedVarKeys.length > 0) {
                                    storedVarKeys.forEach((variationKey) => {
                                        if (payVars[variationKey] === storedVars[variationKey]) {
                                            variationAndExtraChecker.push(true);
                                        } else {
                                            variationAndExtraChecker.push(false);
                                        }
                                    });
                                }

                                // [N8 FIX] Extras: symmetric length + content check (no "undefined" string)
                                const storedExtras = Array.isArray(state.lists[listKey].item_extras.extras) ? state.lists[listKey].item_extras.extras : [];
                                const payExtras = Array.isArray(pay.item_extras.extras) ? pay.item_extras.extras : [];
                                if (storedExtras.length !== payExtras.length) {
                                    variationAndExtraChecker.push(false);
                                } else if (payExtras.length > 0) {
                                    // Every extra in pay must be present in stored (same count already verified)
                                    const allMatch = payExtras.every(e => storedExtras.includes(e));
                                    variationAndExtraChecker.push(allMatch ? true : false);
                                }

                                if (variationAndExtraChecker.includes(false)) {
                                    newChecker.push(false);
                                } else {
                                    // [V-1 FIX] Check instruction before merging — different instructions = separate items
                                    var sameInstruction = (state.lists[listKey].instruction || '') === (pay.instruction || '');
                                    var sameBundled =
                                        posLineAddonsSignature(state.lists[listKey].pos_line_addons) ===
                                        posLineAddonsSignature(pay.pos_line_addons);
                                    if (sameInstruction && sameBundled) {
                                        newChecker.push(true);
                                        state.lists[listKey].quantity += pay.quantity;
                                    } else {
                                        newChecker.push(false);
                                    }
                                }
                                variationAndExtraChecker = [];
                            } else {
                                newChecker.push(false);
                            }
                        });

                        _.forEach(newChecker, (check) => {
                            if (check) {
                                isNew = false;
                            }
                        });
                        newChecker = [];
                    }

                    if (isNew) {
                        state.lists.push(shapePosListItem(pay));
                        isNew = false;
                    }
                });
            }
            saveCartToStorage(state);
            state.restoredFromStorage = false; // Reset flag après première modification
        },
        replaceCartLine: function (state, payload) {
            var index = payload.index;
            var pay = payload.item;
            if (index < 0 || index >= state.lists.length || !pay) return;
            state.lists.splice(index, 1, shapePosListItem(pay));
            saveCartToStorage(state);
            state.restoredFromStorage = false;
        },
        subtotal: function (state) {
            if (state.lists.length > 0) {
                let subtotal = 0;
                _.forEach(state.lists, (list, listKey) => {
                    state.lists[listKey].total = computePosCartLineDisplayTotal(state.lists[listKey]);
                    subtotal += state.lists[listKey].total;
                });
                state.subtotal = subtotal;
            } else {
                state.subtotal = 0;
            }
            saveCartToStorage(state);
        },
        quantity: function (state, payload) {
            if (payload.status === "increment") {
                state.lists[payload.id].quantity++;
            } else if (payload.status === "decrement") {
                if (state.lists[payload.id].quantity === 1) {
                    state.lists.splice(payload.id, 1);
                    state.discount = 0;
                } else {
                    state.lists[payload.id].quantity--;
                }
            } else {
                state.lists[payload.id].quantity = payload.status;
            }
            saveCartToStorage(state);
        },
        deleteCartItem: function (state, payload) {
            if (payload.status === "decrement") {
                state.lists.splice(payload.id,1);
            }
            saveCartToStorage(state);
        },
        discount: function (state, payload) {
            state.discount = payload;
            saveCartToStorage(state);
        },
        resetCart: function (state) {
            state.lists = [];
            state.subtotal = 0;
            state.discount = 0;
            clearCartFromStorage();
            state.restoredFromStorage = false;
        },
        acknowledgeRestore: function (state) {
            state.restoredFromStorage = false;
        },
        /**
         * [POS-9.1.9] Replace state with the scoped saved cart (or reset to
         * empty if nothing saved for this scope).
         */
        hydrateFromScope: function (state, saved) {
            if (saved && Array.isArray(saved.lists)) {
                state.lists = saved.lists;
                state.subtotal = saved.subtotal || 0;
                state.discount = saved.discount || 0;
                state.restoredFromStorage = saved.lists.length > 0;
            } else {
                state.lists = [];
                state.subtotal = 0;
                state.discount = 0;
                state.restoredFromStorage = false;
            }
        },
    },
};
