# AUDIT POS — Section 3/4 : Stock/86 + Fin de journée X/Z + Tiroir + Permissions Spatie + Dashboard + Conformité fiscale

**Date.** 2026-04-18
**Scope.** Sous-agent 3 du POS_MASTER_BRIEF (§3.3 → §3.7 + conformité §1.2 INVARIANTS).
**Mode.** Read-only strict. Aucun fichier modifié hors ce rapport.
**Repo.** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
**Recouvrement.** AUDIT_KIOSK_GLOBAL_2026-04-18.md §2 (stock/availability) + AUDIT_POS_BRANCH_ISOLATION_004 + AUDIT_POS_COUPON_LOYALTY_005 + AUDIT_POS_AMEND_ORDER_GAP_010.

---

## 1. Verdict synthèse par sous-domaine

| Sous-domaine | Verdict | Commentaire court |
| --- | --- | --- |
| Stock / 86 | **BLOCKED** | Moteur `AvailabilityService` existe mais **aucun endpoint admin/POS** pour toggle manuel, et **aucune libération de stock** sur CANCELED/REJECTED/RETURNED. Rupture auto par `max_daily_qty` seulement. |
| Fin de journée X/Z | **BLOCKED** (MISSING_FEATURE) | Aucun modèle `ZReport`/`XReport`, aucune migration, aucun controller, aucun hash chain, aucune numérotation séquentielle fiscale. `SalesReportController` fait un simple listing Excel/PDF. **Non-conformité NF525 / loi Finance 2018 anti-fraude TVA**. |
| Tiroir caisse | **BLOCKED** (MISSING_FEATURE) | Aucun modèle/table `cash_drawer`, aucun événement d'ouverture/fermeture, aucun comptage, aucun écart. Le kiosk hardware bridge expose `cashDrawerOpen` mais aucune trace persistée. |
| Permissions Spatie | **WARN-BLOCKED** | Couche Spatie installée, scopes branche OK, mais **matrice de 2 permissions POS seulement** (`pos`, `pos-orders`). Aucune perm `pos-cancel-after-paid`, `pos-refund`, `pos-discount-over-threshold`, `pos-amend`, `pos-reopen-z`, `pos-manage-drawer`, `pos-close-day`. Discount manuel **non gaté**. |
| Audit log immuable | **BLOCKED** | Table `action_logs` existe mais : **non-immuable** (Eloquent UPDATE/DELETE libre), **sans `branch_id`** (fuite cross-tenant via `DashboardService::auditTrail`), **sans hash chaîné**, **sans enum d'action**. La spec demande `audit_logs` INSERT-only signé. |
| Dashboard | **WARN** | Tuilage classique (CA, orders, SLA, audit trail). `BranchScope` global protège la majorité des requêtes, mais `DashboardService::auditTrail` ignore la scope (ActionLog n'a pas de `branch_id`). Pas de tuile uptime borne / alerte hardware / rupture en temps réel. |
| TVA / arrondis / devises | **WARN** | TVA fixée par `Item.tax_id` **sans cascade order_type** (dine-in 10 % vs takeaway 5.5 % vs alcool 20 % non codé). Stockage `decimal:6` au lieu de cents int. `round(…, 2)` PHP (HALF_AWAY_FROM_ZERO). **Mono-EUR en pratique** (pas de scoping branche × devise). Allergens **non exposés** dans `ItemResource` admin. |

**Résumé.** 4 domaines sur 7 en **BLOCKED**, dont 3 pour features entièrement absentes (Z fiscal, tiroir caisse, audit log immuable). C'est le **pire bloc de la Phase POS-A** : le POS ne peut pas être exploité en France sans Z NF525, et rien n'existe aujourd'hui dans le repo.

---

## 2. Audit stock & 86

### 2.1 Ce qui existe

- `app/Services/Menu/AvailabilityService.php:32-74` — `toggle($itemId, $branchId, $available, $reason)` transactionnel + lock + event `ItemAvailabilityChanged::forBranch`. Implémentation correcte en soi.
- `app/Services/Menu/AvailabilityService.php:118-163` — `decrementForOrder(Order $order)` appelé par `DecrementItemAvailabilityOnOrder` listener sur `OrderCreated`. Auto-86 quand `daily_consumed_qty >= max_daily_qty`. OK.
- `app/Listeners/DecrementItemAvailabilityOnOrder.php:1-25` — listener branché à `OrderCreated`.
- `app/Models/ItemBranchAvailability.php` — pivot avec `is_available`, `unavailable_reason`, `daily_consumed_qty`, `max_daily_qty`.
- `app/Http/Resources/NormalItemResource.php:35-49` — expose `allergens[]` pour kiosk.

### 2.2 Ce qui manque

| # | Manque | Evidence |
| --- | --- | --- |
| S-a | **Aucun endpoint admin/POS pour toggler un item 86 manuellement**. Seul chemin = auto-86 via compteur journalier. | `grep -rn AvailabilityService app/Http/Controllers/Admin → 0 hit`. Ticket déjà noté par kiosk audit C-7. |
| S-b | **Aucune libération de stock** sur CANCELED / REJECTED / RETURNED. `decrementForOrder` est one-way. | `grep -rn "incrementForOrder\|releaseStock\|IncrementItemAvailability" → 0 hit`. |
| S-c | Le modèle `Item` ne porte **pas de vraie quantité** (`stock_qty`). Tout repose sur `max_daily_qty` + compteur journalier. Pas de réapprovisionnement, pas de réception, pas d'audit d'inventaire. | `app/Models/Item.php:20-73` — pas de `stock_qty`. |
| S-d | `ItemResource` admin (POS standard) **n'expose pas** les allergens (seul le kiosk `NormalItemResource` le fait). | `app/Http/Resources/ItemResource.php:19-65` — aucun champ `allergens`. |
| S-e | Pas de notification « rupture mid-prep » du POS vers kiosk/web (feature mentionnée §3.3 du brief). | `grep -rn "RuptureNotification\|MidPrep" → 0 hit`. |
| S-f | Pas de UI admin de visualisation globale des articles 86 par branche (dashboard rupture). | `resources/js/components/admin/dashboard/` → aucune tuile "rupture". |

---

## 3. Audit fin de journée X/Z + conformité fiscale (critique)

### 3.1 État factuel

- `ls database/migrations/ | rg "z_report|x_report|fiscal|end_of_day|session_cash|shift" → 0 hit`.
- `ls app/Models/ | rg "ZReport|XReport|Shift|Session|Fiscal" → 0 hit`.
- `rg "NF525|hash_chain|prev_hash|fiscal_signature" app/ → 0 hit`.
- `app/Http/Controllers/Admin/SalesReportController.php:40-89` — expose simple listing `Order` + export Excel/PDF. **Aucune clôture**, **aucune irréversibilité**, **aucune signature**.
- `app/Services/OrderService.php:475,801` — `order_serial_no = date('dmy') . $id` → **non séquentiel per-branche**, trous possibles, pas un numéro fiscal.

### 3.2 Exigences NF525 / Loi Finance 2018 (décret 2017-1367) non satisfaites

| Exigence | Présent ? |
| --- | --- |
| Inaltérabilité des enregistrements | ❌ (soft delete autorisé partout) |
| Sécurisation : empreinte cryptographique chaînée | ❌ |
| Numérotation séquentielle des tickets par branche sans trou | ❌ (`order_serial_no` = `dmy . id`) |
| Conservation 6 ans | ❌ (pas d'archivage, soft delete) |
| Journal des événements fiscaux | ❌ |
| Clôture journalière Z + période + annuelle | ❌ |
| Ticket rectificatif / annulation horodaté signé | ❌ |

### 3.3 Conclusion

L'ensemble du domaine **fin de journée + X/Z + conformité fiscale française** est **intégralement absent**. C'est un blocage réglementaire majeur : aucune branche française ne peut être certifiée LNE / INFOCERT en l'état. Priorité P0 réglementaire.

---

## 4. Audit tiroir caisse

### 4.1 État factuel

- Aucune table `cash_drawer_sessions`, `cash_drawer_events`, `drawer_opens`.
- Aucun modèle `CashDrawer*`.
- Aucun event `DrawerOpened`, `CashCountingRecorded`, `DrawerDiscrepancy`.
- `resources/js/config/kioskHardware.js` mentionne `cashDrawerOpen` côté kiosk hardware bridge, mais **aucune persistance serveur** n'existe.
- Aucun module "comptage fin de journée" / "remise en banque" / "bordereau cash".

### 4.2 Conséquence

- Ouverture de tiroir non tracée (ni auto cash, ni ouverture manager).
- Motif d'ouverture non exigé.
- Écarts caisse **impossibles à mesurer**.
- **Zéro traçabilité opérationnelle**, qui est une exigence audit interne et LCB-FT (lutte anti-fraude).

---

## 5. Audit permissions Spatie (matrice rôles × actions + gaps)

### 5.1 Socle

- `app/Services/PermissionService.php` — mince wrapper Spatie, OK.
- `database/seeders/RoleTableSeeder.php:17-67` — 8 rôles seedés : Admin, Customer, Delivery Boy, Waiter, Chef, Branch Manager, POS Operator, Stuff.
- `database/seeders/PermissionTableSeeder.php` — 69 permissions seedées, dont **2 seulement POS** : `pos` (L113), `pos-orders` (L121).

### 5.2 Matrice actuelle (rôles × actions POS sensibles)

| Action POS | Permission requise | Admin | Branch Manager | POS Operator | Waiter | Chef | Stuff |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Créer commande POS | `pos-orders` (implicite via store) | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| Annuler commande (y c. après PAID) | `pos-orders` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| Supprimer commande (DELETE) | `pos-orders` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| Changer statut paiement | `pos-orders` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| Discount manuel caissier | **Aucune** (!) | open | open | open | open | open | open |
| Refund / rembour. monétaire | n/a (feature absente) | — | — | — | — | — | — |
| Amend order (ajout/retrait item) | n/a (feature absente) | — | — | — | — | — | — |
| Z fiscal / X / reopen Z | n/a (feature absente) | — | — | — | — | — | — |
| EOD / drawer close | n/a (feature absente) | — | — | — | — | — | — |
| Créer staff | `employees_create` / `administrators_create` | ✅ | ❌ (seed n'inclut pas create) | ❌ | ❌ | ❌ | ❌ |

Références :
- `app/Http/Controllers/Admin/PosOrderController.php:26-35` — `middleware('permission:pos-orders')->only(index, destroy, export, changeStatus, changePaymentStatus, selectDeliveryBoy, reorderItems)`.
- `app/Http/Controllers/Admin/PosController.php` — store POS.
- Discount manuel : `app/Services/Pricing/PricingService.php:213` + `app/Services/Pricing/DiscountCalculator.php` — aucune vérif rôle avant application.
- Routes POS : `routes/api.php:621-638` — aucun `->middleware('can:…')` ou `role:…`.

### 5.3 FormRequest authorize() audit

`grep` sur `app/Http/Requests/*.php` : **quasi tous** les FormRequest retournent `return true`. Notamment :
- `app/Http/Requests/PosOrderRequest.php:19-22` — `return true`.
- `app/Http/Requests/OrderRequest.php:19-22` — `return true`.
- `app/Http/Requests/OrderStatusRequest.php:15` — check `auth()->check()` seulement.
- `app/Http/Requests/PaymentStatusRequest.php:14` — check `auth()->check()` seulement.

Aucun `authorize()` ne vérifie une permission dédiée au niveau request.

### 5.4 Gaps majeurs

- Aucune granularité : **toute personne ayant `pos-orders` peut tout faire** sur commande.
- Aucun seuil discount (un POS Operator peut faire -100 % via `manual_discount` sans trace distincte).
- Pas de rôle "caissier" / "runner" / "kitchen-lead" distinct de POS Operator ; le brief `POS_MASTER_BRIEF.md:98` attend ces 3 rôles.
- Aucun rôle "super-admin" séparé (tout repose sur `branch_id=0`).
- Cancel après PAID non bloqué par permission → `OrderStateMachine::apply` autorise la transition si règles enum OK.

---

## 6. Audit `audit_logs`

### 6.1 État factuel

- Table **`audit_logs` INEXISTANTE**. Seule la table `action_logs` existe : `database/migrations/2026_03_06_182733_create_action_logs_table.php`.
- `app/Models/ActionLog.php:8-22` — Model Eloquent standard : `use HasFactory`, **aucun garde immuabilité** (pas de `static::updating` blocker, pas de `static::deleting` blocker). UPDATE et DELETE libres.
- Colonnes : `user_id`, `action` (string libre), `resource` (string libre), `details` (text), `created_at/updated_at`.
- **`branch_id` absent** → `DashboardService::auditTrail()` (L305-326) renvoie TOUS les logs globaux à toute personne ayant la permission `dashboard`. Fuite cross-tenant garantie en multi-branches (sauf super-admin).
- Pas de signature, pas de hash chaîné, pas d'enum d'action, pas de `session_id` / `ip` / `user_agent`.

### 6.2 Usages constatés (`app/Services/OrderService.php`)

- L508 — `'Nouvelle commande Web/App'`.
- L887 — `'Nouvelle commande POS'`.
- L1189 — `'Nouvelle commande sur Table'`.
- L1451 — `'Changement de statut'`.
- L1508 — `'Statut paiement modifié'`.
- Rien sur discount manuel, rien sur refund, rien sur cancel spécifique (juste la transition générique).

### 6.3 Verdict

Le `action_logs` actuel est un **log applicatif lisible**, pas un journal fiscal. Pour NF525 et audit interne, il faut **une vraie table `audit_logs`** avec INSERT-only enforcement (`booted::updating → abort`, `booted::deleting → abort`), `branch_id`, `signature_hmac`, `prev_hash`.

---

## 7. Audit Dashboard

### 7.1 Périmètre actuel

Tuiles (`resources/js/components/admin/dashboard/`) :

- `DashboardComponent.vue` — conteneur.
- `OrderStatisticsComponent.vue`, `OrderSummaryComponent.vue`, `SalesSummaryComponent.vue` → `DashboardService::orderStatistics/orderSummary/salesSummary` (L23-148).
- `FeaturedItemsComponent.vue`, `MostPopularItemsComponent.vue`, `TopCustomersComponent.vue`, `ChannelStatsComponent.vue` — top produits, top clients, split canaux (Web/Kiosk/POS).
- `RealtimeReportComponent.vue`, `SlaAlertsComponent.vue` — suivi temps réel + alertes SLA (uniquement orders, pas hardware).
- `AuditTrailComponent.vue` — lit `DashboardService::auditTrail()` (L305-326). **Faille : ActionLog sans branch_id.**

### 7.2 Branch scoping

- `BranchScope` global sur `Order`, `DiningTable`, `User`, `PushNotification`, `FrontendOrder` → toutes les requêtes Dashboard via `Order::…` sont scopées automatiquement pour staff non-admin (`BranchScope.php:27-39`).
- `Item::where(...)` aussi OK via le global scope.
- **Exception : `ActionLog`** — n'a ni `branch_id` ni global scope → `auditTrail()` transverse.

### 7.3 Manques fonctionnels (vs §3.5 brief)

| Tuile attendue | Présent ? |
| --- | --- |
| CA jour avec comparaison J-1 / J-7 / J-30 | ❌ (juste `salesSummary` mensuel) |
| Ticket moyen / panier moyen | ❌ |
| Taux de conversion kiosk | ❌ |
| Top 10 produits jour | ⚠️ (MostPopularItems mais sans fenêtre glissante) |
| Alertes rupture (stock 86) | ❌ |
| Alertes hardware (imprimante/TPE/tiroir) | ❌ (hardware stub kiosk seulement) |
| Uptime borne(s) | ❌ |

---

## 8. Audit TVA / arrondis / multi-devise

### 8.1 TVA

- `app/Services/TaxService.php:54-75` — CRUD standard, pas de lien order_type.
- `app/Services/Pricing/TaxCalculator.php:9-17` — `lineTaxAmount()` applique `taxRate` (percentage ou fixed) sur le line subtotal. **Ne prend pas `order_type` en paramètre** → pas de cascade dine-in 10 % / takeaway 5.5 % / alcool 20 %.
- `app/Services/Pricing/PricingService.php:141-152` — utilise `Item.tax_id` en dur. Variations et extras **héritent du même tax_id que l'item parent** (un seul `tax_rate` par ligne `OrderItem`).
- `app/Enums/TaxType.php` — `PERCENTAGE` / `FIXED` uniquement.

**Conséquence.** Un burger dine-in et takeaway porteront exactement le même taux de TVA côté backend. La règle métier française (10 % resto sur place, 5,5 % emporter) **n'est pas codée**. C'est un risque fiscal direct si le restaurant déclare en s'appuyant sur l'export.

### 8.2 Arrondis

- `PricingService.php:136-197` — `round($value, 2)` partout. PHP `round()` par défaut = `PHP_ROUND_HALF_UP` (half away from zero), ce qui **colle** à l'exigence DGCCRF si tous les montants sont positifs. Pas documenté.
- **Money stocké en `decimal:6`** (`app/Models/Order.php:59-63`, `app/Models/Transaction.php:11-18`) — pas en cents int. Risque de dérive sur des opérations multi-étapes.

### 8.3 Multi-devise

- `app/Models/Currency.php:10` — `name, symbol, code, is_cryptocurrency, exchange_rate`.
- `app/Enums/CurrencyPosition.php` — position affichage.
- **Aucune FK `branch.currency_id`**, aucun `Order.currency_code`. Toute l'application utilise `AppLibrary::currencyAmountFormat` qui lit la config globale.
- **Mono-EUR** en pratique. Un déploiement multi-pays (GBP, MAD, CHF) nécessitera une réarchitecture.

### 8.4 Allergens visibles caissier

- `app/Http/Resources/ItemResource.php:19-65` — pas de clé `allergens`. Le caissier POS ne peut pas conseiller un client sur les allergènes depuis la fiche produit POS. **Contradiction directe avec l'invariant §1.2 POS_INVARIANTS "Allergens visibles caissier"**.

---

## 9. Findings priorisés

### POS-P3-F-1 — MISSING Z fiscal (NF525 / Loi Finance 2018) — P0
- **file:line.** (absent) — ni `app/Models/ZReport.php`, ni `database/migrations/*z_report*`, ni `app/Http/Controllers/Admin/ZReportController.php`.
- **description.** Aucune fonctionnalité de clôture journalière fiscale : pas de numérotation séquentielle par branche, pas de hash chaîné HMAC, pas d'archivage 6 ans, pas de signature, pas d'export certifiable LNE/INFOCERT. `SalesReportController` ne remplit aucun de ces rôles.
- **impact.** Non-conformité NF525 / loi 2017-1367. Exploitation impossible en France sans régularisation. Risque d'amende (7 500 €/an/caisse).
- **fix_proposal.** Créer migration `z_reports` (`id`, `branch_id`, `sequence_no`, `period_start`, `period_end`, `totals_json`, `vat_breakdown_json`, `prev_hash`, `signature_hmac`, `created_at`). Model INSERT-only (`booted::updating/deleting → abort`). Service `ZReportService::close($branchId)` avec lock + signature chaînée. Controller + route `POST /admin/z-report/close`. Permission dédiée `pos-close-z`. Export PDF signé.
- **invariants touchés.** §1.2 Z fiscal, audit log immuable.
- **resurface_from.** POS_INVARIANTS_AND_GATES.md:24-28.

### POS-P3-F-2 — MISSING X report (lecture seule intraday) — P0
- **file:line.** (absent).
- **description.** Aucun rapport X (snapshot intraday non-clôturant) : CA, nb transactions, par mode de paiement, par staff, par heure. Le manager n'a pas de vision en cours de journée avant Z.
- **impact.** Exploitation opérationnelle dégradée. Pas de pilotage en cours de service.
- **fix_proposal.** Endpoint `GET /admin/x-report?branch=&from=&to=` + service agrégeant `Order` + `Transaction` sur la fenêtre courante, ventilation `payment_method`, `status`, `staff_id`, buckets horaires. Pas de persistance (read-only).
- **invariants touchés.** §3.4 brief.
- **resurface_from.** POS_MASTER_BRIEF.md §3.4.

### POS-P3-F-3 — MISSING tiroir caisse (session + comptage + écarts) — P0
- **file:line.** (absent) — pas de `cash_drawer_sessions` migration, pas de model, pas d'event.
- **description.** Aucune table de session caisse (open_time, staff, opening_float, close_time, expected, counted, discrepancy, motif). Le kiosk hardware expose `cashDrawerOpen` mais rien n'est persisté.
- **impact.** Aucune traçabilité cash, fraude interne impossible à détecter, LCB-FT non couverte.
- **fix_proposal.** Migrations `cash_drawer_sessions` + `cash_drawer_events` (open/close/manual_open avec motif). Events `DrawerOpened`, `DrawerClosed`. Permission `pos-manage-drawer`. UI POS counting screen.
- **invariants touchés.** §1.2 Tiroir-caisse.
- **resurface_from.** POS_INVARIANTS_AND_GATES.md:29.

### POS-P3-F-4 — MISSING audit_logs immuable — P0
- **file:line.** `app/Models/ActionLog.php:1-23` + `database/migrations/2026_03_06_182733_create_action_logs_table.php:15-22`.
- **description.** Table `action_logs` existe mais (a) **mutable** (pas de garde updating/deleting), (b) **sans `branch_id`** (fuite cross-tenant via `DashboardService::auditTrail` L305-326), (c) **sans signature HMAC chaînée**, (d) **sans enum d'action** (strings libres).
- **impact.** Un administrateur malveillant peut éditer/effacer un log après coup. En multi-branches, un Branch Manager accédant à la tuile AuditTrail lit les logs des autres branches.
- **fix_proposal.** Créer migration `audit_logs` (INSERT-only via `booted` gate + `static::updating(fn → abort)`). Colonnes : `id`, `branch_id`, `user_id`, `action_type` (enum), `target_type`, `target_id`, `payload_json`, `prev_hash`, `signature_hmac`, `ip`, `user_agent`, `session_id`, `occurred_at`. Remplacer tous les `ActionLog::create` par `AuditLog::write()`. Ajouter `BranchScope`.
- **invariants touchés.** §1.2 Audit log immuable + branch_id isolation.
- **resurface_from.** POS_INVARIANTS_AND_GATES.md:22-23, AUDIT_POS_BRANCH_ISOLATION_004.

### POS-P3-F-5 — MISSING stock release sur CANCELED/REJECTED/RETURNED — P0
- **file:line.** `app/Services/Menu/AvailabilityService.php:118-163` (décrément unique) + `app/Services/OrderService.php:1385,1435` (loyalty only, stock non réhydraté).
- **description.** `decrementForOrder` consomme le compteur journalier sur `OrderCreated`, mais **aucun listener** n'incrémente quand la commande passe CANCELED/REJECTED/RETURNED. `grep -rn "incrementForOrder|releaseStock" → 0 hit`.
- **impact.** Sur une journée avec beaucoup d'annulations, un item passe à 86 faussement → refus de vente, CA perdu.
- **fix_proposal.** Listener `ReleaseItemAvailabilityOnCanceledOrder` branché à `OrderStatusChanged` quand newStatus ∈ {CANCELED, REJECTED, RETURNED}. Décrémente `daily_consumed_qty`, réactive `is_available` si `< max_daily_qty`, dispatch `ItemAvailabilityChanged` si flip.
- **invariants touchés.** Stock tracking cohérent, kiosk audit C-7 proche.
- **resurface_from.** AUDIT_KIOSK_GLOBAL_2026-04-18.md §2 C-7.

### POS-P3-F-6 — Discount manuel sans permission ni seuil ni audit dédié — P0
- **file:line.** `app/Services/Pricing/PricingService.php:213-217` + `app/Services/Pricing/DiscountCalculator.php`.
- **description.** `manualDiscount` s'applique pour tout contexte ∈ {pos, table}. Aucune vérif rôle, aucun plafond (on peut passer -100 %). Le log `ActionLog` mélange discount dans `details` (L881-892), pas d'entrée discret.
- **impact.** Fraude caissier triviale : discount 90 % sur chaque commande cash → écart tiroir en fin journée.
- **fix_proposal.** Créer permission `pos-discount-up-to-10`, `pos-discount-over-10-requires-manager`. Ajouter `authorize()` dans `PosOrderRequest` + check serveur dans `PricingService`. Enregistrer un `AuditLog` dédié `DiscountApplied` avec montant + motif obligatoire.
- **invariants touchés.** §1.2 Permissions Spatie avant action sensible.

### POS-P3-F-7 — MISSING endpoint admin toggle 86 manuel — P0
- **file:line.** `app/Services/Menu/AvailabilityService.php:32-74` (existe, jamais exposé).
- **description.** `AvailabilityService::toggle()` implémenté et testé (tests/Feature/Menu/AvailabilityServiceTest.php), mais aucun controller/route ne l'expose. `grep -rn AvailabilityService app/Http → 0 hit`.
- **impact.** Un manager ne peut pas mettre un plat en rupture depuis l'UI. Doit passer par tinker.
- **fix_proposal.** Controller `AvailabilityController` avec `POST /admin/items/{item}/availability` `{is_available: bool, reason: string}`. Permission `items-manage-availability`. UI : bouton 86 sur POS item tile + admin item list.
- **invariants touchés.** UX opérationnelle de base.
- **resurface_from.** AUDIT_KIOSK_GLOBAL_2026-04-18.md §2 C-7.

### POS-P3-F-8 — MISSING multi-tender (cash + card + TR sur la même commande) — P1
- **file:line.** `app/Models/Transaction.php:10` — `order_id, transaction_no, amount, payment_method, type, sign`. Aucune contrainte "sum(payments) = order.total".
- **description.** `grep -rn "multi.tender|split_payment|partial_payment" → 0 hit`. Un paiement = un `Transaction` = un `payment_method`. Le modèle permet en théorie N records liés à la même order, mais aucune orchestration service + UI.
- **impact.** Impossible d'encaisser 30 € cash + 20 € ticket resto + 50 € carte. Client se voit refusé ou le caissier contourne par un seul mode → fausse déclaration fiscale.
- **fix_proposal.** Service `PaymentService::registerTender($orderId, $method, $amount, $ref)` avec SQL check `SUM(transactions.amount WHERE order_id=X) <= order.total`. UI multi-tender dans `PosPaymentComponent`.
- **invariants touchés.** §1.2 Multi-tenders invariant.

### POS-P3-F-9 — MISSING amend order (add/remove item post-création) — P1
- **file:line.** `app/Services/OrderService.php` — `grep amendOrder|addItem|removeItem → 0 hit`. `app/Domain/Events/EventContract.php:37` déclare `OrderItemAdded` mais aucune classe d'event ne l'émet.
- **description.** Aucun endpoint `PATCH /pos-order/{order}/items`. Le brief AUDIT_POS_AMEND_ORDER_GAP_010 acte ce gap.
- **impact.** Erreur caisse = supprimer + recréer (rompt queue number + KDS).
- **fix_proposal.** Voir AUDIT_POS_AMEND_ORDER_GAP_010. Endpoint `PATCH`, bloqué après `PREPARED`, recalcul SSOT, event `OrderItemAdded`/`OrderItemRemoved`, audit log, permission `pos-amend-order`.
- **invariants touchés.** §1.2 Amend order.
- **resurface_from.** AUDIT_POS_AMEND_ORDER_GAP_010.

### POS-P3-F-10 — TVA sans cascade par order_type — P1
- **file:line.** `app/Services/Pricing/PricingService.php:141-152`, `app/Services/Pricing/TaxCalculator.php:9-17`.
- **description.** `taxRate` lu depuis `Item.tax_id` uniquement. Aucun paramètre `orderType` dans `TaxCalculator::lineTaxAmount`. Un burger dine-in (10 %) et takeaway (5,5 %) portent la même TVA.
- **impact.** Déclaration TVA fausse pour tout restaurant français proposant emporter.
- **fix_proposal.** Étendre `Tax` avec colonnes `rate_dine_in`, `rate_takeaway`, `rate_delivery`, ou créer table `item_tax_rate` pivot par `order_type`. Passer `orderType` dans `PricingRequest` → `TaxCalculator`. Règles alcool = 20 % quelle que soit la destination.
- **invariants touchés.** §1.2 TVA cascade.
- **resurface_from.** POS_MASTER_BRIEF.md §3.6.

### POS-P3-F-11 — Allergens absents d'`ItemResource` admin (cassier cécité) — P1
- **file:line.** `app/Http/Resources/ItemResource.php:19-65`.
- **description.** Seule `NormalItemResource` (kiosk) expose `allergens`. L'UI POS charge `ItemResource` classique → le caissier n'a aucune info allergène sur la fiche produit.
- **impact.** Risque sanitaire + non-respect règlement UE 1169/2011 sur information consommateur.
- **fix_proposal.** Ajouter `allergens` dans `ItemResource::toArray` (même pattern que `NormalItemResource`), avec eager load `with('allergens')` côté `ItemService::list`.
- **invariants touchés.** §1.2 Allergens visibles caissier.
- **resurface_from.** POS_INVARIANTS_AND_GATES.md:34.

### POS-P3-F-12 — order_serial_no non séquentiel per-branche, non fiscal — P1
- **file:line.** `app/Services/OrderService.php:475,801,...` — `date('dmy') . $this->order->id`.
- **description.** Utilise l'ID global en suffixe. Trous inévitables (commandes d'autres branches incrémentent l'ID). Pas utilisable comme numéro de ticket fiscal NF525 sans trou.
- **impact.** Même une fois ZReport ajouté, la numérotation ticket restera non-conforme.
- **fix_proposal.** Compteur `order_sequence_no` atomique par branche via `Cache::lock('branch:{id}:order_seq')` (pattern déjà utilisé pour `queue_number`). Format `YYMMDD-{branch_code}-{NNNNNN}`.
- **invariants touchés.** §1.2 Z fiscal numérotation séquentielle.

### POS-P3-F-13 — `action_logs.branch_id` absent → fuite cross-tenant dashboard — P1
- **file:line.** `database/migrations/2026_03_06_182733_create_action_logs_table.php:15-22` + `app/Services/DashboardService.php:305-326`.
- **description.** `ActionLog` n'a pas de `branch_id`. `DashboardService::auditTrail` renvoie `ActionLog::with('user')->limit(50)` sans aucun filtre. Un Branch Manager avec permission `dashboard` lit l'activité des autres branches.
- **impact.** Fuite cross-tenant P1 sécurité/RGPD (noms staff, ids commandes, montants).
- **fix_proposal.** Migration additive `action_logs.branch_id` (nullable pour legacy, NOT NULL à terme) + backfill via `users.branch_id`. Ajouter `BranchScope` sur `ActionLog`. Filtrer explicitement dans `auditTrail()`.
- **invariants touchés.** §1.2 branch_id data isolation.
- **resurface_from.** AUDIT_POS_BRANCH_ISOLATION_004.

### POS-P3-F-14 — Rôles "caissier / runner / kitchen-lead" absents du seed — P2
- **file:line.** `database/seeders/RoleTableSeeder.php:17-67`.
- **description.** Le brief exige rôles : super-admin, admin branche, manager, **caissier**, **runner**, **kitchen-lead**. Le seed livre : Admin, Customer, Delivery Boy, Waiter, Chef, Branch Manager, POS Operator, Stuff. Caissier ≈ POS Operator, mais runner et kitchen-lead sont absents.
- **impact.** Impossible de distinguer caissier d'un "runner" en salle ; permissions communes à tous les staff "Stuff".
- **fix_proposal.** Ajouter rôles `Runner` et `Kitchen Lead` au seed. Mapper permissions granulaires KDS/OSS/POS.
- **invariants touchés.** §3.7 brief.

### POS-P3-F-15 — Money en `decimal:6` (float-like) au lieu de cents int — P2
- **file:line.** `app/Models/Order.php:59-63`, `app/Models/Transaction.php:11-18`, `app/Services/Pricing/PricingService.php` partout (usage `float`).
- **description.** Toute la chaîne manipule des `float` arrondis à la fin. Cents int non utilisés. Le brief §1.2 "Arrondis" demande stockage cents int.
- **impact.** Dérive possible sur multi-lignes / multi-étapes (discount puis TVA puis coupon). Risques contestation comptable.
- **fix_proposal.** Plan séparé de migration `decimal → int cents` (lourd). Short-term : figer les invariants `round(, 2)` à chaque frontière service + tests property-based.
- **invariants touchés.** §1.2 Arrondis.
- **resurface_from.** AUDIT_KIOSK_GLOBAL_2026-04-18.md §2 (unit drift `base_price_cents` vs `price`).

### POS-P3-F-16 — Aucune permission granulaire PermissionTableSeeder POS — P2
- **file:line.** `database/seeders/PermissionTableSeeder.php:113-125` (uniquement `pos` + `pos-orders`).
- **description.** Aucune des permissions attendues par le brief n'existe : `pos-cancel-after-paid`, `pos-refund`, `pos-discount-up-to-X`, `pos-amend-order`, `pos-reopen-z`, `pos-close-day`, `pos-manage-drawer`, `items-manage-availability`.
- **impact.** Aucune matrice de responsabilité métier applicable tant que les permissions n'existent pas.
- **fix_proposal.** Étendre `PermissionTableSeeder` + `RolePermissionTableSeeder` pour mapper rôles × nouvelles permissions. Livré en parallèle de findings F-1, F-3, F-6.

### POS-P3-F-17 — Dashboard sans tuile rupture / hardware / uptime — P2
- **file:line.** `resources/js/components/admin/dashboard/*` — composants listés dans §7.1.
- **description.** Les tuiles attendues par §3.5 brief (rupture temps réel, alerte imprimante/TPE/tiroir, uptime borne) ne sont pas livrées.
- **impact.** Le manager n'a pas de vision opérationnelle en cours de service.
- **fix_proposal.** Tuile `RuptureAlertsComponent` sur événements `ItemAvailabilityChanged`. Tuile `HardwareHealthComponent` sur `kioskHardware.healthcheck`. Backend `POST /frontend/kiosk/healthcheck` persisté + agrégé.

### POS-P3-F-18 — PosOrderController::destroy efface physiquement commande — P2
- **file:line.** `app/Http/Controllers/Admin/PosOrderController.php:59-68` + `routes/api.php:628`.
- **description.** `DELETE /admin/pos-order/{order}` → `$this->orderService->destroy($order)`. En NF525, une commande encaissée ne doit pas pouvoir être effacée, seulement annulée par ticket rectificatif.
- **impact.** Non-conformité fiscale + perte de traçabilité.
- **fix_proposal.** Remplacer `destroy` par `cancelWithReceipt` qui écrit un ticket rectificatif signé et ne supprime rien. Bloquer route `DELETE` côté serveur (HTTP 405 + message).
- **invariants touchés.** §1.2 Z fiscal inaltérabilité.

### POS-P3-F-19 — Multi-devise non scopé branche — P3
- **file:line.** `app/Models/Currency.php:10`, aucune FK `branches.currency_id`.
- **description.** La table `currencies` existe avec `exchange_rate` mais aucun Order ne porte `currency_code`. `AppLibrary::currencyAmountFormat` lit une config globale.
- **impact.** Multi-pays impossible (FR EUR / UK GBP / MA MAD). Acceptable en V1 si scope marché = FR. À tracker.
- **fix_proposal.** `branches.currency_id` FK + `orders.currency_code` snapshot + affichage branche-aware. Lourd, V2+.

### POS-P3-F-20 — Allergens double source (JSON + pivot) — P3
- **file:line.** `app/Models/Item.php:43,71` (`allergen_flags` JSON) + pivot `item_allergen` utilisé par `NormalItemResource`.
- **description.** Deux sources de vérité sans listener de synchro. `AllergenService::projectFlags` cité en migration mais jamais créé.
- **impact.** Admin modifie JSON → pivot obsolète (ou inversement).
- **fix_proposal.** Choisir 1 source (pivot préféré pour i18n) + listener sync + dépréciation `allergen_flags`.
- **resurface_from.** AUDIT_KIOSK_GLOBAL_2026-04-18.md §2 C-5.

---

## 10. Check-list invariants §1.2 POS_INVARIANTS (1 réponse par ligne)

- [x] Permissions Spatie avant action sensible → **NON** (F-6, F-16).
- [x] Audit log immuable (INSERT only) → **NON** (F-4).
- [x] Z fiscal NF525 / loi Finance 2018 → **NON** (F-1).
- [x] Tiroir-caisse ouverture loggée (staff, motif, timestamp) → **NON** (F-3).
- [x] Multi-tenders (N payments == total) → **NON** (F-8).
- [x] Idempotency `X-Idempotency-Key` scoped `(branch_id, key)` → oui (hors scope sous-agent 3, hérité d'AUDIT_POS_IDEMPOTENCY_RETRIES_007).
- [x] Refund partiel avec motif + permission + log → **NON** (feature absente).
- [x] Amend order autorisé avant DELIVERED + event + audit → **NON** (F-9).
- [x] Allergens visibles caissier → **NON** (F-11).
- [x] TVA cascade par order_type → **NON** (F-10).
- [x] Arrondis cents int → **NON partiel** (F-15).

---

## 11. Matrice recouvrement avec kiosk (signal pour Track A)

| Finding POS | Touche zone shared kiosk | Recommandation inter-track |
| --- | --- | --- |
| POS-P3-F-5 (release stock on cancel) | `AvailabilityService` + `kioskMenu` | Phase kiosk P9.x `UPDATE_ITEM` ignore `is_available` (C-2) — solution commune. |
| POS-P3-F-7 (toggle 86 endpoint) | Kiosk C-7 identique | Résoudre une fois (controller unique + invalidation cache `/menu`). |
| POS-P3-F-11 (allergens ItemResource) | Kiosk NormalItemResource déjà OK | Copier pattern vers `ItemResource` admin. |
| POS-P3-F-20 (double source allergens) | Kiosk C-5 | Même refactor (single source). |
| POS-P3-F-15 (cents int) | Unit drift `base_price_cents` kiosk | Travail commun en V2. |
| POS-P3-F-4 (audit_logs immuable) | Kiosk AdminOverrideAuditTest lit déjà ActionLog | Migration `ActionLog → AuditLog` coordonnée. |

---

## 12. Top 5 findings (résumé exécutif)

1. **POS-P3-F-1 / P0** — Z fiscal NF525 **totalement absent** (pas de migration, modèle, controller, hash chain). Blocage réglementaire.
2. **POS-P3-F-3 / P0** — Tiroir caisse **totalement absent** (ni session, ni comptage, ni écart). LCB-FT impossible.
3. **POS-P3-F-4 / P0** — `audit_logs` immuable **absent**. `action_logs` existant mutable + sans `branch_id` → fuite cross-tenant via `DashboardService::auditTrail`.
4. **POS-P3-F-5 / P0** — Stock **non libéré** sur CANCELED/REJECTED/RETURNED → risque auto-86 fantôme en fin de journée.
5. **POS-P3-F-6 / P0** — Discount manuel **sans permission ni plafond ni audit discret** → fraude caissier triviale.

Les 3 premiers (F-1, F-3, F-4) sont des **MISSING_FEATURE régulatoires** P0 : ils doivent être tranchés en Phase POS-B comme vagues dédiées (estimation ≥ 3 j-h chacune) avant toute autre feature. F-5 et F-6 sont livrables en quelques heures chacun.
