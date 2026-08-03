# Abuse audit — IDOR (suivi) + Fidélité — Le Cayenne (staging)

2026-07-30 · `https://127.0.0.1:443` via `ssh lecayenne` (staging, DB foodking).
2 comptes invités réels (OTP) : **A** user 20 / code 39051145 / 500 pts / commande 230 ; **B** user 22 / E05B0C24 / 300 pts / commande 231.
Tokens invités = `kiosk:order` seul, `branch_id=0`. **Fixtures nettoyées** (users 20/22, orders 230/231, ledger, nonces, tokens, otps → 0 restant).

## Synthèse (6 lignes)
1. IDOR suivi **BLOQUÉ** : token A → commande de B = 403 sans PII ; id inexistant/0/négatif = 404 uniforme.
2. Vol de points **BLOQUÉ** : A « redeem » le code de B = 403 (un invité `kiosk:order` n'est PAS une borne — exige une vraie `KioskMachine`).
3. Course concurrente **BLOQUÉE** : 6× redeem //, solde 500 → 5 passent, 1 « insuffisant », solde plancher 0, jamais négatif (`lockForUpdate`).
4. QR **BLINDÉ** : rejeu=`qr_replay` (nonce UNIQUE), forge=`qr_invalid_signature` (`hash_equals`), A scanne le VRAI QR de B = neutre, 0 PII.
5. Escalade **BLOQUÉE** : invité → routes admin = 403 « Kiosk-scoped tokens » ; `add-points` = 403 staff-only ; OTP verify = 429 (3/5 min).
6. **P0=0 · P1=0 · P2=0** (money-path + PII étanches). 2 notes durcissement non-bloquantes.

## IDOR suivi (token A)
| Attaque → réponse | Verdict · garde |
|---|---|
| `GET /order/show/231` (B) → `403 "Access denied: you do not own this order."` | **BLOQUÉ ✓** `FrontendOrderService.php:754` (`user_id !== Auth::id()`) |
| `/order/show/230` (propre) → `200 {data}` | contrôle OK |
| `/order/show/99999999`, `/0`, `/-1` → `404 ORDER_NOT_FOUND` | **BLOQUÉ ✓** route-model-bind |
| Énumération 226→231 → 403 (existe) / 404 (absent), **aucune PII** | **BLOQUÉ ✓** idem |
| `POST /order/change-status/231` → `403 "This action is unauthorized."` | **BLOQUÉ ✓** service `:790/:882` |
| `GET /order/show/231/escpos` → `403 Unauthorized` (invité = pas de KioskMachine) | **BLOQUÉ ✓** `OrderController.php:115-122` |
| `POST /order/231/payment-confirm` → bloqué (KioskMachine + ownership) | **BLOQUÉ ✓** `OrderController.php:174-190` |
| `GET /order/wait-estimate?branch_id=1..99` → agrégat seul, 0 PII | **BLOQUÉ ✓** `WaitEstimateService` SELECT-only |

P2-info (non-bloquant) : oracle 403-vs-404 laisse deviner l'EXISTENCE d'un id (0 donnée exposée ; comportement Laravel standard).

## Fidélité (redeem/history/add-points)
| Attaque → réponse | Verdict · garde |
|---|---|
| Redeem code d'AUTRUI (A→B) → `403 "Non autorisé"` | **BLOQUÉ ✓** `LoyaltyController.php:369` + `:343` (KioskMachine réelle) |
| Redeem > solde (10000/500) → `400 "Points insuffisants"` | **BLOQUÉ ✓** `:387` |
| Redeem négatif / zéro → `422 "at least 1"` | **BLOQUÉ ✓** `:53` |
| Redeem non-multiple (150) → `400 "multiple de 100"` | **BLOQUÉ ✓** `:383` |
| Redeem overflow (999999999) → `422 "not greater than 10000"` | **BLOQUÉ ✓** `:53` |
| Double-redeem // (6×100, solde 500) → 5 OK/1 rejet, **solde=0 jamais négatif** | **BLOQUÉ ✓** `lockForUpdate :363` + `:387` |
| Rejeu même Idempotency-Key ×3 // → 1 seul débit (solde 200) | **BLOQUÉ ✓** middleware `idempotency` (route :1555) |
| Historique d'AUTRUI → scopé `where(user_id)`, aucun param id/token | **BLOQUÉ ✓** `:571` |
| add-points auto (self-credit) → `403 "Non autorisé"` | **BLOQUÉ ✓** staff-only `:246` |

## QR fidélité
| Attaque → réponse | Verdict · garde |
|---|---|
| Rejeu même QR ×2 → 1er PII, 2e `qr_replay` | **BLOQUÉ ✓** nonce INSERT-UNIQUE `LoyaltyQrSigner.php:143-157` |
| QR forgé (hmac altéré) → `qr_invalid_signature` | **BLOQUÉ ✓** `hash_equals :119` |
| A scanne le VRAI QR de B → `customer_not_found`, **0 PII de B** | **BLOQUÉ ✓** choke-point `:805` |
| Expiration `exp+leeway` présente | **BLOQUÉ ✓** `:136` |

Info (non-bloquant) : scanner le QR d'une victime consomme son nonce (griefing/DoS mineur ; QR auto-rotatif 5 min).

## Escalade / auth
| Attaque → réponse | Verdict · garde |
|---|---|
| Invité → `/admin/*` (default-access, menu-projection, stock, setting) → `403 "Kiosk-scoped tokens cannot access admin routes"` | **BLOQUÉ ✓** `BlockKioskTokenFromAdminRoutes.php:124` |
| Brute-force OTP verify → `429` (3/5 min) | **BLOQUÉ ✓** throttle route `:220` |

## Comptage
**P0=0 · P1=0 · P2=0.** Money-path fidélité et PII client étanches (IDOR, race, rejeu/forge QR, énumération, escalade).
2 observations de durcissement, non-exploitables (données/argent) : oracle existence-commande (403/404) ; consommation nonce QR d'autrui.
