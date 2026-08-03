# Triage adversaire de l'audit forensique externe (FoodKing_Audit_20260706) — 2026-07-06

Audit externe : 100% statique, deps non installées, synthèse interrompue, verdict « block 3,5/10, 33 critiques, non déployable ».
Triage : 7 spécialistes vérifient chaque critique contre le HEAD `b0f7b7285` + réfutateurs indépendants (défaut réfuté).

## Décompte : 34 critiques → 23 DÉJÀ CORRIGÉS, 1 FAUX, 1 BY-DESIGN, 8 REAL_OPEN dont 4 survivent à la réfutation (tous P2)

### Calibration (3/3 Top-6 démentis)
- Clé Firebase `public/file/service-account-file.json` : ABSENTE/non-trackée.
- Stripe troncature : déjà `round-before-cast` [P0-6 CTO 2026-05-16].
- Kiosk token = admin id=1 : seeder utilise email lookup, PAS id=1.

### ALREADY_FIXED (23) — l'audit visait un snapshot ancien
C03, C05, C06, C08, C10, C13, C14, C15, C16, C19, C20, C21, C22, C23, C25, C26, C27, C28, C30, C31, C32, C34, C40.
Exemples : C03/C22 scellement (SealedOrderGuard sur RETURNED/CANCELED/REJECTED + PaymentStateMachine PAID→[]) ; C32/C40 annulées-PAID (déjà gardées) ; C05/C06 delivery_charge (min:0 + recalcul serveur C3) ; C23 Stripe ; C15/C19/C26 kiosk token ; C30 clé Firebase absente.

### FAUX / BY-DESIGN / dégradés
- C11 FALSE_REASONING (confirmation paiement — vérif existe).
- C29 BY_DESIGN_V1 (garde kiosque — V1 mono-branche).
- C07 réfuté → P3 (branch_id=0 : le mécanisme ne tient pas au HEAD).
- C17 réfuté → P3 (migration TRUNCATE réelle mais ne s'exécute qu'une fois, chemin non-standard).
- C37 réfuté → NONE (se réfute lui-même).

### ✅ 4 VRAIS CONFIRMÉS (tous P2, réfutation refuted=false au HEAD b0f7b7285)
| ID | Sévérité | Zone | Frozen ? | Fix |
|---|---|---|---|---|
| **C09** | P2 | `/api/admin/users` (+ index) atteignable par tout token staff sans permission → énumération PII (nom+email) | Non | Gate permission / bloquer tokens role CUSTOMER |
| **C36** | P2 | Points fidélité débités à la création mais PAS remboursés quand `CleanupStalePendingKioskOrders` auto-annule (asymétrie : changeStatus rembourse, le job non) | Non | `refundPoints` dans le job (miroir changeStatus) |
| **C39** | P2 | Remise fidélité borne affichée au panier mais jamais appliquée au paiement (payload sans discount) → client voit -X, débité plein | Non | Gater le redeem fidélité (comme promo W2) OU câbler le discount |
| **C33/C04** | P2 | Fenêtre morte entre deux Z : recette encaissée entre close(Z_n) et open(Z_{n+1}) hors de tout Z signé | **OUI ZReportService §7** | LOCK + gate owner (borne basse = closed_at Z précédent) |

## Conclusion
L'audit externe est ~85% périmé/faux. **Le vrai résidu = 3 P2 non-frozen (fixables tout de suite) + 1 P2 fiscal frozen (gate owner).** Aucun P0/P1 réel. Cohérent avec l'état GO V1 LOCAL déjà attesté.

---
## RÉSOLUTION (2026-07-06, HEAD 6832c6694)
- C09 corrigé `6ecc093b2` — validé adversaire HOLDS (403 Chef / 200 POS, 8 autres index déjà gardés)
- C36 corrigé `a55812152` — validé adversaire HOLDS (refund idempotent prouvé MySQL réel)
- C39 corrigé `7e59c461a` + P3 résiduel écran paiement `6832c6694` — validé adversaire HOLDS (0 discount fantôme)
- C33/C04 = LOCK DRAFT `plans/LOCK_ZREPORT_C33_DEAD_WINDOW_CONTINUITY_2026-07-06.md` — GATE OWNER (frozen NF525)
- Validation adversaire CLEAN, gates : Vitest 2260/0, PHPUnit 3182/0, frozen 0 hors LOCK, CHAIN OK ×4.
- Rapport adversaire : `validation-adversaire.json`.
