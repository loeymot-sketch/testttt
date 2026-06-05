import _ from "lodash";
import { computePosCartLineDisplayTotal } from "../../helpers/posCartLineMath";
import {
    normalizeExtraEntries,
    normalizeExtrasPayload,
    normalizeId,
    normalizeQuantity,
    normalizeVariationEntries,
    normalizeVariationsPayload,
} from "../../helpers/posNormalizeIds";

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
            discountReason: state.discountReason, // [M4-02]
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

function normalizeCartItemId(value, fallback = null) {
    const normalized = normalizeId(value);
    return normalized === null ? fallback : normalized;
}

function buildVariationSignature(variations) {
    return normalizeVariationEntries(variations)
        .map((variation) => `${variation.item_attribute_id || '_'}:${variation.id}:${variation.quantity}`)
        .sort()
        .join('|');
}

function buildExtraSignature(extras) {
    return normalizeExtraEntries(extras)
        .map((extra) => `${extra.id}:${extra.quantity}`)
        .sort()
        .join('|');
}

/** Same merge signature as `lists` mutation (item_id, variations, extras, instruction, bundled addons). */
function samePosLineMergeSignature(a, b) {
    if (!a || !b) return false;
    if (normalizeCartItemId(a.item_id) !== normalizeCartItemId(b.item_id)) return false;
    if ((a.instruction || '') !== (b.instruction || '')) return false;
    if (buildVariationSignature(a.item_variations) !== buildVariationSignature(b.item_variations)) {
        return false;
    }
    if (buildExtraSignature(a.item_extras) !== buildExtraSignature(b.item_extras)) return false;
    if (posLineAddonsSignature(a.pos_line_addons) !== posLineAddonsSignature(b.pos_line_addons)) return false;
    return true;
}

function posLineAddonsSignature(addons) {
    const arr = normPosLineAddons(addons);
    if (arr.length === 0) return '_';
    // [M3 FIX] Include menu_extras in signature so lines with different frites options don't merge
    return arr
        .map((a) => {
            const extrasHash = Array.isArray(a.menu_extras) ? a.menu_extras.slice().sort().join(',') : '';
            return `${a.parent_addon_id}:${a.item_id}:${a.quantity}:${extrasHash}:${buildVariationSignature(a.item_variations)}:${buildExtraSignature(a.item_extras)}`;
        })
        .sort()
        .join('|');
}

/** Normalise un addon bundlé en préservant menu_extras et menu_restore */
function normPosLineAddon(a) {
    return {
        parent_addon_id: normalizeCartItemId(a.parent_addon_id, a.parent_addon_id),
        name: a.name,
        image: a.image || '',
        item_id: normalizeCartItemId(a.item_id, a.item_id),
        quantity: normalizeQuantity(a.quantity, 1),
        discount: a.discount || 0,
        currency_price: a.currency_price,
        convert_price: a.convert_price,
        item_variations: normalizeVariationEntries(a.item_variations),
        item_extras: normalizeExtraEntries(a.item_extras),
        item_variation_total: a.item_variation_total || 0,
        item_extra_total: a.item_extra_total || 0,
        instruction: a.instruction || '',
        total_price: a.total_price || 0,
        menu_extras: Array.isArray(a.menu_extras) ? a.menu_extras : [],
        menu_restore: a.menu_restore || null,
    };
}

export function migrateLegacyVariations(item) {
    if (!item) return [];
    const normalized = normalizeVariationEntries(item.item_variations);
    item.item_variations = normalized;
    return normalized;
}

export function migrateLegacyExtras(item) {
    if (!item) return [];
    const normalized = normalizeExtraEntries(item.item_extras);
    item.item_extras = normalized;
    return normalized;
}

export function migrateLegacySelections(item) {
    if (!item) return item;
    migrateLegacyVariations(item);
    migrateLegacyExtras(item);
    return item;
}

export function normalizeCartForApi(items) {
    return (Array.isArray(items) ? items : []).map((item) => {
        const normalizedItem = migrateLegacySelections(_.cloneDeep(item));

        return {
            ...normalizedItem,
            item_id: normalizeCartItemId(normalizedItem.item_id, normalizedItem.item_id),
            branch_id: normalizeCartItemId(normalizedItem.branch_id, normalizedItem.branch_id),
            item_variations: normalizeVariationsPayload(normalizedItem.item_variations),
            item_extras: normalizeExtrasPayload(normalizedItem.item_extras),
            pos_line_addons: normPosLineAddons(normalizedItem.pos_line_addons).map((addon) => ({
                ...addon,
                item_id: normalizeCartItemId(addon.item_id, addon.item_id),
                parent_addon_id: normalizeCartItemId(addon.parent_addon_id, addon.parent_addon_id),
                item_variations: normalizeVariationsPayload(normalizeVariationEntries(addon.item_variations)),
                item_extras: normalizeExtrasPayload(normalizeExtraEntries(addon.item_extras)),
            })),
        };
    });
}

/** Forme canonique d'une ligne panier (évite duplication lists vs replaceCartLine) */
function shapePosListItem(pay) {
    const normalized = migrateLegacySelections(_.cloneDeep(pay));

    return {
        discount: pay.discount,
        image: pay.image,
        instruction: pay.instruction,
        item_extra_total: pay.item_extra_total,
        item_extras: normalized.item_extras,
        item_id: normalizeCartItemId(pay.item_id, pay.item_id),
        item_variation_total: pay.item_variation_total,
        item_variations: normalized.item_variations,
        name: pay.name,
        currency_price: pay.currency_price,
        convert_price: pay.convert_price,
        quantity: normalizeQuantity(pay.quantity, 1),
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
            // [M4-02] Persist the manual-discount REASON with the amount so a cart
            // restored from localStorage (page reload) keeps it — else orderSubmit
            // sent discount>0 with an empty reason and the backend 422'd invisibly.
            discountReason: '',
            restoredFromStorage: false,
        };
    })(),
    getters: {
        lists: function (state) {
            state.lists.forEach((item) => {
                migrateLegacySelections(item);
            });
            return state.lists;
        },
        subtotal: function (state) {
            return state.subtotal;
        },
        discount: function (state) {
            return state.discount;
        },
        discountReason: function (state) { // [M4-02]
            return state.discountReason || '';
        },
        restoredFromStorage: function (state) {
            return state.restoredFromStorage || false;
        },
        getVariationQuantity: function () {
            return function (item, variationId) {
                const variationKey = normalizeId(variationId);

                if (variationKey === null) return 0;

                return normalizeVariationEntries(item && item.item_variations)
                    .filter((variation) => variation.id === variationKey)
                    .reduce((total, variation) => total + normalizeQuantity(variation.quantity, 1), 0);
            };
        },
        getAttributeTotalQuantity: function () {
            return function (item, attributeId) {
                const attributeKey = normalizeId(attributeId);

                if (attributeKey === null) return 0;

                return normalizeVariationEntries(item && item.item_variations)
                    .filter((variation) => variation.item_attribute_id === attributeKey)
                    .reduce((total, variation) => total + normalizeQuantity(variation.quantity, 1), 0);
            };
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
        setItemVariations: function (context, payload) {
            context.commit('setItemVariations', payload);
            context.commit('subtotal');
        },
        /**
         * [P12_POS_CART_PRUNE / F-VERIFY-01-02] Remove cart lines whose item_id
         * matches an item flagged unavailable by the ItemAvailabilityChanged
         * broadcast. Mirrors the kiosk pattern (kioskCart/pruneUnavailableLines).
         * Bundle add-ons (pos_line_addons[].item_id) are out of scope: the
         * server guard remains the SSOT and rejects the order at submit time.
         */
        pruneUnavailable: function (context, itemId) {
            context.commit('pruneUnavailable', itemId);
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
        /**
         * Optimistic POS add: push a shaped line immediately; caller runs `lists` next,
         * then `__optimisticConfirm` (or `__optimisticRollback` on failure).
         */
        addOptimistic: function (context, payload) {
            const item = payload && payload.item;
            if (!item) {
                return null;
            }
            const tempId = `${Date.now()}-${Math.random()}`;
            context.commit('__optimisticAdd', { tempId, item });
            context.commit('subtotal');
            return tempId;
        },
    },
    mutations: {
        __optimisticAdd: function (state, payload) {
            const tempId = payload && payload.tempId;
            const raw = payload && payload.item;
            if (!tempId || !raw) return;
            const shaped = shapePosListItem(raw);
            shaped._optimistic = true;
            shaped._tempId = tempId;
            shaped._optimisticListsDelta = shaped.quantity;
            state.lists.push(shaped);
            saveCartToStorage(state);
            state.restoredFromStorage = false;
        },
        __optimisticConfirm: function (state, tempId) {
            const idx = state.lists.findIndex((l) => l && l._tempId === tempId);
            if (idx === -1) return;
            const line = state.lists[idx];
            const hasOlderDup = state.lists.some((l, i) =>
                i !== idx &&
                l &&
                !l._optimistic &&
                samePosLineMergeSignature(l, line)
            );
            if (hasOlderDup) {
                state.lists.splice(idx, 1);
            } else {
                const delta = line._optimisticListsDelta;
                if (delta != null && line.quantity > delta) {
                    line.quantity -= delta;
                }
                delete line._optimistic;
                delete line._tempId;
                delete line._optimisticListsDelta;
            }
            saveCartToStorage(state);
            state.restoredFromStorage = false;
        },
        __optimisticRollback: function (state, tempId) {
            const idx = state.lists.findIndex((l) => l && l._tempId === tempId);
            if (idx === -1) return;
            state.lists.splice(idx, 1);
            saveCartToStorage(state);
            state.restoredFromStorage = false;
        },
        lists: function (state, payload) {
            if (payload.length > 0) {
                let isNew = false;
                let newChecker = [];
                _.forEach(payload, (pay) => {
                    const shapedPay = shapePosListItem(pay);
                    if (state.lists.length === 0) {
                        isNew = true;
                    } else {
                        isNew = true;
                        _.forEach(state.lists, (list, listKey) => {
                            migrateLegacySelections(state.lists[listKey]);

                            if (list.item_id === shapedPay.item_id) {
                                const sameVariations =
                                    buildVariationSignature(state.lists[listKey].item_variations) ===
                                    buildVariationSignature(shapedPay.item_variations);
                                const sameExtras =
                                    buildExtraSignature(state.lists[listKey].item_extras) ===
                                    buildExtraSignature(shapedPay.item_extras);

                                if (!sameVariations || !sameExtras) {
                                    newChecker.push(false);
                                } else {
                                    // [V-1 FIX] Check instruction before merging — different instructions = separate items
                                    var sameInstruction = (state.lists[listKey].instruction || '') === (shapedPay.instruction || '');
                                    var sameBundled =
                                        posLineAddonsSignature(state.lists[listKey].pos_line_addons) ===
                                        posLineAddonsSignature(shapedPay.pos_line_addons);
                                    if (sameInstruction && sameBundled) {
                                        newChecker.push(true);
                                        state.lists[listKey].quantity += shapedPay.quantity;
                                    } else {
                                        newChecker.push(false);
                                    }
                                }
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
                        state.lists.push(shapedPay);
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
        pruneUnavailable: function (state, itemId) {
            const id = parseInt(itemId, 10);
            if (!id) return;
            const before = state.lists.length;
            state.lists = state.lists.filter(line => parseInt(line.item_id, 10) !== id);
            if (state.lists.length !== before) {
                state.discount = 0;
                saveCartToStorage(state);
            }
        },
        discount: function (state, payload) {
            state.discount = payload;
            saveCartToStorage(state);
        },
        discountReason: function (state, payload) { // [M4-02]
            state.discountReason = (payload == null) ? '' : String(payload);
            saveCartToStorage(state);
        },
        resetCart: function (state) {
            state.lists = [];
            state.subtotal = 0;
            state.discount = 0;
            state.discountReason = ''; // [M4-02]
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
                state.lists = saved.lists.map((item) => shapePosListItem(item));
                state.subtotal = saved.subtotal || 0;
                state.discount = saved.discount || 0;
                state.discountReason = saved.discountReason || ''; // [M4-02]
                state.restoredFromStorage = saved.lists.length > 0;
            } else {
                state.lists = [];
                state.subtotal = 0;
                state.discount = 0;
                state.discountReason = ''; // [M4-02]
                state.restoredFromStorage = false;
            }
        },
        setItemVariations: function (state, payload) {
            const item = payload && payload.item;
            const variationId = normalizeId(payload && payload.varId);
            const attributeId = normalizeId(payload && payload.attrId);

            if (!item || variationId === null) return;

            const quantity = Math.max(0, Math.floor(Number(payload && payload.quantity) || 0));
            const variations = normalizeVariationEntries(item.item_variations);
            const existingIndex = variations.findIndex((variation) => variation.id === variationId);

            if (quantity === 0) {
                if (existingIndex !== -1) {
                    variations.splice(existingIndex, 1);
                }
            } else if (existingIndex !== -1) {
                variations.splice(existingIndex, 1, {
                    ...variations[existingIndex],
                    id: variationId,
                    item_attribute_id: attributeId,
                    quantity,
                });
            } else {
                variations.push({
                    id: variationId,
                    item_attribute_id: attributeId,
                    quantity,
                });
            }

            item.item_variations = variations;
            saveCartToStorage(state);
        },
    },
};
