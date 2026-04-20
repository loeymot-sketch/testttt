# VERIFY-13 — Data Integrity (migrations, contraintes, soft-delete, schema)

**Date :** 2026-04-20
**Mode :** AUDIT-ONLY (aucune modification de code applicatif)
**Origine :** `tasks/verify-2026-04-20/13_VERIFY_DATA_INTEGRITY.md` ← `AUDIT_POS_110_DATA_2026-04-19.md`
**Priorité tâche :** P1
**Livrable :** ce rapport (`reports/review/VERIFY_13_DATA_INTEGRITY_2026-04-20.md`)

---

## 1. Plan d'audit (5 lignes)

1. **Pass A — Migrations** : tables critiques (orders, order_items, transactions, loyalty_transactions, z_reports, audit_logs, domain_events, action_logs, order_status_transitions, branches, item_branch_availability) — relevé colonnes `branch_id`, FK, UNIQUE, NOT NULL, indexes.
2. **Pass B — Models Eloquent** : casts (decimal vs float), `SoftDeletes`, `BranchScope`, guards `boot()/booted()`, restore restrictions.
3. **Cross-check** `docs/DATABASE_SCHEMA_CORE.md` ↔ état réel des migrations 2025/2026.
4. **Matrice** table × FK × UNIQUE × index × soft-delete × cast prix.
5. **Verdict** : cocher V1–V6, conclusion `GLOBAL: ALL_GREEN | WARN | FAIL` + propositions cycles `P*`.

---

## 2. Sources lues (current cycle scope)

- `tasks/verify-2026-04-20/13_VERIFY_DATA_INTEGRITY.md`
- `AGENTS.md`, `.cursor/rules/safety.mdc`, `.cursor/rules/scope.mdc`, `.cursor/rules/project-invariants.mdc`
- Migrations critiques (ciblées par sujet) :
  - `2022_11_17_110125_create_branches_table.php`
  - `2022_11_17_110810_create_orders_table.php`
  - `2022_11_17_110832_create_order_items_table.php`
  - `2023_03_23_143747_create_transactions_table.php`
  - `2026_03_25_002938_add_idempotency_key_to_orders_table.php`
  - `2026_04_18_140003_scope_idempotency_key_to_branch.php`
  - `2026_03_25_004307_add_transaction_id_to_orders_table.php`
  - `2026_03_26_075918_create_loyalty_transactions_table.php`
  - `2026_03_26_075919_add_unique_to_loyalty_transactions.php`
  - `2026_04_15_200000_create_domain_events_table.php`
  - `2026_04_15_230000_create_order_status_transitions_table.php`
  - `2026_04_15_230100_create_item_branch_availability_table.php`
  - `2026_04_15_230200_v1_soft_deletes_and_deletion_log.php`
  - `2026_04_18_120008_create_loyalty_consents_table.php`
  - `2026_03_06_182733_create_action_logs_table.php`
  - `2026_04_19_000000_add_branch_id_to_action_logs.php`
  - `2026_04_22_200000_add_composite_index_branch_created_to_action_logs.php`
  - `2026_04_22_000001_add_fiscal_sequence_no_to_orders.php`
  - `2026_04_22_000002_create_audit_logs_table.php`
  - `2026_04_22_100000_add_unique_chain_index_to_audit_logs.php`
  - `2026_04_22_000003_create_z_reports_table.php`
  - `2026_03_12_130000_add_performance_indexes.php`
- Models : `Order`, `FrontendOrder`, `OrderItem`, `Item`, `Transaction`, `Branch`
- Doc : `docs/DATABASE_SCHEMA_CORE.md`
- Audit prior : `reports/review/AUDIT_POS_110_DATA_2026-04-19.md`

> Pas de tour large du repo : seules les tables citées dans la tâche §2 et les hypothèses §3 ont été instruites (token discipline §scope).

---

## 3. Pass A — Migrations (FK, UNIQUE, NOT NULL, branch_id)

### 3.1 `orders` (table fiscale centrale)

- Colonnes critiques : `id`, `user_id` (FK→users, RESTRICT par défaut), `branch_id` (FK→branches, RESTRICT), `status` **NOT NULL**, `subtotal/total/discount/delivery_charge` **decimal(19,6) NOT NULL** (sauf nullable explicites), `payment_method`, `payment_status`, `order_type`.
- Ajouts post-création :
  - `idempotency_key` (varchar 64, nullable) — d'abord UNIQUE simple (`2026_03_25_002938`), puis remplacé par UNIQUE composite **`(branch_id, idempotency_key)`** (`2026_04_18_140003`) ✓ *branch-scoped*.
  - `fiscal_sequence_no` (UNSIGNED BIGINT nullable) + UNIQUE **`(branch_id, fiscal_sequence_no)`** (`2026_04_22_000001`) ✓ NF525 / Loi Finance 2018.
  - `transaction_id`, `card_type` (`2026_03_25_004307`).
  - `total_tax`, `loyalty_awarded`, `dining_table_id`, `pos_payment_*`, `source_surface`, `loyalty_customer_code`, `queue_number`, `address`.
  - `deleted_at` via `2026_04_15_230200_v1_soft_deletes_and_deletion_log.php` ✓.
- Indexes (`2026_03_12_130000_add_performance_indexes`) : `(branch_id, status)`, `user_id`, `order_datetime`, `status`. ✓ couvre H1 pour KDS/POS.

### 3.2 `order_items`

- FK strictes ✓ : `order_id`→orders, `branch_id`→branches, `item_id`→items (toutes `constrained()`, RESTRICT default).
- `quantity` integer NOT NULL ; `discount`, `price`, `total_price`, `item_variation_total`, `item_extra_total` **decimal(19,6)**.
- Ajouts : `tax_*`, `allergens_snapshot` JSON, `deleted_at` (soft-delete).
- Index `(order_id)` ajouté (`add_performance_indexes`). Pas de composite `(branch_id, item_id)` — mineur (volume modéré, requêtes filtrées via `order_id`).

### 3.3 `transactions` (paiements bruts)

- **`order_id` = `unsignedBigInteger` SANS FK** ❌ — risque d'orphelin si une commande est hard-deleted (atténué par `Order` SoftDeletes mais pas garanti pour FrontendOrder ni purges admin).
- **Pas de colonne `branch_id`** ❌ — viole l'invariant §3 *branch_id business isolation* pour la couche paiement : impossible de scoper `Transaction::query()` par branche sans jointure.
- Pas d'UNIQUE sur `transaction_no` (potentiel doublon PSP).
- `amount` : decimal(19,6) ✓.
- Aucune migration ultérieure n'a ajouté ces contraintes (recherche exhaustive sur `*transactions*.php`).

### 3.4 `loyalty_transactions`

- FK `user_id`→users `cascadeOnDelete` ✓. `order_id` indexé sans FK (acceptable : référence faible). 
- UNIQUE composite `(user_id, order_id, type)` ✓ (`2026_03_26_075919`) — protège contre double crédit/débit.
- Casts intégers signés (`points`, `balance_after`) — *pas* de price float.

### 3.5 `z_reports` (clôture journalière fiscale)

- `branch_id` indexé ✓ ; **pas de FK→branches** ❌ (non bloquant si Branch SoftDeletes — confirmé) mais devrait être documenté `onDelete('restrict')`.
- UNIQUE composite **`(branch_id, sequence_no)`** ✓ → couvre V3.
- Indexes `(branch_id, status)` et `(branch_id, closed_at)` ✓.
- Montants `decimal(15,2)` ✓.
- Chaîne HMAC `prev_hash`/`signature` (CHAR 64) — INSERT-only par design service.

### 3.6 `audit_logs` (NF525 — chaîne immuable)

- `branch_id`/`user_id` nullable indexés, **pas de FK** ❌ (intentionnel : conserver evidence même si user/branch supprimé — *justifiable*, à documenter).
- UNIQUE **`(branch_id, prev_hash)`** ✓ (`2026_04_22_100000`) — anti-fork chaîne.
- **Triggers DB BEFORE UPDATE/DELETE** sur MySQL/MariaDB **et SQLite** ✓ — INSERT-only enforced au niveau moteur.
- `down()` refuse rollback en `APP_ENV=production` (rétention 6 ans NF525) ✓.
- `current_hash` CHAR(64) NOT NULL ; `prev_hash` nullable (premier maillon) ✓.

### 3.7 `domain_events` (outbox)

- `branch_id` nullable, **pas de FK** ❌ (acceptable pour outbox polymorphe ; à documenter).
- Indexes : `(dispatched_at, occurred_at)` `idx_pending`, `(aggregate_type, aggregate_id)`, `(branch_id, occurred_at)` ✓.
- Pas d'UNIQUE sur `correlation_id` — *à challenger* si déduplication broadcast voulue.

### 3.8 `order_status_transitions`

- `order_id` `unsignedBigInteger` **sans FK** ❌ (modèle polymorphe `order_type` Order vs FrontendOrder — intentionnel, à documenter).
- Indexes `(order_id, order_type, occurred_at)` et `(occurred_at)` ✓.
- `actor_id` nullable, pas de FK (set null logique côté service).

### 3.9 `action_logs`

- FK `user_id`→users `onDelete('set null')` ✓.
- `branch_id` ajouté en `2026_04_19_000000`, **nullable, sans FK**, indexé. Backfill best-effort via sous-requête portable MySQL/SQLite ✓.
- Composite `(branch_id, created_at)` ajouté ✓ (`2026_04_22_200000`) — couvre dashboard hot path.

### 3.10 `branches`, `item_branch_availability`, `loyalty_consents`

- `branches` : `name/city/state/zip_code/address` NOT NULL ✓ (cf. règle d'intégrité §3.2 doc), `status` default ACTIVE, SoftDeletes ajouté ✓.
- `item_branch_availability` : UNIQUE `(item_id, branch_id)` ✓, index `(branch_id, is_available)` ✓, **pas de FK** ❌ sur `item_id`/`branch_id` (cohérence applicative).
- `loyalty_consents` : FK `user_id` `cascadeOnDelete` ✓ (RGPD : effacement compte ⇒ effacement consentement), index `(user_id, occurred_at)` ✓.

---

## 4. Pass B — Models Eloquent (casts, soft-delete, guards)

### 4.1 Casts prix — V5 ✅

| Modèle | Champ prix | Cast |
|---|---|---|
| `Order` | subtotal, discount, delivery_charge, total_tax, total, pos_received_amount | `decimal:6` |
| `FrontendOrder` | subtotal, discount, delivery_charge, total | `decimal:6` |
| `OrderItem` | discount, tax_amount, price, item_variation_total, item_extra_total, total_price | `decimal:6` |
| `Item` | price | `decimal:6` |
| `Transaction` | amount | `decimal:6` |

**Aucun `float` sur un champ monétaire** dans les models lus. ✅

### 4.2 SoftDeletes & guards

- `Order` : `use SoftDeletes` + `static::restoring(...) throw RuntimeException` (Order.php L98-106) — restore **bloqué** car enfants hard-deleted (`OrderAddress`, `OrderCoupon`). One-way audit trail conforme NF525. ✅ **H2 réfutée** (le risque évoqué — fuite via restore — est explicitement bouché).
- `FrontendOrder` : même table `orders`, `SoftDeletes`, **mais pas de restore guard** (utilise `booted()`). Accessoire : passe par même `restoring` event → **bloqué de facto** (event bus partagé sur table). À confirmer en P12.
- `OrderItem` : `SoftDeletes` ✓ (cohérent avec Order).
- `Branch` : `SoftDeletes` ✓.
- `Item` : `SoftDeletes` ✓ (price snapshotté dans `order_items.price`).
- `BranchScope` global : appliqué sur `Order::boot()` et `FrontendOrder::booted()` ✓.

### 4.3 Asymétries & angles morts

- **`Transaction` : pas de `BranchScope`, pas de SoftDeletes, pas de FK DB sur `order_id`** → **finding majeur** pour intégrité fiscale et isolation branche.
- `OrderItem` : pas de `BranchScope` global (relations via `order_id` héritent du scope d'`Order`).

---

## 5. Matrice synthèse — table × FK × UNIQUE × index × soft-delete × cast prix

| Table | branch_id | FK clés | UNIQUE | Indexes utiles | SoftDelete | Casts prix | Verdict |
|---|---|---|---|---|---|---|---|
| `branches` | — (PK) | — | — | — | ✅ | n/a | ✅ |
| `orders` | ✅ FK→branches | user, branch | `(branch_id, idempotency_key)`, `(branch_id, fiscal_sequence_no)` | `(branch_id,status)`, user_id, order_datetime, status | ✅ + restore guard | `decimal:6` ✅ | ✅ |
| `order_items` | ✅ FK→branches | order, branch, item | — | `order_id` | ✅ | `decimal:6` ✅ | ✅ |
| `transactions` | **❌ ABSENT** | **❌ pas de FK order_id** | ❌ rien sur `transaction_no` | — | ❌ | `decimal:6` ✅ | ⚠️ FAIL partiel |
| `loyalty_transactions` | — (par user) | user (cascade) | `(user_id, order_id, type)` | user_id, order_id, loyalty_code | — | ints (no price) | ✅ |
| `z_reports` | ✅ indexé (pas FK) | ❌ branch sans FK | `(branch_id, sequence_no)` | `(branch_id,status)`, `(branch_id,closed_at)` | — | `decimal(15,2)` ✅ | ✅ (noter FK manquant) |
| `audit_logs` | ✅ indexé (pas FK) | ❌ intentionnel (rétention) | `(branch_id, prev_hash)` | `(branch_id,created_at)`, `(resource,resource_id)` | — / triggers IMMUTABLE | n/a | ✅ |
| `domain_events` | ✅ nullable indexé | ❌ polymorphe | — | `idx_pending`, `idx_aggregate`, `idx_branch` | — | n/a | ✅ |
| `order_status_transitions` | — (via order) | ❌ pas de FK order_id (polymorphe) | — | `(order_id,order_type,occurred_at)`, `occurred_at` | — | n/a | ✅ (justifié) |
| `action_logs` | ✅ nullable backfill | user (set null), ❌ pas FK branch_id | — | `branch_id`, `(branch_id,created_at)` | — | n/a | ✅ |
| `item_branch_availability` | ✅ | ❌ (cohérence applicative) | `(item_id, branch_id)` | `(branch_id, is_available)` | — | n/a | ✅ |
| `loyalty_consents` | — (via user) | user (cascade) | — | `(user_id, occurred_at)` | — | n/a | ✅ |
| `items` | — | — | — | `(status, item_category_id)`, `(id, price)` | ✅ | `decimal:6` ✅ | ✅ |

---

## 6. Cross-check `docs/DATABASE_SCHEMA_CORE.md` (V6)

Le doc actuel décrit **uniquement** : `BRANCH`, `USER`, `KIOSK_MACHINE`, `ORDER` (sans fiscal/idempotency/loyalty/transaction_id), `ITEM`, `ITEM_CATEGORY`, `TAX`, `COUPON`, `ORDER_ITEM`.

**Manquants vs migrations actuelles** :

- `orders.fiscal_sequence_no` + UNIQUE composite NF525.
- `orders.idempotency_key` + UNIQUE composite branch-scoped.
- `orders.transaction_id`, `card_type`.
- `orders.total_tax`, `loyalty_points_awarded`, `loyalty_customer_code`, `source_surface`, `pos_payment_*`, `dining_table_id`, `queue_number`.
- Tables : `transactions`, `loyalty_transactions`, `loyalty_consents`, `z_reports`, `audit_logs`, `domain_events`, `order_status_transitions`, `item_branch_availability`, `action_logs`, `deletion_log`.
- SoftDeletes ajoutés (`orders`, `order_items`, `branches`, `item_categories`, `users`, `items`).
- Triggers immuabilité `audit_logs`.
- Indexes performance ajoutés en série 2026.

**Conclusion V6 : doc divergente — H5 confirmée.** ⚠️

---

## 7. Vérifications obligatoires (checklist §5)

- [x] **V1** Indexes scope branche + filtres fréquents — ✅ couverts (`(branch_id,status)` orders, `(branch_id,created_at)` action_logs/audit_logs, `(branch_id,closed_at)` z_reports, `(branch_id,is_available)` availability).
- [~] **V2** FK avec `onDelete` documenté pour chaque table critique — ⚠️ **partiel** : `transactions.order_id` sans FK, `z_reports.branch_id`, `audit_logs.branch_id`, `action_logs.branch_id`, `domain_events.branch_id`, `order_status_transitions.order_id`, `item_branch_availability.{item_id,branch_id}` sans FK. Plusieurs sont *justifiés* (polymorphisme, rétention NF525) mais ne sont pas **documentés** dans le code/doc.
- [x] **V3** Unique sur séquences fiscales `(branch_id, …)` — ✅ `orders fiscal_sequence_no`, `z_reports sequence_no`, `audit_logs prev_hash`.
- [x] **V4** Soft-delete justifié (Order non-restorable) — ✅ `Order::restoring` throws RuntimeException ; cohérent avec audit POS-110.
- [x] **V5** Casts décimaux sur prix (jamais float) — ✅ `decimal:6` partout (Order, FrontendOrder, OrderItem, Item, Transaction).
- [ ] **V6** Doc `DATABASE_SCHEMA_CORE.md` alignée — ❌ **non**, doc 2022 figée, ne reflète pas la série de migrations 2026.

---

## 8. Hypothèses challengées

| H | Statut | Note |
|---|---|---|
| H1 index `(branch_id,status)` / `(branch_id,created_at)` | **Réfutée** | Indexes présents (orders, action_logs, audit_logs). |
| H2 `Order::restore()` actif | **Réfutée** | Bloqué par `static::restoring` runtime exception. |
| H3 FK manquantes (orphelins OrderItem) | **Partiellement confirmée** | `order_items` ✅ ; **`transactions` sans FK order_id** ❌ ; `order_status_transitions`, `audit_logs.branch_id`, `z_reports.branch_id`, `action_logs.branch_id` sans FK (souvent justifié). |
| H4 unique manquant séquences fiscales | **Réfutée** | Unique composite branch-scoped pour `fiscal_sequence_no`, `z_reports.sequence_no`, `audit_logs.(branch_id,prev_hash)`. |
| H5 doc divergente | **Confirmée** | `DATABASE_SCHEMA_CORE.md` ne reflète pas l'état actuel. |
| H6 cast Eloquent float sur prix | **Réfutée** | Tous les prix sont `decimal:6`. |

---

## 9. Conclusion

> **GLOBAL : WARN**

**Justification du verdict** :

- Critères ALL_GREEN non remplis : V2 partiel + V6 non aligné.
- Critère FAIL **non déclenché** strictement (pas de cast float prix ; FK manquantes mais aucune *critique* sur la chaîne fiscale **immuable** — `audit_logs`/`z_reports` sont protégés par UNIQUE composite + triggers + service layer ; `transactions.order_id` est lecture seule via `Order::transaction()` HasOne, le risque d'orphelin est atténué par `Order` SoftDeletes mais n'est **pas garanti** par la DB).
- WARN justifié principalement par : (a) `transactions` sans `branch_id` ni FK → angle mort isolation §invariant 3 sur la couche paiement ; (b) divergence doc.

---

## 10. Suite proposée (cycles `P*`)

1. **P11_DATA_INDEXES_FK** *(P1)* — Ajouter FK explicites + `onDelete` documenté :
   - `transactions.order_id` → orders (`onDelete('restrict')`, soft-delete-safe).
   - `z_reports.branch_id`, `action_logs.branch_id`, `loyalty_transactions.order_id` (FK `set null`/`restrict` selon politique).
   - **Documenter** explicitement les FK volontairement absentes (`audit_logs`, `domain_events`, `order_status_transitions`) avec rationale rétention/polymorphisme dans la migration et dans `DATABASE_SCHEMA_CORE.md`.
2. **P12_DATA_DOC_SYNC** *(P1)* — Refresh complet `docs/DATABASE_SCHEMA_CORE.md` : schéma Mermaid étendu (transactions, z_reports, audit_logs, domain_events, order_status_transitions, loyalty_*, item_branch_availability, action_logs, deletion_log, soft-deletes, idempotency, fiscal_sequence_no). Ajout d'une section "FK volontairement absentes" et d'une note explicite sur `Order::restoring` + immuabilité `audit_logs`.
3. **P13_DATA_TRANSACTIONS_BRANCH_ISOLATION** *(P2 — invariant 3)* — Ajouter `branch_id` à `transactions` (backfill via `orders.branch_id`), index `(branch_id, created_at)`, FK→branches, et `BranchScope` global sur `Transaction` (avec exception POS dashboard cross-branch). Risque : nécessite audit symétrie OrderService/FrontendOrderService (création des transactions).

---

## 11. Conformité audit-only

- Aucun fichier applicatif (`app/`, `database/migrations/`, `resources/`, `routes/`) modifié.
- Seul write produit : ce rapport `reports/review/VERIFY_13_DATA_INTEGRITY_2026-04-20.md`.
- Aucune dérogation aux invariants FoodKing (pricing SSOT, OrderStatus enum, branch_id isolation, dispatch after commit, OrderService/FrontendOrderService symmetry, frozen zones) déclenchée par cet audit lecture-seule.
- Aucun `SCOPE_PRESSURE` ni `ESCALATION` à signaler.
