// Le Cayenne — localStorage helpers (V0 standalone state persistence)
//
// V0 = persistance locale uniquement (cart, auth token, last orders).
// Phase 2 = remplacé par appels API + Sanctum bearer (cf. CONNECTION_PLAN.md §3).

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

  // -------------------------------------------------------------------------
  // EXPORT
  // -------------------------------------------------------------------------
  window.LC = window.LC || {};
  window.LC.storage = {
    set, get, remove,
    setAuth, getAuth, clearAuth, isAuthenticated,
    getCart, setCart, clearCart,
    markOnboardingSeen, hasSeenOnboarding,
  };
})();
