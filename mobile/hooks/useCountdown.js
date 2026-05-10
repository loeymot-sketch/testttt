// Le Cayenne — useCountdown helper
//
// Formats remaining ms as mm:ss for UI display. Driven externally by Date.now()
// (via LC.dev.advanceTime in tests). The component using this is expected to
// trigger re-renders via its own tick (e.g. useLoyaltyQR's setTick).

(function () {
  'use strict';

  function formatRemaining(ms) {
    if (ms == null || ms < 0) return '0:00';
    const s = Math.floor(ms / 1000);
    const m = Math.floor(s / 60);
    const r = s % 60;
    return m + ':' + String(r).padStart(2, '0');
  }

  Object.assign(window, { formatRemaining });
})();
