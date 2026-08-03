import axios from "axios";
import appService from "../../../services/appService";

// [iter15-mega-fix C-003 2026-05-10] Local cache for /frontend/setting payload.
// When the backend rate-limits us with 429 (e.g. customer wall display polling
// fast on a degraded network), we resolve with the last-good response instead
// of rejecting and letting the caller cascade into a redirect.
const LS_KEY = "foodking_frontend_setting_cache_v1";
function readCache() {
    try {
        if (typeof window === "undefined" || !window.localStorage) return null;
        const raw = window.localStorage.getItem(LS_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch (_e) {
        return null;
    }
}
function writeCache(payload) {
    try {
        if (typeof window === "undefined" || !window.localStorage) return;
        window.localStorage.setItem(LS_KEY, JSON.stringify(payload));
    } catch (_e) {
        /* localStorage quota / disabled — silently skip */
    }
}

export const frontendSetting = {
    namespaced: true,
    state: {
        lists: [],
    },
    getters: {
        lists: function (state) {
            return state.lists;
        }
    },
    actions: {
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "frontend/setting";
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    context.commit("lists", res.data.data);
                    // [iter15-mega-fix C-003 2026-05-10] Snapshot last-good response.
                    writeCache({ data: { data: res.data.data } });
                    resolve(res);
                }).catch((err) => {
                    // [iter15-mega-fix C-003 2026-05-10] On 429 (rate-limit),
                    // fall back to the last cached payload instead of rejecting.
                    // Prevents the OSS / customer-screen cascade where 429 leaks
                    // through to a router push to /login.
                    const status = err && err.response && err.response.status;
                    if (status === 429) {
                        const cached = readCache();
                        if (cached) {
                            context.commit("lists", cached.data.data);
                            return resolve(cached);
                        }
                    }
                    reject(err);
                });
            });
        }
    },
    mutations: {
        lists: function (state, payload) {
            state.lists = payload;
        }
    },
};
