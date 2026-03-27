import axios from 'axios';

export const loyaltySetup = {
    namespaced: true,
    state: {
        lists: [],
    },
    getters: {
        lists: (state) => state.lists,
    },
    actions: {
        lists(context) {
            return new Promise((resolve, reject) => {
                axios
                    .get('admin/setting/loyalty-setup')
                    .then((res) => {
                        context.commit('lists', res.data.data);
                        resolve(res);
                    })
                    .catch(reject);
            });
        },
        save(context, payload) {
            return new Promise((resolve, reject) => {
                axios
                    .put('/admin/setting/loyalty-setup', payload)
                    .then((res) => {
                        context.commit('lists', payload);
                        resolve(res);
                    })
                    .catch(reject);
            });
        },
    },
    mutations: {
        lists(state, payload) {
            state.lists = payload;
        },
    },
};
