/**
 * posSplitPayment.js
 *
 * Mission : CV1-POS-SPLIT-PAYMENT-001
 * Doc plan : docs/audit/SPLIT_PAYMENT_IMPLEMENTATION_2026-05-06.md
 *
 * Pure helpers (zero Vue / Vuex dependency) for the multi-tender (split)
 * payment surface in PaymentComponent.vue.
 *
 * Money is handled in INTEGER CENTS internally to avoid float drift
 * (the canonical 30.01 € → 10/10/10.01 split is impossible with binary
 * floats). The public surface accepts and returns euros (Number) but the
 * math always round-trips through Math.round(x * 100).
 *
 * Public API
 * ──────────
 *  - toCents(amount)               → integer cents (rounded half-up)
 *  - fromCents(cents)              → Number with 2 decimals
 *  - validateTranche(tranche)      → { valid, errors: { ... } }
 *  - computeChange(tranche)        → cents (>=0; only for CASH with tendered)
 *  - sumCovered(tranches)          → cents (sum of valid tranche.amount)
 *  - remainingCents(totalCents, tranches)
 *  - canConfirm(totalCents, tranches) → boolean
 *  - splitEqually(totalCents, n)   → array of N tranche-stub objects with
 *                                     integer-cent amounts that sum exactly
 *                                     to totalCents (last tranche carries
 *                                     the remainder cent(s)).
 *  - serializeTranches(tranches)   → array safe for JSON / payload
 *
 * Tranche shape
 * ─────────────
 *  {
 *    id:       string,         // local UUID-ish, for v-for keys
 *    mode:     number,         // posPaymentMethodEnum (1=CASH, 2=CARD, ...)
 *    amount:   number,         // EUR, 2 decimals (kept as Number for UI)
 *    tendered: number | null,  // EUR; only for CASH; null for non-cash
 *    note:     string | null,  // card last-4 / reference, optional
 *  }
 *
 * Note on cents :
 *   - toCents(0.1 + 0.2) === 30  // float drift handled
 *   - splitEqually(toCents(30.01), 3) → [1000, 1000, 1001]
 *   - splitEqually(toCents(30), 3)    → [1000, 1000, 1000]
 */

import posPaymentMethodEnum from '../enums/modules/posPaymentMethodEnum';

const CASH_MODES = new Set([posPaymentMethodEnum.CASH]);
const VALID_MODES = new Set([
    posPaymentMethodEnum.CASH,
    posPaymentMethodEnum.CARD,
    posPaymentMethodEnum.MOBILE_BANKING,
    posPaymentMethodEnum.OTHER,
    posPaymentMethodEnum.TICKET_RESTAURANT,
]);

export function toCents(amount) {
    const num = Number(amount);
    if (!Number.isFinite(num)) return 0;
    return Math.round(num * 100);
}

export function fromCents(cents) {
    const c = Number(cents) || 0;
    return Math.round(c) / 100;
}

export function isCashMode(mode) {
    return CASH_MODES.has(Number(mode));
}

export function isValidMode(mode) {
    return VALID_MODES.has(Number(mode));
}

/**
 * Validate one tranche.
 * Returns { valid: boolean, errors: { mode?, amount?, tendered? } }.
 */
export function validateTranche(tranche) {
    const errors = {};
    if (!tranche || typeof tranche !== 'object') {
        return { valid: false, errors: { tranche: 'invalid_tranche' } };
    }
    const mode = Number(tranche.mode);
    if (!isValidMode(mode)) {
        errors.mode = 'invalid_mode';
    }
    const amount = Number(tranche.amount);
    if (!Number.isFinite(amount) || amount <= 0) {
        errors.amount = 'amount_required';
    }
    if (isCashMode(mode)) {
        if (tranche.tendered === null || tranche.tendered === undefined || tranche.tendered === '') {
            errors.tendered = 'tendered_required';
        } else {
            const tendered = Number(tranche.tendered);
            if (!Number.isFinite(tendered) || tendered <= 0) {
                errors.tendered = 'tendered_invalid';
            } else if (toCents(tendered) < toCents(amount)) {
                errors.tendered = 'tendered_below_amount';
            }
        }
    }
    return { valid: Object.keys(errors).length === 0, errors };
}

/**
 * Change for a CASH tranche, in cents. 0 if non-cash, missing tendered, or
 * tendered < amount. Caller decides what to render.
 */
export function computeChangeCents(tranche) {
    if (!tranche || !isCashMode(tranche.mode)) return 0;
    const amountCents = toCents(tranche.amount);
    const tenderedCents = toCents(tranche.tendered);
    if (tenderedCents <= 0 || amountCents <= 0) return 0;
    if (tenderedCents < amountCents) return 0;
    return tenderedCents - amountCents;
}

/**
 * Sum of tranche.amount across all VALID tranches, in cents.
 * Invalid tranches contribute 0 (so the cashier visibly cannot confirm
 * until they fix them).
 */
export function sumCoveredCents(tranches) {
    if (!Array.isArray(tranches)) return 0;
    return tranches.reduce((acc, t) => {
        const { valid } = validateTranche(t);
        if (!valid) return acc;
        return acc + toCents(t.amount);
    }, 0);
}

export function remainingCents(totalCents, tranches) {
    return Number(totalCents || 0) - sumCoveredCents(tranches);
}

/**
 * canConfirm — every tranche valid AND remaining ≤ 1 cent slack.
 * The 1-cent slack absorbs UI-rounding noise; the backend will perform a
 * strict sum check at persist time.
 */
export function canConfirm(totalCents, tranches) {
    if (!Array.isArray(tranches) || tranches.length === 0) return false;
    for (const t of tranches) {
        if (!validateTranche(t).valid) return false;
    }
    const remaining = remainingCents(totalCents, tranches);
    return remaining <= 1 && remaining >= -100; // up to 1 € overpay tolerated
}

/**
 * splitEqually(totalCents, n)
 *
 * Distributes totalCents across n tranches such that:
 *   - sum(parts) === totalCents (exact, no float drift)
 *   - first (n-1) parts are floor(totalCents / n)
 *   - last  part absorbs the remainder cents
 *
 * Returns an array of CENT integers; caller maps to tranche objects.
 */
export function splitEquallyCents(totalCents, n) {
    const total = Math.round(Number(totalCents) || 0);
    const count = Math.max(1, Math.floor(Number(n) || 0));
    if (count === 1) return [total];
    const base = Math.floor(total / count);
    const remainder = total - base * count;
    const parts = new Array(count).fill(base);
    parts[count - 1] += remainder;
    return parts;
}

/**
 * splitEqually(totalEur, n) → array of tranche stubs with EUR amounts.
 * Default mode is CASH (cashier will pick the actual mode per tranche).
 *
 * [iter15-BUG-multi-person 2026-05-10] Pre-fill `tendered = amount` for cash
 * tranches so the freshly-split tranches are immediately VALID — otherwise
 * `sumCoveredCents` excludes them (cash tranche without tendered = invalid),
 * the cashier sees "Couvert: 0.00€" / "Reste dû: 2.00€" right after clicking
 * "Diviser à parts égales", and the multi-person UX is effectively broken.
 *
 * Pre-filling exact-change tendered is the most natural default : the cashier
 * sees the split correctly applied and overrides per-tranche tendered if the
 * customer pays with a larger note. The amount-only tendered keeps
 * `computeChangeCents` returning 0 (no change due) until override.
 */
export function splitEqually(totalEur, n, defaultMode = posPaymentMethodEnum.CASH) {
    const partsCents = splitEquallyCents(toCents(totalEur), n);
    const cashDefault = isCashMode(defaultMode);
    return partsCents.map((cents, idx) => {
        const amountEur = fromCents(cents);
        return {
            id: makeTrancheId(idx),
            mode: defaultMode,
            amount: amountEur,
            tendered: cashDefault ? amountEur : null,
            note: null,
        };
    });
}

let _trancheCounter = 0;
export function makeTrancheId(seed) {
    _trancheCounter += 1;
    const ts = Date.now().toString(36);
    const ctr = _trancheCounter.toString(36);
    return `tr_${ts}_${ctr}_${seed ?? ''}`;
}

/**
 * Build the API payload field expected by the backend (PLAN_P12).
 * Frozen-zone backend currently ignores payment_breakdown[]; the field is
 * forward-compatible — the front-end attaches it on every multi-tender
 * submit so backend rollout is a server-only flip.
 */
export function serializeTranches(tranches) {
    if (!Array.isArray(tranches)) return [];
    return tranches.map((t) => {
        const cents = toCents(t.amount);
        const tenderedCents = isCashMode(t.mode) ? toCents(t.tendered) : null;
        return {
            mode: Number(t.mode),
            amount: fromCents(cents),
            tendered: tenderedCents != null ? fromCents(tenderedCents) : null,
            change: isCashMode(t.mode) ? fromCents(computeChangeCents(t)) : 0,
            note: t.note ?? null,
        };
    });
}

/**
 * Total change (cents) summed across all CASH tranches. Card/non-cash
 * contribute 0.
 */
export function totalChangeCents(tranches) {
    if (!Array.isArray(tranches)) return 0;
    return tranches.reduce((acc, t) => acc + computeChangeCents(t), 0);
}

/**
 * [iter12 2026-05-09] Bidirectional split — auto-balance helper.
 *
 * Use case observed by owner : cashier wants "10€ on card + remainder
 * in cash" or vice versa. Today the multi-paiement modal forces manual
 * entry of every amount, which is slow and error-prone (forgetting to
 * balance results in "Order quote expired" because cashier hesitates).
 *
 * This helper takes the current tranche set + total + the index of the
 * tranche the cashier just edited, and returns a new tranche array
 * where the OTHER tranche(s) absorb the remainder so the sum equals
 * total exactly. Two policies :
 *
 *   - 'auto-pair' (default) : if exactly 2 tranches, the non-edited
 *     one absorbs the entire remainder. Most common cash-card split.
 *   - 'last-tranche'        : if >2 tranches, the LAST tranche absorbs
 *     remainder (matching splitEqually's tail-carries-remainder rule).
 *
 * Returns a new array (no mutation). If the totals don't need
 * rebalancing or the tranche set is invalid, returns the input as-is.
 */
export function autoBalanceTranches(tranches, totalCents, editedIndex, policy = 'auto-pair') {
    if (!Array.isArray(tranches) || tranches.length < 2) return tranches;
    if (typeof totalCents !== 'number' || totalCents <= 0) return tranches;
    if (typeof editedIndex !== 'number' || editedIndex < 0 || editedIndex >= tranches.length) return tranches;

    const editedAmountCents = toCents(tranches[editedIndex].amount);
    if (editedAmountCents < 0 || editedAmountCents > totalCents) return tranches;

    const remainderCents = totalCents - editedAmountCents;

    let absorberIndex;
    if (tranches.length === 2 || policy === 'auto-pair') {
        absorberIndex = (editedIndex + 1) % tranches.length;
    } else {
        absorberIndex = tranches.length - 1;
        if (absorberIndex === editedIndex) absorberIndex = tranches.length - 2;
    }

    return tranches.map((t, i) => {
        if (i !== absorberIndex) return { ...t };
        const newAmountCents = remainderCents;
        return {
            ...t,
            amount: fromCents(newAmountCents),
        };
    });
}

/**
 * [iter12 2026-05-09] Compute remaining-due (cents) for a NEW tranche
 * given current tranches + total. Used by "Add tranche" UI to pre-fill
 * the next tranche amount with whatever's left.
 *
 * Returns 0 if already covered or overpaid.
 */
export function computeRemainderForNextTrancheCents(totalCents, tranches) {
    const remaining = remainingCents(totalCents, tranches);
    return Math.max(0, remaining);
}

/**
 * [iter12 2026-05-09] Suggest tendered for CASH tranches in a split.
 *
 * After splitEqually() (or any auto-fill) creates CASH tranches, this
 * helper rounds each amount UP to the next €5 to suggest a realistic
 * cash-received value (cashier scenario : 3 people pay 10€ each, each
 * hands a 10€ note, OR each hands a 20€ and gets 10€ change).
 *
 * Default rounding step = 5€ (matches typical Euro denominations).
 * Pass step=10 for round-up to next 10€ if preferred.
 *
 * Returns NEW array; only CASH tranches with null/0 tendered are
 * touched. If tendered already set by user, never overwrite.
 */
export function suggestTenderedForCashTranches(tranches, step = 5) {
    if (!Array.isArray(tranches)) return tranches;
    const stepCents = Math.max(1, Math.round(Number(step) * 100));

    return tranches.map((t) => {
        if (!isCashMode(t.mode)) return { ...t };
        const tenderedCents = toCents(t.tendered);
        if (tenderedCents > 0) return { ...t };
        const amountCents = toCents(t.amount);
        if (amountCents <= 0) return { ...t };
        const suggested = Math.ceil(amountCents / stepCents) * stepCents;
        return { ...t, tendered: fromCents(suggested) };
    });
}

export default {
    toCents,
    fromCents,
    isCashMode,
    isValidMode,
    validateTranche,
    computeChangeCents,
    sumCoveredCents,
    remainingCents,
    canConfirm,
    splitEqually,
    splitEquallyCents,
    serializeTranches,
    totalChangeCents,
    makeTrancheId,
    autoBalanceTranches,
    computeRemainderForNextTrancheCents,
    suggestTenderedForCashTranches,
};
