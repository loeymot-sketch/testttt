import axios from 'axios'
// [UR1-002 V1.0.2 Wave B1] Re-export sanitizePendingPhone from the SSOT
// helper so callers that imported it from this module (e.g.
// store/index.js getState rehydrate override) keep working unchanged.
// SSOT lives at resources/js/helpers/phoneDisplay.js (mirrors
// App\Support\PhoneDisplay::safe — backend afc094091).
import { sanitizePendingPhone } from '../../helpers/phoneDisplay';
export { sanitizePendingPhone };

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
        // [GOAL-2026-05-29 P-AUTH] Proactively re-issue the Sanctum Bearer BEFORE its
        // 480-min TTL expires. The SPA is Bearer-everywhere: the delta-poll AND the
        // WebSocket channel-auth both use this token, so without a refresh the ENTIRE
        // sync (WS + poll) dies at ~8h — an always-on KDS/OSS would silently go stale
        // mid-service. /api/refresh-token (RefreshTokenController) re-issues a fresh
        // token preserving abilities. On failure we keep the existing token (the
        // reactive subscription_error handler + 401->login remain the safety net).
        refreshAuthToken: function (context) {
            const current = context.state.authToken;
            if (!current) {
                return Promise.resolve(false);
            }
            return axios.post('refresh-token', { token: current })
                .then((res) => {
                    const fresh = res && res.data && res.data.token ? res.data.token : null;
                    // [REG-1 2026-05-30] If a logout (or another rotation) happened while
                    // this POST was in flight, state.authToken is no longer `current` — do
                    // NOT resurrect a session the operator deliberately ended (or clobber a
                    // newer token a cross-tab `storage` sync just adopted).
                    if (fresh && context.state.authToken === current) {
                        context.commit('authTokenRefreshed', fresh);
                        return true;
                    }
                    return false;
                })
                .catch(() => false);
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
            // [GOAL-2026-05-29 P-AUTH-SYNC] Pass the fresh token EXPLICITLY: localStorage
            // is not yet written at mutation time (vuex-persist runs post-mutation), so a
            // no-arg call would inject the stale token and the first KDS/POS private
            // channel subscribe right after login would fail auth (silent degrade to poll).
            if (typeof window !== 'undefined' && typeof window._refreshEchoAuth === 'function') {
                window._refreshEchoAuth(payload.token);
            }
        },
        // [GOAL-2026-05-29 P-AUTH] Store the proactively-refreshed Bearer + re-inject it
        // into Echo so the live WS channel-auth keeps working across the refresh. The
        // axios interceptor reads state.authToken, so it picks up the fresh token on the
        // next request; vuex-persist writes it to localStorage for reloads.
        authTokenRefreshed: function (state, token) {
            // [REG-1 2026-05-30] Never resurrect a logged-out session. authLogout nulls
            // authStatus + authToken; a late-resolving proactive refresh (or a cross-tab
            // storage event) must not write a fresh Bearer back into a session that was
            // deliberately ended.
            if (!state.authStatus) {
                return;
            }
            state.authToken = token;
            // [GOAL-2026-05-29 P-AUTH-SYNC] Pass the fresh token explicitly (same stale-
            // by-one reason as authLogin) so a post-refresh reconnect re-subscribes the
            // live WS channels with the NEW Bearer, not the just-deleted old one.
            if (typeof window !== 'undefined' && typeof window._refreshEchoAuth === 'function') {
                window._refreshEchoAuth(token);
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
