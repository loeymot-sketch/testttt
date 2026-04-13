# 🔍 MASSIVE AUDIT REPORT 002 - Analyse Détaillée des Échecs

> **Date:** 2026-03-10  
> **Auditeur:** Claude (Lead Architect)  
> **Mission:** Analyse approfondie des tests T05 et T06 avec résolution

---

## 🚨 RÉSULTATS DU DEBUG

### Test T05: Kiosk cannot access admin

**Attendu:** 401 ou 403  
**Reçu:** 200 (HTML de la SPA Vue.js)

```html
<!-- Réponse reçue -->
<!DOCTYPE html>
<html lang="en">
<head>
    <title>FoodKing Test</title>
    <!-- ... Vue.js SPA ... -->
</head>
<body>
    <div id="app">
        <default-component />
    </div>
</body>
</html>
```

**Diagnostic:** Le Kiosk peut accéder au dashboard admin ! Le middleware d'autorisation ne bloque pas correctement. Le token Sanctum du Kiosk est valide mais il devrait être rejeté pour les routes admin.

**Root Cause Probable:**
- La route `/api/admin/dashboard` n'a pas de middleware de permission spécifique
- Le token Sanctum du Kiosk est accepté car il est authentifié
- Manque de vérification du rôle/permission pour l'accès dashboard

---

### Test T06: Kiosk can create order

**Attendu:** 200 ou 201  
**Reçu:** 400 - "Invalid Api Key"

**Diagnostic:** Le test manque le header `x-api-key` requis par le middleware `apiKey`.

**Fix:** Ajouter `->withHeader('x-api-key', $this->apiKey())` à la requête.

---

## 🎯 CORRECTIONS REQUISES

### Correction T05: Ajouter Middleware Dashboard

**Fichier:** `routes/api.php` (à vérifier)

La route `/api/admin/dashboard` doit avoir un middleware qui vérifie les permissions. Actuellement elle ne vérifie que `auth:sanctum` mais pas le rôle.

**Solution proposée:**
```php
// routes/api.php
Route::middleware(['auth:sanctum', 'permission:dashboard'])
    ->get('/admin/dashboard', [DashboardController::class, 'index']);
```

Ou alternativement, ajouter une vérification dans le contrôleur:
```php
// app/Http/Controllers/Admin/DashboardController.php
public function index()
{
    // Vérifier que l'utilisateur a le droit d'accéder au dashboard
    if (!auth()->user()->hasAnyRole(['Admin', 'Branch Manager', 'POS Operator'])) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }
    // ...
}
```

---

### Correction T06: Ajouter Header API Key

**Fichier:** `tests/Feature/AntiGravityTest.php:125-137`

**Code actuel:**
```php
$response = $this->actingAs($user)->postJson('/api/frontend/order', [
    'order_type' => 5,
    ...
]);
```

**Code corrigé:**
```php
$response = $this->actingAs($user)
    ->withHeader('x-api-key', $this->apiKey())  // AJOUTER CETTE LIGNE
    ->postJson('/api/frontend/order', [
        'order_type' => 5,
        ...
]);
```

---

## 🔄 MISE À JOUR DU PLAN

### Tâche 1.1 (Mise à jour): Fix Autorisation Dashboard

**Fichier:** `app/Http/Controllers/Admin/DashboardController.php` ou `routes/api.php`

**Action:**
1. Vérifier la route `/api/admin/dashboard`
2. Ajouter middleware `permission:dashboard` OU vérification de rôle
3. S'assurer que les utilisateurs Kiosk sont rejetés

**Assigné à:** Kimi  
**Type:** local-validation (unit/integration)  
**Validation:** Playwright / E2E verification Cycle

---

### Tâche 1.2 (Mise à jour): Fix Test T06 - Ajouter API Key

**Fichier:** `tests/Feature/AntiGravityTest.php`

**Action:**
1. Modifier ligne 130: Ajouter `->withHeader('x-api-key', $this->apiKey())`
2. Exécuter test pour vérifier

**Assigné à:** Kimi  
**Type:** local-validation (unit/integration)  
**Validation:** local-validation

---

## 📝 RÉSUMÉ DES VRAIS PROBLÈMES

| ID | Problème | Localisation | Sévérité | Type |
|----|----------|--------------|----------|------|
| T05 | Kiosk peut accéder au dashboard admin | `DashboardController` ou `routes/api.php` | 🔴 Haute | Sécurité |
| T06 | Test manque header API key | `AntiGravityTest.php:130` | 🟡 Moyenne | Test |

**Note:** Le problème `faviconLogo` a été fixé dans `payment.blade.php` mais ce n'était pas la cause principale des échecs T05/T06.

---

## ✅ CHECKLIST DE VALIDATION

- [ ] T05: Vérifier que Kiosk reçoit 403 sur `/api/admin/dashboard`
- [ ] T06: Vérifier que Kiosk peut créer commande avec API key (200/201)
- [ ] Tous les tests AntiGravityTest passent (18/18)

---

**Fin du rapport d'analyse détaillée.**

*Prochaine étape:* Implémenter les corrections T05 et T06.
