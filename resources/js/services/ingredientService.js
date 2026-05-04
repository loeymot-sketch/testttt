import axios from 'axios';

export function listIngredients(params = {}) {
    return axios.get('/admin/ingredients', { params });
}

export function showIngredient(globalId) {
    return axios.get(`/admin/ingredients/${encodeURIComponent(globalId)}`);
}

export function toggleIngredientAvailability(globalId, isAvailable, reason = null) {
    return axios.put(`/admin/ingredients/${encodeURIComponent(globalId)}/availability`, {
        is_available: isAvailable,
        reason,
    });
}
