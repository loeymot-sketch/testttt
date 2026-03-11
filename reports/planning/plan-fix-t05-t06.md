# 📋 PLAN FIX: Tests T05 et T06

> **Date:** 2026-03-10  
> **Planification:** Claude (Lead Architect)  
> **Basé sur:** [reports/antigravity/report-massive-audit-002.md](../antigravity/report-massive-audit-002.md)

---

## 🎯 OBJECTIF

Corriger les 2 tests échouants (T05, T06) pour atteindre 18/18 tests verts.

---

## 🐛 PROBLÈMES IDENTIFIÉS

### T05: Kiosk cannot access admin
- **Problème:** Kiosk reçoit 200 au lieu de 401/403 sur `/api/admin/dashboard`
- **Cause:** Route dashboard sans middleware de permission
- **Fix:** Ajouter vérification de rôle/permission

### T06: Kiosk can create order
- **Problème:** Test retourne 400 "Invalid Api Key"
- **Cause:** Header `x-api-key` manquant
- **Fix:** Ajouter le header à la requête

---

## 🔧 TÂCHES DE CORRECTION

### Tâche 1: Fix Test T06 (Simple - 5 min)

**Fichier:** `tests/Feature/AntiGravityTest.php`

**Ligne à modifier:** 130

**Code actuel:**
```php
public function test_t06_kiosk_can_create_order()
{
    [$branch, $user, $kiosk] = $this->setupKiosk();
    $item = \Database\Factories\ItemFactory::new()->create(['price' => 10]);

    $response = $this->actingAs($user)->postJson('/api/frontend/order', [
        'order_type' => 5,
        'subtotal' => 10,
        'total' => 10,
        'is_advance_order' => 0,
        'source' => 1,
        'items' => json_encode([['item_id' => $item->id, 'price' => 10, 'quantity' => 1]])
    ]);
    // ...
}
```

**Code corrigé:**
```php
public function test_t06_kiosk_can_create_order()
{
    [$branch, $user, $kiosk] = $this->setupKiosk();
    $item = \Database\Factories\ItemFactory::new()->create(['price' => 10]);

    $response = $this->actingAs($user)
        ->withHeader('x-api-key', $this->apiKey())  // <-- AJOUTÉ
        ->postJson('/api/frontend/order', [
            'order_type' => 5,
            'subtotal' => 10,
            'total' => 10,
            'is_advance_order' => 0,
            'source' => 1,
            'items' => json_encode([['item_id' => $item->id, 'price' => 10, 'quantity' => 1]])
        ]);
    // ...
}
```

**Validation:**
```bash
php artisan test --filter=test_t06
# Attendu: PASS (200 ou 201)
```

---

### Tâche 2: Fix Autorisation Dashboard (T05)

**Analyse préalable requise:**

D'abord, examiner la route dashboard:
```bash
# Vérifier les routes
grep -n "dashboard" routes/api.php
```

**Option A: Middleware dans routes (Préféré)**

**Fichier:** `routes/api.php`

**Trouver:**
```php
Route::get('/admin/dashboard', [DashboardController::class, 'index']);
```

**Remplacer par:**
```php
Route::middleware(['auth:sanctum', 'permission:dashboard'])
    ->get('/admin/dashboard', [DashboardController::class, 'index']);
```

**Option B: Vérification dans contrôleur**

**Fichier:** `app/Http/Controllers/Admin/DashboardController.php`

**Modifier méthode index():**
```php
public function index()
{
    // Vérifier l'autorisation
    if (!auth()->user()->hasAnyRole(['Admin', 'Branch Manager', 'POS Operator', 'Chef'])) {
        return response()->json(['message' => 'Forbidden - Insufficient permissions'], 403);
    }
    
    // ... reste du code
}
```

**Validation:**
```bash
php artisan test --filter=test_t05
# Attendu: PASS (401 ou 403)
```

---

## 🧪 VALIDATION FINALE

### Étape 1: Exécuter tests individuels
```bash
php artisan test --filter=test_t05
php artisan test --filter=test_t06
```

### Étape 2: Exécuter tous les tests AntiGravity
```bash
php artisan test --filter=AntiGravityTest
```

**Attendu:**
```
PASS: 18 tests
FAIL: 0 tests
```

---

## 📝 EXECUTION SUMMARY TEMPLATE

Pour le fichier `reports/execution/execution-fix-t05-t06.md`:

```markdown
# Execution Summary: Fix T05 & T06

## Tâche 1: Fix T06 (API Key)
- [x] Modifié AntiGravityTest.php ligne 130
- [x] Ajouté ->withHeader('x-api-key', $this->apiKey())
- [x] Test passe: 200/201

## Tâche 2: Fix T05 (Autorisation)
- [x] Modifié [FICHIER]
- [x] Ajouté middleware/vérification permission
- [x] Test passe: 401/403

## Résultat Final
- 18/18 tests AntiGravityTest passent
- Prêt pour Phase 2 (Tests massifs)
```

---

**Plan prêt pour exécution par Kimi.**

*Priorité:* Tâche 1 (T06) d'abord car c'est une ligne simple à ajouter.
