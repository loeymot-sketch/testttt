import axios from 'axios';

export const posFloorplan = {
    namespaced: true,
    state: {
        tables: [],
    },
    getters: {
        tables(state) {
            return Array.isArray(state.tables) ? state.tables : [];
        },
        byStatus(state) {
            return (status) => (Array.isArray(state.tables) ? state.tables : []).filter((table) => {
                return (table?.occupancy_status || 'free') === status;
            });
        },
    },
    actions: {
        fetchState(context) {
            return axios.get('admin/pos/floorplan/state').then((response) => {
                context.commit('SET_TABLES', response.data?.data || []);

                return response;
            });
        },
        async assign(context, payload) {
            const response = await axios.post(`admin/pos/floorplan/${payload.tableId}/assign`, {
                order_id: payload.orderId,
            });

            await context.dispatch('fetchState');

            return response;
        },
        async release(context, tableId) {
            const response = await axios.post(`admin/pos/floorplan/${tableId}/release`);

            await context.dispatch('fetchState');

            return response;
        },
        async transfer(context, payload) {
            const response = await axios.post('admin/pos/floorplan/transfer', {
                source_table_id: payload.sourceId,
                target_table_id: payload.targetId,
            });

            await context.dispatch('fetchState');

            return response;
        },
    },
    mutations: {
        SET_TABLES(state, payload) {
            state.tables = Array.isArray(payload) ? payload : [];
        },
    },
};
