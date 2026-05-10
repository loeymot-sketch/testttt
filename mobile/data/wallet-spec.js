// Le Cayenne — Wallet integration data shape (V0 stub, Phase 6 wire)
//
// ─────────────────────────────────────────────────────────────────────────
// V0 (today) :
//   - v0_mock = true
//   - V0 buttons in ScreenLoyalty open ModalWalletV0Notice explaining that
//     the wallet integration is pending production deployment.
//   - asset_url points to stub SVG (not the official Apple/Google asset —
//     see uploads/add-to-{apple,google}-wallet-fr-stub.svg).
//
// Phase 6 :
//   - Owner provisions Apple Developer Program ($99/yr) + Pass Type ID +
//     WWDR G4 cert ; Google Cloud project + Wallet Console + Issuer ID +
//     service account JSON key. (See mobile/WALLET_PLAN.md owner gates D4.)
//   - Backend exposes /api/v1/frontend/loyalty/wallet/apple (returns .pkpass
//     blob, Content-Type application/vnd.apple.pkpass) and /wallet/google
//     (returns { save_url } JWT-signed save link). Implementation outline
//     in mobile/WALLET_PLAN.md.
//   - Flip v0_mock = false to swap handler from ModalWalletV0Notice to
//     real fetch(endpoint) flow.
//
// Backend backlog reference (99_VERDICT.md §6) :
//   B-14 : Wallet Apple + Google backend pipeline.
// ─────────────────────────────────────────────────────────────────────────

(function () {
  'use strict';

  window.LC = window.LC || {};
  window.LC.walletSpec = {
    apple: {
      asset_url: 'uploads/add-to-apple-wallet-fr-stub.svg',
      endpoint:  '/api/v1/frontend/loyalty/wallet/apple',  // Phase 6 — .pkpass bytes
      accept:    'application/vnd.apple.pkpass',
      min_height_dp: 40,   // Apple HIG minimum
    },
    google: {
      asset_url: 'uploads/add-to-google-wallet-fr-stub.svg',
      endpoint:  '/api/v1/frontend/loyalty/wallet/google', // Phase 6 — { save_url } JWT
      accept:    'application/json',
      min_height_dp: 48,   // Google Pay brand minimum
    },
    v0_mock: true,   // flip to false in Phase 6 wire
    // Owner gate references for telemetry/analytics :
    owner_gates: ['D4-A apple-dev-program', 'D4-B google-wallet-console'],
  };
})();
