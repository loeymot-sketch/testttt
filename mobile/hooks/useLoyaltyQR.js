// Le Cayenne — useLoyaltyQR hook (V0)
//
// Per Agent-2 §3 pattern: chained setTimeout (NOT setInterval) + visibilitychange
// listener + in-flight ref guard + server-anchored expires_at + cleanup on unmount.
//
// Phase 6 : refresh() will POST /api/v1/frontend/loyalty/qr/sign (B-09 in
// 99_VERDICT.md §6) and receive { payload, signature, expires_at } back. V0 mock
// calls window.LC.loyalty.generateSignedQR(loyaltyCode) which returns the same
// shape with a SHA-256 mock signature.

(function () {
  'use strict';
  const { useState, useEffect, useRef, useCallback } = React;

  /**
   * @returns {{
   *   qr: { payload: string, signature: string, expires_at: number } | null,
   *   refresh: () => Promise<void>,
   *   remainingMs: number,
   *   isInflight: boolean,
   * }}
   */
  function useLoyaltyQR(loyaltyCode) {
    const [qr, setQr] = useState(null);
    const [isInflight, setInflight] = useState(false);
    const [tick, setTick] = useState(0);  // forces remainingMs recompute
    const inflightRef = useRef(null);
    const cancelledRef = useRef(false);

    const refresh = useCallback(async () => {
      if (inflightRef.current) return inflightRef.current;
      const LC = window.LC;
      if (!LC || !LC.loyalty || !LC.loyalty.generateSignedQR) {
        // Defensive — shouldn't happen if data layer loaded.
        return null;
      }
      setInflight(true);
      const p = LC.loyalty.generateSignedQR(loyaltyCode)
        .then(result => {
          if (cancelledRef.current) return null;
          setQr(result);
          return result;
        })
        .finally(() => {
          inflightRef.current = null;
          setInflight(false);
        });
      inflightRef.current = p;
      return p;
    }, [loyaltyCode]);

    // Initial + ongoing refresh schedule
    useEffect(() => {
      cancelledRef.current = false;
      let timer = null;

      const scheduleNext = () => {
        if (cancelledRef.current) return;
        const expMs = (qr && qr.expires_at) ? qr.expires_at * 1000 : 0;
        const remaining = expMs - Date.now();
        // Refresh 60s before expiry; tick every 1s for countdown display
        if (remaining < 60000) {
          refresh().then(() => {
            if (!cancelledRef.current) {
              timer = setTimeout(scheduleNext, 1000);
            }
          });
        } else {
          // 1s countdown tick — but we wake up only at refresh threshold
          // Cheap tick for the UI; component reads remainingMs via Date.now diff
          setTick(t => t + 1);
          timer = setTimeout(scheduleNext, 1000);
        }
      };

      // Bootstrap
      if (!qr) {
        refresh().then(() => {
          if (!cancelledRef.current) scheduleNext();
        });
      } else {
        scheduleNext();
      }

      // Foreground re-trigger
      const onVisibility = () => {
        if (document.visibilityState === 'visible' && !cancelledRef.current) {
          // Force a tick to recompute remaining; if expired, scheduleNext will refresh.
          setTick(t => t + 1);
        }
      };
      document.addEventListener('visibilitychange', onVisibility);

      return () => {
        cancelledRef.current = true;
        if (timer) clearTimeout(timer);
        document.removeEventListener('visibilitychange', onVisibility);
      };
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [qr && qr.expires_at, refresh, loyaltyCode]);

    // tick is intentionally read to force re-render
    // eslint-disable-next-line no-unused-vars
    const _force = tick;

    const remainingMs = qr ? Math.max(0, qr.expires_at * 1000 - Date.now()) : 0;

    return { qr, refresh, remainingMs, isInflight };
  }

  // Expose globally — React+Babel-standalone has no module system.
  Object.assign(window, { useLoyaltyQR });
})();
