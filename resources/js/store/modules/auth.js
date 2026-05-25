import axios from 'axios'

/**
 * [UR4-002 V1.0.2 Wave A1] Strip `PENDING_CREATE_<hex>` / `PENDING_<id>` server
 * sentinel from a user payload before it lands in Vuex `authInfo`.
 *
 * Backend (PhoneDisplay::safe + Resource layer) already sanitizes API responses
 * post-commit `afc094091`, but vuex-persistedstate rehydrates `auth.authInfo`
 * from localStorage at boot BEFORE any API call — so legacy polluted storage
 * carries the sentinel forward. This helper is applied at every write boundary
 * inside the auth module; `getState` override in store/index.js handles
 * rehydrate of pre-existing polluted state.
 *
 * Mirrors the pattern used at:
 *   - resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue:355
 *   - resources/js/components/admin/profile/ProfileEditProfileComponent.vue:114
 *   - app/Support/PhoneDisplay::safe (backend SSOT)
 */
export function sanitizePendingPhone(user) {
    if (!user || typeof user !== 'object') {
        return user;
    }
    const phone = user.phone;
    if (typeof phone === 'string' && phone.startsWith('PENDING_')) {
        return { ...user, phone: null };
    }
    return user;
}

export const auth = {
    state: {
        authStatus: false,
        authToken: null,
        authBranchId: '',
        authInfo: {},
        authMenu: [],
        resetInfo: {
            email: null,
            resetToken: null,
        },
        authPermission: {},
        authDefaultPermission: {},
        authDefaultMenu: {}
    },
    getters: {
        authStatus: function (state) {
            return state.authStatus;
        },
        authToken: function (state) {
            return state.authToken;
        },
        authBranchId: function (state) {
            return state.authBranchId;
        },
        authInfo: function (state) {
            return state.authInfo;
        },
        authMenu: function (state) {
            return state.authMenu;
        },
        authPermission: function (state) {
            return state.authPermission;
        },
        authDefaultPermission: function (state) {
            return state.authDefaultPermission;
        },
        authDefaultMenu: function (state) {
            return state.authDefaultMenu;
        },
        resetInfo: function (state) {
            return state.resetInfo;
        }
    },
    actions: {
        login: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.post('auth/login', payload).then((res) => {
                    context.commit('authLogin', res.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        authcheck: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.post('auth/authcheck', payload).then((res) => {
                    if (res.data.status === false) {
                        context.commit('authLogout');
                    } else if (res.data.user) {
                        context.commit('authRefresh', res.data);
                    }
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        logout: function (context) {
            return new Promise((resolve, reject) => {
                axios.post('auth/logout').then((res) => {
                    context.commit('authLogout');
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        forgetPassword: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.post('auth/forgot-password', payload).then((res) => {
                    context.commit('forgetPassword', payload);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        verifyCode: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.post('auth/forgot-password/verify-code', payload).then((res) => {
                    context.commit('verifyCode', {
                        email: payload.email,
                        resetToken: res?.data?.reset_token || null,
                    });
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        resetPassword: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.post('auth/forgot-password/reset-password', payload).then((res) => {
                    context.commit('resetPassword', res.data.token);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        updateAuthInfo: function (context, payload) {
            return new Promise((resolve, reject) => {
                if (context.state.authInfo.id === payload.id) {
                    context.commit('authInfo', payload);
                    resolve(payload);
                } else {
                    reject('user data not match');
                }
            });
        },
        GuestLoginVerify: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.post('auth/guest-signup/verify', payload).then((res) => {
                    context.commit('authLogin', res.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        loginDataReset: function (context) {
            context.commit('authLogout');
        }
    },
    mutations: {
        authLogin: function (state, payload) {
            state.authStatus = true;
            state.authToken = payload.token;
            state.authBranchId = payload.branch_id;
            // [UR4-002 V1.0.2 Wave A1] strip PENDING_ sentinel before persistence.
            state.authInfo = sanitizePendingPhone(payload.user);
            state.authMenu = payload.menu;
            state.authPermission = payload.permission;
            state.authDefaultPermission = payload.defaultPermission;
            state.authDefaultMenu = payload.defaultMenu;
            // [GAP-34-2] Re-inject the new token into Echo auth headers after login.
            // Echo is initialized at page load before the token exists — this ensures
            // private channel auth works immediately after login without page reload.
            if (typeof window !== 'undefined' && typeof window._refreshEchoAuth === 'function') {
                window._refreshEchoAuth();
            }
        },
        authLogout: function (state) {
            state.authStatus = false;
            state.authToken = null;
            state.authBranchId = '';
            state.authInfo = {};
            state.authMenu = [];
            state.authPermission = {};
            state.authDefaultPermission = {};
            state.authDefaultMenu = {};
        },
        forgetPassword: function (state, payload) {
            state.resetInfo = {
                email: payload.email,
                resetToken: null,
            }
        },
        verifyCode: function (state, payload) {
            state.resetInfo = {
                email: payload.email,
                resetToken: payload.resetToken,
            }
        },
        resetPassword: function (state) {
            state.resetInfo = {
                email: null,
                resetToken: null,
            }
        },
        authInfo: function (state, payload) {
            // [UR4-002 V1.0.2 Wave A1] strip PENDING_ sentinel before persistence.
            state.authInfo = sanitizePendingPhone(payload);
        },
        authRefresh: function (state, payload) {
            state.authBranchId = payload.branch_id;
            // [UR4-002 V1.0.2 Wave A1] strip PENDING_ sentinel before persistence.
            state.authInfo = sanitizePendingPhone(payload.user);
            state.authMenu = payload.menu;
            state.authPermission = payload.permission;
            state.authDefaultPermission = payload.defaultPermission;
            state.authDefaultMenu = payload.defaultMenu;
        }
    },
}
