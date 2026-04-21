/**
 * [V14 T03] Canonical variation selection + pricing rows for POS↔Kiosk parity tests.
 * Mirrors POS ItemComponent.getAttributeConfig / meat-slot rules; kiosk max slots
 * are driven by the same N when _tailleMeta.viandeCount matches attribute max_select.
 */

import { computePosCartLineDisplayTotal } from '../../../resources/js/helpers/posCartLineMath';
import { kioskViandeCatalogForItem } from '../../../resources/js/helpers/kioskViandeCatalog';
import { normalizeId, normalizeQuantity, normalizeVariationEntries } from '../../../resources/js/helpers/posNormalizeIds';

export function getAttributeConfig(attribute) {
    const maxSelect = normalizeQuantity(attribute && attribute.max_select, 1);
    const allowRepeat = Boolean(attribute && attribute.allow_repeat);
    const isMulti = maxSelect > 1 || allowRepeat;
    const minSelectRaw = normalizeId(attribute && attribute.min_select);
    const minSelect = minSelectRaw === null ? (isMulti ? 0 : 1) : Math.min(minSelectRaw, maxSelect);

    return {
        minSelect,
        maxSelect,
        allowRepeat,
        isMulti,
    };
}

export function getAttributeTotalQuantity(itemVariations, attributeId) {
    const aid = normalizeId(attributeId);
    if (aid === null) return 0;
    return normalizeVariationEntries(itemVariations)
        .filter((e) => e.item_attribute_id === aid)
        .reduce((t, e) => t + normalizeQuantity(e.quantity, 1), 0);
}

export function isAttributeAtMax(attribute, itemVariations) {
    const config = getAttributeConfig(attribute);
    return getAttributeTotalQuantity(itemVariations, attribute.id) >= config.maxSelect;
}

export function hasAttributeSelectionError(attribute, itemVariations) {
    const config = getAttributeConfig(attribute);
    return getAttributeTotalQuantity(itemVariations, attribute.id) < config.minSelect;
}

/** POS path: increment one slot if under max (same guard as ItemComponent.incrementVariation). */
export function posTryIncrement(attribute, variation, itemVariations) {
    if (isAttributeAtMax(attribute, itemVariations)) return itemVariations;
    const vid = normalizeId(variation.id);
    const aid = normalizeId(attribute.id);
    if (vid === null || aid === null) return itemVariations;
    const config = getAttributeConfig(attribute);
    let next = normalizeVariationEntries(itemVariations).filter(
        (e) => !(e.item_attribute_id === aid && e.id === vid),
    );
    if (!config.isMulti) {
        next = next.filter((e) => e.item_attribute_id !== aid);
    }
    const prevQty =
        normalizeVariationEntries(itemVariations).find((e) => e.id === vid && e.item_attribute_id === aid)
            ?.quantity || 0;
    next.push({
        id: vid,
        item_attribute_id: aid,
        quantity: normalizeQuantity(prevQty, 1) + 1,
        variation_name: attribute.name,
        name: variation.name,
    });
    return next;
}

/**
 * Kiosk path (meat counter): same slot cap as POS when kioskMaxSlots === attribute.max_select.
 */
export function kioskTryIncrement(attribute, variation, itemVariations, kioskMaxSlots) {
    const total = getAttributeTotalQuantity(itemVariations, attribute.id);
    if (total >= kioskMaxSlots) return itemVariations;
    return posTryIncrement(attribute, variation, itemVariations);
}

function priceBookForItem(item) {
    const book = new Map();
    const bag = item && item.variations ? item.variations : {};
    Object.keys(bag).forEach((k) => {
        const list = bag[k];
        if (!Array.isArray(list)) return;
        list.forEach((v) => {
            if (v && v.id != null) {
                const p = parseFloat(v.convert_price) || parseFloat(v.price) || 0;
                book.set(v.id, p);
            }
        });
    });
    return book;
}

export function enrichVariationsWithCatalogPrices(itemFixture, itemVariations) {
    const book = priceBookForItem(itemFixture);
    return normalizeVariationEntries(itemVariations).map((e) => ({
        ...e,
        convert_price: book.get(e.id) ?? 0,
    }));
}

export function buildListRowForDisplayTotal(itemFixture, itemVariations, itemExtras = []) {
    const priced = enrichVariationsWithCatalogPrices(itemFixture, itemVariations);
    const variationAddon = priced.reduce(
        (s, v) => s + (parseFloat(v.convert_price) || 0) * normalizeQuantity(v.quantity, 1),
        0,
    );
    const extraAddon = (Array.isArray(itemExtras) ? itemExtras : []).reduce(
        (s, e) => s + (parseFloat(e.convert_price) || parseFloat(e.price) || 0) * normalizeQuantity(e.quantity, 1),
        0,
    );

    return {
        quantity: 1,
        convert_price: itemFixture.convert_price,
        item_variation_total: variationAddon,
        item_extra_total: extraAddon,
        item_variations: normalizeVariationEntries(itemVariations),
        item_extras: itemExtras,
        pos_line_addons: [],
    };
}

export function parityDisplayTotal(itemFixture, itemVariations, itemExtras) {
    return computePosCartLineDisplayTotal(buildListRowForDisplayTotal(itemFixture, itemVariations, itemExtras));
}

/** POS / catalogue: hide inactive (status 10) or explicit availability false. */
export function posVariationVisibleInChoices(v) {
    if (!v) return false;
    if (v.availability === false || v.is_available === false) return false;
    if (Number(v.status) === 10) return false;
    return true;
}

export function filterPosVariationChoices(variationsList) {
    return (Array.isArray(variationsList) ? variationsList : []).filter(posVariationVisibleInChoices);
}

/**
 * Kiosk meat-step catalogue ids (production helper).
 */
export function kioskMeatCatalogIds(item) {
    return kioskViandeCatalogForItem(item).map((r) => r.id).filter((id) => typeof id === 'number');
}

export function makeMeatAttribute(min, max, allowRepeat = true) {
    return {
        id: 1,
        name: 'Viande',
        min_select: min,
        max_select: max,
        allow_repeat: allowRepeat,
    };
}

export function makeMeatItem(attr, variationDefs, kioskExtra = {}) {
    const variations = {};
    variations[attr.id] = variationDefs.map((d, i) => ({
        id: d.id ?? i + 100,
        item_attribute_id: attr.id,
        name: d.name,
        convert_price: d.price ?? 0,
        price: d.price ?? 0,
        status: d.status !== undefined ? d.status : 5,
        availability: d.availability,
        is_available: d.is_available,
        ...kioskExtra,
    }));

    return {
        id: 9001,
        name: 'Tacos test',
        convert_price: 10,
        currency_price: '10 EUR',
        itemAttributes: [attr],
        variations,
        extras: [],
    };
}
