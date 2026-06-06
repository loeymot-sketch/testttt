// ============================================================================
// Le Cayenne — System A (Mobile) → System B (POS backend) API CONTRACT MIRROR
// ----------------------------------------------------------------------------
// ⚠️ DOCUMENTED, NOT ACTIVATED. Commented, NON-WIRED mirror of the real backend
// contracts so a future wireup is mechanical (swap data source; render layer
// identical — same accepted pattern as composer_profile). ZERO network calls.
// Verify:  grep -nE "fetch\(|axios|XMLHttpRequest" mobile/apiContract.js  →  (none)
//
// Source of truth: reports/goal-system-a-2026-06-06/POS_INTEGRATION_GUIDE.md
//   (+ cartography/POS_INTERSECTION_CONTRACTS.md). Activation = owner gate G5.
// CONNECTION_PLAN.md is the prior migration roadmap; its option_ids / /v1/ naming
// are PROPOSALS — the running contract below is authoritative.
// ============================================================================
(function () {
  'use strict';

  const API = {
    base: '/api/frontend',
    routes: {
      menu:        'GET  /api/frontend/menu/customer/{branch}',   // (to be created) — today kiosk-only
      orderStore:  'POST /api/frontend/order',                    // EXISTS, Pricing-SSOT, idempotent
      orderShow:   'GET  /api/frontend/order/show/{frontendOrder}',
      signupOtp:   'POST /api/auth/signup/otp',
      signupVerify:'POST /api/auth/signup/verify',
    },
    realtime: { provider: 'pusher/soketi', customerOrderChannel: '(to be created) private-order.{token}' },
  };

  // Running order-line contract (NOT option_ids). Client prices ignored; snapshot server-built.
  // Header at wireup: X-Idempotency-Key: <uuid>.
  const ORDER_LINE = {
    item_id: 'number>0',
    quantity: 'number>0',
    item_variations: [{ id: 'number', quantity: 'number?' }], // must belong to item_id (422 else)
    item_extras:     [{ id: 'number', quantity: 'number?' }],
    instruction: 'string?',
  };

  function toBackendLine(localLine) {
    return {
      item_id: localLine.itemId,
      quantity: localLine.qty,
      item_variations: (localLine.variations || []).map(function (v) { return { id: v.id, quantity: v.qty || 1 }; }),
      item_extras: (localLine.extras || []).map(function (e) { return { id: e.id, quantity: e.qty || 1 }; }),
      instruction: localLine.note || '',
    };
  }

  window.LC = window.LC || {};
  window.LC.apiContract = { API: API, ORDER_LINE: ORDER_LINE, toBackendLine: toBackendLine, ACTIVATED: false };
})();
