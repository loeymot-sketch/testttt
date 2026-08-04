# CONVERGENCE FINALE — durcissement 4 systèmes (2026-08-04)
_Paiement/annulation · cumul de points · utilisation des points · gestion de compte_

## VERDICT : CONVERGÉ — 2 cycles adversariaux consécutifs P0+P1=0 sur les 4 systèmes

| Système | Cycle 1 | Cycle 2 | Cycle 3 | Cycle 4 | Convergence |
|---|---|---|---|---|---|
| **Compte** | 0/0 | 0/0 | — | — | ✅ 2 passes consécutives |
| **Cumul points** | 1 P1 → healé | 0/0 | 0/0 | — | ✅ 2 passes consécutives |
| **Utilisation points** | (cluster RED-1/2/3/4 → healé) | 0/0 | 0/0 | — | ✅ 2 passes consécutives |
| **Paiement/annulation** | 1 P1 → healé | 1 P1 → healé | 0/0 | 0/0 | ✅ 2 passes consécutives |

Chaque finding remonté par les auditeurs adversariaux (4 initiaux + 11 cycles) était un **vrai bug**, healé en **TDD** (rouge→vert), déployé VPS+Vercel, puis **re-disputé** au cycle suivant. Zéro hallucination retenue (verify-before-report sur chaque finding).

## ~22 correctifs déployés (HEAD `827afae93`)

### Paiement + annulation
- **P0** refund/chargeback Mollie avalé → cascade RefundCreated (Order pas FrontendOrder → REFUNDED réel).
- **P0** résidu double-paiement → auto-remboursement Mollie sur commande terminale + idempotency requise (ferme le vecteur front).
- **P1** annulation de paiement échoué/annulé → commande annulée (fin de « annuler = validé »).
- **P1** carte refusée n'affiche plus « payé » (inline=paid seulement).
- **P1** rejeu DLQ ne ressuscite plus une commande REFUNDED en PAID.
- **P1** R1 centralisé (caissier n'accepte pas une carte web UNPAID, toutes routes).
- **P1** client ne peut plus auto-annuler une commande PAYÉE sans remboursement.

### Cumul de points
- **P0** clawback symétrique à l'award (legacy/désactivé repris au remboursement — fin de « la maison paie »).
- **P1** garde anti-award [CANCELED, REJECTED, RETURNED].
- **P1** janitor reprend les points GAGNÉS d'un fantôme PREPARED impayé (kiosk + web + phone).
- **P2** plancher reaper > fenêtre d'attach (anti double-bénéfice).

### Utilisation des points (LOCK_FRONTENDORDER_REDEEM_REORDER)
- **P1** pré-rachat réordonné authz→attach→débit-frais : fin du plein-tarif-points-partis (RED-1), du double débit (RED-2), du vol de pré-rachat IDOR (RED-3), du débit sous le min (RED-4).

### Compte
- **P0×2** takeover d'un compte staff/invité SOFT-DELETED (garde anti-escalade désarmée + lookup sans withTrashed).
- **P1** squatting `/loyalty/register` (email non-vérifié plus lié).
- **P1** anti email-bombing (plafond OTP par email).
- **P1** resend code réparé (prénom+nom).

### Coupon (adjacent)
- **P1** coupon 1-usage plus brûlé par une commande annulée.

## Gates finales (toutes vertes)
Payment 54 · Loyalty 46 · Coupon 49 · Auth 50 · redeem 9 · janitor 5 · annulation 8 · throttle 11 · pricing 7 · register 6 · **chaîne NF525 OK ×4** · **frozen 0 hors LOCK** (2 LOCK docs : ticket-viande + redeem-reorder).

## RESTE — P2/P3 documentés (owner-gate / défense-en-profondeur, NON bloquants)
- Refund partiel Mollie traité comme total (backlog V1.0.2, conservateur).
- amount_mismatch sans auto-refund ; garde REFUNDED redondante sur reconcile/paymentConfirm (déjà bloqués par PaymentStateMachine, non atteignables web-Mollie).
- Compte : oracle d'énumération `/register`, `users.phone` sans UNIQUE (migration+dédup prod = **gate owner**), squat pré-emptif d'un numéro inutilisé (fix propre = SMS interdit par mandat owner).
- Cumul : commande à l'avance web/phone purgée trop tôt (order-loss, reachability masquée) ; sentinelle award `-1` sur SIGKILL ; points sur frais de livraison / taux figé = **décisions produit owner**.

## Gate go-live (rappel, hors code)
Le passage en production réelle de la carte reste gaté sur la résolution du **TAMPER** de la chaîne fiscale VPS (record du 30/06) — vérification/décision owner.
