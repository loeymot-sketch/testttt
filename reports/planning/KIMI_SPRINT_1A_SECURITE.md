# KIMI PLAN — SPRINT 1-A : SÉCURITÉ CRITIQUE
**Émetteur :** Claude (Architecte)
**Destinataire :** KIMI (Builder)
**Priorité :** 🔴 P0 — Ne pas déployer sans ces fixes
**Fichier de retour :** `reports/execution/latest.md`

---

## Vue d'ensemble

Ce sprint corrige les **4 vulnérabilités de sécurité critiques** confirmées par audit de code :
1. `rand()` pour IDs financiers → collision de transactions
2. Aucun rate limiting → brute force sans résistance
3. Weak PIN (4 chiffres) → brute force en secondes
4. IDOR silencieux → accès à des ressources sans 403

---

## FIX-SEC-01 : Remplacer `rand()` par `Str::uuid()` ou `random_int()`

**Fichier principal :** `app/Services/OrderService.php`

### Lignes à corriger

```bash
# Trouver toutes les occurrences
grep -n "rand(" app/Services/OrderService.php
grep -n "rand(" app/Services/FrontendOrderService.php
grep -n "rand(" app/Http/Controllers/
```

**Lignes identifiées :**
- `OrderService.php:918` — Transaction ID
- `OrderService.php:942` — Transaction ID fallback
- `FrontendOrderService.php:303` — Transaction ID
- `GuestSignupController.php:101` — Mot de passe temporaire
- `LoyaltyController.php:109-115` — Code cashback

### Code AVANT → APRÈS

```php
// ===== OrderService.php:918 =====
// AVANT
$transactionId = rand(111111111111111, 99999999999999);

// APRÈS
$transactionId = \Illuminate\Support\Str::uuid()->toString();
// Ou pour un format numérique court :
$transactionId = 'TXN-' . \Illuminate\Support\Str::random(12);
```

```php
// ===== GuestSignupController.php:101 =====
// AVANT — Mot de passe aléatoire
$password = rand(100000, 999999);

// APRÈS
$password = \Illuminate\Support\Str::random(10);
```

```php
// ===== LoyaltyController.php:109 =====
// AVANT — Code cashback faible entropie
$code = substr(md5(uniqid()), 0, 8);

// APRÈS
$code = strtoupper(\Illuminate\Support\Str::random(8));
```

### Import à ajouter en tête de fichier (si absent dans chaque fichier modifié)
```php
use Illuminate\Support\Str;
```

---

## FIX-SEC-02 : Rate Limiting sur les Endpoints Auth

**Fichier :** `routes/api.php`

### Localiser les routes auth

```bash
grep -n "auth/login\|auth/register\|forgot-password\|kiosk-login" routes/api.php
```

### Code AVANT

```php
Route::post('/auth/login', [LoginController::class, 'login']);
Route::post('/auth/register', [RegisterController::class, 'register']);
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendOtp']);
Route::post('/kiosk-login', [KioskMachineLoginController::class, 'login']);
```

### Code APRÈS

```php
// 5 tentatives de login par minute par IP
Route::post('/auth/login', [LoginController::class, 'login'])
    ->middleware('throttle:5,1');

// 10 tentatives d'inscription par minute par IP
Route::post('/auth/register', [RegisterController::class, 'register'])
    ->middleware('throttle:10,1');

// 3 tentatives de reset par heure par IP (anti-spam SMS)
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendOtp'])
    ->middleware('throttle:3,60');

// 5 tentatives de connexion Kiosk par minute
Route::post('/kiosk-login', [KioskMachineLoginController::class, 'login'])
    ->middleware('throttle:5,1');
```

---

## FIX-SEC-03 : Remplacer `rand(1000, 9999)` pour le PIN de reset

**Fichier :** `app/Http/Controllers/Auth/ForgotPasswordController.php`

```bash
grep -n "rand" app/Http/Controllers/Auth/ForgotPasswordController.php
```

### Code AVANT → APRÈS

```php
// AVANT (L42)
$pin = rand(1000, 9999);  // Seulement 10K possibilités

// APRÈS — 6 chiffres cryptographiquement sûrs
$pin = random_int(100000, 999999);  // 900K possibilités + cryptographiquement sûr
```

> ⚠️ NOTE : `random_int()` est cryptographiquement sûr contrairement à `rand()`.

---

## FIX-SEC-04 : IDOR — Retourner 403 au lieu de `[]`

**Fichier :** `app/Services/OrderService.php`

```bash
# Trouver les return [] après checks d'accès
grep -n "return \[\]" app/Services/OrderService.php
# Chercher aussi dans
grep -n "return \[\]" app/Services/FrontendOrderService.php
```

### Lignes ~L791, L811, L827 (vérifier avec grep)

```php
// AVANT — Silencieux, ne dit pas à l'utilisateur qu'il n'a pas accès
if (!$order) {
    return [];
}

// APRÈS — HTTP 403 standard
if (!$order) {
    abort(403, 'Access denied: you do not have permission to access this order.');
}
```

---

## ✅ TESTS OBLIGATOIRES KIMI (Sprint 1-A)

### TEST-SEC-01 : Vérifier l'absence de rand() dans les fichiers critiques
```bash
grep -rn "rand(" app/Services/OrderService.php
grep -rn "rand(" app/Services/FrontendOrderService.php
grep -rn "rand(" app/Http/Controllers/Auth/
# → ATTENDU : Aucun résultat (ou uniquement hors financier)
```

### TEST-SEC-02 : Vérifier les throttle ajoutés
```bash
grep -n "throttle" routes/api.php
# → ATTENDU : 4+ lignes avec throttle
```

### TEST-SEC-03 : Test brute force manuel (API)
```bash
# Envoyer 6 requêtes de login consécutives
for i in {1..6}; do
    curl -s -o /dev/null -w "%{http_code}\n" \
        -X POST http://localhost:8000/api/auth/login \
        -H "Content-Type: application/json" \
        -H "x-api-key: $(grep MIX_API_KEY .env | cut -d= -f2 | tr -d '\r')" \
        -d '{"email":"test@test.com","password":"wrong"}'
done
# → ATTENDU : 5 premiers = 400 (invalid creds), 6ème = 429 (Too Many Requests)
```

### TEST-SEC-04 : Vérifier les IDs générés ne sont plus prévisibles
```bash
php artisan tinker --execute="
echo 'UUID: ' . \Illuminate\Support\Str::uuid() . PHP_EOL;
echo 'Random: ' . \Illuminate\Support\Str::random(12) . PHP_EOL;
echo 'PIN: ' . random_int(100000, 999999) . PHP_EOL;
"
```

---

## 📄 Auto-Audit KIMI en fin d'implémentation

Avant d'écrire dans `reports/execution/latest.md`, vérifier :

```bash
# 1. Aucun rand() financier
echo "=== rand() restants ==="
grep -rn "rand(" app/Services/ app/Http/Controllers/Auth/ --include="*.php"

# 2. Throttle présent
echo "=== throttle sur routes ==="
grep -n "throttle" routes/api.php | wc -l
# Doit être >= 4

# 3. return [] suspects
echo "=== IDOR potentiels ==="
grep -n "return \[\]" app/Services/OrderService.php
```
