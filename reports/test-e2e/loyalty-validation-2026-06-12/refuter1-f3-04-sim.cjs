// Empirical simulation of PosLoyaltyRedeemModal.vue:209-216 handler
// against the EXACT parsed envelope shape produced by eventContract.js parseEvent()
// (payload nested under .payload — verified eventContract.js:62-76, handler(parsed) at :385).

// Real wire payload from foodking_e2e domain_events #9205 (customer B = user 44, earn at kiosk)
const parsedEnvelopeFromOnEvents = {
  version: 1,
  type: 'loyalty.balance_changed',
  aggregateId: 44,
  branchId: 1,
  occurredAt: '2026-06-12T10:00:00Z',
  correlationId: 'abc',
  payload: { delta: 7, reason: 'earn', user_id: 44, branch_id: 1, balance_after: 177 },
};

// ---- Scenario: cashier looked up customer A, modal shows A's balance = 500 ----
function runHandlerAsWrittenToday(event, state) {
  // verbatim logic of PosLoyaltyRedeemModal.vue:209-216
  if (state._destroyed) return;
  if (state.customerBalance === null) return;
  const after = Number(event?.balance_after);          // <-- reads top level (F3-01)
  if (Number.isFinite(after)) state.customerBalance = after;
}

function runHandlerIfF301FixedNaively(event, state) {
  // same handler but reading the correct path (the obvious F3-01 fix)
  if (state._destroyed) return;
  if (state.customerBalance === null) return;
  const after = Number(event?.payload?.balance_after); // <-- F3-01 fixed
  if (Number.isFinite(after)) state.customerBalance = after;
  // NOTE: no user_id comparison possible — modal state has NO customerUserId field
  // (data() = loyaltyCode/pointsToRedeem/loading/errorMessage/successMessage/customerBalance/lastResponse)
  // and POS redeem response (PosLoyaltyController.php:66-80) exposes no customer user_id.
}

const s1 = { _destroyed: false, customerBalance: 500 }; // customer A displayed
runHandlerAsWrittenToday(parsedEnvelopeFromOnEvents, s1);
console.log('[TODAY / as-written]  customerBalance after B-earn event:', s1.customerBalance,
  s1.customerBalance === 500 ? '=> UNCHANGED (handler inoperative, F3-04 LATENT — masked by F3-01)' : '=> OVERWRITTEN');

const s2 = { _destroyed: false, customerBalance: 500 };
runHandlerIfF301FixedNaively(parsedEnvelopeFromOnEvents, s2);
console.log('[IF F3-01 FIXED]      customerBalance after B-earn event:', s2.customerBalance,
  s2.customerBalance === 177 ? '=> OVERWRITTEN with customer B(44) balance — F3-04 CONFIRMED ACTIVE' : '=> unaffected');
