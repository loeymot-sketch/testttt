# Cartographie DB + Modèles — FoodKing V1 LOCAL Le Cayenne

> Vague 01-structure — lecteur read-only `db-schema`. Session 2026-07-02.
> Tout ce qui est cité ci-dessous a été vu via Read/grep/ls/tinker DANS cette session.
> DB live interrogée : connexion `mysql`, base **`foodking_e2e`** (lecture seule).

---

## 1. Vue d'ensemble

- **176 migrations** dans `database/migrations/` (comptées par `ls | wc -l`). Dernière : `2026_06_01_100000_add_delivery_fee_free_km_to_branches.php` (aucune migration 2026-06 postérieure ni 2026-07 — vérifié par grep `^2026_06|^2026_07`).
- **77 entrées dans `app/Models/`** (76 modèles + dossier `Scopes/` contenant `BranchScope.php` et `WizardProfileBranchScope.php`).
- Base historique = fork FoodKing générique (migrations 2022_11_17_*) massivement durcie par vagues 2026_03→2026_06 : NF525 (fiscal seq, audit chain, triggers d'immutabilité), idempotence, multi-tenant, wizard, stock, cash, delivery.
- **Note nommage** : le périmètre demandé mentionnait « composer_profiles/steps » — ces tables n'existent PAS sous ce nom (ABSENT vérifié dans `ls database/migrations`). Les tables réelles du composer/wizard sont `item_wizard_profiles` / `item_wizard_steps` / `item_wizard_step_versions` (migrations `2026_04_27_143100`, `143110`, `2026_05_04_000010`).

---

## 2. Migrations par domaine (regroupement observé)

### Orders / order_items / order_payments
| Migration | Contenu clé |
|---|---|
| `2022_11_17_110810_create_orders_table.php` | base : order_serial_no, token, user_id FK, branch_id FK, subtotal/discount/delivery_charge/total DECIMAL(19,6), order_type (default DELIVERY), payment_method, payment_status (default UNPAID), status, delivery_boy_id, creator/editor |
| `2022_11_17_110832_create_order_items_table.php` | order_id/branch_id/item_id FK, quantity, price, item_variations + item_extras LONGTEXT, totals, instruction |
| `2024_10_28...add_pos_payment_method_and_note` / `2025_02_09...pos_received_amount` | colonnes POS |
| `2026_03_06_170846_add_queue_number_to_orders` + `2026_04_26_213800_add_unique_branch_queue_number_to_orders.php:54` | **UNIQUE (branch_id, business_date, queue_number)** |
| `2026_03_25_002938_add_idempotency_key_to_orders_table.php:17` | idempotency_key VARCHAR(64) unique (v1) |
| `2026_04_18_140003_scope_idempotency_key_to_branch.php:35` | v2 : UNIQUE (branch_id, idempotency_key) |
| `2026_05_24_023000_add_user_id_to_orders_idempotency_unique.php:65` | v3 finale : **UNIQUE (branch_id, user_id, idempotency_key)** `orders_branch_user_idempotency_unique` ; down() REFUSÉ en production (RuntimeException, lignes 81-88 : « re-open the cross-customer idempotency leak ») |
| `2026_03_26_075905_add_source_surface_to_orders` | colonne source_surface (pos/kiosk/web/mobile/delivery) |
| `2026_05_06_180000_create_order_payments_table` | multi-tender (tranches) ; `2026_05_16_120001` ajoute terminal_id |
| `2026_05_06_200000_add_parent_order_id_to_orders` + `2026_05_19_200000...unique_parent_order_id.php:66` | miroirs de remboursement, UNIQUE parent_order_id |
| `2026_04_22_000020_add_composition_snapshot_to_order_items` | **composition_snapshot est sur order_items, PAS sur orders** |
| `2026_05_24_040211_add_composition_snapshot_immutability_trigger.php:86-118` | TRIGGER BEFORE UPDATE `order_items_composition_snapshot_no_update` — SIGNAL 45000 (MySQL) / RAISE(ABORT) (SQLite) |
| `2026_04_23_100000_add_release_tracking_to_order_items` | tracking release KDS |
| `2026_04_18_140004` + `2026_04_20_131600` | allergens_snapshot sur order_items + backfill codes FR |
| `2026_05_09_200000_add_fiscal_alloc_error_at_to_orders` | flag retry alloc fiscale |

### Fiscal NF525
| Migration | Contenu clé |
|---|---|
| `2026_04_22_000001_add_fiscal_sequence_no_to_orders.php:37` | fiscal_sequence_no BIGINT NULL + **UNIQUE (branch_id, fiscal_sequence_no)** `orders_branch_fiscal_seq_unique` |
| `2026_04_22_000002_create_audit_logs_table.php:35-57` | audit_logs : action/resource/payload JSON, prev_hash+current_hash CHAR(64), ip/user_agent/session_id ; triggers BEFORE UPDATE + BEFORE DELETE SIGNAL 45000 (commentaire lignes 20-27) ; **down() bloqué en production** (lignes 63-70) |
| `2026_04_22_100000_add_unique_chain_index_to_audit_logs.php:35` | **UNIQUE (branch_id, prev_hash)** — anti-fork de chaîne |
| `2026_04_22_000003_create_z_reports_table.php:27-64` | z_reports : sequence_no par branche, total_ht/ttc/tva DECIMAL(15,2), total_by_method/tax_rate JSON, prev_hash+signature HMAC, status open/closed, **UNIQUE (branch_id, sequence_no)** ligne 62 |
| `2026_05_09_160000_add_z_reports_delete_trigger_immutability.php:51` | TRIGGER `z_reports_no_delete` |
| `2026_05_10_010000_secure_fiscal_audit_trail_immutability.php:113-137` | 3 SIGNAL 45000 supplémentaires |
| `2026_05_16_130000` / `2026_05_18_120300` / `2026_05_18_140000` / `2026_05_24_050000` | triggers de parité SQLite (cash_movements, delivery_boy_cash, stock_movements, z_reports/order_payments) |

### Items / catalogue / wizard
- Base 2022 : `items`, `item_categories`, `item_attributes` (name+status seulement à l'origine — `2022_11_17_110541:16-25`), `item_variations` (item_attribute_id FK + name + price DECIMAL(19,6) — lignes 19-21), `item_extras`, `item_addons`, `taxes`, `offers/offer_items`, `coupons`.
- `2026_04_22_000010_add_min_max_repeat_to_item_attributes.php:11-18` : **min_select (def 0) / max_select (def 1) / allow_repeat** — le contrat de complétude de composition (source des 422 « Viande 2 actuel:0 »).
- `2026_03_26_090640/090651` : visible_on + group_label sur extras/variations (projection par surface).
- `2026_04_16_200000` : colonne channels (JSON, NULL = toutes surfaces) sur items+categories.
- Wizard : `item_wizard_profiles` (polymorphique owner depuis `2026_05_05_000020`, scope nullable branch via `WizardProfileBranchScope`), `item_wizard_steps` (UNIQUE (profile_id, step_key) `2026_04_27_143110:30`), `item_wizard_step_versions` (UNIQUE (profile_id, version)).
- Allergènes : `allergens` (code unique `2026_04_18_120002:23`), pivot `item_allergen` (UNIQUE item+allergen), `item_extra_allergens`.
- Dispo : `item_branch_availability` (UNIQUE (item_id, branch_id) `2026_04_15_230100:26`).

### Stock
- `stock_levels` (`2026_04_27_143120:22` — UNIQUE (branch_id, stockable_type, stockable_id), polymorphique) + manual_unavailable (`2026_05_08_150000`).
- `stock_movements` (`2026_04_27_143130:19` — idempotency_key unique) + triggers immutabilité (`2026_05_18_140000`).

### Cash
- `cash_drawer_sessions` (`2026_05_08_140000`) + UNIQUE partiel « 1 seul tiroir ouvert » (`2026_05_10_020000`) + colonnes acteur (`2026_05_17_100000`).
- `cash_movements` (`2026_05_08_140100`) + trigger no-delete SQLite (`2026_05_16_130000`).
- Livreur : `delivery_boy_cash_sessions/movements` + UNIQUE partiel open + triggers (`2026_05_18_1202xx/1203xx`).

### Events / webhooks / idempotence
- `domain_events` (`2026_04_15_200000`) + **UNIQUE idempotency_key** (`2026_05_09_180000:40` `uniq_domain_events_idempotency_key`).
- `webhook_events` (`2026_05_09_120000:83`) : **UNIQUE (provider, webhook_id)** `uk_webhook_provider_id`, status enum pending/processed/failed/duplicate, attempts, order_id FK ajouté par `2026_05_18_120000_add_webhook_events_order_id_fk`. Provider = discriminant string → couvre Stripe/SenangPay et l'intégration Uber (aucune table dédiée uber — ABSENT vérifié dans ls migrations).
- `order_quotes` (`2026_04_25_190000:13-40`) : quote_token UUID unique, intent_hash + hmac_signature CHAR(64), canonical_payload JSON, totaux, expires_at/consumed_at/consumed_order_id — le devis signé backend (SSOT prix pré-commande).
- `pos_parked_orders` (UNIQUE (user_id, idempotency_token) `2026_04_20_200000:27`).
- `pending_payment_confirmations` (UNIQUE transaction_id `2026_05_08_120000:43`).
- `order_status_transitions` (`2026_04_15_230000`) — journal des transitions.

### Kiosk / hardware / divers
- `kiosk_machines` (`2025_02_21_110459`) + **UNIQUE (branch_id, machine_id)** (`2026_05_19_210000:75`).
- `printers` (`2026_04_20_210000_create_printers_table`), `payment_terminals` (`2026_05_16_120000`).
- `dining_tables` + occupancy + `dining_table_audit_logs` ; `loyalty_transactions` (UNIQUE (user_id, order_id, type) `2026_03_26_075919:29`), `loyalty_qr_nonces_consumed` (nonce unique), `loyalty_consents` ; users : loyalty_code unique (`2026_03_08_145926:16`), nfc_uid UNIQUE (branch_id, nfc_uid) (`2026_04_20_220000:23`), phone required (`2026_05_16_140100`) ; branches : zone, fiscal identity (`2026_04_20_210000`), delivery_fee_base/per_km/minimum/free_km (`2026_05_18_100000/110000`, `2026_06_01_100000`) ; spatie permissions (`2022_05_01_142407`) ; `sync_metrics`, `action_logs` (+branch_id + index composite), `deletion_log` (`2026_04_15_230200_v1_soft_deletes_and_deletion_log`).

---

## 3. Modèle Order (`app/Models/Order.php`)

- fillable (lignes 20-66) : order_serial_no, **queue_number**, business_date, token, user_id, branch_id, montants, order_type, payment_status, status, **pos_payment_method / pos_payment_note / pos_received_amount**, loyalty_customer_code, **source_surface**, **idempotency_key**, parent_order_id, loyalty_points_awarded, **fiscal_alloc_error_at**, creator_id (attribution caissier NF525, commentaire lignes 58-65 : user_id = CLIENT, creator_id = opérateur).
- **`composition_snapshot` et `fiscal_sequence_no` ne sont PAS dans le fillable d'Order** : composition_snapshot vit sur OrderItem (`OrderItem.php:75`, cast array ligne 102) ; fiscal_sequence_no est colonne orders (migration 2026_04_22_000001) écrite hors mass-assignment (par le service fiscal — frozen, non lu dans ce périmètre).
- casts (68-97) : montants decimal:6, business_date date, fiscal_alloc_error_at datetime.
- `getTotalHtAttribute()` (112-118) : HT virtuel = total − total_tax (identité TTC=HT+TVA « by construction » pour le Z).
- boot() (120-161) : BranchScope global (123) ; **restore() interdit** — RuntimeException (139-147, soft-delete = piste d'audit one-way car enfants hard-deleted) ; hook creating auto-dérive `source_surface='delivery'` si order_type=DELIVERY (156-160).
- Relations : orderItems, items (belongsToMany withTrashed), user withTrashed, address, branch, deliveryBoy, coupon, **payments (OrderPayment multi-tranches, 206-209)**, transaction, diningTable.
- `scopeRealizedRevenue` (267-278) + `isRealizedRevenueRow` (286-295) : revenu net = PAID non-terminal + miroirs RETURNED avec parent_order_id (total négatif) — doit rester en lock-step avec le netting du Z.

Enums vérifiés : `OrderStatus` PENDING=1/ACCEPT=4/PREPARING=7/PREPARED=8/OUT_FOR_DELIVERY=10/DELIVERED=13/CANCELED=16/REJECTED=19/RETURNED=22 ; `PaymentStatus` PAID=5/UNPAID=10/**PENDING_COUNTER=15**/REFUNDED=20 ; `PosPaymentMethod` CASH=1/CARD=2/…/TICKET_RESTAURANT=5/**COUNTER_DEFERRED=6**.

## 4. OrderItem (`app/Models/OrderItem.php`)

- BranchScope global (ligne 27, fix P0 isolation 2026-05-09).
- **Garde applicative d'immutabilité composition_snapshot** : hook `updating` (50-58) → RuntimeException si dirty et original non-null ; doublée par le trigger DB (migration 2026_05_24_040211). Legacy backfill (original null) autorisé.
- Porte item_variations/item_extras (legacy string) + composition_snapshot (array) + allergens_snapshot (array) + colonnes tax dénormalisées.

## 5. Item (`app/Models/Item.php`) + catalogue

- fillable (20-47) : flags kiosk (is_upsell, is_chef_pick, diet flags), channels (cast array, NULL = toutes surfaces — `isVisibleOn()` lignes 83-86), kiosk_emoji, **kds_station**.
- Relations : variations() filtrées ACTIVE avec itemAttribute (135), extras() ACTIVE (140), addons, category, tax, allergens (pivot is_trace, 174-179). Fallback images via `config/menu_images.php` (95-105).
- **Item n'a PAS de BranchScope** (catalogue global, dispo par branche via item_branch_availability).

## 6. BranchScope + couverture modèles

- `app/Models/Scopes/BranchScope.php` (frozen) : apply() ligne 17, champ qualifié `table.branch_id` (28) ; admin branch_id=0 = pas de filtre, staff = sa branche uniquement, jamais les lignes branch_id=0 (31-38, FIX-54-8).
- addGlobalScope(BranchScope) vu par grep dans : Order:123, OrderItem:27, FrontendOrder:23, OrderPayment:67, OrderQuote:22, CashDrawerSession:68, CashMovement:59, StockLevel:25, StockMovement:23, KioskMachine:38, User:89 (+ baseline 20 modèles verrouillée par `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php` — fichier vu au ls). ItemWizardProfile utilise `WizardProfileBranchScope` (ItemWizardProfile.php:24).
- Immutabilité applicative jumelle : StockMovement append-only (référencé au commentaire OrderItem.php:48-49).

## 7. Seeders

- `DatabaseSeeder.php` : chaîne core (Menu*, Permission/Role, Company, Language, Currency, **BranchTableSeeder**, User, RolePermission, ComposerPermissionsMinimal, IngredientPermission, **AdminWebGuardPermissionsSyncSeeder** — miroir 82 perms sanctum→web, KioskMachineTableSeeder…) puis **`MenuSeeder` = SSOT unique du menu FR** (bloc lignes ~78-97 : anciens seeders items dépréciés/throw), TimeSlot, Offers/Coupons, **AllergensSeeder** (14 allergènes EU 1169/2011, idempotent), Push/Message/DiningTable. Commandes : `php artisan menu:create|reset|verify`.
- **État git non-commité (vérifié)** : `BranchTableSeeder.php` modifié (+11/−7) ; **`DeliveryConfigSeeder.php` untracked** — origine 437 Rue Élie Gruyelle Hénin-Beaumont, 4 €/≤5 km +1 €/km, offert ≥30 € (Settings group delivery + colonnes branch), consommé par `DeliveryFeeService::fromDistanceKm` (doc en tête de fichier). **Il n'est PAS appelé par DatabaseSeeder** (grep de la chaîne : absent) — seed manuel.
- ~85 seeders au total, beaucoup historiques/one-shot (OwnerMenuUpdate20260623Seeder, WizardCayenneAndBolsCorrectionsSeeder, E2E*…).

## 8. État DB LIVE (mysql `foodking_e2e`, lecture seule via tinker)

| Mesure | Valeur |
|---|---|
| orders | 3043 (b1=3038, b7=2, b8=1, b9=2 — **4 branches en DB**) |
| items actifs (status=5, non supprimés) | **48** (total 87 avec soft-deleted/inactifs) |
| item_variations actives / item_extras actifs | 420 / 353 |
| item_wizard_profiles / steps | 29 / 128 |
| audit_logs | 4711 lignes, MAX(id)=4715 (**id max > count : trous d'id normaux — AUTO_INCREMENT consommé par transactions rollback ; la chaîne se vérifie par prev_hash, pas par id**) |
| z_reports | 24 |
| fiscal_sequence_no branch 1 | n=2585, min=1, max=2588 (requête brute incluant soft-deleted) |
| fiscal seq total toutes branches | 2589 |
| source_surface | pos=1734, kiosk=1143, NULL=127, delivery=35, web=3, mobile=1 |
| webhook_events | 0 (aucun webhook réel reçu) |
| domain_events | 9898 |
| cash_drawer_sessions / cash_movements | 31 / 377 |

## 9. Invariants observés (file:line)

1. Pricing/composition figés : composition_snapshot immuable post-insert — hook Eloquent `OrderItem.php:50-58` + trigger DB `2026_05_24_040211:86-118` (SIGNAL 45000 / RAISE ABORT).
2. Séquence fiscale : UNIQUE (branch_id, fiscal_sequence_no) `2026_04_22_000001:37-41` ; flag retry `fiscal_alloc_error_at` (Order.php:57, cast :94).
3. Chaîne audit : UNIQUE (branch_id, prev_hash) `2026_04_22_100000:35` + triggers no-UPDATE/no-DELETE `2026_04_22_000002` ; rollback des migrations fiscales REFUSÉ en production (`2026_04_22_000002:63-70`, `2026_05_24_023000:81-88`).
4. Z-report : UNIQUE (branch_id, sequence_no) `2026_04_22_000003:62` + trigger no-delete `2026_05_09_160000:51` ; identité TTC=HT+TVA via `Order::getTotalHtAttribute` (Order.php:112-118).
5. Idempotence commandes : UNIQUE (branch_id, user_id, idempotency_key) `2026_05_24_023000:65` (3 générations d'index, forward-only).
6. Idempotence webhooks : UNIQUE (provider, webhook_id) `2026_05_09_120000:83` ; domain_events idempotency_key unique `2026_05_09_180000:40`.
7. Queue number : UNIQUE (branch_id, business_date, queue_number) `2026_04_26_213800:54`.
8. Refund mirror : UNIQUE parent_order_id `2026_05_19_200000:66` ; netting Z ↔ `Order::scopeRealizedRevenue` (Order.php:267-278).
9. Isolation branche : BranchScope.php:17-38 (admin=0 bypass, staff jamais branch_id=0) sur ≥11 modèles vus + sentinel test.
10. Order::restore() interdit (Order.php:139-147) — soft-delete one-way NF525.
11. Complétude composition : item_attributes.min_select/max_select/allow_repeat `2026_04_22_000010:11-18`.
12. 1 tiroir ouvert max : UNIQUE partiel `2026_05_10_020000` (cash) + `2026_05_18_120200` (livreur).

## 10. Risques préliminaires (observations à VÉRIFIER par les vagues suivantes — pas des findings)

1. **Séquence fiscale b1 : 2585 lignes pour une plage 1..2588** → 3 numéros de la plage sans ligne visible en requête brute (qui inclut pourtant les soft-deleted). À vérifier avec `php artisan fiscal:verify-chain` (vague sécurité/NF525) — peut être bruit e2e (b7/b8/b9 portent 4 seq) ou vrai gap.
2. **48 items actifs vs « 45 items V1 » du SSOT CLAUDE.md §3bis** — dérive de compte à confirmer (ajouts owner 2026-06 ? `OwnerMenuUpdate20260623Seeder` existe).
3. **4 branches en DB (b1 + b7/b8/b9 avec 5 commandes)** vs mandat « 1 branche branch_id=1 » — probablement résidus de tests e2e ; vérifier qu'aucune n'est active côté front.
4. **127 orders avec source_surface NULL** — le fix `258f74722` (mémoire session) rattrape le NULL côté file d'encaissement, mais la donnée reste non backfillée.
5. **BranchTableSeeder modifié non-commité + DeliveryConfigSeeder untracked et hors chaîne DatabaseSeeder** — un `migrate:fresh --seed` sur une machine neuve ne poserait pas la config livraison ; risque de dérive dev↔prod.
6. `2022_11_17_110810:31` : `->default(date('y-m-d h:m:s'))` — default figé au moment de la migration (bug legacy classique, inoffensif si order_datetime toujours fourni).
7. AuditLog/ZReport/DomainEvent **sans BranchScope** (exemptions documentées CLAUDE.md §9, confirmé : aucun addGlobalScope dans AuditLog.php/ZReport.php via grep) — assumé V1, hard-fail V2.
8. `Item.variations()`/`extras()` filtrent status=ACTIVE dans la relation (Item.php:135-140) — les surfaces qui veulent l'historique doivent bypasser ; cohérent avec les 140 variations orphelines normalisées (mémoire 2026-07-01).

## 11. Couverture de tests observée (ls réels)

- `tests/Feature/Branch/` : BranchScopeCoverageSentinelTest, OrderBranchIsolationTest, BranchFiscalIdentityTest, BranchDeactivationTokenRevokeTest, BranchDestroyRevokesTokensTest, OssAdminBranchPolicyTest.
- `tests/Feature/Fiscal/` (extraits) : AuditLogHashChainTest, AuditLogImmutabilityTest, AuditLogConcurrencyTest, CompositionSnapshotImmutabilityTriggerSentinel, FiscalAllocOrphanRetryTest, FiscalArchiveVerifyChainTest, FiscalSealingHmacTest, FiscalCashAtCounterLifecycleTest, AuditTruncateProtectionDeployDocTest.
- Racine Feature (extraits) : BranchIsolationTest, BranchScopeTest, ConcurrentOrderTest, ActionLogBranchIsolationTest + dossiers Cash/, Composer/, Delivery/, Coupon/, Database/.

## 12. Questions ouvertes

1. Les 3 numéros fiscaux « manquants » de la plage b1 sont-ils des allocations sur commandes hard-deleted/e2e, ou un vrai gap ? (fiscal:verify-chain à lancer).
2. Le passage 45 → 48 items actifs est-il une décision owner tracée (seeder 2026-06-23) ou une dérive du SSOT ?
3. Les branches b7/b8/b9 doivent-elles être purgées de foodking_e2e avant les mesures de la vague e2e (elles polluent les compteurs) ?
4. DeliveryConfigSeeder doit-il être ajouté à DatabaseSeeder + commité (avec le BranchTableSeeder modifié) ?
5. `orders.fiscal_sequence_no` est-il volontairement hors `$fillable` (écriture via service fiscal uniquement) — à confirmer en lisant FiscalSequenceService (frozen, hors périmètre de ce lecteur).
