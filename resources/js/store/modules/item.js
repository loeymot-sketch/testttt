import axios from 'axios'
import appService from "../../services/appService";


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
            return new Promise((resolve, reject) => {
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
                let url = `admin/item/details/${id}`;
                const params = {};
                if (surface) {
                    params.surface = surface;
                }
                if (branchId) {
                    params.branch_id = branchId;
                }
                const config = Object.keys(params).length > 0 ? { params } : undefined;
                axios.get(url, config).then((res) => {
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
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
