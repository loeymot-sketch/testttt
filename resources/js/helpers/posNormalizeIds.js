function normalizeCollection(arr) {
    return Array.isArray(arr) ? arr : [];
}

export function normalizeId(value) {
    if (value === '' || value === null || typeof value === 'undefined') {
        return null;
    }

    const normalized = Math.floor(Number(value));

    if (!Number.isFinite(normalized) || normalized <= 0) {
        return null;
    }

    return normalized;
}

export function isLegacyVariationPayload(payload) {
    return !!(
        payload &&
        !Array.isArray(payload) &&
        typeof payload === 'object' &&
        payload.variations &&
        typeof payload.variations === 'object'
    );
}

export function isLegacyExtraPayload(payload) {
    return !!(
        payload &&
        !Array.isArray(payload) &&
        typeof payload === 'object' &&
        Array.isArray(payload.extras)
    );
}

export function normalizeQuantity(value, fallback = 1) {
    const fallbackNumber = normalizeId(fallback) || 1;
    const normalized = Math.floor(Number(value));

    if (!Number.isFinite(normalized) || normalized <= 0) {
        return fallbackNumber;
    }

    return normalized;
}

export function normalizeVariationsPayload(arr) {
    return normalizeCollection(arr)
        .map((entry) => {
            const id = normalizeId(entry && entry.id);

            if (id === null) {
                return null;
            }

            const normalizedEntry = {
                ...entry,
                id,
                quantity: normalizeQuantity(entry && entry.quantity, 1),
            };

            if (normalizedEntry.item_attribute_id == null) {
                delete normalizedEntry.item_attribute_id;
            }

            return normalizedEntry;
        })
        .filter(Boolean);
}

export function normalizeExtrasPayload(arr) {
    return normalizeCollection(arr)
        .map((entry) => {
            const id = normalizeId(entry && entry.id);

            if (id === null) {
                return null;
            }

            return {
                ...entry,
                id,
                quantity: normalizeQuantity(entry && entry.quantity, 1),
            };
        })
        .filter(Boolean);
}

export function normalizeVariationEntries(payload) {
    if (Array.isArray(payload)) {
        return payload
            .map((entry) => {
                const id = normalizeId(entry && entry.id);

                if (id === null) {
                    return null;
                }

                return {
                    ...entry,
                    id,
                    item_attribute_id: normalizeId(entry && entry.item_attribute_id),
                    quantity: normalizeQuantity(entry && entry.quantity, 1),
                };
            })
            .filter(Boolean);
    }

    if (!isLegacyVariationPayload(payload)) {
        return [];
    }

    const namesById = payload.names_by_id || {};
    const nameEntries = Object.entries(payload.names || {});

    return Object.entries(payload.variations || {})
        .map(([attributeId, variationId], index) => {
            const id = normalizeId(variationId);

            if (id === null) {
                return null;
            }

            const nameMeta = namesById[String(attributeId)];
            const fallbackNameEntry = nameEntries[index];

            return {
                id,
                item_attribute_id: normalizeId(attributeId),
                quantity: 1,
                variation_name: nameMeta ? nameMeta.attrName : (fallbackNameEntry ? fallbackNameEntry[0] : undefined),
                name: nameMeta ? nameMeta.varName : (fallbackNameEntry ? fallbackNameEntry[1] : undefined),
            };
        })
        .filter(Boolean);
}

export function normalizeExtraEntries(payload) {
    if (Array.isArray(payload)) {
        return payload
            .map((entry) => {
                const id = normalizeId(entry && entry.id);

                if (id === null) {
                    return null;
                }

                return {
                    ...entry,
                    id,
                    quantity: normalizeQuantity(entry && entry.quantity, 1),
                };
            })
            .filter(Boolean);
    }

    if (!isLegacyExtraPayload(payload)) {
        return [];
    }

    return (payload.extras || [])
        .map((extraId, index) => {
            const id = normalizeId(extraId);

            if (id === null) {
                return null;
            }

            return {
                id,
                quantity: 1,
                name: Array.isArray(payload.names) ? payload.names[index] : undefined,
            };
        })
        .filter(Boolean);
}

export function normalizeCartForApi(items) {
    return normalizeCollection(items).map((item) => ({
        ...item,
        item_id: normalizeId(item && item.item_id),
        branch_id: normalizeId(item && item.branch_id),
        item_variations: normalizeVariationsPayload(normalizeVariationEntries(item && item.item_variations)),
        item_extras: normalizeExtrasPayload(normalizeExtraEntries(item && item.item_extras)),
    }));
}
