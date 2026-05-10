// Le Cayenne — Reward State Machine (V0 client-only mock)
//
// ─────────────────────────────────────────────────────────────────────────
// ⚠️  MOCK ONLY — no backend `loyalty_rewards` table or per-reward state
//     persistence. V0 stores SELECTED + APPLIED_NEXT_ORDER in localStorage
//     via `LC.storage.setPendingRedemption(...)`. The other states (LOCKED,
//     UNLOCKED, CONSUMED, EXPIRED, REVERSED) are DERIVED from balance +
//     history at render time — they are NOT persisted as discrete records.
//
// Phase 6 (B-11 in 99_VERDICT.md §6) :
//   - Backend `loyalty_rewards` table with `is_active`, `period_days`, etc.
//   - Each reward redemption writes a `loyalty_transactions` row with a
//     `reward_id` FK (new column, requires migration).
//   - Backend service computes state transitions atomically (lockForUpdate).
// ─────────────────────────────────────────────────────────────────────────

(function () {
  'use strict';

  // ───────────────────────────────────────────────────────────────────────
  // 7-state lattice
  //
  //                ┌──────────┐
  //                │  LOCKED  │  balance < reward.points_cost
  //                └────┬─────┘
  //                     │ balance crosses cost (any earn)
  //                     ▼
  //                ┌──────────┐
  //                │ UNLOCKED │  balance >= cost && reward.is_active
  //                └────┬─────┘
  //                     │ user taps "Utiliser" (selectReward)
  //                     ▼
  //                ┌──────────┐
  //                │ SELECTED │  ephemeral UI state, not persisted
  //                └────┬─────┘
  //                     │ confirmRedemption (wizard step 2)
  //                     ▼
  //         ┌───────────────────────┐
  //         │ APPLIED_NEXT_ORDER    │  redeem txn written, pending order
  //         │ (= pending redemption)│
  //         └────────┬──────────────┘
  //                  │
  //          ┌───────┴───────┐
  //          │               │
  //   ORDER DELIVERED  ORDER CANCELLED
  //          │               │
  //          ▼               ▼
  //     ┌─────────┐    ┌──────────┐
  //     │ CONSUMED│    │ REVERSED │ (manual_add reversal txn)
  //     └────┬────┘    └──────────┘
  //          │
  //          │ (alt) expiry cron — Phase 6 only
  //          ▼
  //     ┌─────────┐
  //     │ EXPIRED │
  //     └─────────┘
  // ───────────────────────────────────────────────────────────────────────
  const STATES = Object.freeze({
    LOCKED:             'LOCKED',
    UNLOCKED:           'UNLOCKED',
    SELECTED:           'SELECTED',
    APPLIED_NEXT_ORDER: 'APPLIED_NEXT_ORDER',
    CONSUMED:           'CONSUMED',
    EXPIRED:            'EXPIRED',
    REVERSED:           'REVERSED',
  });

  const ACTIONS = Object.freeze({
    EARN_CROSS_THRESHOLD: 'EARN_CROSS_THRESHOLD',  // balance crossed reward.points_cost
    SELECT:               'SELECT',                 // user taps "Utiliser"
    DESELECT:             'DESELECT',               // user changes mind in wizard
    CONFIRM_REDEMPTION:   'CONFIRM_REDEMPTION',     // wizard step 2 commits redeem
    ORDER_DELIVERED:      'ORDER_DELIVERED',        // CONSUMED
    ORDER_CANCELLED:      'ORDER_CANCELLED',        // REVERSED
    EXPIRE:               'EXPIRE',                 // Phase 6 expiry cron
  });

  // ───────────────────────────────────────────────────────────────────────
  // getStateForReward — derive state from observable inputs (balance + history + pending)
  //
  // Returns: 'LOCKED' | 'UNLOCKED' | 'SELECTED' | 'APPLIED_NEXT_ORDER' | 'CONSUMED' | 'EXPIRED' | 'REVERSED'
  // ───────────────────────────────────────────────────────────────────────
  function getStateForReward(reward, ctx) {
    if (!reward) return STATES.LOCKED;
    ctx = ctx || {};
    const balance = (ctx.balance != null) ? ctx.balance : 0;
    const history = ctx.history || [];
    const pending = ctx.pendingRedemption || null;
    const isActive = reward.is_active !== false;  // default true (no backend table yet)

    if (pending && pending.reward_id === reward.id) {
      return STATES.APPLIED_NEXT_ORDER;
    }
    if (ctx.selectedRewardId === reward.id) {
      return STATES.SELECTED;
    }

    // Look for a CONSUMED match: a redeem entry referencing this reward_id
    // whose order completed delivered. V0 history doesn't track delivery
    // status, so we consider any non-pending redeem as CONSUMED.
    const consumed = history.find(h => h.reward_id === reward.id && h.type === 'redeem');
    if (consumed) {
      // Check if reward type permits repeat (e.g. discount per order).
      // V0 default = no repeat tracking (period_days not modeled).
      // We always allow repeat for V0; Phase 6 enforces `period_days`.
    }

    if (!isActive) return STATES.LOCKED;
    return balance >= reward.points_cost ? STATES.UNLOCKED : STATES.LOCKED;
  }

  // ───────────────────────────────────────────────────────────────────────
  // transition — pure functional transition (state, action) -> state
  //
  // For documentation + future deterministic test fixture. Not used in render.
  // ───────────────────────────────────────────────────────────────────────
  function transition(state, action) {
    switch (state) {
      case STATES.LOCKED:
        return action === ACTIONS.EARN_CROSS_THRESHOLD ? STATES.UNLOCKED : STATES.LOCKED;
      case STATES.UNLOCKED:
        if (action === ACTIONS.SELECT) return STATES.SELECTED;
        return STATES.UNLOCKED;
      case STATES.SELECTED:
        if (action === ACTIONS.DESELECT) return STATES.UNLOCKED;
        if (action === ACTIONS.CONFIRM_REDEMPTION) return STATES.APPLIED_NEXT_ORDER;
        return STATES.SELECTED;
      case STATES.APPLIED_NEXT_ORDER:
        if (action === ACTIONS.ORDER_DELIVERED) return STATES.CONSUMED;
        if (action === ACTIONS.ORDER_CANCELLED) return STATES.REVERSED;
        return STATES.APPLIED_NEXT_ORDER;
      case STATES.CONSUMED:
        if (action === ACTIONS.EXPIRE) return STATES.EXPIRED;
        return STATES.CONSUMED;
      default:
        return state;
    }
  }

  // ───────────────────────────────────────────────────────────────────────
  // Validation guards (called at UI handler level)
  // ───────────────────────────────────────────────────────────────────────
  function canSelect(reward, balance) {
    if (!reward) return { ok: false, reason: 'REWARD_NOT_FOUND' };
    if (reward.is_active === false) return { ok: false, reason: 'REWARD_INACTIVE' };
    if (balance < reward.points_cost) {
      return { ok: false, reason: 'INSUFFICIENT_POINTS', missing: reward.points_cost - balance };
    }
    return { ok: true };
  }

  function canConfirm(reward, balance, currentPending) {
    if (currentPending) {
      // V0 policy : one pending redemption at a time
      return { ok: false, reason: 'ANOTHER_REDEMPTION_PENDING', pending: currentPending };
    }
    return canSelect(reward, balance);
  }

  // ───────────────────────────────────────────────────────────────────────
  // Idempotency key — deterministic on a 10-minute window so back-nav within
  // the same window doesn't bypass dedupe.
  //
  // ⚠️ V0-only — boundary crossing replay is a KNOWN GAP: user at minute 9:59
  //    confirms, then user at minute 10:01 retries → different keys, replay
  //    possible. Acceptable in V0 mock. Phase 6 backend `Idempotency-Key`
  //    middleware (B-02 in 99_VERDICT.md §6) uses per-redeem server-generated
  //    UUID instead.
  // ───────────────────────────────────────────────────────────────────────
  function redemptionIdempotencyKey(userId, rewardId) {
    const win = Math.floor(Date.now() / (10 * 60 * 1000));
    return 'redeem-' + userId + '-' + rewardId + '-' + win;
  }

  // -------------------------------------------------------------------------
  // EXPORT
  // -------------------------------------------------------------------------
  window.LC = window.LC || {};
  window.LC.loyaltyRewardState = {
    STATES,
    ACTIONS,
    getStateForReward,
    transition,
    canSelect,
    canConfirm,
    redemptionIdempotencyKey,
  };
})();
