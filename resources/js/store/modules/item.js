import axios from 'axios'
import appService from "../../services/appService";

// [PERF 2026-07-23 POS-instant-open] Cache client court + dédup in-flight pour
// `item/details` sur la surface POS (caisse). Objectif owner : re-cliquer une tuile =
// 0 réseau (ouverture instantanée) et préchauffe (pointerdown/survol) prête au clic.
// Portée volontairement LIMITÉE à surface==='pos' : les autres surfaces (admin
// AvailabilityTogglePanel sans surface, kiosk, web) restent NON cachées → fraîcheur
// et comportement identiques à aujourd'hui. Invalidation : TTL court + événement dispo
// (ItemComponent.syncItemAvailabilityFromBroadcast → action `invalidateDetails`).
const POS_DETAILS_CACHE_TTL_MS = 60000;
const posDetailsCache = new Map();     // key -> { res, at }
const posDetailsInflight = new Map();  // key -> Promise<res>

function posDetailsCacheKey(id, branchId) {
    return String(id) + '|' + String(branchId || '');
}

export const item = {
    namespaced: true,
    state: {
        lists: [],
        page: {},
        pagination: [],
        show: {},
        temp: {
            temp_id: null,
            isEditing: false,
        },
    },
    getters: {
        lists: function (state) {
            return state.lists;
        },
        pagination: function (state) {
            return state.pagination
        },
        page: function(state) {
            return state.page;
        },
        show: function (state) {
            return state.show;
        },
        temp: function (state) {
            return state.temp;
        }
    },
    actions: {
        lists: function (context, payload = {}) {
            return new Promise((resolve, reject) => {
                const requestPayload = payload ? { ...payload } : {};
                const hasExplicitBranchId = Object.prototype.hasOwnProperty.call(requestPayload, 'branch_id')
                    || Object.prototype.hasOwnProperty.call(requestPayload, 'branchId');
                const branchId = context.rootState?.auth?.authBranchId
                    ?? context.rootGetters?.authBranchId
                    ?? context.rootState?.auth?.authInfo?.branch_id
                    ?? context.rootState?.auth?.authUser?.branch_id
                    ?? context.rootState?.auth?.user?.branch_id
                    ?? context.rootGetters?.['auth/authUserBranchId']
                    ?? null;

                if (!hasExplicitBranchId && branchId !== null && branchId !== undefined && branchId !== '' && Number(branchId) !== 0) {
                    requestPayload.branch_id = branchId;
                }

                // [Wave T R3 F1 — silent 422 prevention] When the caller declares
                // `surface=pos`, the backend at app/Http/Controllers/Admin/ItemController:60
                // refuses the request with 422 if no valid branch_id is in scope
                // (per CV1-POS-AVAILABILITY-LIVE-001: serving a POS catalog without
                // a branch would project a global `is_available` and yield clickable
                // tiles for OOS items + 422 at checkout = revenue loss).
                // The PosComponent bootstrap fires `itemList()` on mount BEFORE
                // `defaultAccess/show` resolves the branch for admin users
                // (branch_id=0 in DB), so the first call would 422 silently and
                // load tiles via the later branch-aware refetch. Short-circuit
                // here resolves with an empty payload so no doomed network call
                // fires, no 422 in the console, and the later branch-aware
                // refetch (line 1915 of PosComponent.vue) populates the list.
                const declaredSurface = String(requestPayload.surface || '').toLowerCase();
                const resolvedBranchId = requestPayload.branch_id;
                const hasUsableBranchId = resolvedBranchId !== null
                    && resolvedBranchId !== undefined
                    && resolvedBranchId !== ''
                    && Number(resolvedBranchId) > 0;
                if (declaredSurface === 'pos' && !hasUsableBranchId) {
                    // Resolve quietly — do NOT commit empty payload over an
                    // already-populated list (the post-defaultAccess refetch
                    // will overwrite shortly).
                    resolve({
                        data: { data: [], meta: {} },
                        status: 200,
                        skipped: true,
                        reason: 'pos_surface_requires_branch_id',
                    });
                    return;
                }

                let url = 'admin/item';
                if (Object.keys(requestPayload).length > 0) {
                    url = url + appService.requestHandler(requestPayload);
                }
                axios.get(url).then((res) => {
                    if(typeof requestPayload.vuex === "undefined" || requestPayload.vuex === true) {
                        context.commit('lists', res.data.data);
                        context.commit('page', res.data.meta);
                        context.commit('pagination', res.data);
                    }
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        save: function (context, payload) {
            return new Promise((resolve, reject) => {
                let method = axios.post;
                let url = '/admin/item';
                if (this.state['item'].temp.isEditing) {
                    method = axios.post;
                    url = `/admin/item/${this.state['item'].temp.temp_id}`;
                }
                method(url, payload.form).then(res => {
                    context.dispatch('lists', payload.search).then().catch();
                    context.commit('reset');
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        edit: function (context, payload) {
            context.commit('temp', payload);
        },
        destroy: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.delete(`admin/item/${payload.id}`).then((res) => {
                    context.dispatch('lists', payload.search).then().catch();
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        show: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.get(`admin/item/show/${payload}`).then((res) => {
                    context.commit('show', res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        changeImage: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios
                    .post(
                        `/admin/item/change-image/${payload.id}`,
                        payload.form,
                        {
                            headers: {
                                "Content-Type": "multipart/form-data",
                            },
                        }
                    )
                    .then((res) => {
                        context.commit("show", res.data.data);
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        reset: function (context) {
            context.commit('reset');
        },
        export: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/item/export';
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url, {responseType: 'blob'}).then((res) => {
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        downloadSample: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/item/download-sample/';
                axios.get(url, { responseType: 'blob' }).then((res) => {
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        import: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.post('/admin/item/import/file', payload.form).then((res) => {
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        // [AUDIT 2026-04-17 R2] Dual signature for surface-aware detail fetches.
        //   Legacy:   dispatch('item/details', 123)                    → no ?surface
        //   New:      dispatch('item/details', { id: 123, surface: 'pos' }) → ?surface=pos
        // Invalid surface values are ignored to avoid forging query strings server-side.
        details: function (context, payload) {
            let id = payload;
            let surface = null;
            let branchId = null;
            if (payload !== null && typeof payload === 'object') {
                id = payload.id;
                if (typeof payload.surface === 'string'
                    && ['pos', 'kiosk', 'web'].indexOf(payload.surface) !== -1) {
                    surface = payload.surface;
                }
                branchId = payload.branch_id || payload.branchId || null;
            }

            // [PERF 2026-07-23 POS-instant-open] Cache/dédup UNIQUEMENT sur la surface POS.
            // Les autres surfaces conservent le fetch direct (aucune régression de fraîcheur).
            const cacheable = surface === 'pos';
            const cacheKey = cacheable ? posDetailsCacheKey(id, branchId) : null;
            if (cacheable) {
                const cached = posDetailsCache.get(cacheKey);
                if (cached && (Date.now() - cached.at) < POS_DETAILS_CACHE_TTL_MS) {
                    // Re-clic / préchauffe déjà résolue → 0 réseau, résolution immédiate.
                    return Promise.resolve(cached.res);
                }
                const inflight = posDetailsInflight.get(cacheKey);
                if (inflight) {
                    // Requête identique déjà en vol (préchauffe + clic) → on partage.
                    return inflight;
                }
            }

            let url = `admin/item/details/${id}`;
            const params = {};
            if (surface) {
                params.surface = surface;
            }
            if (branchId) {
                params.branch_id = branchId;
            }
            const config = Object.keys(params).length > 0 ? { params } : undefined;

            const request = axios.get(url, config).then((res) => {
                if (cacheable) {
                    posDetailsCache.set(cacheKey, { res, at: Date.now() });
                    posDetailsInflight.delete(cacheKey);
                }
                return res;
            }).catch((err) => {
                if (cacheable) {
                    posDetailsInflight.delete(cacheKey);
                }
                throw err;
            });

            if (cacheable) {
                posDetailsInflight.set(cacheKey, request);
            }
            return request;
        },
        // [PERF 2026-07-23 POS-instant-open] Purge le cache details POS. Appelée quand la
        // disponibilité d'un item change (broadcast), pour que la prochaine ouverture
        // reflète l'état réel sans attendre le TTL. `payload` = id (ou {id}) → purge cet
        // item ; vide/null → purge tout.
        invalidateDetails: function (context, payload) {
            let id = null;
            if (payload !== null && payload !== undefined) {
                id = (typeof payload === 'object') ? payload.id : payload;
            }
            if (id === null || id === undefined || id === '') {
                posDetailsCache.clear();
                posDetailsInflight.clear();
                return;
            }
            const prefix = String(id) + '|';
            Array.from(posDetailsCache.keys()).forEach((key) => {
                if (key.indexOf(prefix) === 0) {
                    posDetailsCache.delete(key);
                }
            });
            Array.from(posDetailsInflight.keys()).forEach((key) => {
                if (key.indexOf(prefix) === 0) {
                    posDetailsInflight.delete(key);
                }
            });
        },
        lookupByBarcode: function (context, code) {
            return new Promise((resolve, reject) => {
                const safe = encodeURIComponent(String(code));
                axios.get(`admin/item/lookup-barcode/${safe}`).then((res) => {
                    if (res.data && res.data.meta && res.data.meta.duplicate_barcode) {
                        console.warn('[POS] Multiple items share this barcode; using first match');
                    }
                    resolve(res.data.data);
                }).catch((err) => {
                    if (err.response && err.response.status === 404) {
                        resolve(null);
                        return;
                    }
                    reject(err);
                });
            });
        },
    },
    mutations: {
        lists: function (state, payload) {
            state.lists = payload
        },
        pagination: function (state, payload) {
            state.pagination = payload;
        },
        page: function (state, payload) {
            if(typeof payload !== "undefined" && payload !== null) {
                state.page = {
                    from: payload.from,
                    to: payload.to,
                    total: payload.total
                }
            }
        },
        show: function (state, payload) {
            state.show = payload;
        },
        temp: function (state, payload) {
            state.temp.temp_id = payload;
            state.temp.isEditing = true;
        },
        reset: function(state) {
            state.temp.temp_id = null;
            state.temp.isEditing = false;
        }
    },
}
