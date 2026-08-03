# Audit adversaire LOGIQUE MÉTIER — Caisse indirecte (livreur / fidélité / historique / kanban / OSS / walk-in)

Date : 2026-07-11 · DB réel `foodking_e2e` (2865 commandes) · lecture seule + tinker rollback.
Cibles : `DeliveryBoyCashSessionService`, `PosRedemptionService`, `WalkInCustomerResolver`,
`OrderHistoryController`/`OrderService::list`, `PosOrdersTrackerComponent` (kanban),
`OrderStatusScreen*` (écran client).

Verdict : **0 P0. 1 P2 prouvé live (fidélité). 1 P2 transverse (fidélité pré-commande). 1 P3.**
Cœur argent/points correct partout ailleurs (preuves DB réelles ci-dessous).

---

## P2-A — Fidélité caisse : points BRÛLÉS au-delà de la valeur livrée quand une remise préexiste
Fichier : `app/Services/Loyalty/PosRedemptionService.php:145`

Le garde anti-négatif vérifie `if ($discountEur > $subtotal)` — il n'utilise QUE les euros du
rachat courant, **pas le cumul** `$currentDiscount + $discountEur`. Ligne 224 le total est
ensuite `round(max(0, $subtotal - $newDiscount + $delivery), 2)` : si la remise cumulée dépasse
le sous-total, le total est **clampé à 0** mais **la totalité des points est débitée**.

Repro live (transaction rollback, order #5632 sub=6,90 €, client u64 code 00597EDE 406 pts) :
```
T2 BEFORE: sub=6,90  disc=6,00 (remise préexistante)  total=0,90  | u64 = 406 pts
T2 rachat 200 pts (=2,00 €) → discount_eur=2  newDiscount=8,00 (>6,90)
T2 AFTER : total=0,00  disc=8,00  | u64 = 206 pts
```
→ 200 pts (valeur 2,00 €) débités pour **0,90 € de remise réellement livrée** ; ~1,10 € de
valeur-points perdue par le client. Total jamais négatif (pas de sur-encaissement) mais points faux.

Atteignabilité : `pos.manual_discount_enabled=true` (vérifié en config, défaut `true`). Chemins
réels de remise préexistante : remise manuelle POS, OU commande borne avec remise-fidélité posée à
la création (client A) puis rachat POS par le caissier pour un **autre** code (client B) — l'unique
`(user_id, order_id, type)` n'empêche PAS le stacking cross-client sur la même commande.

Fix : `if (round($currentDiscount + $discountEur, 2) > $subtotal)` → refuser (`DISCOUNT_EXCEEDS_SUBTOTAL`),
ou plafonner `discountEur` au sous-total restant et ne débiter que les points correspondants.

---

## P2-B (transverse, hors fichiers-cible mais visible en historique) — rachat fidélité pré-commande à `order_id = NULL`
Fichier : `app/Http/Controllers/Frontend/LoyaltyController.php:382`

Le rachat borne/pré-commande écrit un ledger `type=redeem` avec `order_id => null` (« pre-order,
no order_id yet ») ET débite les points immédiatement dans sa propre transaction.

Preuves DB : 7 lignes redeem réelles à `order_id=NULL` (LT#53→60, `source_surface=pos`, créées
2026-07-08). Test live (rollback) : deux insertions redeem `order_id=NULL` même user → **les deux
réussissent** (l'unique `(user_id, order_id, type)` ne s'applique pas aux NULL en MySQL). Par contraste,
deux redeem `order_id` non-null → **1062 Duplicate** (garde OK sur le chemin POS).

Conséquences : (1) l'invariant LOCK §6.2 « single redemption » ne tient PAS pour les pré-rachats ;
(2) `LoyaltyService::refundPoints` indexe sur `order_id` → un pré-rachat `NULL` **n'est jamais
remboursable** : si le client abandonne la commande après rachat, les points sont **perdus
définitivement**. Le contrôle de solde empêche seulement l'overdraw (pas de solde négatif).

Fix : lier la ligne à la commande une fois créée, ou débiter à la création de commande (pas avant),
ou index d'unicité tolérant-NULL + réconciliation d'abandon.

---

## P3 — `closeSession` idempotent efface un recomptage corrigé
Fichier : `app/Services/Delivery/DeliveryBoyCashSessionService.php:156`

Si une session est re-fermée avec un `closing_amount` corrigé AVANT réconciliation, le 2ᵉ appel est
un no-op silencieux (retourne la 1ʳᵉ valeur) : le manager réconcilie contre le mauvais montant. Flux
rare (l'UI ne rejoue pas close), d'où P3. Sinon la machine à états est saine (recordMovement bloqué
hors OPEN → somme stable entre close et reconcile).

---

## Vérifié CORRECT (réfutations, chiffres réels)

**Caisse livreur — réconciliation** : sur les 9 sessions réelles, `expected = opening + Σ signed(mvt)`
recomputé == stocké (100 %). Signe d'écart correct : `variance = closing − expected`, négatif = manque.
Ex. S#5 close=137,50 exp=140,00 → var=−2,50 (manque 2,50 €) ✓ ; S#10 close=0 exp=63 → −63 ✓ ;
multi-commandes S#9 open=50 +Σ44,5 = exp 94,5 ✓. Chaîne audit unifiée `audit_logs` par branche.

**Fidélité — cœur** : rachat normal order #5632 200 pts → total 6,90→4,90, solde 406→206 ✓ ;
solde insuffisant (100000 pts) → 422 INSUFFICIENT_BALANCE ✓ ; double-rachat même commande → 409
ALREADY_REDEEMED (unique DB, prouvé) ✓ ; total jamais < 0 (`max(0,…)`).

**Fidélité — TVA/fiscal** : `order.total_tax` non modifié par la remise est **by-design** : la TVA
est nettée sur la base post-remise au Z (`ZReportService.php:520-799`
`LOCK_ZREPORT_F1_DISCOUNT_NETTING`, ratio `(subtotal−discount)/subtotal`), couvert par
`tests/Feature/Fiscal/ZReportDiscountNettingTest.php`. Le commentaire « DISCOUNTS_DISABLED_V1 » de
`PosRedemptionService:72` est STALE (le gate est ouvert car F1 est corrigé). Pas de P0 fiscal.

**Walk-in** : PAS de doublons clients. `BranchScope::apply` fait un early-return sur `User`
(`BranchScope.php:21`, anti-récursion Sanctum) → `firstOrCreate(['email'=>walkingcustomer])` retrouve
l'unique user id=2 (branch_id=0) quel que soit le contexte de branche. Count réel = 1. (Note mineure :
`idx_users_email` non-unique + firstOrCreate non-atomique → course théorique au tout 1ᵉʳ appel ;
non-live car la ligne existe.)

**Kanban suivi (tracker)** : `ordersByStatus` range chaque commande dans **exactement une** colonne
(if/else if) ; compteur badge = `col.orders.length` == réel. Pas de commande en 2 colonnes. Le remap
ACCEPT→PRÉPARATION (commande payée non-cash restée en ACCEPT) est intentionnel/documenté
(TRACKER-CONTINUITY-FIX). `list()` renvoie TOUT le jour (paginate≠1 ⇒ `->get()`, `per_page` ignoré)
→ pas de troncature à 100.

**Historique** : total affiché = `SimpleOrderResource.total = round(order.total,2)` == DB.
Pagination réelle (`paginate:1, per_page:10`, meta-driven). Permission `pos-orders|pos` + garde
cross-branche 403 (anti-énumération). Écran client OSS : filtre fail-closed KIOSK/TAKEAWAY +
PREPARING/PREPARED + garde identifiant (queue/token) + parité board cuisine.

---

### Résumé sévérité
| # | Sévérité | Zone | État |
|---|----------|------|------|
| P2-A | Points faux (valeur perdue) | PosRedemptionService stacking | **prouvé live** |
| P2-B | Points perdus (non remboursables) | LoyaltyController pré-rachat NULL | prouvé (7 lignes DB) |
| P3 | Recomptage effacé | DeliveryBoyCashSessionService close | logique |
| — | Réconciliation livreur | 9 sessions | ✓ correct |
| — | Fidélité cœur + TVA/Z | — | ✓ correct |
| — | Walk-in / kanban / historique / OSS | — | ✓ correct |
