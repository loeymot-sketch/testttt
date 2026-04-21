import axios from 'axios';

function client() {
    if (typeof window !== 'undefined' && window.axios) {
        return window.axios;
    }

    return axios;
}

export async function listPrinters(params = {}) {
    const { data } = await client().get('admin/printers', { params });

    if (Array.isArray(data?.data)) {
        return data.data;
    }

    return Array.isArray(data) ? data : [];
}

export async function createPrinter(payload) {
    const { data } = await client().post('admin/printers', payload);

    return data?.data ?? data;
}

export async function updatePrinter(printerId, payload) {
    const { data } = await client().put(`admin/printers/${printerId}`, payload);

    return data?.data ?? data;
}

export async function deletePrinter(printerId) {
    const response = await client().delete(`admin/printers/${printerId}`);

    return { ok: response.status === 204, status: response.status };
}

export async function testPrint(printerId) {
    try {
        const { data, status } = await client().post(`admin/printers/${printerId}/test-print`);

        return {
            ok: !!data?.ok,
            status,
        };
    } catch (error) {
        return {
            ok: false,
            status: error?.response?.status || 0,
            error: error?.response?.data?.message || error?.message || 'unknown',
        };
    }
}
