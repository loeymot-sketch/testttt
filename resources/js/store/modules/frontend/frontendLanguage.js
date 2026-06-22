import axios from "axios";
import appService from "../../../services/appService";

export const frontendLanguage = {
    namespaced: true,
    state: {
        lists: [],
        show: {},
    },
    getters: {
        lists: function (state) {
            return state.lists;
        },
        show: function (state) {
            return state.show;
        },
    },
    actions: {
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "frontend/language";
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    if (typeof payload.vuex === "undefined" || payload.vuex === true) {
                        context.commit("lists", res.data.data);
                    }
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        show: function (context, payload) {
            if(payload) {
                const resolveFromCache = () => {
                    const language = (context.state.lists || []).find((item) => {
                        return String(item.id) === String(payload) || String(item.code) === String(payload);
                    });

                    if (!language) {
                        return null;
                    }

                    context.commit("show", language);
                    return { data: { data: language } };
                };
                const resolveFromCodeFallback = () => {
                    const code = String(payload || "").trim();
                    if (!code || /^\d+$/.test(code)) {
                        return null;
                    }

                    const language = {
                        id: null,
                        name: code.toUpperCase(),
                        code,
                        display_mode: code === "ar" ? 10 : 5,
                    };
                    context.commit("show", language);
                    return { data: { data: language } };
                };

                return new Promise((resolve, reject) => {
                    const cached = resolveFromCache();
                    if (cached) {
                        resolve(cached);
                        return;
                    }

                    const payloadLooksLikeCode = !/^\d+$/.test(String(payload));
                    if (payloadLooksLikeCode) {
                        context.dispatch("lists", { paginate: 0 }).then(() => {
                            const fallback = resolveFromCache();
                            if (fallback) {
                                resolve(fallback);
                                return;
                            }
                            resolve(resolveFromCodeFallback());
                        }).catch(() => resolve(resolveFromCodeFallback()));
                        return;
                    }

                    axios.get(`frontend/language/show/${payload}`).then((res) => {
                        context.commit("show", res.data.data);
                        resolve(res);
                    }).catch((err) => {
                        const status = err?.response?.status;
                        if (status === 404 || status === 422) {
                            context.dispatch("lists", { paginate: 0 }).then(() => {
                                const fallback = resolveFromCache();
                                if (fallback) {
                                    resolve(fallback);
                                    return;
                                }
                                reject(err);
                            }).catch(() => reject(err));
                            return;
                        }
                        reject(err);
                    });
                });
            }
        },
    },
    mutations: {
        lists: function (state, payload) {
            state.lists = payload;
        },
        show: function (state, payload) {
            state.show = payload;
        }
    },
};
