/**
 * Pure helpers for POS receipt HTML (and future ESC/POS bridging).
 */

function normalizeMethod(m) {
    if (m === null || m === undefined || m === '') {
        return '';
    }
    if (typeof m === 'string') {
        return m.toUpperCase();
    }
    return m;
}

/**
 * Format payment lines for multi-tender breakdown.
 * Backward-compatible with order.pos_payment_method when payments_breakdown is empty.
 */
export function formatPaymentsBreakdown(order) {
    if (Array.isArray(order?.payments_breakdown) && order.payments_breakdown.length > 0) {
        return order.payments_breakdown.map((p) => ({
            method: normalizeMethod(p.method ?? p.payment_method ?? null),
            amount: Number(p.amount || 0),
            currency_amount: p.currency_amount ?? null,
            change_amount: Number(p.change_amount || 0),
            reference: p.reference ?? null,
        }));
    }
    if (order?.pos_payment_method !== undefined && order?.pos_payment_method !== null && order?.pos_payment_method !== '') {
        const tendered = order.pos_received_amount != null && order.pos_received_amount !== ''
            ? Number(order.pos_received_amount)
            : Number(order.total || 0);
        return [{
            method: order.pos_payment_method,
            amount: tendered,
            currency_amount: order.pos_received_currency_amount ?? order.total_currency_price ?? null,
            change_amount: Number(order.cash_back_amount || 0),
            reference: null,
        }];
    }
    return [];
}

/**
 * NF525 footer lines (ticket #, audit fingerprint, legal footer).
 */
export function buildNf525Footer(order) {
    const lines = [];
    if (order?.fiscal_sequence_no !== undefined && order?.fiscal_sequence_no !== null && order.fiscal_sequence_no !== '') {
        lines.push({ key: 'fiscal_ticket_no', value: order.fiscal_sequence_no });
    }
    if (order?.audit_chain_fingerprint) {
        lines.push({ key: 'audit_fingerprint', value: order.audit_chain_fingerprint });
    }
    if (order?.pos_legal_footer) {
        lines.push({ key: 'legal_mentions', value: order.pos_legal_footer });
    }
    return lines;
}

/**
 * Receipt width class from paper width (mm). Default 58mm.
 */
export function receiptWidthClass(paperWidthMm) {
    const w = Number(paperWidthMm || 58);
    if (w >= 76) {
        return 'receipt-80mm';
    }
    return 'receipt-58mm';
}

/**
 * [V14 GLOBAL FINDING G-1 P0 + G-2 P1]
 * Normalize an order_item.item_variations payload into a flat array of lines
 * compatible with both shapes :
 *
 *  • LEGACY (pre-T07) — `[{id, variation: {variation_name, name}}]`
 *      OR `{attrId: {variation_name, name}}` (very old) — `quantity` absent or 1.
 *
 *  • SNAPSHOT (post-T07) — `[{variation_id, attribute_name, variation_name,
 *      quantity, unit_price}]` — comes from
 *      `OrderItemResource::resolveVariationsForApi()` when
 *      `composition_snapshot` is present (NF525 immutability path).
 *
 * Returns a flat list `[{label, name, quantity}]` ready to render. The
 * receipt template that previously read `variation.variation_name` and
 * `variation.name` produced "undefined" for the snapshot shape because
 * the snapshot uses `variation_name` for the value and `attribute_name`
 * for the label, NOT `name`. This helper hides both shapes from the UI.
 */
export function normalizeReceiptVariations(rawVariations) {
    if (rawVariations === null || rawVariations === undefined) {
        return [];
    }
    const list = Array.isArray(rawVariations)
        ? rawVariations
        : Object.values(rawVariations);
    return list
        .filter((v) => v && typeof v === 'object')
        .map((v) => {
            const fromSnapshot = typeof v.attribute_name === 'string' || typeof v.variation_id !== 'undefined';
            const label = fromSnapshot
                ? (v.attribute_name || v.variation_name || '')
                : (v.variation_name || v.attribute_name || '');
            const name = fromSnapshot
                ? (v.variation_name || v.name || '')
                : (v.name || v.variation_name || '');
            const qtyRaw = v.quantity;
            const qty = Number.isFinite(Number(qtyRaw)) ? Math.max(0, Number(qtyRaw)) : 1;
            return {
                label: String(label),
                name: String(name),
                quantity: qty || 1,
            };
        })
        .filter((line) => line.name !== '');
}

/**
 * [V14 GLOBAL FINDING G-1 P0]
 * Normalize an order_item.item_extras payload into a flat array compatible
 * with both shapes (legacy `{name}` and snapshot `{extra_id, name, quantity,
 * unit_price}`). Empty / null inputs return [].
 */
export function normalizeReceiptExtras(rawExtras) {
    if (rawExtras === null || rawExtras === undefined) {
        return [];
    }
    const list = Array.isArray(rawExtras) ? rawExtras : Object.values(rawExtras);
    return list
        .filter((e) => e && typeof e === 'object')
        .map((e) => {
            const qtyRaw = e.quantity;
            const qty = Number.isFinite(Number(qtyRaw)) ? Math.max(0, Number(qtyRaw)) : 1;
            return {
                name: String(e.name || e.extra_name || ''),
                quantity: qty || 1,
            };
        })
        .filter((line) => line.name !== '');
}
