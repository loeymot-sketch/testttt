# KIMI PLAN — SPRINT 1-B : PERFORMANCE & INTÉGRITÉ DONNÉES
**Émetteur :** Claude (Architecte)
**Destinataire :** KIMI (Builder)
**Priorité :** 🔴 P0 — Impact direct sur la vitesse de caisse et la cohérence DB
**Fichier de retour :** `reports/execution/latest.md`
**Prérequis :** Sprint 1-A (Sécurité) complété

---

## Vue d'ensemble

Ce sprint corrige les **problèmes de performance et d'intégrité des données** :
1. `Item::get()` — Full table scan à chaque commande (N+1)
2. Aucune transaction DB → incohérences silencieuses
3. Aucun index DB → Full table scans sur `orders`
4. Race condition sur les numéros de queue

---

## FIX-PERF-01 : Remplacer `Item::get()` par une requête ciblée

**Fichier :** `app/Services/OrderService.php`

### Trouver les occurrences

```bash
grep -n "Item::get()" app/Services/OrderService.php
grep -n "Item::get()" app/Services/FrontendOrderService.php
```

**Lignes identifiées :** L266, L443, L647

### Code AVANT → APRÈS

```php
// ===== L266 — AVANT =====
$dbItems = Item::get()->pluck('price', 'id');
// Charge TOUTE la table items (potentiellement des milliers d'entrées!)

// ===== L266 — APRÈS =====
// Extraire d'abord les item_ids depuis la commande entrante
$requestedItemIds = collect($orderItems)->pluck('item_id')->filter()->unique()->toArray();

$dbItems = Item::select('id', 'price')
    ->whereIn('id', $requestedItemIds)
    ->pluck('price', 'id');
// Charge uniquement les items de la commande (5-20 items max)
```

```php
// ===== L647 — AVANT =====
$items = Item::get()->pluck('tax_id', 'id');

// ===== L647 — APRÈS =====
$taxItemIds = collect($orderItems)->pluck('item_id')->filter()->unique()->toArray();
$items = Item::select('id', 'tax_id')
    ->whereIn('id', $taxItemIds)
    ->pluck('tax_id', 'id');
```

> ⚠️ IMPORTANT KIMI : Avant de modifier, faire `grep -n -A5 "Item::get()"` pour comprendre le contexte exact de chaque utilisation.

---

## FIX-PERF-02 : Ajouter les Index DB manquants

**Fichier à créer :** `database/migrations/XXXX_add_performance_indexes.php`

### Migration à créer

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Index sur orders (table la plus critique)
        Schema::table('orders', function (Blueprint $table) {
            // Vérifier si l'index n'existe pas déjà avant d'ajouter
            if (!$this->indexExists('orders', 'idx_orders_branch_status')) {
                $table->index(['branch_id', 'status'], 'idx_orders_branch_status');
            }
            if (!$this->indexExists('orders', 'idx_orders_user_id')) {
                $table->index('user_id', 'idx_orders_user_id');
            }
            if (!$this->indexExists('orders', 'idx_orders_datetime')) {
                $table->index('order_datetime', 'idx_orders_datetime');
            }
        });

        // Index sur items (pour les requêtes POS)
        Schema::table('items', function (Blueprint $table) {
            if (!$this->indexExists('items', 'idx_items_status_category')) {
                $table->index(['status', 'item_category_id'], 'idx_items_status_category');
            }
        });

        // Index sur users (pour les lookups auth)
        Schema::table('users', function (Blueprint $table) {
            if (!$this->indexExists('users', 'idx_users_deleted_at')) {
                $table->index('deleted_at', 'idx_users_deleted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_orders_branch_status');
            $table->dropIndexIfExists('idx_orders_user_id');
            $table->dropIndexIfExists('idx_orders_datetime');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_items_status_category');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_users_deleted_at');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(\Illuminate\Support\Facades\DB::select("SHOW INDEX FROM {$table}"))->pluck('Key_name')->contains($indexName);
    }
};
```

---

## FIX-PERF-03 : Corriger le Transaction Handling incohérent

**Fichier :** `app/Services/ItemCategoryService.php`

### Identifier le problème

```bash
grep -n "DB::beginTransaction\|DB::commit\|DB::rollBack\|rollback" app/Services/ItemCategoryService.php
```

### Pattern correct à utiliser

```php
// AVANT — Anti-pattern (rollBack hors catch)
try {
    DB::beginTransaction();
    // ... opérations ...
    DB::commit();
} catch (Exception $exception) {
    // rollBack manquant ou dans le mauvais scope
    throw new Exception(...);
}

// APRÈS — Pattern correct avec DB::transaction()
DB::transaction(function () use (...) {
    // ... opérations ...
    // Si exception → rollback automatique
    // Si succès → commit automatique
});
```

### Appliquer partout dans ItemCategoryService.php

```bash
# Voir toutes les méthodes store/update/delete
grep -n "function store\|function update\|function destroy" app/Services/ItemCategoryService.php
```

---

## FIX-PERF-04 : Sécuriser le numéro de queue (Race Condition)

**Fichier :** `app/Services/OrderService.php`

### Localiser la logique de queue

```bash
grep -n "lockForUpdate\|queue_number\|order_number" app/Services/OrderService.php | head -20
```

### Pattern sécurisé

```php
// AVANT — Lock présent mais parse fragile
$lastOrder = Order::lockForUpdate()->where(...)->first();
// ... regex parsing du queue number ..

// APRÈS — Utiliser une séquence atomique en DB
// Option 1 : MySQL AUTO_INCREMENT séparé
// Option 2 : Séquence verrouillée proprement

$queueNumber = DB::transaction(function () use ($branchId) {
    // Le lockForUpdate doit englober TOUTE la transaction
    $lastOrder = Order::lockForUpdate()
        ->where('branch_id', $branchId)
        ->whereDate('created_at', today())
        ->latest('id')
        ->first();

    $nextQueue = $lastOrder ? ($lastOrder->queue_number + 1) : 1;
    return $nextQueue;
});
```

---

## ✅ TESTS OBLIGATOIRES KIMI (Sprint 1-B)

### TEST-PERF-01 : Vérifier que Item::get() a disparu
```bash
grep -n "Item::get()" app/Services/OrderService.php
# ATTENDU : Aucun résultat
```

### TEST-PERF-02 : Vérifier que la migration s'exécute
```bash
php artisan migrate --force
# Vérifier les index créés
php artisan tinker --execute="
\$indexes = \DB::select('SHOW INDEX FROM orders');
echo 'Indexes sur orders: ' . count(\$indexes) . PHP_EOL;
foreach (\$indexes as \$idx) {
    echo '  - ' . \$idx->Key_name . ' (' . \$idx->Column_name . ')' . PHP_EOL;
}
"
```

### TEST-PERF-03 : Mesurer le gain de performance
```bash
php artisan tinker --execute="
\$start = microtime(true);
\App\Models\Item::get()->pluck('price', 'id'); // AVANT
\$old = (microtime(true) - \$start) * 1000;

\$start = microtime(true);
\App\Models\Item::select('id', 'price')->whereIn('id', [1,2,3,4,5])->pluck('price', 'id'); // APRÈS
\$new = (microtime(true) - \$start) * 1000;

echo 'AVANT: ' . round(\$old, 2) . 'ms' . PHP_EOL;
echo 'APRÈS: ' . round(\$new, 2) . 'ms' . PHP_EOL;
echo 'Gain: ' . round((\$old - \$new) / \$old * 100, 1) . '%' . PHP_EOL;
"
```

### TEST-PERF-04 : Vérifier DB::transaction utilisé
```bash
grep -n "DB::transaction\|DB::beginTransaction" app/Services/ItemCategoryService.php
# ATTENDU : Au moins 2-3 DB::transaction() calls
```

---

## 📄 Auto-Audit KIMI en fin d'implémentation

```bash
echo "=== Item::get() restants ==="
grep -rn "Item::get()" app/Services/ --include="*.php"
# Doit être 0

echo "=== Indexes DB ==="
php artisan tinker --execute="echo count(\DB::select('SHOW INDEX FROM orders')) . ' indexes sur orders';"
# Doit être > 3

echo "=== Transactions cohérentes ==="
grep -rn "DB::rollBack\|rollback" app/Services/ --include="*.php"
# Doit ne montrer que des usages dans des blocs catch
```
