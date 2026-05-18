// Le Cayenne — Dev helpers (V0 test-mode + manual experimentation)
//
// ─────────────────────────────────────────────────────────────────────────
// `window.LC.dev.*` namespace used by:
//   - E2E test specs (tests/mobile-e2e/loyalty-*.spec.js per Agent-6 §1.3)
//   - Manual debugging via DevTools console
//
// All mutations persist to localStorage so reload survives. Most fire a
// `lc:loyalty-changed` CustomEvent so components can re-render.
//
// ⚠️ This file is loaded in production V0 app. It is intentionally not
//    behind a `if (DEBUG)` flag because V0 IS a prototype; in Phase 6
//    native build (Capacitor / Vue 3 / RN per CONNECTION_PLAN.md §4) the
//    dev namespace gets gated by build env.
// ─────────────────────────────────────────────────────────────────────────

(function () {
  'use strict';

  // Time stub — used by tests to fast-forward QR refresh, expiry, etc.
  let _timeOffsetMs = 0;
  const _origDateNow = Date.now.bind(Date);

  function _stubbedNow() {
    return _origDateNow() + _timeOffsetMs;
  }
  Date.now = _stubbedNow;

  function _emit(name, detail) {
    try {
      window.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
    } catch (e) {}
  }

  // ───────────────────────────────────────────────────────────────────────
  // earnPoints(amount, sourceCode) — credit points client-side
  //
  // sourceCode must match an EARN_METHODS.code value (e.g. 'purchase_app',
  // 'welcome_bonus', 'manual_cashier'). Method's `type` + `source_surface`
  // are looked up automatically.
  //
  // Returns the new account state for chaining.
  // ───────────────────────────────────────────────────────────────────────
  function earnPoints(amount, sourceCode, opts) {
    opts = opts || {};
    const LC = window.LC;
    if (!LC || !LC.loyalty) return null;

    const method = LC.loyalty.findEarnMethod(sourceCode);
    if (!method) {
      console.warn('[LC.dev.earnPoints] Unknown source:', sourceCode);
      return null;
    }

    const orderId = opts.order_id || null;

    // V0 idempotency dedupe (per-device)
    if (LC.storage.idempotency.hasEarned(orderId, method.source_surface)) {
      console.info('[LC.dev.earnPoints] Already recorded — dedupe hit', orderId, method.source_surface);
      return LC.loyalty.account;
    }

    const account = LC.loyalty.account;
    const newBalance = account.balance + amount;

    // Append history entry
    const newId = (LC.loyalty.history.reduce((m, h) => Math.max(m, h.id || 0), 1000)) + 1;
    const entry = {
      id: newId,
      date: _toDateStr(new Date()),
      type: method.type,
      points: +amount,
      description: opts.description || _formatDescription(method.description_template, opts),
      order_id: orderId,
      source_surface: method.source_surface,
    };

    LC.loyalty._replaceHistory([entry, ...LC.loyalty.history]);
    LC.loyalty._replaceAccount({
      balance: newBalance,
      lifetime_earned: account.lifetime_earned + amount,
    });
    LC.storage.idempotency.recordEarn(orderId, method.source_surface, amount);

    _emit('lc:loyalty-changed', { type: 'earn', amount, source: sourceCode });
    return LC.loyalty.account;
  }

  // ───────────────────────────────────────────────────────────────────────
  // redeemReward(rewardId, opts) — atomic balance check + debit with idempotency
  //
  // opts.idempotency_key (optional) — if supplied, the response is cached and
  // replays within the 10-min TTL window return the SAME response with
  // `replayed: true`. This prevents the double-debit bug (D-001 in
  // test-e2e/mobile round-1) where two consecutive clicks on the same reward
  // would each debit the balance.
  //
  // [test-e2e fix D-001 round-2 2026-05-11] idempotency check
  //
  // Returns Promise<{ok: bool, error?: string, balance_after?: number, replayed?: bool}>.
  // Promise shape for test scenario 9 (race condition Promise.allSettled).
  // ───────────────────────────────────────────────────────────────────────
  const REDEEM_KEYS_STORE = 'redeemed_keys';
  const REDEEM_KEY_TTL_MS = 10 * 60 * 1000; // 10-min window matches loyaltyRewardState

  function _redeemPruneAndRead() {
    const LC = window.LC;
    if (!LC || !LC.storage) return {};
    const raw = LC.storage.get(REDEEM_KEYS_STORE, {}) || {};
    const now = Date.now();
    let dirty = false;
    Object.keys(raw).forEach(k => {
      const entry = raw[k];
      if (!entry || typeof entry.ts !== 'number' || (now - entry.ts) > REDEEM_KEY_TTL_MS) {
        delete raw[k];
        dirty = true;
      }
    });
    if (dirty) LC.storage.set(REDEEM_KEYS_STORE, raw);
    return raw;
  }

  function redeemReward(rewardId, opts) {
    opts = opts || {};
    return new Promise((resolve) => {
      const LC = window.LC;
      if (!LC || !LC.loyalty) {
        resolve({ ok: false, error: 'LOYALTY_UNAVAILABLE' });
        return;
      }
      const reward = LC.loyalty.rewardById(rewardId);
      if (!reward) {
        resolve({ ok: false, error: 'REWARD_NOT_FOUND' });
        return;
      }

      // [test-e2e fix D-001 round-2 2026-05-11] idempotency check — replay returns cached response
      const idempotencyKey = opts.idempotency_key;
      if (idempotencyKey && LC.storage && LC.storage.get) {
        const seen = _redeemPruneAndRead();
        if (seen[idempotencyKey]) {
          const cached = seen[idempotencyKey].response;
          resolve(Object.assign({}, cached, { replayed: true }));
          return;
        }
      }

      const account = LC.loyalty.account;
      if (account.balance < reward.points_cost) {
        resolve({ ok: false, error: 'INSUFFICIENT_POINTS', missing: reward.points_cost - account.balance });
        return;
      }

      const newBalance = account.balance - reward.points_cost;
      const newId = (LC.loyalty.history.reduce((m, h) => Math.max(m, h.id || 0), 1000)) + 1;
      const entry = {
        id: newId,
        date: _toDateStr(new Date()),
        type: 'redeem',
        points: -reward.points_cost,
        description: reward.name,
        order_id: null,
        source_surface: 'mobile',
        reward_id: reward.id,
      };

      LC.loyalty._replaceHistory([entry, ...LC.loyalty.history]);
      LC.loyalty._replaceAccount({
        balance: newBalance,
        lifetime_redeemed: account.lifetime_redeemed + reward.points_cost,
      });

      const response = { ok: true, balance_after: newBalance, reward_id: rewardId };

      // [test-e2e fix D-001 round-2 2026-05-11] persist response under idempotency key
      if (idempotencyKey && LC.storage && LC.storage.set) {
        const seen = _redeemPruneAndRead();
        seen[idempotencyKey] = { ts: Date.now(), response };
        LC.storage.set(REDEEM_KEYS_STORE, seen);
      }

      _emit('lc:loyalty-changed', { type: 'redeem', amount: -reward.points_cost, reward_id: rewardId });
      resolve(response);
    });
  }

  // ───────────────────────────────────────────────────────────────────────
  // advanceTime(seconds) — shift `Date.now()` forward
  //
  // Forces any TTL-tracking component to perceive time has passed. Tests
  // use this to verify QR refresh logic without waiting real-time.
  // ───────────────────────────────────────────────────────────────────────
  function advanceTime(seconds) {
    _timeOffsetMs += Math.floor(seconds * 1000);
    _emit('lc:time-advanced', { seconds, offset_ms: _timeOffsetMs });
  }
  function resetTime() {
    _timeOffsetMs = 0;
  }
  function getTimeOffset() {
    return _timeOffsetMs;
  }

  // ───────────────────────────────────────────────────────────────────────
  // seedHistory(n) — generate N chronological earn entries for pagination tests
  // ───────────────────────────────────────────────────────────────────────
  function seedHistory(n) {
    const LC = window.LC;
    if (!LC || !LC.loyalty) return null;
    const entries = [];
    const surfaces = ['mobile', 'kiosk', 'pos', 'mobile_welcome'];
    // [MOBILE FINAL V1 2026-05-19] aligned to canonical Le Cayenne catalogue
    // (post MENU-RESET 2026-05-13). Test-only seed; was using legacy fictional
    // names (Box Nashville/Smash/etc) → replaced with real menu items.
    const itemNames = ['Sandwich Cayenne', 'Big Cayenne', 'Tacos M', 'Tacos L', 'Chicken Burger', 'Bowl Frites', 'Grande Frites', 'Coca-Cola'];
    let nextId = LC.loyalty.history.reduce((m, h) => Math.max(m, h.id || 0), 2000) + 1;
    const now = new Date();
    for (let i = 0; i < n; i++) {
      const dt = new Date(now.getTime() - (i + 1) * 86400000);
      entries.push({
        id: nextId++,
        date: _toDateStr(dt),
        type: 'earn',
        points: 5 + (i % 50),
        description: itemNames[i % itemNames.length],
        order_id: 'C-' + (10000 + i),
        source_surface: surfaces[i % surfaces.length],
      });
    }
    LC.loyalty._replaceHistory([...entries, ...LC.loyalty.history]);
    _emit('lc:loyalty-changed', { type: 'seed-history', count: n });
    return entries.length;
  }

  // ───────────────────────────────────────────────────────────────────────
  // seedAccount(partial) — merge into account state
  // ───────────────────────────────────────────────────────────────────────
  function seedAccount(partial) {
    const LC = window.LC;
    if (!LC || !LC.loyalty) return null;
    LC.loyalty._replaceAccount(partial || {});
    _emit('lc:loyalty-changed', { type: 'seed-account' });
    return LC.loyalty.account;
  }

  // ───────────────────────────────────────────────────────────────────────
  // setConsent(status) — set RGPD consent flag
  // ───────────────────────────────────────────────────────────────────────
  function setConsent(status) {
    const LC = window.LC;
    if (!LC || !LC.storage) return;
    LC.storage.setConsent(status);
    if (LC.loyalty && LC.loyalty._replaceAccount) {
      LC.loyalty._replaceAccount({ consent_status: status });
    }
    _emit('lc:loyalty-changed', { type: 'consent', status });
  }

  // ───────────────────────────────────────────────────────────────────────
  // simulateRefund(orderId) — reverse the earn for an order via manual_add txn
  //
  // Mirrors backend `LoyaltyService::refundPoints` behavior: writes a
  // `type='manual_add'` reversal entry, NOT a 'reversal' type (which doesn't
  // exist in the enum).
  // ───────────────────────────────────────────────────────────────────────
  function simulateRefund(orderId) {
    const LC = window.LC;
    if (!LC || !LC.loyalty) return null;
    const original = LC.loyalty.history.find(h => h.order_id === orderId && h.type === 'earn');
    if (!original) {
      console.warn('[LC.dev.simulateRefund] No earn entry for order', orderId);
      return null;
    }
    const account = LC.loyalty.account;
    const newId = (LC.loyalty.history.reduce((m, h) => Math.max(m, h.id || 0), 1000)) + 1;
    const reversal = {
      id: newId,
      date: _toDateStr(new Date()),
      type: 'manual_add',
      points: -original.points,
      description: 'Remboursement ' + orderId,
      order_id: orderId,
      source_surface: original.source_surface,
      linked_to_history_id: original.id,
    };
    LC.loyalty._replaceHistory([reversal, ...LC.loyalty.history]);
    LC.loyalty._replaceAccount({ balance: account.balance - original.points });
    _emit('lc:loyalty-changed', { type: 'refund', order_id: orderId });
    return reversal;
  }

  // ───────────────────────────────────────────────────────────────────────
  // clearAll() — wipe loyalty state + restore defaults
  // ───────────────────────────────────────────────────────────────────────
  function clearAll() {
    const LC = window.LC;
    if (!LC || !LC.storage) return;
    LC.storage.remove('loyalty');
    LC.storage.remove('loyalty_consent');
    LC.storage.remove('loyalty_pending_redemption');
    LC.storage.remove('qr_preference');
    LC.storage.remove('redeemed_keys'); // [test-e2e fix D-001 round-2 2026-05-11]
    LC.storage.idempotency.reset();
    LC.storage.remove('wallet_apple_dismissed_at');
    LC.storage.remove('wallet_google_dismissed_at');
    resetTime();
    if (LC.loyalty && LC.loyalty._rehydrate) LC.loyalty._rehydrate();
    _emit('lc:loyalty-changed', { type: 'clear-all' });
  }

  // ───────────────────────────────────────────────────────────────────────
  // onRender(componentName, callback) — render-counter hook for perf tests
  //
  // Components must self-register via `LC.dev.notifyRender('ScreenLoyalty')`
  // inside their useEffect with empty deps. Tests subscribe via onRender.
  // ───────────────────────────────────────────────────────────────────────
  const _renderListeners = {};
  function onRender(componentName, cb) {
    _renderListeners[componentName] = _renderListeners[componentName] || [];
    _renderListeners[componentName].push(cb);
  }
  function notifyRender(componentName) {
    const list = _renderListeners[componentName];
    if (!list) return;
    for (let i = 0; i < list.length; i++) {
      try { list[i](); } catch (e) {}
    }
  }

  // ───────────────────────────────────────────────────────────────────────
  // Helpers
  // ───────────────────────────────────────────────────────────────────────
  function _toDateStr(d) {
    // ISO YYYY-MM-DD slice — V0 history dates are date-only.
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + dd;
  }
  function _formatDescription(template, opts) {
    if (!template) return '';
    return template
      .replace('{serial}', String(opts.serial || opts.order_id || '?'))
      .replace('{N}', String(opts.amount || '?'))
      .replace('{referee_first_name}', String(opts.referee_first_name || 'Ami'));
  }

  // -------------------------------------------------------------------------
  // EXPORT
  // -------------------------------------------------------------------------
  window.LC = window.LC || {};
  window.LC.dev = {
    earnPoints,
    redeemReward,
    advanceTime,
    resetTime,
    getTimeOffset,
    seedHistory,
    seedAccount,
    setConsent,
    simulateRefund,
    clearAll,
    onRender,
    notifyRender,
  };
})();
