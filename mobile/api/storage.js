// Le Cayenne — localStorage helpers (V0 standalone state persistence)
//
// V0 = persistance locale uniquement (cart, auth token, last orders, loyalty).
// Phase 6 = remplacé par appels API + Sanctum bearer (cf. CONNECTION_PLAN.md §3).

(function () {
  'use strict';

  const NS = 'lecayenne.';

  function set(key, value) {
    try {
      localStorage.setItem(NS + key, JSON.stringify(value));
    } catch (e) { /* quota or disabled */ }
  }
  function get(key, fallback) {
    try {
      const raw = localStorage.getItem(NS + key);
      if (raw === null) return fallback;
      return JSON.parse(raw);
    } catch (e) { return fallback; }
  }
  function remove(key) {
    try { localStorage.removeItem(NS + key); } catch (e) {}
  }

  // ---- Auth ----
  // V0 : juste un flag "isAuthenticated" + numéro de tel saisi à l'OTP.
  function setAuth(payload) {
    set('auth', payload);
  }
  function getAuth() {
    return get('auth', null);
  }
  function clearAuth() {
    remove('auth');
    remove('cart');
    // Clear loyalty-related local state too — different user must not inherit
    // previous user's idempotency keys, consent flag, etc.
    remove('loyalty');
    remove('loyalty_consent');
    remove('loyalty_idempotency');
    remove('loyalty_pending_redemption');
    remove('redeemed_keys'); // [test-e2e fix D-001 round-2 2026-05-11] redeem idempotency cache
    remove('wallet_apple_dismissed_at');
    remove('wallet_google_dismissed_at');
    // QR preference can persist (UI preference, not user-bound).
    if (window.LC && window.LC.loyalty && window.LC.loyalty._rehydrate) {
      window.LC.loyalty._rehydrate();
    }
  }
  function isAuthenticated() {
    const a = getAuth();
    return !!(a && a.token);
  }

  // ---- Cart ----
  function getCart() {
    return get('cart', []);
  }
  function setCart(cart) {
    set('cart', cart);
  }
  function clearCart() {
    set('cart', []);
  }

  // ---- Onboarding seen ----
  function markOnboardingSeen() {
    set('onboarding_seen', true);
  }
  function hasSeenOnboarding() {
    return !!get('onboarding_seen', false);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Loyalty state (account + history)
  //
  // Component pattern: read `window.LC.loyalty.account` / `.history` for
  // rendering; mutate via `LC.loyalty._replaceAccount(...)` /
  // `_replaceHistory(...)` which persist via setLoyalty().
  // ─────────────────────────────────────────────────────────────────────────
  function setLoyalty(state) {
    set('loyalty', state);
  }
  function getLoyalty(fallback) {
    return get('loyalty', fallback);
  }

  // ---- RGPD consent flag ----
  // 'opted_in' | 'opted_out' — UI gates QR + earn UI behind opted_in.
  function setConsent(status) {
    set('loyalty_consent', status);
  }
  function getConsent() {
    return get('loyalty_consent', 'opted_in');
  }

  // ---- QR vs Barcode preference (UI-only, not user-bound) ----
  function setQRPreference(mode) {
    set('qr_preference', mode);  // 'qr' | 'barcode'
  }
  function getQRPreference() {
    return get('qr_preference', 'qr');
  }

  // ---- Plastic card linked flag (V0 mock — backend has no link mechanism) ----
  function setPlasticCardLinked(linked) {
    const account = (window.LC && window.LC.loyalty && window.LC.loyalty.account) || {};
    if (window.LC && window.LC.loyalty && window.LC.loyalty._replaceAccount) {
      window.LC.loyalty._replaceAccount({ plastic_card_linked: !!linked });
    } else {
      set('loyalty', { account: Object.assign({}, account, { plastic_card_linked: !!linked }) });
    }
  }
  function isPlasticCardLinked() {
    const a = (window.LC && window.LC.loyalty && window.LC.loyalty.account) || {};
    return !!a.plastic_card_linked;
  }

  // ---- Pending redemption (APPLIED_NEXT_ORDER state) ----
  function setPendingRedemption(state) {
    if (state == null) {
      remove('loyalty_pending_redemption');
    } else {
      set('loyalty_pending_redemption', state);
    }
  }
  function getPendingRedemption() {
    return get('loyalty_pending_redemption', null);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Idempotency map — V0 client-side dedupe for earn events.
  //
  // ⚠️ V0 device-only. Login on different phone OR app reinstall clears it.
  //    This is UX-level (avoid double toast), NOT a fraud mitigation.
  //    Phase 6 (B-02 in 99_VERDICT.md §6) requires server-side Idempotency-Key
  //    middleware on `/loyalty/redeem` and `loyalty_transactions.idempotency_key`
  //    column UNIQUE constraint.
  //
  // Per Agent-3 §4 spec : TTL 30j, cap 500 FIFO, key = `${order_id ?? 'null'}:${source_surface}`.
  // ─────────────────────────────────────────────────────────────────────────
  const IDEM_KEY = 'loyalty_idempotency';
  const IDEM_TTL_MS = 30 * 24 * 3600 * 1000;
  const IDEM_MAX = 500;

  function _idemRead() {
    return get(IDEM_KEY, []);
  }
  function _idemWrite(arr) {
    const now = Date.now();
    const cutoff = now - IDEM_TTL_MS;
    let pruned = arr.filter(e => e.ts >= cutoff);
    pruned.sort((a, b) => a.ts - b.ts);
    if (pruned.length > IDEM_MAX) pruned = pruned.slice(-IDEM_MAX);
    set(IDEM_KEY, pruned);
  }
  function _idemCompose(orderId, sourceSurface) {
    return (orderId == null ? 'null' : String(orderId)) + ':' + String(sourceSurface);
  }
  function hasEarned(orderId, sourceSurface) {
    const key = _idemCompose(orderId, sourceSurface);
    return _idemRead().some(e => e.key === key);
  }
  function recordEarn(orderId, sourceSurface, points) {
    const key = _idemCompose(orderId, sourceSurface);
    const arr = _idemRead();
    if (arr.some(e => e.key === key)) return false;
    arr.push({ key, ts: Date.now(), points });
    _idemWrite(arr);
    return true;
  }
  function resetIdempotency() {
    remove(IDEM_KEY);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Wallet dismissal flags — prevent re-nag after user dismisses Add to Wallet
  // ─────────────────────────────────────────────────────────────────────────
  function setWalletDismissed(kind) {
    set('wallet_' + kind + '_dismissed_at', Date.now());
  }
  function getWalletDismissed(kind) {
    return get('wallet_' + kind + '_dismissed_at', null);
  }

  // -------------------------------------------------------------------------
  // EXPORT
  // -------------------------------------------------------------------------
  window.LC = window.LC || {};
  window.LC.storage = {
    set, get, remove,
    setAuth, getAuth, clearAuth, isAuthenticated,
    getCart, setCart, clearCart,
    markOnboardingSeen, hasSeenOnboarding,
    // Loyalty:
    setLoyalty, getLoyalty,
    setConsent, getConsent,
    setQRPreference, getQRPreference,
    setPlasticCardLinked, isPlasticCardLinked,
    setPendingRedemption, getPendingRedemption,
    // Idempotency:
    idempotency: { hasEarned, recordEarn, reset: resetIdempotency },
    // Wallet:
    setWalletDismissed, getWalletDismissed,
  };
})();
