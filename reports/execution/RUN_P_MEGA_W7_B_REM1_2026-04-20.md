# RUN — P-MEGA W7.B Hardware fallback REMEDIATION 1 (2026-04-20)

**HEAD base** : `7459487ee`  
**EXECUTE_DELEGATION** : foodking-routine-implementer  
**REMEDIATION_ATTEMPT** : 1  
**Outcome** : PASSED  

**Vitest (post-remédiation)** : **700 / 700** (fichiers 90 ; +1 assertion `TPE_TIMEOUT_MS` dans spec existante).

## Objectif

Corriger 2 findings post-VERIFY (sentinelle `TPE_TIMEOUT_MS`, A11y + scroll ticket fallback) et documenter findings UX dans le rapport EXECUTE d’origine.

## Livrables

| Fix | Description |
|-----|-------------|
| 1 (MED) | `tests/js/kioskPaymentTpeTimeout.spec.js` : import `KIOSK_HARDWARE` + assertion `TPE_TIMEOUT_MS === 120_000` |
| 2 (LOW) | `KioskConfirmationComponent.vue` : `role="status"` + `aria-live="polite"` sur `#kiosk-print-receipt` quand `printFailed` |
| 3 (LOW) | Scroll ticket fallback : `max-height: 80vh`, `overflow-y: auto`, `overscroll-behavior: contain`, `-webkit-overflow-scrolling: touch` (SFC scoped + `kiosk-fallback.css`) |
| 4 (LOW) | `RUN_P_MEGA_W7_B_HARDWARE_FALLBACK_EXECUTE_2026-04-20.md` : section **Findings post-VERIFY** (F-VERIFY-W7B-01 / 02) |

## Gate

- Aucun fichier hors scope (auth, pricing, schema, `kioskOfflineQueue*`, W5 gated hors `KioskConfirmationComponent` ARIA/CSS).
