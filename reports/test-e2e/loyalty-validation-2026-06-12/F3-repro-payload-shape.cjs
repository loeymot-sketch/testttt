/**
 * LANE F3 repro — PosLoyaltyRedeemModal live-balance handler vs real wire envelope.
 *
 * - parseEvent: VERBATIM logic from resources/js/services/eventContract.js:62-77
 *   (onEvents calls handler(parsed) at eventContract.js:385)
 * - modalHandler: VERBATIM logic from resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue:209-216
 * - envelope: EXACT output of \App\Domain\Events\EventContract::buildEnvelope(DomainEvent#9144)
 *   captured via tinker on foodking_e2e 2026-06-12 (assertEnvelopeValid=YES)
 */
// --- exact wire envelope (from tinker, see F3-sync-setup.md) ---
const envelope = {
    version: 1,
    type: 'loyalty.balance_changed',
    aggregate_id: 1,
    branch_id: 1,
    occurred_at: '2026-06-12T02:26:43+02:00',
    correlation_id: '2f09a61d-067d-48d3-bcb9-1089461515e6',
    payload: { delta: 10, reason: 'earn', user_id: 1, branch_id: 1, balance_after: 500 },
};

// --- eventContract.js:62-77 parseEvent (validateEnvelope passes: object, version 1, type str, payload obj) ---
function parseEvent(raw) {
    return {
        version: raw.version,
        type: raw.type,
        aggregateId: raw.aggregate_id,
        branchId: raw.branch_id ?? null,
        occurredAt: raw.occurred_at ?? null,
        correlationId: raw.correlation_id ?? null,
        payload: raw.payload,
    };
}

// --- PosLoyaltyRedeemModal.vue:209-216 handler, verbatim ---
const state = { _destroyed: false, customerBalance: 490 }; // cashier looked up: 490 pts (stale)
const handler = (event) => {
    if (state._destroyed) return;
    if (state.customerBalance === null) return; // no lookup yet
    const after = Number(event?.balance_after); // <-- Vue file line 212
    if (Number.isFinite(after)) {
        state.customerBalance = after;          // <-- Vue file line 214
    }
};

// what onEvents actually delivers (eventContract.js:385 handler(parsed)):
const parsed = parseEvent(envelope);
handler(parsed);
console.log('A) REAL wire event through real contract  -> customerBalance =', state.customerBalance,
    state.customerBalance === 500 ? '(updated OK)' : '(STALE — event silently ignored, balance_after is at event.payload.balance_after)');

// what the vitest spec tests/js/posLoyaltyLiveBalance.spec.js:45 feeds (flat, onEvents MOCKED):
state.customerBalance = 490;
handler({ balance_after: 500 });
console.log('B) FLAT object as fed by the vitest mock  -> customerBalance =', state.customerBalance,
    state.customerBalance === 500 ? '(updated — spec passes on the wrong shape)' : '(stale)');

// the fix-shaped read:
state.customerBalance = 490;
const after = Number(parsed?.payload?.balance_after);
if (Number.isFinite(after)) state.customerBalance = after;
console.log('C) Reading event.payload.balance_after    -> customerBalance =', state.customerBalance, '(what the code should do)');
