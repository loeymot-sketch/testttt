import axios from 'axios';

export const posCustomer = {
    namespaced: true,
    state: {},
    mutations: {},
    actions: {
        lookupByNfc(context, nfcUid) {
            return axios.post('admin/pos/customers/lookup-by-nfc', {
                nfc_uid: nfcUid,
            });
        },
    },
};
