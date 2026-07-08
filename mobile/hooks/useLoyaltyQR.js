// Le Cayenne — useLoyaltyQR hook
//
// [GOAL-SYNC 2026-07-08] Mint-on-display RÉEL (contrat §3) : le QR n'est plus
// un mock 'FK:<code>' (format REJETÉ backend, accept_legacy_plaintext=false) —
// il vient de POST /api/frontend/loyalty/qr via LC.mobileApi.loyaltyQr()
// → { token:'lqr.…', expires_at, ttl_seconds:300, loyalty_code }.
//
// État exposé : { token, expiresAt, secondsLeft, loading, error, loyaltyCode, refresh }
//   • error = null | 'auth_required' | 'network' | 'http'
//   • re-mint AUTO à expiration (secondsLeft → 0) + refresh() manuel
//     (throttle backend 30/min — l'auto re-mint toutes les ~300 s est très en-dessous).
//   • non connecté → error 'auth_required', AUCUN appel réseau.
//
// Pattern conservé : chained setTimeout (pas de setInterval) + visibilitychange
// + garde in-flight + expires_at ancré serveur + cleanup à l'unmount.

(function () {
  'use strict';
  const { useState, useEffect, useRef, useCallback } = React;

  function nowSec() { return Math.floor(Date.now() / 1000); }

  function useLoyaltyQR() {
    const [state, setState] = useState({
      token: null,
      expiresAt: null,     // unix seconds (serveur)
      loyaltyCode: null,
      loading: false,
      error: null,         // null | 'auth_required' | 'network' | 'http'
    });
    const [tick, setTick] = useState(0);     // recompute secondsLeft chaque seconde
    const inflightRef = useRef(null);
    const cancelledRef = useRef(false);
    const stateRef = useRef(state);
    stateRef.current = state;

    const refresh = useCallback(function () {
      if (inflightRef.current) return inflightRef.current;
      const api = window.LC && window.LC.mobileApi;
      if (!api || typeof api.loyaltyQr !== 'function' || !api.isAuthed || !api.isAuthed()) {
        setState(s => Object.assign({}, s, { token: null, loading: false, error: 'auth_required' }));
        return Promise.resolve(null);
      }
      setState(s => Object.assign({}, s, { loading: true }));
      const p = api.loyaltyQr()
        .then(function (d) {
          if (cancelledRef.current) return null;
          d = d || {};
          // expires_at serveur (ISO ou unix) ; fallback ttl_seconds (défaut 300 s).
          let exp = null;
          if (d.expires_at != null) {
            exp = typeof d.expires_at === 'number' ? d.expires_at : Math.floor(new Date(d.expires_at).getTime() / 1000);
          }
          if (!exp || !isFinite(exp)) exp = nowSec() + (Number(d.ttl_seconds) || 300);
          setState({
            token: d.token || null,
            expiresAt: exp,
            loyaltyCode: d.loyalty_code || null,
            loading: false,
            error: d.token ? null : 'http',
          });
          return d;
        })
        .catch(function (e) {
          if (cancelledRef.current) return null;
          const kind = (e && e.kind === 'network') ? 'network'
            : (e && e.status === 401) ? 'auth_required'
            : 'http';
          setState(s => Object.assign({}, s, { token: null, loading: false, error: kind }));
          return null;
        })
        .finally(function () { inflightRef.current = null; });
      inflightRef.current = p;
      return p;
    }, []);

    // Bootstrap + ticker 1 s : compte à rebours + re-mint AUTO à expiration.
    useEffect(function () {
      cancelledRef.current = false;
      let timer = null;

      const loop = function () {
        if (cancelledRef.current) return;
        const s = stateRef.current;
        const expired = s.expiresAt != null && (s.expiresAt - nowSec()) <= 0;
        if ((s.token == null || expired) && !s.loading && s.error !== 'auth_required' && s.error !== 'network') {
          refresh();
        }
        setTick(t => t + 1);
        timer = setTimeout(loop, 1000);
      };

      refresh().then(function () {
        if (!cancelledRef.current) timer = setTimeout(loop, 1000);
      });

      // Retour au premier plan : re-mint immédiat si expiré pendant le background.
      const onVisibility = function () {
        if (document.visibilityState === 'visible' && !cancelledRef.current) {
          const s = stateRef.current;
          if (s.expiresAt != null && (s.expiresAt - nowSec()) <= 0) refresh();
          setTick(t => t + 1);
        }
      };
      document.addEventListener('visibilitychange', onVisibility);

      return function () {
        cancelledRef.current = true;
        if (timer) clearTimeout(timer);
        document.removeEventListener('visibilitychange', onVisibility);
      };
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [refresh]);

    // tick lu pour forcer le re-render du compte à rebours
    // eslint-disable-next-line no-unused-vars
    const _force = tick;

    const secondsLeft = state.expiresAt != null ? Math.max(0, state.expiresAt - nowSec()) : 0;

    return {
      token: state.token,
      expiresAt: state.expiresAt,
      loyaltyCode: state.loyaltyCode,
      secondsLeft: secondsLeft,
      loading: state.loading,
      error: state.error,
      refresh: refresh,
    };
  }

  // Expose globally — React+Babel-standalone has no module system.
  Object.assign(window, { useLoyaltyQR });
})();
