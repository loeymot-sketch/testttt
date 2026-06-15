# GOAL — Migration FoodKing → SaaS multi-tenant multi-secteur
**Date:** 2026-06-15 · Auteur: superviseur (orchestrateur) · Type: GOAL stratégique d'architecture (ultra-plan).
**Mission:** faire passer FoodKing de **V1 LOCAL mono-restaurant** (Le Cayenne, 1 SIRET, 1 box OVH) à un **SaaS multi-tenant** couvrant des besoins **par client / secteur / type d'activité** : resto à la carte sur place, pizzeria, hôtel, bar, coffeeshop, et autre commerce.

> **Statut: PLAN seulement.** Aucun code touché. Décisions stratégiques propriétaire requises (§G) AVANT « lance le GOAL ». Échelle réaliste: ré-architecture **6-12 mois**, pas quelques vagues — le plan séquence par criticité (le socle tenancy débloque tout le reste).

---

## §0 — Préambule

### §0.1 Working-tree
Branche actuelle `goal/wizard-wysiwyg-builder-2026-06-14` (Wizard Studio CONVERGÉ, non poussé, G-PUSH). La migration SaaS = **nouvelle branche longue** `saas/multitenant-foundation` à partir de `main`/spine intégrée. Ne PAS mélanger avec le wizard. Décision: §G-0.

### §0.2 Pipeline par tâche
Chaque tâche s'exécute via `ultra-audit-profond` (5 spécialistes read-only → implémenteur TDD → RED-team → test → visuel → dispute). Ce GOAL ne re-décrit pas le pipeline.

### §0.3 Critères de convergence (Axis 6)
Convergence = 2 cycles consécutifs P0+P1=0 **avec set de findings identique**. Triggers de rejet: tout label brut, tout console error, **toute fuite cross-tenant** (P0 absolu), tout diff frozen sans LOCK, toute régression NF525 (escalade humaine immédiate), toute acceptance sans chemin de test nommé.

### §0.4 Invariants hérités (CLAUDE.md §7/§8) — NE PAS casser pendant la migration
Frozen zones (kiosk/POS wizards, FiscalSequenceService/ZReportService/AuditLogService, BranchScope, IdempotencyKeyMiddleware, PricingService, OrderStateMachine) : toute modif tenancy qui les touche = **LOCK doc + countersign propriétaire** (skill `lock-plan`). NF525: prix 100% backend SSOT, séquence gap-free, chaîne HMAC append-only — **par entité légale** désormais.

### §0.5 — LES 3 GRANDES DÉCISIONS D'ARCHITECTURE (à trancher AVANT toute exécution — §G)
1. **Modèle d'isolation données**: (A) **shared-DB + `tenant_id` + TenantScope** (1 base, scope strict, moins cher, risque de fuite si scope raté) vs (B) **DB-per-tenant** (isolation forte, conforme « entité légale séparée » NF525, ops/coût plus lourds) vs (C) **hybride** (shared-DB pour opérationnel + schéma/connexion dédiés pour le fiscal NF525). **Reco superviseur: (C) hybride** — opérationnel shared-DB tenant_id, fiscal (orders/audit_logs/z_reports) en schéma/connexion par entité légale, pour que l'attestation NF525 d'un tenant soit physiquement isolable lors d'un contrôle fiscal.
2. **Verticalisation secteur**: système de **modules activables par tenant** (table-service, pizza, hotel, bar, retail) sur un socle commun (catalogue + composer + pricing SSOT + order/orderItem étendus), PAS des forks par secteur. Le composer/Wizard Studio (déjà bâti+durci) est le socle de flexibilité produit.
3. **Go-to-market séquentiel**: ne PAS livrer 6 secteurs d'un coup. Séquence reco: **fast-food (acquis) → coffeeshop (le plus proche) → pizzeria (composer + half-and-half) → à-la-carte table-service (le plus gros chantier order-flow) → bar → hôtel (PMS, le plus intégratif) → retail générique**.

---

## §1 — Système 1 : TENANCY CORE (le socle — tout en dépend)

### Contract
Introduire une entité **Tenant** (= le client SaaS / l'entité légale) AU-DESSUS de Branch. Hiérarchie cible: **Tenant → Branches → données opérationnelles**. Isolation stricte cross-tenant, RBAC tenant-scopé, contexte tenant résolu par requête (domaine/sous-domaine).

### Anchors (vérifiés 2026-06-15)
- `app/Models/Scopes/BranchScope.php:13-42` — scope branche; **admin branch_id=0 = bypass GLOBAL per-user** (l.31-35) = **incompatible isolation tenant**.
- `app/Traits/MultiTenantModelTrait.php:13-18` — **STUB VIDE** (nom only, zéro logique).
- `app/Models/User.php:47,89` — `branch_id` + `addGlobalScope(BranchScope)`; rôles Spatie NON tenant-scopés.
- `app/Models/Branch.php:14-25` — **aucun `tenant_id`, aucune relation** (data-only).
- `app/Http/Middleware/IdempotencyKeyMiddleware.php:182-219` — résolution branche per-user/payload, **pas de sous-domaine/header tenant**.
- `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php:48-66` — baseline 22 scopés + 10 exemptés.
- Rôle « Tenant Admin » référencé (`IngredientPermissionSeeder.php`) mais **JAMAIS seedé** (`RoleTableSeeder.php`).

### Sub 1.1 — Entité Tenant + schéma
- T-1.1.1 Créer `tenants` (id, slug, **domain/subdomain**, status, plan_id, locale, currency, timezone, jurisdiction) + modèle `Tenant` + relations `hasMany(Branch)`.
   • anchor: nouveau `app/Models/Tenant.php` + `database/migrations/*_create_tenants_table.php` (TO BE CREATED)
   • test: (TO BE CREATED at `tests/Feature/Tenant/TenantModelTest.php`)
- T-1.1.2 Ajouter `tenant_id` (FK) à `branches` + aux 22 tables BranchScope + aux 10 exemptées → migration additive + backfill (tenant 1 = Le Cayenne).
   • anchor: `database/migrations/*_add_tenant_id_*` (TO BE CREATED) ; backfill via `Branch::all()`
   • test: (TO BE CREATED at `tests/Feature/Tenant/TenantBackfillTest.php`) — 100% des lignes existantes → tenant_id=1
- T-1.1.3 Remplir `Branch.tenant_id` + relation `belongsTo(Tenant)` + `Branch::hasMany(User/Order/...)`.
   • test: (TO BE CREATED at `tests/Feature/Tenant/BranchTenantRelationTest.php`)
**Acceptance**: migration additive nullable→backfill→not-null ; sentinel BranchScope toujours vert ; 0 ligne orpheline.

### Sub 1.2 — TenantScope (isolation, AU-DESSUS de BranchScope)
- T-1.2.1 Créer `TenantScope` (global scope) qui filtre TOUTES les requêtes par `tenant_id` du contexte courant, AVANT le filtre branche.
   • anchor: nouveau `app/Models/Scopes/TenantScope.php` (TO BE CREATED) — modèle sur BranchScope:13-42
   • test: (TO BE CREATED at `tests/Feature/Tenant/TenantScopeIsolationTest.php`) — tenant A ne voit JAMAIS une ligne tenant B (P0 fuite)
- T-1.2.2 **Refondre l'admin branch_id=0**: aujourd'hui = dieu global. Cible: `super_admin` (support FoodKing, cross-tenant explicite + audité) vs `tenant_admin` (god DANS son tenant uniquement). Supprimer le bypass per-user implicite.
   • anchor: `BranchScope.php:31-35` (FROZEN → **LOCK requis**) + `IdempotencyKeyMiddleware.php:188-194` + `DefaultAccessModelTrait.php:21` + `AppLibrary.php:215` (4 sites dupliqués → centraliser)
   • test: (TO BE CREATED at `tests/Feature/Tenant/AdminBypassTenantBoundedTest.php`) — un tenant_admin ne peut PAS muter une autre tenant via payload branch_id
- T-1.2.3 `TenantContext` (facade/singleton) + middleware résolvant le tenant par **domaine/sous-domaine** (puis fallback user.tenant_id), tôt dans le cycle requête.
   • anchor: nouveau `app/Support/TenantContext.php` + `app/Http/Middleware/ResolveTenant.php` (TO BE CREATED)
   • test: (TO BE CREATED at `tests/Feature/Tenant/TenantResolutionMiddlewareTest.php`)
**Acceptance**: test d'isolation P0 vert (A↔B étanche) ; les 4 sites de bypass dupliqués → 1 service ; LOCK BranchScope countersigné.

### Sub 1.3 — RBAC tenant-scopé
- T-1.3.1 Seeder rôles tenant-hiérarchie (super_admin global / tenant_admin / branch_admin / branch_manager / pos_operator / chef / waiter…) avec `tenant_id` sur l'attribution.
   • anchor: `database/seeders/RoleTableSeeder.php` (étendre) ; Spatie `model_has_roles` + colonne tenant
   • test: (TO BE CREATED at `tests/Feature/Tenant/TenantScopedRolesTest.php`)
- T-1.3.2 Sentinel `TenantScopeCoverageSentinelTest` (jumeau de BranchScopeCoverageSentinel) — toute table avec `tenant_id` DOIT déclarer TenantScope ou être exemptée explicitement.
   • test: (TO BE CREATED at `tests/Feature/Tenant/TenantScopeCoverageSentinelTest.php`)
**Acceptance**: sentinel tenant vert ; un branch_manager tenant A ≠ pouvoir sur tenant B.

---

## §2 — Système 2 : FISCAL NF525 multi-entité-légale

### Contract
Chaque tenant = **entité légale distincte** (SIRET propre, chaîne NF525 propre, Z propres, secret de signature propre, rétention propre). Aujourd'hui tout est **par-branche** avec secret Z **global** → insuffisant légalement pour N entités.

### Anchors (vérifiés)
- `app/Services/Fiscal/FiscalSequenceService.php:57-114` — séquence per-branche, lock `fiscal_seq_b{branchId}`, UNIQUE(branch_id, fiscal_sequence_no).
- `app/Services/Fiscal/AuditLogService.php:56-110,269-292` — chaîne HMAC per-branche (`audit_chain_b{id}`), secret override per-branche `FISCAL_AUDIT_SECRET_BRANCH_{id}` sinon global.
- `app/Services/Fiscal/FiscalSealingService.php:60-68` — secret Z **GLOBAL uniquement** (pas d'override).
- migrations `2026_04_22_*` — UNIQUE(branch_id, prev_hash) audit_logs ; UNIQUE(branch_id, sequence_no) z_reports ; **aucun tenant_id**.
- `config/fiscal.php:31,38,148` — `FISCAL_AUDIT_SECRET`, `FISCAL_Z_REPORT_SECRET`, `archive_retention_years=6` (globaux).
- `AppServiceProvider.php:165-302` — boot guards single-box (APP_URL unique, CACHE driver, IDEMPOTENCY).

### Sub 2.1 — Dimension tenant dans la chaîne fiscale (FROZEN → LOCK obligatoire)
- T-2.1.1 Ajouter `tenant_id` à `orders`/`audit_logs`/`z_reports` + index composites `UNIQUE(tenant_id, branch_id, …)`.
   • anchor: migrations `2026_04_22_*` (additif) ; **LOCK_FISC** countersigné
   • test: (TO BE CREATED at `tests/Feature/Fiscal/Tenant/FiscalTenantColumnTest.php`)
- T-2.1.2 `FiscalSequenceService::next(tenant_id, branch_id)` — séquence gap-free **par entité légale**, lock `fiscal_seq_t{tenant}_b{branch}`.
   • anchor: `FiscalSequenceService.php:57-114` (FROZEN → LOCK) ; test régression chaîne triple-vert
   • test: existant `tests/Feature/Fiscal/*` (ré-exécuter) + (TO BE CREATED `FiscalSequencePerTenantGapFreeTest.php`)
- T-2.1.3 Inclure `tenant_id`/SIRET dans le payload signé Z (`FiscalSealingService.php:26-31`) + chaîne audit `audit_chain_t{tenant}`.
   • test: (TO BE CREATED at `tests/Feature/Fiscal/Tenant/ZSignatureIncludesTenantTest.php`)
**Acceptance**: 2 tenants sur la même branche → 2 chaînes/séquences/Z **indépendamment attestables gap-free** ; `fiscal:verify-chain --tenant=X` isolé.

### Sub 2.2 — Secrets + rétention par entité légale
- T-2.2.1 Résolution de secret **per-tenant** (`FISCAL_AUDIT_SECRET_TENANT_{id}`, `FISCAL_Z_REPORT_SECRET_TENANT_{id}`) → un tenant compromis ≠ tous. (P0 sécurité)
   • anchor: `AuditLogService.php:269-292` (étendre per-tenant) + `FiscalSealingService.php:60-68` + `config/fiscal.php`
   • test: (TO BE CREATED at `tests/Feature/Fiscal/Tenant/PerTenantSecretTest.php`)
- T-2.2.2 Rétention/jurisdiction **par tenant** (FR 6 ans, autres pays variables) au lieu de `archive_retention_years` global.
   • anchor: `config/fiscal.php:148` → `tenants.retention_years`
   • test: (TO BE CREATED at `tests/Feature/Fiscal/Tenant/PerTenantRetentionTest.php`)
**Acceptance**: rotation/compromission d'un secret tenant n'affecte aucun autre ; cron d'archivage respecte la rétention par tenant.
**⚠️ Gate légal §G-FISC**: certification NF525 par entité légale = avis expert-comptable/éditeur. Décision « hybride DB » (§0.5-1) impacte directement ce système.

---

## §3 — Système 3 : CATALOGUE tenant-scopé + composition (socle réutilisable)

### Contract
Le catalogue (items/categories/variations/extras) est **GLOBAL** aujourd'hui → chaque tenant doit avoir SON menu. Le composer/Wizard Studio (déjà bâti+durci, voir `plans/UIUX_SUPERVISOR_CONVERGENCE_2026-06-15.md`) est le **socle de flexibilité produit cross-secteur**.

### Anchors (vérifiés)
- `app/Models/Item.php` — **0 `branch_id`** (global) ; `ItemCategory/ItemVariation/ItemExtra` idem ; visibilité par `visible_on`/`channels`.
- `app/Models/ItemBranchAvailability.php:9-53` — per-branche on/off SEULEMENT (pas prix/nom/image).
- `app/Models/ItemWizardProfile.php:10-111` (+ `branch_id_scope` nullable) + `app/Services/Composer/ComposerProfileProjection.php` — composition configurable (RÉUTILISABLE).
- `app/Services/Pricing/PricingService.php:36-369` — SSOT prix backend ; `composition_snapshot` immuable.

### Sub 3.1 — Tenant-scoping du catalogue
- T-3.1.1 `tenant_id` sur items/categories/variations/extras/wizard_profiles + TenantScope (chaque tenant = catalogue isolé). Le catalogue reste GLOBAL **au sein d'un tenant** (pas de BranchScope) — overrides per-branche via `ItemBranchAvailability` étendu.
   • anchor: migrations catalog (additif) ; `Item.php`, `ItemCategory.php`
   • test: (TO BE CREATED at `tests/Feature/Tenant/Catalog/TenantCatalogIsolationTest.php`)
- T-3.1.2 `ItemBranchCustomization` (nom/image/prix override per-branche) — débloquer « thin crust sur branche A, sicilian sur branche B ».
   • anchor: nouveau modèle + migration (TO BE CREATED) ; étend `ItemBranchAvailability:9-53`
   • test: (TO BE CREATED at `tests/Feature/Tenant/Catalog/BranchCatalogOverrideTest.php`)
**Acceptance**: tenant A ≠ voir/utiliser le menu de tenant B ; override per-branche live-prouvé.

### Sub 3.2 — Composer comme socle multi-secteur (extension des acquis)
- T-3.2.1 **Modificateurs de portion** (half-and-half pizza, « extra fromage moitié gauche ») : champ `portion`/`side` sur ItemExtra + métadonnée de choix composer.
   • anchor: `ItemExtra.php` (+ champ) ; `ComposerProfileProjection.php:75-80` (choix sans portion aujourd'hui)
   • test: (TO BE CREATED at `tests/Feature/Composer/PortionModifierTest.php`) + visuel borne pizza
- T-3.2.2 Pricing portion-aware (½ topping = ½ prix) dans PricingService (FROZEN → LOCK si modif cœur, sinon extension).
   • anchor: `PricingService.php:168-191` (extras pricing) ; LOCK si nécessaire
   • test: (TO BE CREATED at `tests/Feature/Pricing/PortionPricingTest.php`)
**Acceptance**: pizza moitié-moitié commandable, facturée correctement (NF525), rendue identique borne+caisse.

### Sub 3.3 — Pricing sectoriel (temps + folio)
- T-3.3.1 **Pricing temporel** (happy hour: -30% cocktails 17-19h) au niveau prix (pas juste éligibilité coupon).
   • anchor: `PricingService.php:36-369` (aucune fenêtre temps sur item.price) ; `Coupon.php:31-33` (éligibilité only)
   • test: (TO BE CREATED at `tests/Feature/Pricing/TimeBasedPricingTest.php`)
**Acceptance**: prix horaire auto-appliqué backend, snapshot immuable, NF525 OK.

---

## §4 — Système 4 : MODULES SECTORIELS (order-flow) — activables par tenant

### Contract
Socle commun (Order/OrderItem/OrderStateMachine/KDS/OSS) + **modules** activés par `tenant.modules[]`. Order types déjà présents (DINING_TABLE/TAKEAWAY/DELIVERY/KIOSK/POS). Manquent les primitives métier par secteur.

### Anchors (vérifiés)
- `app/Enums/OrderType.php:5-12` (5 types) ; `app/Domain/Order/OrderStateMachine.php:25-91` (FROZEN — pas d'états coursing/tab/split).
- `app/Models/Order.php` — pas de seat/course/tab/split/server/room/folio ; `app/Models/OrderItem.php:61-80` — pas de course_id/seat_id/split_group.
- `app/Models/DiningTable.php:9-56` — occupancy only (pas transfer/merge/seats).
- `app/Models/Item.php:46` — `kds_station` (routing make-line existe).

### Sub 4.1 — Module Table-service à-la-carte (le plus gros chantier)
- T-4.1.1 `OrderItem.{seat_id, course_id, fire_at, ready_at}` + `Order.{server_id, tab_status}` (additif).
   • test: (TO BE CREATED at `tests/Feature/TableService/CoursingSchemaTest.php`)
- T-4.1.2 **Coursing** dans l'OrderStateMachine (FROZEN → LOCK) : fire apps → hold mains → fire mains ; KDS coursing-aware.
   • anchor: `OrderStateMachine.php:25-91` (LOCK) ; KDS `Item.kds_station`
   • test: (TO BE CREATED at `tests/Feature/TableService/CoursingFlowTest.php`) + E2E KDS coursing
- T-4.1.3 **Running tab + split bill** (par siège / par montant / par moyen de paiement) + multi-paiement par order.
   • anchor: `Order.dining_table_id` (isolé aujourd'hui) ; `OrderPayment` (+ split_group_id)
   • test: (TO BE CREATED at `tests/Feature/TableService/SplitBillTest.php` — fiscal: chaque encaissement = ligne NF525)
- T-4.1.4 Table transfer/merge + assignation serveur.
   • anchor: `DiningTable.php:9-56` (+ transfer/merge)
   • test: (TO BE CREATED at `tests/Feature/TableService/TableTransferTest.php`)
**Acceptance**: 4 couverts → 1 tab → coursing KDS → split paiement → **chaque encaissement gap-free NF525** ; E2E table-service vert.

### Sub 4.2 — Modules Pizza / Bar / Coffeeshop / Hôtel / Retail (par vague GTM)
- T-4.2.1 **Pizza**: half-and-half (dépend §3.2) + make-line KDS. Test: `tests/Feature/Sector/PizzaHalfTest.php` (TO BE CREATED).
- T-4.2.2 **Bar**: open tab + pré-auth + happy-hour (dépend §3.3) + **vérif d'âge** (`item.requires_age_check` + `OrderItem.age_verified_at`). Test: `tests/Feature/Sector/BarTabAgeTest.php` (TO BE CREATED).
- T-4.2.3 **Coffeeshop**: order-ahead + créneau pickup + « ma commande habituelle » (preset) + groupes de modificateurs (composer). Test: `tests/Feature/Sector/CoffeeOrderAheadTest.php` (TO BE CREATED).
- T-4.2.4 **Hôtel**: `Order.{room_id, folio_id, pms_reference}` + charge chambre + bons petit-déj + intégration PMS (service sync). Test: `tests/Feature/Sector/HotelFolioTest.php` (TO BE CREATED). **Gate intégration tierce §G-PMS**.
- T-4.2.5 **Retail générique**: mode barcode-only (scan→prix→panier, bypass composer) + inventaire pur sans cuisine. Test: `tests/Feature/Sector/RetailBarcodeTest.php` (TO BE CREATED).
**Acceptance par module**: activable/désactivable par `tenant.modules` ; un tenant fast-food ne voit aucune UI table-service ; E2E par secteur vert ; NF525 préservé partout.

---

## §5 — Système 5 : INFRA / DEPLOY / CONFIG SaaS

### Contract
Passer du **single-box OVH** au **cloud multi-tenant**. Bonne nouvelle vérifiée: **l'isolation des canaux temps-réel est déjà correcte** (`routes/channels.php:41-62`, branch.{id} + token). Les verrous sont config/déploiement.

### Anchors (vérifiés)
- `.env.example` — `CACHE_DRIVER=file` (redis prod), `SESSION_DRIVER=file`, Pusher **app unique** (1 credential = tous les canaux), single `APP_URL`.
- `config/app.php:140,157` — `locale=fr` (« DO NOT CHANGE »), `EUR` global ; `Branch.available_locales` existe mais fallback global.
- `config/features.php:1-53` — **2 flags globaux** seulement (pas de store per-tenant).
- `deploy/ansible/site.yml` — single OVH VPS, Soketi mono-box ; backup dump global (restore all-or-nothing).
- `routes/channels.php:41-62` — isolation canal OK (✅).

### Sub 5.1 — Isolation runtime cross-tenant (P0)
- T-5.1.1 Préfixe `tenant_id` OBLIGATOIRE sur toutes les clés Cache/Redis + idempotency (sinon collision cross-tenant). Test: `tests/Feature/Tenant/Infra/CacheKeyTenantPrefixTest.php` (TO BE CREATED).
- T-5.1.2 Canaux broadcast `tenant.{t}.branch.{b}` (durcir l'isolation déjà correcte) ; `SESSION_DRIVER=database/redis` (multi-instance). anchor: `routes/channels.php:41-62`. Test: `tests/Feature/Tenant/Infra/BroadcastTenantIsolationTest.php` (TO BE CREATED).
**Acceptance**: aucune fuite Cache/canal/session cross-tenant (P0) ; test d'abonnement croisé refusé.

### Sub 5.2 — Config per-tenant + modules
- T-5.2.1 Déplacer locale/currency/timezone/jurisdiction de `config/app.php` vers `tenants.*` (fallback config global). Débloque multi-pays.
   • anchor: `config/app.php:140,157` ; `Branch.available_locales`
   • test: (TO BE CREATED at `tests/Feature/Tenant/Infra/PerTenantLocaleCurrencyTest.php`)
- T-5.2.2 **Store de feature-flags/modules per-tenant** (DB) remplaçant `config/features.php` global → active les modules sectoriels §4.
   • anchor: `config/features.php:1-53`
   • test: (TO BE CREATED at `tests/Feature/Tenant/Infra/PerTenantModuleFlagTest.php`)
**Acceptance**: 1 tenant FR-EUR + 1 tenant autre-locale coexistent ; modules togglés par tenant pilotent l'UI.

### Sub 5.3 — Déploiement multi-instance + boot guards
- T-5.3.1 Guards `AppServiceProvider:165-302` rendus per-tenant (APP_URL/domain par tenant, CACHE redis obligatoire multi-instance — lever UNI-03 backlog).
   • anchor: `AppServiceProvider.php:253-302` ; backlog UNI-03 (CLAUDE.md §8)
   • test: (TO BE CREATED at `tests/Feature/Tenant/Infra/PerTenantBootGuardTest.php`)
- T-5.3.2 Orchestration multi-instance (Docker/K8s), Redis/MySQL partagés scopés, LB, **Soketi/Pusher namespace par tenant**, backup tenant-aware. **Gate ops §G-CLOUD**.
**Acceptance**: 2 tenants, 2 domaines, 1 infra partagée, 0 fuite, health per-tenant.

---

## §6 — Système 6 : BUSINESS SaaS (billing + onboarding) — n'existe pas

### Contract
Couche monétisation/provisioning absente. SaaS = plans, abonnements, facturation, onboarding self-serve, seed catalogue par secteur.

### Anchors (vérifiés)
- `ls app/Models | grep -iE 'subscri|plan|billing'` → **rien** (seul `Subscriber.php` = newsletter). Aucune intégration Stripe/Cashier.

### Sub 6.1 — Plans & abonnements
- T-6.1.1 `plans`/`subscriptions` + Laravel Cashier (Stripe) ; le `plan` débloque les modules sectoriels (§5.2). Test: `tests/Feature/Billing/SubscriptionTest.php` (TO BE CREATED).
- T-6.1.2 Gating modules par plan (fast-food gratuit, table-service/hotel = tiers payants). Test: `tests/Feature/Billing/PlanModuleGatingTest.php` (TO BE CREATED).

### Sub 6.2 — Onboarding / provisioning
- T-6.2.1 Signup self-serve → création tenant + sous-domaine + seed catalogue **par secteur choisi** + pairing matériel. Test: `tests/Feature/Onboarding/TenantProvisioningTest.php` (TO BE CREATED).
- T-6.2.2 Templates catalogue par secteur (pizza/coffee/bar starter menus). Test: `tests/Feature/Onboarding/SectorCatalogSeedTest.php` (TO BE CREATED).
**Acceptance**: un nouveau client choisit « pizzeria », obtient un tenant isolé + menu starter + modules pizza activés, en self-serve.

---

## §A — Agent Army Map + Fan-Out (par tâche, via ultra-audit-profond)

| Rôle | Subagent | Tools | Focus SaaS |
|---|---|---|---|
| Architect | Plan | RO | hiérarchie tenant→branch, ordre des scopes |
| **Security (renforcé)** | general-purpose | RO | **fuite cross-tenant = P0 systématique** sur chaque tâche |
| DBA | general-purpose | RO | tenant_id backfill, index composites, FK cascade, N+1 |
| Fiscal | general-purpose | RO | NF525 per-entité, gap-free, secrets, rétention |
| SRE/Sync | general-purpose | RO | cache/canal/session isolation, multi-instance |
| Implementer | general-purpose | Edit/Write/Bash | TDD-first, jamais parallèle entre implémenteurs |
| RED-team | general-purpose | RO | tenter de fuir A→B après chaque heal |
| QA/RED Visual | general-purpose | Playwright | UI par secteur (modules togglés), 2 tenants |

**Fan-out**: tâche tenancy/fiscal/migration → Architect+Security+DBA+Fiscal+Implementer+RED (Security TOUJOURS). Tâche secteur frontend → +UX+QA/RED Visual. **5 spécialistes RO en 1 message** (parallèle). Implémenteur jamais ×2.

---

## §X — Vagues de convergence (séquentiel par défaut — tenancy débloque tout)

| Vague | Scope | Parallélisme | Dépend de | Checkpoint clé |
|---|---|---|---|---|
| **V0 Décisions** | Trancher §0.5 (1/2/3) + §G gates | — | — | Propriétaire a tranché DB-model + secteurs + GTM |
| **V1 Tenancy Core** | §1 (entité, tenant_id, TenantScope, RBAC) | séquentiel (touche tout) | V0 | isolation P0 A↔B verte ; sentinel tenant ; LOCK BranchScope |
| **V2 Fiscal multi-entité** | §2 | séquentiel (FROZEN/NF525) | V1 | 2 chaînes indépendantes gap-free ; gate légal §G-FISC |
| **V3 Catalogue tenant** | §3.1 + §3.2 + §3.3 | séquentiel | V1 | menus isolés ; half-and-half ; pricing temporel |
| **V4 Module Table-service** | §4.1 | séquentiel (FROZEN OrderStateMachine) | V1,V3 | coursing+tab+split E2E ; NF525 chaque encaissement |
| **V5 Modules secteur (GTM)** | §4.2 (pizza→bar→coffee→hôtel→retail) | **modules disjoints = parallèle possible** | V3,V4 | par module: toggle tenant + E2E + NF525 |
| **V6 Infra SaaS** | §5 | partiel parallèle | V1 | 0 fuite cache/canal/session ; multi-instance |
| **V7 Business SaaS** | §6 | séquentiel | V1,V5,V6 | onboarding self-serve d'un tenant secteur |

**Checkpoint (6 points, Axis 3)** par vague: tâches PASS ; frozen diff 0 (ou LOCK) ; **NF525 chain attestée PAR TENANT** ; gate visuel ; RED dispute (fuite cross-tenant=0) ; BRAIN §2/§3 maj.
**Interrupt-resume**: commit WIP `wip(Vn): …` + manifeste `reports/saas/INTERRUPT_Vn_<ts>.md` + BRAIN §2.
**Convergence-failure (3 cycles)**: STOP → subagent Plan « pivot DB-model ? » → `reports/saas/STUCK_Vn.md` → choix propriétaire (accept-doc / pivot / défér / human).

---

## §G — Owner Gates (WHO / WHAT / WHERE)

| Gate | Description | WHO | WHAT | WHERE | Statut |
|---|---|---|---|---|---|
| G-0 | Modèle d'isolation DB (shared / per-tenant / **hybride reco**) | Propriétaire | décision écrite | BRAIN §6 + commit tag | **PENDING (bloque V1+)** |
| G-SECTORS | Quels secteurs V1.0 + ordre GTM | Propriétaire | liste priorisée | BRAIN §6 | PENDING (bloque V5) |
| G-FISC | Certification NF525 **par entité légale** | Propriétaire + expert-comptable/éditeur | avis conformité | LOCK_FISC §10 | PENDING (bloque V2) |
| G-PMS | Intégration PMS hôtel (quel système) | Propriétaire | choix PMS + accès API | reports/saas/ | PENDING (bloque §4.2.4) |
| G-CLOUD | Provider cloud + budget infra multi-instance | Propriétaire | choix infra + budget | reports/saas/ | PENDING (bloque V6 deploy) |
| G-BILLING | Modèle de prix SaaS (plans/tiers) + Stripe | Propriétaire | grille tarifaire + compte Stripe | reports/saas/ | PENDING (bloque V7) |
| G-PUSH | Pousser la branche saas/ | Propriétaire | autorisation explicite | commit/PR | PENDING |

**Owner-gate-waiting**: V0/V1 (tenancy local) peuvent avancer pendant que G-PMS/G-CLOUD/G-BILLING sont PENDING. G-0 et G-FISC bloquent en dur.

---

## §R — Références
- `plans/UIUX_SUPERVISOR_CONVERGENCE_2026-06-15.md` + `plans/ADVERSARIAL_ARMY_CONVERGENCE_2026-06-15.md` — le composer/Wizard Studio (socle §3.2, déjà durci).
- `CONSTITUTION.md` + `SYSTEM_MAP.md` + `CLAUDE.md §7/§8/§9` (frozen, NF525, multi-tenant invariants) + `PROJECT_BRAIN.md §2`.
- Cartographies sources (4 agents Explore, 2026-06-15) — anchors §1-§5.
- Skills: `ultra-audit-profond` (pipeline tâche), `lock-plan` (override frozen), `test-e2e` (convergence), `superpower-gstack` (LOOP).

## §F — Règle finale
DONE = SaaS multi-tenant où **2 tenants de secteurs différents coexistent, totalement étanches (0 fuite P0), chacun avec sa chaîne NF525 indépendamment attestable, ses modules sectoriels, sa config**, le tout sans casser un seul invariant frozen/NF525 du V1. **Production-perfect par tenant, pas « presque ».** Le socle (tenancy + composer + pricing SSOT) est la fondation ; les secteurs sont des modules, pas des forks.

> **Le point faible #1 = l'absence totale de couche Tenant** (`MultiTenantModelTrait` vide, pas de `tenant_id`, admin=dieu-global per-user). C'est le keystone : rien d'autre n'est sûr tant qu'il n'est pas posé. Le point faible #2 = **la chaîne NF525 par-branche avec secret Z global** (blocage légal multi-entité). Le point fort = **le composer/Wizard Studio + pricing SSOT + isolation canaux temps-réel déjà correcte** = une vraie fondation réutilisable.
