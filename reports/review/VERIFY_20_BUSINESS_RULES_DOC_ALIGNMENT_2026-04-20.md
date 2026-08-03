# VERIFY-20 — Alignement `docs/BUSINESS_RULES.md` ↔ code (P1 stock, P3 RETURNED, coupons, NF525)

**Date :** 2026-04-20
**Mode :** AUDIT-ONLY (lecture seule, **aucun fichier `docs/` ni applicatif modifié**)
**Tâche :** `tasks/verify-2026-04-20/20_VERIFY_BUSINESS_RULES_DOC_ALIGNMENT.md`
**Auteur :** Planner-Orchestrator (subagent `foodking-planner-orchestrator`)
**Origine :** finding `F-DOC-001` + dérives constatées (P1 livré, P3 RETURNED ajouté, audit NF525, P8/P9 coupons)
**Inputs amont consommés (au lieu de re-explorer) :** `VERIFY_01_P1_AVAILABILITY_2026-04-20.md`, `VERIFY_03_P3_REFUND_RETURNED_2026-04-20.md`, `VERIFY_08_FISCAL_NF525_Z_OPEN_2026-04-20.md`, `VERIFY_10_BRANCH_ISOLATION_2026-04-20.md`, `VERIFY_19_AVAILABILITY_TOGGLE_ROUTE_2026-04-20.md`, `AUDIT_POS_110_FISCAL_NF525_2026-04-19.md`, `AUDIT_POS_110_HIDDEN_RISKS_2026-04-19.md`.

---

## 0. Verdict global

> **GLOBAL : FAIL**
>
> La doc `docs/BUSINESS_RULES.md` est **factuellement fausse** sur la section **Stock Management** (déclare "not implemented" alors que P1 est livré et qu'`AvailabilityService::assertItemsOrderableForBranch` rejette tout `OrderItem` indisponible — chemins Kiosk, POS, Table, Frontend). C'est un **piège pour onboarding et audit externe**, qualifié `FAIL doc` par `VERIFY_01` (`F-VERIFY-01-07`, sévérité HIGH-doc).
> S'y ajoutent **5 sections manquantes** matérielles : (a) RETURNED — motif obligatoire + idempotence + contre-partie cashback ; (b) Coupons — `limit_per_user`, `minimum_order`, plancher 0 ; (c) NF525 — hash chain audit_log, séquence fiscale par branche, Z OPEN/CLOSED, immutabilité ; (d) Vision SaaS multi-tenant / `branch_id=0` Admin / `BranchScope` ; (e) Loyalty `refundPoints` no-op.
> §1 (Pricing SSOT), §2 (formule total), §4 (Order Status enum) et §Kiosk Idle Timeout sont **alignés au code**. Le bloc OrderStatus est correct mais sa version "transitions" doit déléguer à `docs/ORDER_FLOW.md` plutôt que doublonner.
>
> Critère §6 du task file : « FAIL si la doc induit en erreur sur P1/P3 (priorité immédiate). » → atteint sur **P1** (§Stock Management) ; sur **P3**, la doc ne ment pas mais oublie le motif obligatoire et l'idempotence — gap matériel.

---

## 1. Plan exécuté (5 lignes)

1. Pass A — lecture intégrale de `docs/BUSINESS_RULES.md` (60 lignes, 7 sections) + extraction de chaque règle déclarée.
2. Pass B — cross-check de chaque règle vs code réel (`OrderStatus`, `CouponService`, `PricingService`, `AvailabilityService`, `OrderService::changeStatus`, `LoyaltyService`, `BranchScope`, `ZReportService`, `AuditLogService`) en exploitant les rapports `VERIFY-01/03/08/10/19` déjà produits.
3. Construction de la matrice **doc-section × code-réalité × verdict** (aligné / divergent / partiel / manquant).
4. Vérification de la checklist `§5 V1–V5` du task file.
5. Rédaction du présent rapport + annexe **patch markdown ready-to-commit (NON appliqué)**.

---

## 2. Pass A — Extraction règles déclarées par `docs/BUSINESS_RULES.md`

| Section doc | Lignes | Règles énoncées |
|---|---|---|
| §1 Règle du Prix Central (SSOT) | `:7-14` | Frontend ne définit jamais le prix ; backend recalcule depuis `Item::find()` ; `total` payload **ignoré mathématiquement**. |
| §2 Calcul Grand Total + TVA cascade | `:16-31` | `ORDER_TOTAL = SUBTOTAL + DELIVERY − DISCOUNT + TAXES` ; TVA en cascade par `tax_id` d'item. |
| §3 Réductions / Coupons | `:33-39` | Frontend envoie code → backend valide via `CouponService` (Date / Fixed / Percentage) ; plafond `maximum_discount` ; total **ne peut jamais être < 0**. |
| §4 Transitions États (OrderStatus) | `:41-49` | Pipeline `PENDING → ACCEPT → PREPARING → PREPARED → OUT_FOR_DELIVERY → DELIVERED` ; terminaux `CANCELED/REJECTED/RETURNED` ; saut interdit ; admin dashboard peut bypasser ; **isolation branche** (user assigné même `branch_id` que l'`Order`). |
| §Kiosk Idle Timeout | `:51-55` | 3 min reset (180 000 ms) ; modal "still there?" à 2 min 30 ; pas de timer sur idle/payment/waiting/confirmation. |
| §Stock Management | `:57-59` | **« FoodKing v1 does not track item stock levels. There is no `stock` column, no `is_available` flag, and no stock validation at order time. Planned for v2: `items.stock_quantity` (nullable integer)… »** |

Aucune autre section. Notamment **absentes** : NF525 / hash chain audit_log / Z report ; vision SaaS multi-branche / multi-tenant ; loyalty / refund points ; idempotence RETURNED ; cashback ; `BranchScope` / `branch_id=0` ; coupon `limit_per_user` / `minimum_order` ; itinéraire de transition complet (déjà documenté ailleurs : `docs/ORDER_FLOW.md`).

---

## 3. Pass B — Cross-check code (réalité observée)

### 3.1 §1 Pricing SSOT — code

`app/Services/Pricing/PricingService.php:30-45` recalcule le subtotal item par item depuis `Item::find()` ; le `total` du payload n'est jamais consulté pour les `order_items`. `PricingService.php:234` : `round(max(0.0, $rawTotal), 2)` ⇒ plancher 0 garanti côté code (la doc l'a sur §3 mais pas redit en §2). **Aligné.**

### 3.2 §2 Formule Grand Total + TVA cascade — code

`PricingService::calculateOrder` (chemin SSOT par défaut `config('pricing.use_ssot_service', true)`) implémente `SUBTOTAL + DELIVERY − DISCOUNT + TAXES`, taxes en cascade par item (`tax_id`). `OrderService.php:612` (POS), `:1016` (Table), `FrontendOrderService.php:212` (Kiosk) — invocation unifiée. **Aligné.**

### 3.3 §3 Coupons — code (`app/Services/CouponService.php`)

| Règle doc | Réalité code | Verdict |
|---|---|---|
| Validation type `Date / Fixed / Percentage` | `validateCouponForOrder` (`:287-321`) + `calculateDiscountAmount` (`:268-280`) ; `DiscountType::PERCENTAGE` géré | **Aligné** |
| Plafond `maximum_discount` | `:274-277` borne `$amount > $maximumDiscount → $maximumDiscount` | **Aligné** |
| Plancher 0 sur total | `PricingService.php:234` (`max(0, …)`) + `calculateDiscountAmount` `min($amount, $subtotal)` | **Aligné** mais énoncé hors §2 (à recentrer en §2) |
| **`limit_per_user`** (un même user max N fois) | `CouponService.php:308-318` : `OrderCoupon::where(['user_id'=>…,'coupon_id'=>…])->count() >= limit` → 422 `coupon_limit_exceeded` | **Doc MANQUANTE** |
| **`minimum_order`** (panier seuil) | `CouponService.php:293-298` : 422 `minimum_order_amount` | **Doc MANQUANTE** |
| Validité dates `start_date`/`end_date` | `:300-306` | **Doc présente (mention "Date")** mais détail flou |

### 3.4 §4 OrderStatus — code

| Règle doc | Réalité code | Verdict |
|---|---|---|
| Enum `OrderStatus` SOT (1/4/7/8/10/13/16/19/22) | `app/Enums/OrderStatus.php:7-15` exact | **Aligné** |
| Pipeline + terminaux | `app/Domain/Order/OrderStateMachine.php` + `docs/ORDER_FLOW.md` (table légale) | **Aligné** (doublon partiel avec ORDER_FLOW) |
| Saut interdit (`PENDING → DELIVERED`) | `ValidStatusTransition` rule + `OrderStateMachine::assertAllows()` | **Aligné** |
| Admin dashboard peut bypasser tracé `action_logs` | `OrderStateMachine::allows()` autorise sortie terminal pour `Admin` ; `ActionLog::create` posé dans `changeStatus` | **Aligné** |
| Isolation branche (mutateur même `branch_id`) | `OrderService.php:1492-1497` (changeStatus), `:1606-1611` (changePaymentStatus), `:1720-1727` (destroy), `KitchenDisplaySystemOrderService.php:130-131` ⇒ 403 explicite ; `BranchScope` global | **Aligné** mais ne dit pas que `Admin` (`branch_id=0`) **court-circuite** la garde par design (cf. `VERIFY_10` §2.5) |
| **Motif (`reason`) obligatoire pour CANCELED/REJECTED/RETURNED** | `OrderService.php:1499-1504` `request->validate(['reason' => 'required\|max:700'])` | **Doc MANQUANTE** sur RETURNED (motif évoqué pour CANCELED dans `ORDER_FLOW.md` mais pas ici) |
| **Idempotence (2× RETURNED)** | `OrderStateMachine::allows()` autorise `from===to` ; aucun guard same-status dans `changeStatus()` ⇒ cashBack/refundPoints/AuditLog rejouables (`VERIFY_03` V2 = **FAIL**) | **Doc MANQUANTE** ET **code défaillant** (cycle `P11_RETURNED_IDEMPOTENCY`) |
| **Contre-partie monétaire RETURNED** (cashBack si `$order->transaction`) | `OrderService.php:1505-1512` + `PaymentService::cashBack` | **Doc MANQUANTE** |
| **Loyalty `refundPoints` no-op si pas de redeem** | `LoyaltyService.php:21-38` (garde `loyalty_customer_code` + `redeemTxns->isEmpty()`) | **Doc MANQUANTE** |

### 3.5 §Stock Management — code

> **Doc dit (`:57-59`) :** « not implemented », « no `stock` column », « **no `is_available` flag** », « no stock validation at order time », « Planned for v2 ».
> **Réalité (post-P1, commit `b76506ae9`) :**
> - Migration `database/migrations/2026_04_15_230100_create_item_branch_availability_table.php:15-28` crée `item_branch_availability` avec `is_available bool DEFAULT TRUE`, `max_daily_qty`, `daily_consumed_qty`, `daily_reset_at`, `unique(item_id, branch_id)`.
> - `AvailabilityService::assertItemsOrderableForBranch` (`app/Services/Menu/AvailabilityService.php:130-149`) lève `InvalidArgumentException` 422 sur tout `OrderItem` unavailable, pour **toutes** les surfaces (POS `OrderService.php:612`, Frontend/Kiosk `FrontendOrderService.php:212`, Table `OrderService.php:1016`).
> - Toggle UI/admin : `POST /api/admin/menu/availability/toggle` ↔ `AvailabilityController::toggle` (`VERIFY_19` §4 V1-V5 PASS).
> - Event `ItemAvailabilityChanged::forBranch` broadcast `private-branch.{id}` + outbox `DomainEvent` ; cache `kiosk.menu.branch.{id}` invalidé via listener.
> - Front kiosk : `kioskCart/pruneUnavailableLines` (`resources/js/store/modules/kioskCart.js:337-352`).
>
> **Verdict : DIVERGENCE MAJEURE — la doc affirme l'inverse de la réalité.** Sévérité : `F-VERIFY-01-07` HIGH-doc. → cycle `P11_BusinessRules_StockSection`.

### 3.6 Sections **manquantes** dans la doc (par rapport au code livré)

| Sujet absent | Évidence code | Risque doc |
|---|---|---|
| **NF525** : hash chain `audit_logs`, séquence fiscale par branche, Z OPEN/CLOSED, X intraday, immutabilité (triggers DB), `pos-manage-fiscal` | `app/Services/Fiscal/{ZReportService,AuditLogService,FiscalSequenceService,XReportService}.php` ; migrations `2026_04_22_000001..100000` | Onboarding back-end ne sait pas que la mutation `audit_logs` est interdite niveau DB ; risque silencieux. |
| **Vision SaaS multi-branche / multi-tenant** ; `BranchScope` global ; `branch_id=0` Admin (peut cross-branch) | `app/Models/Scopes/BranchScope.php`, `Order.php:82`, `User.php:90`, etc. | Onboarding front pense devoir filtrer manuellement ; risque double filtre / fuite. |
| **Idempotence RETURNED** + **contre-partie cashback** + **refundPoints** (V2 actuellement FAIL) | `OrderService.php:1499-1567` + `LoyaltyService.php:21-38` + `PaymentService::cashBack` | Caissier peut double-cliquer "Retourner" → double crédit `users.balance` (cf. `VERIFY_03` §5.1). |
| **Coupons** : `limit_per_user`, `minimum_order`, plancher 0 explicite | `CouponService.php:287-321` | Devs front ignorent ces 422 et les remontent comme bug. |
| **Sealed-Z protection** (RETURNED post-Z fermé non bloqué — F-VERIFY-08-02) | `OrderService.php:1499-1567` vs `:1736-1752` | Doc devrait avertir explicitement de ce gap NF525 et pointer le ticket P11/P14. |
| **Dispatch après commit** (notifications et events) | `OrderService.php:1567-1578` ; règle `safety.mdc §5` | Présente dans `safety.mdc` mais pas dans `BUSINESS_RULES.md` — utile pour onboarding. |

---

## 4. Matrice consolidée doc-section × code-réalité × verdict

| # | Section doc | État code | Verdict | Sévérité | Cycle P |
|---|---|---|---|---|---|
| 1 | §1 Pricing SSOT | `PricingService` recalcule, `total` payload ignoré | **ALIGNÉ** | — | — |
| 2 | §2 Formule total + TVA cascade | Implémenté SSOT, plancher 0 (`max(0,…)`) | **ALIGNÉ** (mineur : repositionner plancher 0 dans §2 plutôt que §3) | LOW | P11 (édito) |
| 3 | §3 Coupons (Date/Fixed/Percentage + plafond + plancher 0) | OK pour types/plafond/plancher | **PARTIEL** : `limit_per_user` + `minimum_order` non documentés | MEDIUM | P11 |
| 4 | §4 Pipeline OrderStatus + saut interdit + admin bypass + isolation branche | Enum exact, pipeline cohérent, branche défense-en-profondeur | **ALIGNÉ** ; gap : motif RETURNED + idempotence + cashback + Admin `branch_id=0` non décrits | MEDIUM | P11 |
| 5 | §Kiosk Idle Timeout (3 min / 2 min 30 modal) | Conforme à `kiosk.idle_*` constantes | **ALIGNÉ** | — | — |
| 6 | §Stock Management ("not implemented") | **P1 livré** : `item_branch_availability` + garde + UI toggle + event + cache | **DIVERGENT MAJEUR (FAIL)** | HIGH | **P11 (P0)** |
| 7 | (manquant) NF525 / Z / hash chain / `pos-manage-fiscal` | Implémenté (cf. `VERIFY_08`) | **MISSING** | MEDIUM (WARN selon §6 du task) | P11 ou P11b |
| 8 | (manquant) SaaS multi-tenant / `BranchScope` / `branch_id=0` | Implémenté | **MISSING** | MEDIUM | P11 |
| 9 | (manquant) RETURNED motif + idempotence + cashback + refundPoints | Implémenté (sauf idempotence FAIL `VERIFY_03` V2) | **MISSING + bug code** | HIGH | P11 doc + `P11_RETURNED_IDEMPOTENCY` code |
| 10 | (manquant) Sealed-Z protection (RETURNED post-Z) | Non implémenté (F-VERIFY-08-02) | **MISSING** + invariant NF525 à risque | MEDIUM | `P11_FISCAL_Z_OPEN_HARDENING` ou `P14_FISCAL_CONTREPASSATION` |

---

## 5. Vérifications obligatoires §5 du task file

| V | Critère | Statut | Preuve / commentaire |
|---|---|---|---|
| **V1** | Diff section stock (P1) écrit | **PASS** | §3.5 + §4 ligne 6 ; doc dit "not implemented", code livre `item_branch_availability` + `assertItemsOrderableForBranch`. |
| **V2** | Diff section annulation/RETURNED (P3) écrit | **PASS** | §3.4 (5 dernières lignes) + §4 ligne 9 ; motif obligatoire `required\|max:700`, idempotence FAIL, cashback contrepartie, refundPoints no-op. |
| **V3** | Diff section coupon (P8/P9) écrit | **PASS** | §3.3 + §4 ligne 3 ; `limit_per_user` (`CouponService.php:308-318`), `minimum_order` (`:293-298`) absents de la doc. |
| **V4** | Section NF525 présente OU note manquante explicite | **PASS** (note manquante explicite) | §3.6 ligne NF525 + §4 ligne 7 ; section absente, donc note + cycle proposé. |
| **V5** | Patch doc proposé en annexe (markdown ready-to-commit), **sans** toucher au fichier doc | **PASS** | Annexe A ci-dessous (patch unifié, NON appliqué). |

---

## 6. Cycles P proposés (priorisés)

| Priorité | Cycle | Périmètre | Routage suggéré |
|---|---|---|---|
| **P0** | **P11_BusinessRules_DocSync** | Réécrire `docs/BUSINESS_RULES.md` : §Stock Management (P1), nouveau §RETURNED (motif + idempotence + cashback + refundPoints), enrichir §Coupons (`limit_per_user`, `minimum_order`, plancher 0 dans §2), nouveau §NF525 (hash chain, séquence, Z OPEN/CLOSED, immutabilité), nouveau §SaaS multi-branche / `BranchScope` / `branch_id=0` Admin. Renvoyer §4 vers `docs/ORDER_FLOW.md` pour la table légale (ne pas doublonner). Appliquer le patch annexe §A. | `foodking-routine-implementer` (Composer — édition doc bornée, **aucun code**) |
| **P0** | **P11_RETURNED_IDEMPOTENCY** | Ajouter `if ((int)$order->status === (int)$request->status) return $order;` en tête de `OrderService::changeStatus`, **avant** `DB::transaction` ; tests idempotence (sans loyalty / avec loyalty / sans paiement) dans `PosOrderBL2AuditCallSitesTest`. | `foodking-complex-implementer` (touche service order — sync-sensitive) |
| **P1** | **P11_FISCAL_Z_OPEN_HARDENING** | Bloquer/auditer `RETURNED` + `changePaymentStatus` sur `Order` scellé par Z fermé (F-VERIFY-08-02) ; `verifySignature(prev_z)` + `verifyChain(branch)` à `ZReportService::open()` ; état `STATUS_CLOSING`. | `foodking-complex-implementer` (NF525 critique) |
| **P1** | **P11c_AVAILABILITY_TEST_BIDIRECTIONAL** | Étendre `AvailabilityControllerTest` (OFF→ON, idempotence, fan-out, 403 cross-branch, assertion outbox). | `foodking-routine-implementer` |
| **P2** | **P12_RETURNED_FISCAL_INTEGRATION** | Note de crédit fiscal avec `fiscal_sequence_no` propre + `ZReportService::aggregate` rabattu ; alternative : `OrderRefund` jointable (cf. `P14_FISCAL_CONTREPASSATION`). | `foodking-complex-implementer` |
| **P2** | **P13_FISCAL_EXPORT_JET** | Export JET / PIAF officiel (`foodking:fiscal:export-jet`) + signature détachée. | `foodking-complex-implementer` |

---

## 7. Annexe A — **Patch doc proposé (NON appliqué)**

> **Format :** patch markdown drop-in pour `docs/BUSINESS_RULES.md`. À appliquer **manuellement** dans le cycle `P11_BusinessRules_DocSync` (Composer routine), après revue humaine. **Ne contient aucune édition du fichier `docs/BUSINESS_RULES.md` dans ce rapport** — le patch est inclus à titre informatif.

### A.1 Remplacement complet (recommandé)

```markdown
# FoodKing : Core Business Rules (Logique Mathématique & Garde-fous)

Ce document centralise les **Lois Métier Mathématiques Inflexibles** du projet
ainsi que les **invariants de gouvernance** (NF525, isolation branche, idempotence
des transitions terminales). Toute modification du code (`Controller`, `Service`,
`Frontend`) doit respecter ces principes à la lettre sous peine de corruption
financière ou fiscale (`php artisan test` validera ces règles).

> Pour la table légale des transitions de statut, voir `docs/ORDER_FLOW.md` —
> ce document ne la duplique plus.

---

## ⚖️ 1. Règle du Prix Central (SSOT)

**Principe :** le frontend (Kiosk, App Cliente, POS Web) n'a **JAMAIS** le droit
de définir le prix d'un produit.

- Le backend recalcule **toujours** les prix depuis la BDD (`Item::find()`,
  `ItemVariation`, `ItemExtra`) via `App\Services\Pricing\PricingService::calculateOrder`
  (chemin SSOT par défaut, `config('pricing.use_ssot_service', true)`).
- Le `total` du payload client est **mathématiquement ignoré**.
- Un coupon est validé par `App\Services\CouponService` (plafond, dates,
  type, **`minimum_order`**, **`limit_per_user`**).
- Le total ne peut **jamais** être négatif (plancher `0.00 €` —
  `PricingService.php:234`).

## 🧮 2. Calcul du Grand Total (Addition des frais)

```text
ORDER_TOTAL = max(0, SUBTOTAL(items_db_price * quantity)
                       + DELIVERY_CHARGE (si order_type == Delivery)
                       − DISCOUNT (si coupon valide, borné par maximum_discount)
                       + TAXES (cascade par item.tax_id))
```

### TVA en cascade
- Chaque `Item` possède un `tax_id` ; le `PricingService` boucle sur le panier,
  applique `Price * Tax_Rate / 100` par ligne, somme pour produire `total_tax`.
- Pas de "Taxe Unique Globale" appliquée sur le `Total`.

### Plancher 0
- Coupons + loyalty redeem cumulés ne peuvent pas rendre `ORDER_TOTAL` négatif.

## 🎁 3. Les Réductions (Coupons)

- Le frontend transmet le **code** ("PROMO20"), jamais une remise brute.
- `CouponService::validateCouponForOrder` vérifie :
  - **Type** : `Date`, `Fixed` (ex. 5 €), `Percentage` (ex. 20 %).
  - **`minimum_order`** : 422 `coupon_minimum_order_amount` si `subtotal < min`.
  - **`maximum_discount`** : borne supérieure même sur un coupon `-50 %`.
  - **Validité** : `start_date` ≤ now ≤ `end_date` (Carbon-safe, no-null).
  - **`limit_per_user`** : 422 `coupon_limit_exceeded` si l'utilisateur a déjà
    utilisé le coupon `N` fois (`OrderCoupon` count par `user_id` + `coupon_id`).
- L'addition de tous les rabais ne peut **jamais** rendre `ORDER_TOTAL` < 0.

## 🔄 4. Transitions d'États (Order Status)

- **Source de vérité enum** : `app/Enums/OrderStatus.php`
  (`PENDING=1, ACCEPT=4, PREPARING=7, PREPARED=8, OUT_FOR_DELIVERY=10,
  DELIVERED=13, CANCELED=16, REJECTED=19, RETURNED=22`).
- **Source de vérité transitions** : `app/Domain/Order/OrderStateMachine`
  + `App\Rules\ValidStatusTransition`. **Voir `docs/ORDER_FLOW.md`** pour la
  table légale et le diagramme.
- **Saut interdit** (`PENDING → DELIVERED`) : rejet `IllegalTransitionException`
  ou 422. Seul un Admin peut sortir d'un état terminal (tracé `action_logs`).
- **Motif obligatoire** : `CANCELED`, `REJECTED`, `RETURNED` exigent
  `reason` (`required|max:700`, `OrderService.php:1502-1504`).
- **Isolation branche** : la succursale B ne peut pas muter une commande de A.
  `OrderService::changeStatus`, `changePaymentStatus`, `destroy` et
  `KitchenDisplaySystemOrderService::changeStatus` retournent **403** si
  `auth()->user()->branch_id !== $order->branch_id` (sauf rôle `Admin`,
  cf. §6).

## 🔁 5. RETURNED — contre-passation monétaire

`OrderService::changeStatus(→ RETURNED)` :
1. Valide `reason` (422 si absent).
2. Si `$order->transaction` existe → `PaymentService::cashBack($order, 'credit', …)`
   (crédite `users.balance`, écrit ligne `transactions(type='cash_back', sign='-')`).
3. `LoyaltyService::refundPoints($order, 'pos')` :
   - **No-op** si `loyalty_customer_code` absent.
   - **No-op** si aucune `LoyaltyTransaction` `redeem` antérieure.
   - Sinon : crédit points + `LoyaltyTransaction(type='manual_add')`
     contraint `UNIQUE(user_id, order_id, type)`.
4. `AuditLogService::write(['action' => 'order.returned', …])` (HMAC-chaîné
   par branche).
5. Dispatch `OrderStatusChanged` **après commit** (`DB::transaction` close →
   listener Outbox `PersistOrderStatusChangedToOutbox`).

> **Idempotence (à corriger — cycle `P11_RETURNED_IDEMPOTENCY`)** : aujourd'hui
> `OrderStateMachine::allows()` autorise `from === to`. Un double appel
> `RETURNED → RETURNED` rejoue cashBack + AuditLog pour les commandes sans
> loyalty (les commandes loyalty sont protégées par hasard via la contrainte
> `UNIQUE`). Le service doit court-circuiter `if ($order->status === $newStatus)
> return $order;` en tête.

## 🧾 6. NF525 — Fiscalité française & journal d'audit

- **Séquence fiscale par branche** : `orders.fiscal_sequence_no` allouée par
  `App\Services\Fiscal\FiscalSequenceService` (Cache::lock + `lockForUpdate`).
  Contraintes DB : `orders_branch_fiscal_seq_unique`,
  `z_reports_branch_sequence_unique`, `audit_logs_branch_prev_unique`.
- **Z / X reports** : `ZReportService::open()`/`close()` atomiques,
  idempotence `OPEN`/`CLOSED`. `XReportService::snapshot()` réutilise
  `aggregate()`. Permission requise : `pos-manage-fiscal`. Routes sous
  `routes/api.php` `prefix('fiscal')` (à durcir avec `permission:` middleware
  niveau route — cycle `P11_FISCAL_ROUTE_AUTHZ_HARDENING`).
- **Audit log immuable** : `audit_logs` interdit `UPDATE`/`DELETE` au niveau
  DB (triggers MySQL+SQLite `SIGNAL/RAISE`) **et** au niveau Eloquent
  (`AuditLog::booted()` rejette `updating`/`deleting`).
- **Hash chain** : `AuditLogService::performInsert` calcule `prev_hash` +
  HMAC. `verifyChain(branchId)` retourne `null` si la chaîne est intègre.
  ⚠️ Aucun cron n'appelle automatiquement `verifyChain` (cycle
  `P12_FISCAL_AUDIT_LOG_IMMUTABLE_AUTOVERIFY`).
- **Limites connues** :
  - Pas de protection NF525 sur `RETURNED` ou `changePaymentStatus` d'un
    `Order` scellé par un Z fermé (cycle `P11_FISCAL_Z_OPEN_HARDENING`).
  - `total_ttc` du Z **n'est pas** rabattu après RETURNED (seul
    `refund_count` augmente). Cycle `P12_RETURNED_FISCAL_INTEGRATION` ou
    `P14_FISCAL_CONTREPASSATION`.
  - Export JET / PIAF officiel non livré (`FiscalArchiveCommand` produit un
    zip propriétaire — cycle `P13_FISCAL_EXPORT_JET`).

## 🏢 7. SaaS multi-branche & isolation

- Chaque commande, item, table, push notification, user est scopé par
  `branch_id` via `App\Models\Scopes\BranchScope` (Global Scope Eloquent).
- `Admin` (`branch_id = 0`) **voit toutes les branches** (wildcard) ;
  staff (`branch_id > 0`) ne voit **que** sa branche. Le scope ne s'applique
  pas hors HTTP (`!App::runningInConsole()`), ce qui est exploité par les
  jobs CLI (cleanup, archives) qui filtrent eux-mêmes.
- `withoutGlobalScope(BranchScope::class)` est autorisé uniquement pour des
  cas justifiés (signup duplicate phone, fiscal aggregate, CLI, jobs cron) —
  cf. `VERIFY_10` §2.1.
- **Pusher / Echo** : canal `private-branch.{id}` policy
  (`routes/channels.php:25-39`) — kiosk token (`kiosk:order`) restreint à
  `KioskMachine.branch_id`, admin wildcard, staff branche unique.
- **Fiscal** : `ZReportController::resolveBranchId` rejette en **422** un
  Admin non pinné à une branche (pas de Z déclenché par accident).

## ⏱️ 8. Kiosk — Idle Timeout

- Valeur canonique : **3 min** (`180 000 ms`).
- Modal "Still there?" : **2 min 30** (`150 000 ms`).
- Sans interaction dans la fenêtre : reset à l'écran d'accueil + purge cart.
- Timer **désactivé** sur : idle, payment, waiting, confirmation.

## 📦 9. Disponibilité par branche (P1) — *anciennement « Stock Management »*

> Cette section remplace l'ancienne note "Stock Management (not implemented)".

- **Tables** : `item_branch_availability` (`is_available bool DEFAULT TRUE`,
  `max_daily_qty NULL`, `daily_consumed_qty`, `daily_reset_at`,
  `unique(item_id, branch_id)`).
- **Garde serveur** :
  `App\Services\Menu\AvailabilityService::assertItemsOrderableForBranch`
  est appelée :
  - Soit via `PricingService::calculateOrder` (chemin SSOT par défaut).
  - Soit via la branche legacy non-SSOT (ceinture + bretelles).
  Couvre tous les chemins de production : Kiosk
  (`FrontendOrderService::myOrderStore`), POS
  (`OrderService::posOrderStore`), Table QR (`OrderService::tableOrderStore`).
  Sortie : `InvalidArgumentException` 422 « Article {item_id} indisponible
  pour cette branche ({reason}) ».
- **Toggle admin** : `POST /api/admin/menu/availability/toggle` →
  `AvailabilityController::toggle` (permission Spatie `items_edit`,
  `branch_id` du payload validé contre `resolveScopedBranchIds` du user).
- **Event** : `ItemAvailabilityChanged::forBranch` dispatché **après
  commit** (`DB::afterCommit`) → listener `PersistItemAvailabilityChangedToOutbox`
  pousse `DomainEvent` (canal `private-branch.{branch_id}`) puis
  `DispatchDomainEventsJob` (queue `high`).
- **Cache** : `InvalidateKioskMenuCacheOnItemAvailabilityChanged` purge
  `kiosk.menu.branch.{id}` (TTL 60 s). Garde serveur lit
  `item_branch_availability` en direct (non caché).
- **Front kiosk** : action Vuex `kioskCart/pruneUnavailableLines`
  (`resources/js/store/modules/kioskCart.js:337-352`) retire les lignes
  unavailable du panier en temps réel.
- **Front POS** : tuile grisée via handler `_onItemAvailabilityChanged`
  (`PosComponent.vue:1098-1122`). UX dégradée si une ligne déjà ajoutée
  bascule unavailable → 422 serveur sans highlight ligne (cycle
  `P13_POS_CartPrune_PosUX`).
- **Tests** :
  `tests/Feature/Menu/OrderRejectsUnavailableBranchItemTest.php` (Frontend
  uniquement à ce jour ; couverture POS/Table à étendre — cycle
  `P17_AvailabilityCoverage_PosTableE2E`).

> *Limite v1 :* `max_daily_qty` est posé en schéma mais l'enforcement
> quotidien (decrement `daily_consumed_qty`, reset `daily_reset_at`) est
> hors scope production routée — voir `plans/PLAN_P1_STOCK_SYNC_HANDOFF.md`.
```

### A.2 Diff minimal (alternative légère, si P11 préfère un changement chirurgical)

Patch unifié indicatif :

```diff
--- a/docs/BUSINESS_RULES.md
+++ b/docs/BUSINESS_RULES.md
@@ -54,7 +54,76 @@
 If no interaction within 3 min, the kiosk resets to idle screen and clears the cart.
 Timer is NOT started on: idle screen, payment, waiting, confirmation routes.
 
-## Stock Management (not implemented)
-FoodKing v1 does not track item stock levels. There is no `stock` column, no `is_available` flag, and no stock validation at order time.
-Planned for v2: `items.stock_quantity` (nullable integer), `items.track_stock` (boolean), validation in OrderService/FrontendOrderService before order creation.
+## Disponibilité par branche (P1, livré)
+
+- Table `item_branch_availability` (`is_available bool`, `max_daily_qty NULL`,
+  `daily_consumed_qty`, `daily_reset_at`, `unique(item_id, branch_id)`).
+- Garde serveur `AvailabilityService::assertItemsOrderableForBranch` (422
+  `Article {id} indisponible pour cette branche`) appelée par PricingService
+  + branche legacy sur Kiosk/POS/Table.
+- Toggle admin `POST /api/admin/menu/availability/toggle`
+  (permission `items_edit`, branch scope check).
+- Event `ItemAvailabilityChanged::forBranch` dispatché APRÈS commit, propagé
+  via outbox `DomainEvent` → canal `private-branch.{id}`.
+- Cache `kiosk.menu.branch.{id}` invalidé par listener dédié.
+- Front kiosk : action `kioskCart/pruneUnavailableLines` (Vuex).
+
+## Coupons (compléments §3)
+
+- `minimum_order` : 422 `coupon_minimum_order_amount` si subtotal < min.
+- `limit_per_user` : 422 `coupon_limit_exceeded` après N usages
+  (`OrderCoupon` count par `user_id` + `coupon_id`).
+- Plancher 0 : `PricingService.php:234` (`max(0,…)`).
+
+## RETURNED — contre-passation monétaire
+
+`OrderService::changeStatus(→ RETURNED)` :
+1. `reason` requis (`required|max:700`).
+2. Si `$order->transaction` → `PaymentService::cashBack($order,'credit',…)`.
+3. `LoyaltyService::refundPoints` : no-op si pas de `loyalty_customer_code`
+   ou pas de redeem antérieur.
+4. `AuditLogService::write('order.returned', …)` HMAC-chaîné par branche.
+5. Dispatch `OrderStatusChanged` après commit (Outbox).
+> ⚠️ **Idempotence à corriger** (cycle P11_RETURNED_IDEMPOTENCY) : double
+> appel rejoue cashBack + audit pour orders non-loyalty.
+
+## NF525 — Fiscalité française
+
+- Séquence fiscale par branche (`orders.fiscal_sequence_no`,
+  `FiscalSequenceService`, contraintes UNIQUE).
+- Z/X reports atomiques (`ZReportService`), permission `pos-manage-fiscal`.
+- `audit_logs` immuable (triggers DB MySQL+SQLite + `AuditLog::booted()`).
+- Hash chain : `AuditLogService::verifyChain($branchId)` retourne `null`
+  si la chaîne est intègre.
+- Limites connues : RETURNED post-Z fermé non bloqué (cycle
+  `P11_FISCAL_Z_OPEN_HARDENING`) ; export JET/PIAF officiel non livré
+  (cycle `P13_FISCAL_EXPORT_JET`).
+
+## SaaS multi-branche
+
+- `BranchScope` global sur `Order`, `FrontendOrder`, `DiningTable`,
+  `PushNotification`, `User`.
+- Admin `branch_id = 0` : wildcard cross-branche par design (tracé via
+  `ActionLog`). Staff `branch_id > 0` : restreint à sa branche.
+- Pusher canal `private-branch.{id}` policy
+  (`routes/channels.php:25-39`).
+- Fiscal : `ZReportController::resolveBranchId` impose un Admin pinné
+  (422 sinon).
```

> **Note :** la version A.1 (réécriture complète) est recommandée car elle
> renumérote proprement les sections, supprime la duplication avec
> `docs/ORDER_FLOW.md` et donne une structure stable pour les futurs
> ajouts. La version A.2 est le minimum vital si P11 doit livrer un patch
> court.

---

## 8. Conformité aux invariants FoodKing

| Invariant | Statut audit | Note |
|---|---|---|
| 1. Backend pricing SSOT | ✅ | Doc §1 + code (`PricingService`) alignés. |
| 2. `OrderStatus` enum SOT | ✅ | Doc §4 + `app/Enums/OrderStatus.php` exact. |
| 3. `branch_id` isolation | ✅ (code) / ⚠️ (doc) | Code conforme ; doc oublie `BranchScope` + `branch_id=0` Admin. |
| 4. Dispatch après DB commit | ✅ (code) / ⚠️ (doc) | Code conforme (`safety.mdc §5`) ; doc `BUSINESS_RULES.md` n'en parle pas. |
| 5. `OrderService` / `FrontendOrderService` symétrie | ✅ | Symétrie observée dans P1 et P3. |
| 6. Frozen zones | ✅ | Aucune édition de code dans ce cycle (AUDIT-ONLY strict). |

Aucune `SCOPE_PRESSURE` détectée. Aucune `ESCALATION` ouverte.

---

## 9. Conclusion

```
GLOBAL: FAIL
```

- **FAIL** : §Stock Management déclare l'inverse de la réalité (P1 livré).
  Critère §6 du task file : « FAIL si la doc induit en erreur sur P1/P3 ».
- **WARN** matériel : sections NF525 / SaaS / RETURNED-idempotence absentes.
- **PASS** sur §1, §2, §4 (pipeline), §Kiosk Idle Timeout, V1-V5 du task.

**Action immédiate (P0) :** ouvrir cycle `P11_BusinessRules_DocSync`
(Composer routine) avec le patch §A.1 (recommandé) ou §A.2 (minimum) ; en
parallèle, ouvrir `P11_RETURNED_IDEMPOTENCY` (GPT-5.4 complex) pour fixer
le bug code identifié par `VERIFY_03` V2.

---

*Fin du rapport VERIFY-20.*
