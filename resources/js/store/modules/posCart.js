import _ from "lodash";
import activityEnum from "../../enums/modules/activityEnum";

// Clé localStorage pour le panier POS
const POS_CART_KEY = 'pos_cart_v1';
const POS_CART_TTL_MS = 2 * 60 * 60 * 1000; // 2 heures

function saveCartToStorage(state) {
    try {
        localStorage.setItem(POS_CART_KEY, JSON.stringify({
            lists: state.lists,
            subtotal: state.subtotal,
            discount: state.discount,
            savedAt: Date.now()
        }));
    } catch (e) {
        // localStorage peut être indisponible (mode privé, quota dépassé)
        console.warn('[posCart] localStorage save failed:', e);
    }
}

function loadCartFromStorage() {
    try {
        const raw = localStorage.getItem(POS_CART_KEY);
        if (!raw) return null;
        const data = JSON.parse(raw);
        if (!data || !data.savedAt) return null;
        // Expirer après 2h
        if (Date.now() - data.savedAt > POS_CART_TTL_MS) {
            localStorage.removeItem(POS_CART_KEY);
            return null;
        }
        return data;
    } catch (e) {
        return null;
    }
}

function clearCartFromStorage() {
    try {
        localStorage.removeItem(POS_CART_KEY);
    } catch (e) {}
}

export const posCart = {
    namespaced: true,
    state: (function() {
        const saved = loadCartFromStorage();
        return {
            lists: saved ? saved.lists : [],
            subtotal: saved ? saved.subtotal : 0,
            discount: saved ? saved.discount : 0,
            restoredFromStorage: saved && saved.lists.length > 0
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

                                if (state.lists[listKey].item_variations.variations !== "undefined") {
                                    if (Object.keys(state.lists[listKey].item_variations.variations).length !== 0) {
                                        _.forEach(state.lists[listKey].item_variations.variations, (variationId, variationKey) => {
                                            if (pay.item_variations.variations[variationKey] !== "undefined" && pay.item_variations.variations[variationKey] === variationId) {
                                                variationAndExtraChecker.push(true);
                                            } else {
                                                variationAndExtraChecker.push(false);
                                            }
                                        });
                                    }
                                }

                                if (pay.item_extras.extras.length !== 0 && state.lists[listKey].item_extras.extras.length !== 0) {
                                    _.forEach(pay.item_extras.extras, (payExtra) => {
                                        if (state.lists[listKey].item_extras.extras.includes(payExtra) && state.lists[listKey].item_extras.extras.length === pay.item_extras.extras.length) {
                                            variationAndExtraChecker.push(true);
                                        } else {
                                            variationAndExtraChecker.push(false);
                                        }
                                    });
                                } else {
                                    if (pay.item_extras.extras.length === state.lists[listKey].item_extras.extras.length) {
                                        variationAndExtraChecker.push(true);
                                    } else {
                                        variationAndExtraChecker.push(false);
                                    }
                                }

                                if (variationAndExtraChecker.includes(false)) {
                                    newChecker.push(false);
                                } else {
                                    // [V-1 FIX] Check instruction before merging — different instructions = separate items
                                    var sameInstruction = (state.lists[listKey].instruction || '') === (pay.instruction || '');
                                    if (sameInstruction) {
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
                        state.lists.push({
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
                            quantity: pay.quantity
                        });
                        isNew = false;
                    }
                });
            }
            saveCartToStorage(state);
            state.restoredFromStorage = false; // Reset flag après première modification
        },
        subtotal: function (state) {
            if (state.lists.length > 0) {
                let subtotal = 0;
                _.forEach(state.lists, (list, listKey) => {
                    state.lists[listKey].total = ((list.convert_price + list.item_variation_total + list.item_extra_total) * list.quantity);
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
        }
    },
};
