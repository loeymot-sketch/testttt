/**
 * Pure helpers for POS receipt HTML (and future ESC/POS bridging).
 */

/**
 * Branch block for printed receipt header.
 * Prefer nested `order.branch` from OrderDetailsResource (fresh DB values)
 * over Vuex `backendGlobalState/branchShow`, which can stay stale after
 * settings are updated until a full refetch.
 */
export function receiptBranchHeader(order, storeBranch) {
    const s = storeBranch && typeof storeBranch === 'object' ? storeBranch : {};
    const ob = order?.branch;
    if (!ob || typeof ob !== 'object') {
        return s;
    }
    return { ...s, ...ob };
}

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
    // [SYNC-BORNE 2026-06-30] COUNTER_DEFERRED (6) = commande borne Plan B PAS encore
    // encaissée. Ne pas fabriquer une ligne synthétique « méthode 6 = total » : ce n'est
    // pas un règlement (miroir du garde serveur OrderDetailsResource + ESC/POS renderer).
    // À l'encaissement, pos_payment_method devient le vrai mode → la ligne réapparaît.
    if (Number(order?.pos_payment_method) === 6) {
        return [];
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
 * [CV1-POS-SPLIT-PAYMENT-001] Format LIVE multi-tender breakdown for the
 * pre-confirmation receipt preview (PaymentComponent split block).
 *
 * Input  : array of in-flight tranches { mode, amount, tendered, change?, note? }
 * Output : array of {label, amountText, changeText?} ready to render in the
 *          preview panel. The persisted receipt uses formatPaymentsBreakdown()
 *          (above) which reads from the API resource — this helper is for the
 *          UI-side pre-payment ticket only.
 *
 * Why a dedicated helper? formatPaymentsBreakdown() is shaped for a saved
 * Order resource (currency_amount strings, change_amount on the row); the
 * LIVE tranches are JS-side numbers with a different field shape.
 */
export function buildPaymentBreakdownLines(tranches, options = {}) {
    if (!Array.isArray(tranches) || tranches.length === 0) {
        return [];
    }
    const labelFor = options.labelFor || ((mode) => `Mode ${mode}`);
    const fmt = options.formatAmount || ((n) => `${Number(n).toFixed(2)} €`);
    return tranches.map((t) => {
        const mode = t.mode ?? t.payment_method;
        const amount = Number(t.amount) || 0;
        const tendered = t.tendered != null ? Number(t.tendered) : null;
        const change = Number(t.change ?? t.change_amount ?? 0) || 0;
        const line = {
            mode,
            label: labelFor(mode),
            amount,
            amountText: fmt(amount),
        };
        if (tendered != null && tendered > 0) {
            line.tendered = tendered;
            line.tenderedText = fmt(tendered);
        }
        if (change > 0) {
            line.change = change;
            line.changeText = fmt(change);
        }
        return line;
    });
}

/**
 * Sum total of a tranche array (EUR), ignoring change.
 */
export function sumPaymentBreakdownTotal(tranches) {
    if (!Array.isArray(tranches)) return 0;
    return tranches.reduce((acc, t) => acc + (Number(t.amount) || 0), 0);
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
            // [POS-OUTPUT-AUDIT 2026-06-24 P1-RECU] Keep the charged amount so
            // paid supplements (Cheddar +0,90, Viande supplémentaire +2,50) show
            // their price on the client ticket — same shape as
            // normalizeReceiptAddons. line_total wins; fall back to
            // unit_price*qty; 0 for free garnishes (Salade/Tomate/Oignon).
            let lineTotal = Number.isFinite(Number(e.line_total)) ? Number(e.line_total) : NaN;
            if (!Number.isFinite(lineTotal)) {
                const unit = Number(e.unit_price);
                lineTotal = Number.isFinite(unit) ? unit * (qty || 1) : 0;
            }
            return {
                name: String(e.name || e.extra_name || ''),
                quantity: qty || 1,
                line_total: Math.max(0, lineTotal),
            };
        })
        .filter((line) => line.name !== '');
}

/**
 * [G2-HEAL-03 / G.5 G5-F-002 P1 2026-05-23]
 * Normalize an order_item.item_addons payload (composer addons — kiosk
 * menu_formule bundled drinks/sides, etc.) into a flat array ready to
 * render on the receipt.
 *
 * Snapshot shape (CompositionSnapshotBuilder.php L153-164):
 *   {addon_id, addon_item_id, addon_name, role, quantity,
 *    unit_price, line_total, catalog_price}
 *
 * NF525 CORRECTNESS: rendered amount MUST be `line_total` (ratio-adjusted
 * via menuRoleAdjustedAddonPrice), NEVER `catalog_price`. A Coca bundled
 * with a Big Burger has catalog_price=3.00 but line_total≈1.20 — the
 * customer ticket must surface what was actually charged.
 *
 * Returns [{name, quantity, line_total}] — line_total is a Number ≥0.
 */
export function normalizeReceiptAddons(rawAddons) {
    if (rawAddons === null || rawAddons === undefined) {
        return [];
    }
    const list = Array.isArray(rawAddons) ? rawAddons : Object.values(rawAddons);
    return list
        .filter((a) => a && typeof a === 'object')
        .map((a) => {
            const qtyRaw = a.quantity;
            const qty = Number.isFinite(Number(qtyRaw)) ? Math.max(0, Number(qtyRaw)) : 1;
            const lineTotalRaw = a.line_total;
            let lineTotal = Number.isFinite(Number(lineTotalRaw)) ? Number(lineTotalRaw) : NaN;
            if (!Number.isFinite(lineTotal)) {
                const unit = Number(a.unit_price);
                lineTotal = Number.isFinite(unit) ? unit * (qty || 1) : 0;
            }
            return {
                name: String(a.addon_name || a.name || a.addon_item_name || ''),
                quantity: qty || 1,
                line_total: Math.max(0, lineTotal),
            };
        })
        .filter((line) => line.name !== '');
}

/**
 * When item_variations / item_extras already list the composition, the
 * long `instruction` field often duplicates the same narrative (meat, sauce,
 * menu). Hiding that duplicate keeps 58/80mm tickets readable. Free-text
 * or short add-ons are kept.
 */
export function receiptInstructionForPrint(item) {
    let raw = item && item.instruction ? String(item.instruction).trim() : '';
    if (!raw) {
        return '';
    }

    const name = (item.item_name || '').trim();
    if (name && raw.toLowerCase().startsWith(name.toLowerCase())) {
        raw = raw.slice(name.length).replace(/^[\s:–—-]+/u, '').trim();
    }
    if (!raw) {
        return '';
    }

    const vars = normalizeReceiptVariations(item.item_variations);
    const extras = normalizeReceiptExtras(item.item_extras);
    if (vars.length === 0 && extras.length === 0) {
        return raw;
    }

    const blob = [
        ...vars.map((v) => `${v.label} ${v.name}`),
        ...extras.map((e) => `${e.quantity > 1 ? `${e.quantity}× ` : ''}${e.name}`),
        name,
    ]
        .join(' ')
        .toLowerCase();

    const words = raw.toLowerCase().match(/[a-zàâäéèêëïîôùûçœ0-9]+/gi) || [];
    const meaningful = words.filter((w) => w.length > 2);
    if (meaningful.length < 8) {
        return raw;
    }

    const wordMatchesBlob = (w) => {
        if (blob.includes(w)) {
            return true;
        }
        if (w.length <= 3) {
            return false;
        }
        const stem = w.replace(/(s|es|x)$/i, '');
        return stem.length > 2 && blob.includes(stem);
    };

    let hits = 0;
    for (const w of meaningful) {
        if (wordMatchesBlob(w)) {
            hits += 1;
        }
    }
    const ratio = meaningful.length ? hits / meaningful.length : 0;
    // Strong duplicate: narration repeats the same tokens already printed as variations.
    if (ratio >= 0.72) {
        return '';
    }
    // Long kiosk wizard snapshot: mostly repeats structured picks (FR plural/stem drift).
    const wizardish = /(?:viandes|viande)\s*:|menu\s*\(|↳|\+\s*menu/i.test(raw);
    if (wizardish && meaningful.length >= 14 && ratio >= 0.46) {
        return '';
    }
    return raw;
}
