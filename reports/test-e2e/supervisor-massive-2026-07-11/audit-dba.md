# Audit DBA / Intégrité Données — FoodKing V1 Le Cayenne

- **Date** : 2026-07-11
- **DB** : `foodking_e2e` (MySQL live, lecture seule)
- **Volumes** : orders=3203 (2865 non-supprimées, 338 soft-deleted), order_items=3276, order_payments=259, domain_events=10182, audit_logs=4943, z_reports=25
- **Mode prix** : `pricing.tax_inclusive_prices=true` (TTC) → invariant `total == subtotal + delivery_charge - discount` (taxe DANS le subtotal)
- **Méthode** : croisement DB réelle via tinker read-only. Fenêtre « récente » = `created_at >= 2026-07-01` (logique courante) vs legacy.

---

## VERDICT

**Logique COURANTE saine.** Sur la fenêtre récente (>=2026-07-01, 153 commandes) :
- formule `total` : **0 écart**
- somme paiements == total : **0 écart** (258/258)
- FK orphelins : **0**
- chaînes NF525 : **CLEAN** sur 4 branches, 8/8 triggers présents

Défauts réels trouvés : **1 P2 courant isolé (5501, boisson non facturée)** + **1 P2 NF525 résidu (5399, commande fiscale sans lignes)**. Le reste (59 + 78 + gaps + statuts hors-enum + outbox) est **débris seed/test pré-2026-07-01**, non-bloquant, documenté ci-dessous.

---

## 1. COHÉRENCE MONEY

### 1.1 `total == subtotal + delivery - discount` (TTC)
- **59 écarts** au total, **tous legacy** (2026-05-28 → 2026-06-19). **Récent (>=07-01) : 0.**
- Signature : `subtotal=15.00 → total=16.50` = +10 % ajouté par-dessus = **ancien mode HT** avant bascule TTC. Débris de migration config. Non-bloquant.

### 1.2 `SUM(order_items.total_price) == subtotal`
- **24 écarts**, dont **23 legacy** (surtout id 214-221, itemsum=0 vs subtotal=2.00, seed).
- **1 RÉCENT = DÉFAUT RÉEL → `order 5501` (P2)** :
  - 2 lignes persistées : `item 5231` = Tacos M (item_id 26, 6.90) + `item 5232` = **Coca-Cola 33cl** (item_id 52, **1.90**) → somme lignes **8.80**
  - `orders.subtotal = orders.total = 6.90`, `total_tax=0.63` (lignes=0.80)
  - Les 2 lignes créées à `2026-07-06 17:38:32` (même instant que la commande) → la boisson n'a **jamais** été intégrée au total. **Client sous-facturé de 1.90 €** (manque à encaisser).
  - Type=10 (TAKEAWAY), src=5. Isolé (1/153 récentes) → probable bug du chemin « ajout ligne / upsell à une commande existante » qui ne recalcule pas `subtotal/total/total_tax` via `PricingService`.
  - **Fix** : recalculer les totaux backend après tout ajout de ligne (SSOT `PricingService::calculateOrder`) ; ajouter un test de non-régression `sum(order_items.total_price) == subtotal` sur le chemin upsell.

### 1.3 `SUM(order_payments.amount) == total` (commandes avec paiements)
- **0 écart** sur les 258 commandes portant >=1 ligne de paiement. **PARFAIT.** ✓

### 1.4 `SUM(order_items.tax_amount) == orders.total_tax`
- 3 écarts : id 4225 / 4559 (legacy, ±0.18) + **5501** (0.63 vs 0.80, corollaire du 1.2).

---

## 2. ORPHELINS / FK

- `order_items` sans commande parente (ligne inexistante) : **0** ✓
- `order_payments` sans commande parente : **0** ✓
- `order_payments` dont le parent est soft-deleted : **0** (somme 0.00) ✓
- **322 `order_items` LIVE dont le parent est soft-deleted** : normal (soft-delete Laravel ne cascade pas). **Non-fuyant** : toutes les surfaces client passent par le modèle `Order` (scope SoftDeletes actif) ; aucun chemin client ne requête `order_items` en direct (les seuls `DB::table('order_items')` = release stock par id, ZReport borné au set scoped, commandes console). Voir §5.
- `domain_events` : aggregate_type incohérent (`App\Models\Order`=4488 **et** `Order`=23 ; `App\Models\FrontendOrder`=4540 alors que la table `frontend_orders` n'existe pas — events historiques d'un modèle fusionné). Cosmétique, pas d'orphelin bloquant.

---

## 3. NF525 INTÉGRITÉ

- `fiscal:assert-chain-clean` → **OK** (4 branches)
- `fiscal:verify-chain --all` → **CHAIN OK** audit_logs + z_reports, branches 1/7/8/9
- `fiscal:verify-immutability-triggers` → **8/8 présents**
- Doublons `(branch, fiscal_sequence_no)` : **0** ✓
- `fiscal_alloc_error_at` positionné : **0** ✓
- **Gap séquence branche 1 : `2506, 2507, 2508` (3 numéros)** entre order 4974 (seq 2505, 2026-06-19) et order 5019 (seq 2509, 2026-06-20).
  - Allocation = `MAX(fiscal_sequence_no)+1` **incluant soft-deleted** (`FiscalSequenceService:100`). Un gap ne peut donc venir que de commandes **HARD-deletées** → 3 commandes de test purgées (cf. purge « 186 cmd test » BRAIN). Séquence reste monotone (MAX+1 ne réutilise pas), chaîne HMAC intacte.
  - **Sévérité LOW (débris test)** MAIS invariant prod : le purge/cleanup ne doit JAMAIS hard-delete une commande porteuse de `fiscal_sequence_no` (rétention 6 ans NF525). `CleanupTestFixturesCommand` à border en prod.

---

## 4. N+1 / INDEX (hot paths)

Tous les chemins chauds sont **eager-loaded et sargable** — les fixes annoncés sont bien en place :
- `OrderStatusScreenOrderService::list/listForBranch` : range query sur `order_datetime` (`>= staleFloor` / `< tomorrow`, **sargable**, utilise `idx_orders_datetime`), pas de `whereDate`. Retourne des `Order` (items chargés par la resource). ✓
- `KitchenDisplaySystemOrderService` : `Order::with(['orderItems','address','user'])` + range query + `limit(51)`. **Pas de N+1.** ✓
- `OrderHistoryController → OrderService::list` : `with('orderItems.orderItem.media/.category','transaction','branch','user')`. **Eager.** ✓
- Index présents : `idx_orders_datetime`, `idx_orders_branch_status`, `idx_orders_status`, `idx_orders_status_updated`, `orders_branch_business_date_queue_unique`, `idx_orders_branch_payment`. Couverture correcte des filtres (branch/status/datetime). ✓

**Aucun N+1 flagrant ni requête non-sargable détecté sur les hot paths.**

---

## 5. SOFT-DELETE LEAKS

- `Order`, `OrderItem`, `Item` : tous `use SoftDeletes` + `BranchScope`. ✓
- **KDS / OSS / historique** partent tous du modèle `Order` → soft-deleted exclues → leurs 322 lignes filles ne remontent jamais. ✓
- **Menu client** : `Item` (SoftDeletes) + filtre explicite `where('status', Status::ACTIVE)` (ItemController) → 28 items soft-deleted + 25 items INACTIVE (status=10) exclus des listes client. ✓
- `DB::table('order_items')` directs = write path release stock par `id` (AvailabilityService), totaux ZReport **bornés au set d'orders déjà scopé** (`whereIn(order_id, ratios)` issu du modèle Order), commandes console. **Aucune liste client ne fuit du soft-deleted.** ✓

---

## ANNEXE — Débris seed/test (documenté, NON-bloquant)

| # | Constat | Volume | Fenêtre | Classe |
|---|---------|--------|---------|--------|
| A | Commandes NO-item (78) dont batch 933-957 (tous 16.50, fiscalNo 2432+) | 78 (50 avec fiscalNo) | 2026-05/06 | seed/load-test |
| B | **`order 5399`** KIOSK PAID fiscalNo=**2590** total=9.40 **0 lignes** tax=0 | 1 | 2026-07-02 | **ZOMBIE (P2 NF525)** — healé au read-layer 2026-07-10, mais l'enregistrement fiscal sans lignes **persiste en DB**. À réconcilier/annuler proprement. |
| C | Commandes NO-item récentes 5481/5482 (PENDING_COUNTER), 5556 (REFUNDED) | 3 | 2026-07 | paniers borne abandonnés, sans fiscalNo |
| D | Statut hors-enum : status=2 (1), status=5 (15, batch 4954-4963 type DELIVERY) | 16 | 2026-05/06-17 | seed avec constante invalide, 0 récent |
| E | `domain_events` non-dispatchés | 37 (18 contract-violation + 20 attempts=0) | tous <= 2026-06-17 | résidu outbox — l'outbox tourne (events jusqu'au 2026-07-11), mais ces 37 vieux events ne se draineront pas seuls |
| F | `order_payments.amount <= 0` | 1 | legacy | mineur |
| G | orders `total < 0` (non-del) | 6 | — | **légitime** : commandes RETURNED/avoir (status 22) |

**Recommandations priorisées**
1. **P2 — order 5501** : corriger le chemin ajout-ligne pour recalculer les totaux via `PricingService` (SSOT) ; sentinel `sum(items.total_price)==subtotal`.
2. **P2 — order 5399** : réconcilier l'enregistrement fiscal 2590 sans lignes (avoir/annulation NF525-conforme, pas de hard-delete).
3. **LOW** : border `CleanupTestFixturesCommand` pour interdire le hard-delete de commandes porteuses de `fiscal_sequence_no` en prod (évite gaps type 2506-2508).
4. **LOW** : purger/réconcilier les 37 `domain_events` bloqués (contract violations juin).
