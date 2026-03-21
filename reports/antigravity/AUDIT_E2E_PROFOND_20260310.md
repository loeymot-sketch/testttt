# Audit E2E Profond — FoodKing SaaS

**Date :** 10 Mars 2026  
**Type :** Deep E2E Test & Audit  
**Méthodologie :** Analyse statique, exécution suite PHP, revue documentaire, cartographie des flux

---

## 1. RÉSUMÉ EXÉCUTIF

| Dimension | Score | Verdict |
|-----------|-------|---------|
| **Tests PHP (état actuel)** | 59% (101/171) | ⚠️ 70 échecs à traiter |
| **Architecture & Docs** | 9/10 | ✅ Alignée, source of truth claire |
| **Sécurité Core (Prix, Auth)** | 8/10 | ✅ ValidJsonOrder, recalcul serveur OK |
| **Flux E2E couverts** | 6/10 | ⚠️ Pas de Playwright, purge menu en tests |
| **Authz & Isolation** | 7/10 | ⚠️ Tests authz échouent (attentes vs implémentation) |

**Verdict global :** Le cœur métier (prix, validation JSON, flux POS/Kiosk) est solide et documenté. Les échecs de tests proviennent principalement de **fixtures incomplètes** (Tax::factory manquant, migration purge menu sans re-seed), et de **décalages entre attentes des tests et implémentation réelle** (api-key, rôles).

---

## 2. RÉSULTATS SUITE PHP (`php artisan test`)

### 2.1 Synthèse

| Suite | Pass | Fail | Commentaire |
|-------|------|------|-------------|
| ValidJsonOrderTest | 11 | 0 | ✅ D-004 validé |
| AntiGravityTest | 20 | 0 | ✅ Core sécurité OK |
| POSComprehensiveTest | 8 | 0 | ✅ Flux POS OK |
| AntiGravityManualTest | 2 | 1 | ⚠️ AG-02, AG-03 |
| AntiGravityFinalTest | 0 | 1 | ⚠️ AG-02, AG-04 |
| KioskAuthTest | 0 | 2 | 400 au lieu de 200 |
| KDSFlowTest | 2 | 2 | 403 vs 422 attendu |
| KDSScopeRestrictionTest | 0 | 1 | Chef accède settings |
| SecurityComprehensiveTest | 0 | 6 | api-key, authz |
| PosDiscountTest | 0 | 3 | Tax::factory manquant |
| PosUITest | 0 | 3 | Tax::factory manquant |
| BranchScopeTest | 0 | 1 | subtotal NULL |
| SyncComprehensiveTest | 0 | 1 | table order |
| FrontendOrderServiceTest | 3 | 1 | source code |
| OrderServiceSecurityTest | 4 | 1 | source code |
| Autres | 53 | 48 | Divers |
| **Total** | **101** | **70** | |

### 2.2 Causes racines des échecs

#### A. Migration purge menu sans re-seed

**Fichier :** `database/migrations/2026_03_11_000000_reset_menu_french.php`

- **Comportement :** Lors de `RefreshDatabase`, cette migration purge `items`, `item_categories`, `item_extras`, etc.
- **Problème :** Elle ne re-seed pas le menu. Les tests qui créent des items via `Item::factory()` ou qui attendent des catégories existantes échouent car les tables sont vides.
- **Impact :** AdminCrudComprehensiveTest, KioskFrontendComprehensiveTest, AntiGravityManualTest, etc.

**Recommandation :**  
- Option A : Exclure cette migration de l’environnement de test (config `phpunit.xml` ou migration conditionnelle).  
- Option B : Appeler `MenuSeeder` à la fin de la migration (risque de dépendance circulaire).  
- Option C : Créer une migration de test qui seed un menu minimal après la purge.

#### B. `Tax::factory()` manquant

**Tests :** PosDiscountTest, PosUITest

- **Erreur :** `Call to undefined method App\Models\Tax::factory()`
- **Cause :** Le modèle `Tax` n’a pas de Factory définie.
- **Recommandation :** Créer `database/factories/TaxFactory.php` ou utiliser `Tax::create()` avec des données explicites.

#### C. Décalage authz / api-key

**Tests :** SecurityComprehensiveTest, KDSScopeRestrictionTest, KioskAuthTest

- **Observations :**
  - `admin routes require api key` : le test attend 401/403 sans x-api-key, mais reçoit 200.
  - `kiosk cannot access admin dashboard` : le test utilise `actingAs($user)` avec un User ayant un rôle Kiosk ; l’API renvoie 200 au lieu de 403.
  - `kiosk login with valid credentials` : renvoie 400 au lieu de 200 (probablement format username/password ou machine_id).
- **Cause probable :**  
  - L’api-key n’est pas obligatoire pour les routes admin (ou est fournie par défaut dans le test).  
  - Les rôles/abilities ne sont pas appliqués comme attendu par les tests.  
  - Le login Kiosk attend un format différent (machine_id vs username).

**Recommandation :** Aligner les tests avec l’implémentation réelle (middleware, routes) ou corriger l’implémentation pour respecter AUTHZ_MATRIX.md.

#### D. KDSFlowTest — statuts HTTP

- `kds invalid transition` : attend 422, reçoit 403.
- `kds change status accept to preparing` : reçoit 403 (non attendu).

**Recommandation :** Vérifier la logique de transition dans `PosOrderController` / `OrderService` et les permissions Chef. Adapter les assertions ou le code selon la doc ORDER_FLOW.

#### E. BranchScopeTest — `subtotal` NULL

- **Erreur :** `NOT NULL constraint failed: orders.subtotal`
- **Cause :** Le test crée une commande sans `subtotal` ou via un chemin qui ne le remplit pas.
- **Recommandation :** S’assurer que `OrderService` ou le test fournit toujours `subtotal` pour les commandes.

---

## 3. COUVERTURE DES FLUX E2E

### 3.1 Flux documentés (ORDER_FLOW)

| Étape | Statut | Qui écrit | Qui lit | Couvert par tests |
|-------|--------|-----------|---------|-------------------|
| 1 | PENDING | Kiosk / POS | Caissier | ✅ POSComprehensive, API |
| 2 | ACCEPT | Caissier | KDS, OSS | ✅ POSComprehensive |
| 3 | PREPARING | Chef (KDS) | OSS, POS | ⚠️ KDSFlowTest échoue |
| 4 | PREPARED | Chef | Caissier, OSS | ⚠️ Partiel |
| 5 | DELIVERED | Caissier | Archive | ✅ |

### 3.2 Flux par appareil (DEVICE_FLOW)

| Appareil | Écriture | Lecture | Tests |
|----------|----------|---------|-------|
| **Kiosk** | POST /api/frontend/order | GET frontend/item | KioskFrontendComprehensive, KioskAuthTest (échoue) |
| **POS** | POST /api/admin/pos | Firebase push | POSComprehensive, AntiGravityManualTest |
| **KDS** | change-status | Liste commandes | KDSFlowTest, KDSScopeRestrictionTest (échouent) |
| **OSS** | Aucune | queue_number | OSSReadOnlyTest |

### 3.3 Gaps E2E

| Gap | Sévérité | Description |
|-----|----------|-------------|
| **Pas de Playwright E2E** | Haute | `@playwright/test` présent mais pas de `playwright.config`, pas de tests navigateur. |
| **AG-10, AG-02, AG-11, AG-13** | Moyenne | Checklist manuelle créée ; pas d’automatisation. |
| **Pusher / KDS temps réel** | Moyenne | AG-13 non automatisable sans mock Pusher. |
| **Wizard POS complet** | Moyenne | Pas de test E2E du wizard (viandes, sauces, garnitures, menu, récap). |
| **Paiement Cash** | Basse | Couvert par API ; pas de test UI. |

---

## 4. AUDIT SÉCURITÉ

### 4.1 Points validés

| Élément | Statut |
|---------|--------|
| Recalcul prix serveur | ✅ OrderService, FrontendOrderService |
| ValidJsonOrder (item_id, quantity) | ✅ 11/11 tests |
| Isolation branch KDS | ✅ Code présent |
| CouponService | ✅ Validation serveur |
| Sanctum + Spatie | ✅ En place |

### 4.2 Points d’attention

| Élément | Risque | Réf |
|---------|--------|-----|
| `rand()` / `mt_rand()` (Credit, OTP) | Moyen | AUDIT_PROFOND_COMPLET_20260312 |
| `env()` direct ApiKeyMiddleware | Faible | SECURITY_NOTES |
| v-html (PageComponent) | XSS potentiel | AUDIT_PROFOND_COMPLET_20260312 |

---

## 5. ARCHITECTURE & DOCUMENTATION

### 5.1 Alignement docs ↔ code

| Document | État |
|----------|------|
| ARCHITECTURE.md | ✅ |
| ORDER_FLOW.md | ✅ |
| DEVICE_FLOW.md | ✅ |
| AUTHZ_MATRIX.md | ⚠️ Tests authz échouent |
| BUSINESS_RULES.md | ✅ |
| API_MAP.md | ✅ |
| DATABASE_SCHEMA_CORE.md | ✅ |

### 5.2 Validations POS (PosOrderRequest)

| Champ | Règle |
|-------|-------|
| items | required, json, ValidJsonOrder |
| customer_id | required |
| branch_id | required |
| subtotal | required |
| total | required |
| order_type | required |
| pos_payment_method | required |
| pos_received_amount | required si Cash |

---

## 6. PLAN D’ACTION PRIORISÉ

### P0 — Bloquants tests

1. **Migration purge menu** : Exclure ou adapter pour l’environnement de test (menu minimal après purge).
2. **Tax::factory()** : Créer ou remplacer par `Tax::create()` dans les tests.
3. **BranchScopeTest subtotal** : Corriger la création de commande pour inclure `subtotal`.

### P1 — Authz & API

4. **Kiosk login 400** : Vérifier le format attendu (username/machine_id, password) et le contrat KioskMachineLoginController.
5. **SecurityComprehensiveTest** : Aligner les tests avec le middleware api-key et les rôles réels.
6. **KDSFlowTest** : Corriger les assertions ou la logique de transition KDS.

### P2 — E2E

7. **Playwright** : Ajouter `playwright.config.js` et des tests E2E pour POS (login, ajout item, wizard, paiement).
8. **AG-10, AG-02, AG-11, AG-13** : Automatiser via Playwright ou conserver la checklist manuelle.

---

## 7. ANNEXES

### 7.1 Tests passants (référence)

- ValidJsonOrderTest (11)
- AntiGravityTest (20)
- POSComprehensiveTest (8)
- ValidJsonOrder (D-004)
- OrderStateTransitionTest
- PricingIntegrityTest
- MenuSeederTest
- CouponSecurityTest
- OSSReadOnlyTest
- etc.

### 7.2 Fichiers modifiés récemment (pertinents)

- `reports/antigravity/MANUAL_TEST_CHECKLIST_AG_10_02_11_13.md` — Checklist manuelle
- `reports/planning/sprint_24_finalisation.md` — Plan Sprint 24
- `database/migrations/2026_03_11_000000_reset_menu_french.php` — Purge menu

---

*Rapport généré par audit E2E profond — 10 Mars 2026*
