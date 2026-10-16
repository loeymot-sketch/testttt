# Le Cayenne — mobile E2E latest

- Generated: 2026-10-16T19:15:56.661Z
- Overall status: **failed**
- Tests: 32 (passed 31, failed 0, skipped 0, timed out 1)

## Results

| Status | Test | Duration |
| --- | --- | ---: |
| passed |  ›  › tests/mobile-e2e/account-deletion-critical.spec.js › account deletion is explicit, authenticated, server-backed and purges local identity | 1821 ms |
| passed |  ›  › tests/mobile-e2e/auth-otp-local.spec.js › local OTP: cooldown blocks duplicates, expiry permits one resend, dev_code verifies | 33965 ms |
| passed |  ›  › tests/mobile-e2e/catalog-visual-critical.spec.js › Phase 3 catalogue visuals › mobile server serves the exact canonical WebP bytes | 142 ms |
| passed |  ›  › tests/mobile-e2e/catalog-visual-critical.spec.js › Phase 3 catalogue visuals › home and menu expose real/neutral assets with compact allergen disclosure removed | 2894 ms |
| passed |  ›  › tests/mobile-e2e/catalog-visual-critical.spec.js › Phase 3 catalogue visuals › home Nouveautés prioritizes new arrivals and keeps every card navigable | 1525 ms |
| passed |  ›  › tests/mobile-e2e/catalog-visual-critical.spec.js › Phase 3 catalogue visuals › category → product personalization → cart keeps detail allergen disclosure and images | 2905 ms |
| passed |  ›  › tests/mobile-e2e/catalog-visual-critical.spec.js › Phase 3 catalogue visuals › home hero and category shortcuts open the contextual destination | 1501 ms |
| passed |  ›  › tests/mobile-e2e/inspect-contrast.spec.js › inspect — color-contrast nodes detail on Home | 2281 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-01-earn-order-app.spec.js › S01 — Earn via purchase_app updates balance + history entry visible | 2777 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-02-earn-kiosk-modal.spec.js › S02 — Earn kiosk (source wired) crédite le solde local + entrée historique kiosk | 2410 ms |
| timedOut |  ›  › tests/mobile-e2e/loyalty-03-qr-refresh.spec.js › S03 — QR réel lqr. : mint-on-display + rotation payload via Actualiser — [31mTest timeout of 90000ms exceeded.[39m | 90960 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-04-redeem-wizard.spec.js › S04 — Panel redeem continu : pas de 100, confirm → POST réel, erreur FR, zéro débit client | 2790 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-05-redeem-locked.spec.js › S05 — Paliers : déverrouillés ≤ solde avec UTILISER, verrouillés = pts manquants sans CTA | 2508 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-06-link-plastic-card.spec.js › S06 — Plastic card link persists localStorage flag + shows badge | 3330 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-07-apple-wallet.spec.js › S07 — Apple Wallet button opens V0 notice modal with onSeeQR CTA | 2789 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-08-google-wallet.spec.js › S08 — Google Wallet button opens V0 notice modal with Google branding | 2917 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-09-redeem-race.spec.js › S09 — Redeems concurrents : erreurs typées gérées, zéro mutation client | 2210 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-10-refund-reversal.spec.js › S10 — Refund writes manual_add reversal entry + restores balance | 2237 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-11-opt-out.spec.js › S11 — RGPD opt-out hides QR + shows reactivation panel | 5567 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-12-history-pagination.spec.js › S12 — 100 history entries render and remain scrollable | 2553 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-13-history-filter.spec.js › S13 — History filter chips narrow visible entries by source_surface | 2963 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-14-qr-barcode-toggle.spec.js › S14 — QR/Barcode toggle persists preference across reload | 3794 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-15-empty-state.spec.js › S15 — Zero-balance user sees welcoming empty state + first-order CTA | 2461 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-adv-A1-clipboard-replay.spec.js › A1 — Le token QR lqr. n'apparaît jamais en texte sélectionnable | 3711 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-adv-A2-screenshot-detection.spec.js › A2 — V0 ne détecte pas le screenshot ; le compte à rebours TTL est la mitigation | 3268 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-adv-A3-localStorage-tamper.spec.js › A3 — Tampered localStorage balance is reflected in V0 (Phase 6 must override) | 3162 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-adv-A4-double-tap-redeem.spec.js › A4 — Double-tap sur Confirmer ⇒ exactement 1 POST redeem, zéro débit client | 2381 ms |
| passed |  ›  › tests/mobile-e2e/loyalty-adv-A5-browser-back-mid-wizard.spec.js › A5 — Annuler en confirmation ⇒ retour sélection, zéro POST, zéro débit | 2369 ms |
| passed |  ›  › tests/mobile-e2e/red-d-mobile-fictional-purge-2026-05-18.spec.js › RED-D mobile fictional purge visual gate › 1 — Order history (Commandes tab) shows healed canonical SKUs | 4447 ms |
| passed |  ›  › tests/mobile-e2e/red-d-mobile-fictional-purge-2026-05-18.spec.js › RED-D mobile fictional purge visual gate › 2 — Loyalty screen via prototype direct nav | 4961 ms |
| passed |  ›  › tests/mobile-e2e/red-d-mobile-fictional-purge-2026-05-18.spec.js › RED-D mobile fictional purge visual gate › 3 — Order detail C-1234 via prototype direct nav | 4934 ms |
| passed |  ›  › tests/mobile-e2e/red-d-mobile-fictional-purge-2026-05-18.spec.js › RED-D mobile fictional purge visual gate › 4 — window.LC data layer SSOT check (sentinel sanity) | 3280 ms |
