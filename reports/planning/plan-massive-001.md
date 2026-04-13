# 📋 PLAN MASSIVE 001 - Phase 1: Fix Critiques + Tests Complets

> **Planification:** Claude (Lead Architect)  
> **Date:** 2026-03-10  
> **Basé sur:** [reports/antigravity/report-massive-audit-001.md](../antigravity/report-massive-audit-001.md)

---

## 🎯 OBJECTIF

Passer de 16/18 tests à 18/18 tests verts, puis implémenter 80 tests massifs pour couvrir tous les flux de commande FoodKing.

---

## 📊 ÉTAT ACTUEL

- **Score actuel:** 16/18 (89%)
- **Tests échouant:** T05, T06 (null pointer `faviconLogo`)
- **Tests manquants:** 62 tests sur les 80 du MASSIVE_TEST_PLAN

---

## 🚀 PHASE 1: FIX CRITIQUES (URGENT)

### Tâche 1.1: Fix Null-Safe dans les Vues Blade

**Problème:** `payment.blade.php:21` utilise `$logo->logo` sans vérification null.

**Fichiers à modifier:**

1. `resources/views/payment.blade.php` (ligne 21)
   ```php
   // AVANT (non null-safe):
   <img class="w-full" src="{{ $logo->logo }}" alt="logo">
   
   // APRÈS (null-safe):
   <img class="w-full" src="{{ $logo?->logo ?? asset('images/theme/theme-logo.png') }}" alt="logo">
   ```

2. `resources/views/paymentSuccess.blade.php`
   - Vérifier toutes les références à `$logo`, `$faviconLogo`
   - Rendre null-safe avec `?->` et `??`

3. `resources/views/paymentGateways/cashfree/cashfreeJs.blade.php`
   - Vérifier les mêmes patterns

**Assigné à:** Kimi  
**Type de test:** local-validation (unit/integration)  
**Validation:** Playwright / E2E verification Cycle (retest T05, T06)

---

### Tâche 1.2: Vérifier Null-Safe dans SettingResource

**Problème:** Potentiel crash si `ThemeSetting::first()` retourne null.

**Vérification requise:**
- `SettingResource.php` lignes 46-48: déjà null-safe (`?->` et `??`)
- Mais vérifier que `themeImage()` retourne bien null et pas une exception

**Fichier à vérifier:** `app/Http/Resources/SettingResource.php:97-100`

**Assigné à:** Kimi  
**Type de test:** local-validation  
**Validation:** local-validation

---

### Tâche 1.3: Retest Complet AntiGravityTest

**Action:** Après les fixes 1.1 et 1.2, exécuter:
```bash
php artisan test --filter=AntiGravityTest
```

**Résultat attendu:** 18/18 tests passent (T05 et T06 inclus)

**Assigné à:** Playwright / E2E verification  
**Type de test:** Playwright / E2E verification Cycle (E2E complet)  
**Output:** `reports/antigravity/report-010.md`

---

## 🧪 PHASE 2: CRÉATION DES TESTS MASSIFS (62 tests manquants)

### Tâche 2.1: Module 1 - Authentification (4 tests)

**Fichier:** `tests/Feature/AuthComprehensiveTest.php`

**Tests à créer:**

```php
/**
 * 1.1 - Login Admin valide
 * Route: POST /api/auth/login
 * Attendu: 200 + Token Sanctum
 */
public function test_admin_login_valid()
{
    $branch = Branch::factory()->create();
    $admin = User::factory()->create([
        'branch_id' => $branch->id,
        'email' => 'admin@test.com',
        'password' => bcrypt('password123')
    ]);
    $admin->assignRole('Admin');
    
    $response = $this->withHeader('x-api-key', $this->apiKey())
        ->postJson('/api/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'password123'
        ]);
    
    $response->assertStatus(200);
    $this->assertArrayHasKey('token', $response->json());
}

/**
 * 1.2 - Login Admin invalide
 * Route: POST /api/auth/login
 * Attendu: 401 ou 422
 */
public function test_admin_login_invalid()
{
    $response = $this->withHeader('x-api-key', $this->apiKey())
        ->postJson('/api/auth/login', [
            'email' => 'fake@test.com',
            'password' => 'wrongpassword'
        ]);
    
    $this->assertTrue(in_array($response->status(), [401, 422]));
}

/**
 * 1.7 - Logout Admin
 * Route: POST /api/auth/logout
 * Attendu: 200 + token révoqué
 */
public function test_admin_logout()
{
    [$branch, $admin] = $this->setupAdmin();
    
    $response = $this->actingAs($admin)
        ->withHeader('x-api-key', $this->apiKey())
        ->postJson('/api/auth/logout');
    
    $response->assertStatus(200);
}

/**
 * 1.8 - Accès sans token
 * Route: GET /api/admin/dashboard
 * Attendu: 401 Unauthenticated
 */
public function test_access_without_token_returns_401()
{
    $response = $this->withHeader('x-api-key', $this->apiKey())
        ->getJson('/api/admin/dashboard');
    
    $response->assertStatus(401);
}
```

**Assigné à:** Kimi  
**Type de test:** local-validation  
**Validation:** local-validation

---

### Tâche 2.2: Module 2 - CRUD Admin (20 tests)

**Fichier:** `tests/Feature/AdminCrudComprehensiveTest.php`

**Tests à créer:**
- 2.1-2.5: Items CRUD (list, create, view, update, delete)
- 2.6-2.8: Categories CRUD
- 2.9-2.12: Branches CRUD
- 2.13-2.15: Coupons CRUD
- 2.16-2.18: Dining Tables CRUD
- 2.19-2.20: Kiosk Machines CRUD

**Pattern pour chaque test:**
```php
public function test_admin_can_list_items()
{
    [$branch, $admin] = $this->setupAdmin();
    $item = Item::factory()->create(['branch_id' => $branch->id]);
    
    $response = $this->actingAs($admin)
        ->withHeader('x-api-key', $this->apiKey())
        ->getJson('/api/admin/setting/item');
    
    $response->assertStatus(200);
    $response->assertJsonFragment(['id' => $item->id]);
}
```

**Assigné à:** Kimi  
**Type de test:** local-validation  
**Validation:** local-validation

---

### Tâche 2.3: Module 3 - POS / Caisse (7 tests manquants)

**Fichier:** `tests/Feature/POSComprehensiveTest.php`

**Tests à créer:**
- 3.1: Créer commande POS
- 3.2: Lister commandes POS
- 3.3: Voir détail commande
- 3.5: Changer statut paiement
- 3.6: Supprimer commande
- 3.7: Export commandes
- 3.8: Re-order

**Assigné à:** Kimi  
**Type de test:** local-validation  
**Validation:** local-validation

---

### Tâche 2.4: Module 4 - Kiosk / Frontend (7 tests manquants)

**Fichier:** `tests/Feature/KioskFrontendComprehensiveTest.php`

**Tests à créer:**
- 4.1: Lister catalogue
- 4.2: Items populaires
- 4.3: Items vedettes
- 4.4: Détails item
- 4.5: Lister catégories
- 4.8: Coupon valide
- 4.10: Créneaux horaires

**Assigné à:** Kimi  
**Type de test:** local-validation  
**Validation:** local-validation

---

### Tâche 2.5: Module 5 - KDS (4 tests manquants)

**Fichier:** `tests/Feature/KDSComprehensiveTest.php`

**Tests à créer:**
- 5.1: Lister commandes KDS
- 5.2: Items KDS
- 5.3: Accept → Preparing
- 5.4: Transition invalide

**Assigné à:** Kimi  
**Type de test:** local-validation  
**Validation:** local-validation

---

### Tâche 2.6: Module 6 - OSS (2 tests manquants)

**Fichier:** `tests/Feature/OSSComprehensiveTest.php`

**Tests à créer:**
- 6.1: Lister commandes OSS
- 6.2: Items populaires OSS

**Assigné à:** Kimi  
**Type de test:** local-validation  
**Validation:** local-validation

---

### Tâche 2.7: Module 7 - Commandes Table (4 tests)

**Fichier:** `tests/Feature/TableOrderComprehensiveTest.php`

**Tests à créer:**
- 7.1: Lister catégories table
- 7.2: Tables disponibles
- 7.3: Passer commande table
- 7.4: Falsification prix table

**Assigné à:** Kimi  
**Type de test:** local-validation  
**Validation:** local-validation

---

### Tâche 2.8: Module 8 - Sécurité (7 tests manquants)

**Fichier:** `tests/Feature/SecurityComprehensiveTest.php`

**Tests à créer:**
- 8.2: Kiosk → Delete branch
- 8.3: Kiosk → Create item
- 8.4: Kiosk → Edit price
- 8.5: Client → Admin
- 8.7: POS recalcul prix
- 8.9: Sans API Key
- 8.10: Token expiré

**Assigné à:** Kimi  
**Type de test:** local-validation  
**Validation:** local-validation

---

### Tâche 2.9: Module 9 - Synchronisation (6 tests)

**Fichier:** `tests/Feature/SyncComprehensiveTest.php`

**Tests à créer:**
- 9.1: Kiosk → KDS (commande créée visible)
- 9.2: POS → KDS (commande POS visible)
- 9.3: KDS → OSS (statut changé reflété)
- 9.4: Table → KDS (commande table visible)
- 9.5: POS → Dashboard (compteurs mis à jour)
- 9.6: Cohérence bout-en-bout (même order_id)

**Assigné à:** Kimi  
**Type de test:** local-validation  
**Validation:** local-validation

---

### Tâche 2.10: Module 10 - Dashboard (5 tests)

**Fichier:** `tests/Feature/DashboardReportTest.php`

**Tests à créer:**
- 10.1: Total ventes
- 10.2: Total commandes
- 10.3: Statistiques
- 10.4: Rapport ventes
- 10.5: Export rapport

**Assigné à:** Kimi  
**Type de test:** local-validation  
**Validation:** local-validation

---

## 📝 PHASE 3: EXÉCUTION ET VALIDATION

### Tâche 3.1: Exécution Tests Massifs

**Action:** Exécuter tous les nouveaux tests
```bash
php artisan test
```

**Résultat attendu:** 70+/80 tests passent

**Assigné à:** Playwright / E2E verification  
**Type de test:** Playwright / E2E verification Cycle  
**Output:** `reports/antigravity/report-massive-002.md`

---

### Tâche 3.2: Revue par Claude

**Action:** Analyser les échecs et planifier les corrections

**Input:** Rapport Playwright / E2E verification avec échecs
**Output:** `reports/planning/plan-massive-002.md`

**Assigné à:** Claude  
**Type:** Planning & Review

---

## 🗓️ SÉQUENCE D'EXÉCUTION

### Semaine 1 (Sprint 6)
- **Jour 1-2:** Tâche 1.1 (Fix null-safe Blade)
- **Jour 3:** Tâche 1.2 (Vérifier SettingResource)
- **Jour 4:** Tâche 1.3 (Retest AntiGravityTest)
- **Jour 5:** Validation humaine Phase 1

### Semaine 2-3 (Sprint 7)
- **Jour 1-2:** Tâches 2.1, 2.2 (Auth + CRUD Admin)
- **Jour 3-4:** Tâches 2.3, 2.4 (POS + Kiosk)
- **Jour 5:** Tâches 2.5, 2.6 (KDS + OSS)

### Semaine 4 (Sprint 8)
- **Jour 1:** Tâches 2.7, 2.8 (Table + Sécurité)
- **Jour 2:** Tâches 2.9, 2.10 (Sync + Dashboard)
- **Jour 3-4:** Tâche 3.1 (Exécution tests massifs)
- **Jour 5:** Validation humaine Phase 2

---

## ✅ CRITÈRES DE RÉUSSITE

### Phase 1
- [ ] T05 passe (Kiosk cannot access admin → 401/403)
- [ ] T06 passe (Kiosk can create order → 200/201)
- [ ] 18/18 tests AntiGravityTest verts
- [ ] 0 erreurs `faviconLogo` null pointer

### Phase 2
- [ ] 10 fichiers de test créés
- [ ] 62 tests nouveaux implémentés
- [ ] Tests couvrent tous les modules (1-10)

### Phase 3
- [ ] 70+/80 tests passent
- [ ] Rapport Playwright / E2E verification généré
- [ ] Plan de corrections créé si besoin

---

## 📋 CHECKLIST DE TRAVAIL POUR KIMI

### Avant de commencer
- [ ] Lire `reports/antigravity/report-massive-audit-001.md`
- [ ] Lire `workflows/task-routing.md`
- [ ] Vérifier les helpers dans `tests/TestCase.php`

### Pendant l'implémentation
- [ ] Utiliser `setupAdmin()`, `setupKiosk()`, `setupKds()` existants
- [ ] Ajouter `withHeader('x-api-key', $this->apiKey())` sur routes admin
- [ ] Vérifier les statuts HTTP attendus
- [ ] Commenter chaque test avec description claire

### Après implémentation
- [ ] Exécuter `php artisan test --filter=<NomDuFichier>`
- [ ] Vérifier 0 erreurs
- [ ] Écrire résumé dans `reports/execution/execution-massive-001.md`

---

**Plan prêt pour exécution.**

*Prochaine étape:* Validation humaine de ce plan, puis assignation à Kimi pour Tâche 1.1 (Fix null-safe).
