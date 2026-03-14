# KIMI PLAN — SPRINT 3 : SEEDER & MENU (Fix Permanent)
**Émetteur :** Claude (Architecte)
**Destinataire :** KIMI (Builder)
**Priorité :** 🔴 P0 — Menu POS vide à chaque re-seed = bloquant opérationnel
**Fichier de retour :** `reports/execution/latest.md`
**Peut être lancé en parallèle avec Sprint 1-A**

---

## Vue d'ensemble

Ce sprint corrige le problème le plus immédiatement visible : **le menu POS qui se vide à chaque exécution du Seeder**. Root cause : 14 occurrences de `'status' => 1` dans `MenuSeeder.php`, alors que `Status::ACTIVE = 5`.

---

## Diagnostic Root Cause (Claude Certifié)

```
MenuSeeder.php L320  → Catégories : 'status' => config['status_active'] ✅ CORRECT
MenuSeeder.php L338-637 → Items, Attributes, Extras, Variations :
                          'status' => 1   ← ❌ HARDCODÉ FAUX (14 occurrences)

Status::ACTIVE = 5 (App\Enums\Status)

Résultat : Items créés avec status=1
ItemService filtre avec status=5 (via request)
→ 0 items retournés → Menu POS vide
```

---

## FIX-SEEDER-01 : Ajouter `use App\Enums\Status;`

**Fichier :** `database/seeders/MenuSeeder.php`

### Localiser les imports existants (lignes 1-15)

```bash
head -20 database/seeders/MenuSeeder.php
```

### Ajouter après les autres `use` statements

```php
// AJOUTER cette ligne (autour de la ligne 14)
use App\Enums\Status;
```

---

## FIX-SEEDER-02 : Remplacer les 14 occurrences de `'status' => 1`

### Trouver toutes les occurrences

```bash
grep -n "'status' => 1" database/seeders/MenuSeeder.php
```

**Lignes attendues (vérifier avec grep) :**
L338, L339, L340, L341, L342, L343, L370, L424, L524, L546, L564, L594, L607, L627, L637

### Remplacement — Exemple exact pour chaque section

**Section `createAttributes()` (L338-343) :**
```php
// AVANT
$this->attrViande1 = ItemAttribute::create(['name' => 'Viande 1', 'status' => 1]);
$this->attrViande2 = ItemAttribute::create(['name' => 'Viande 2', 'status' => 1]);
$this->attrViande3 = ItemAttribute::create(['name' => 'Viande 3', 'status' => 1]);
$this->attrViande4 = ItemAttribute::create(['name' => 'Viande 4', 'status' => 1]);
$this->attrSauce   = ItemAttribute::create(['name' => 'Sauce (1ère Gratuite)', 'status' => 1]);
$this->attrCrudite = ItemAttribute::create(['name' => 'Garnitures', 'status' => 1]);

// APRÈS
$this->attrViande1 = ItemAttribute::create(['name' => 'Viande 1', 'status' => Status::ACTIVE]);
$this->attrViande2 = ItemAttribute::create(['name' => 'Viande 2', 'status' => Status::ACTIVE]);
$this->attrViande3 = ItemAttribute::create(['name' => 'Viande 3', 'status' => Status::ACTIVE]);
$this->attrViande4 = ItemAttribute::create(['name' => 'Viande 4', 'status' => Status::ACTIVE]);
$this->attrSauce   = ItemAttribute::create(['name' => 'Sauce (1ère Gratuite)', 'status' => Status::ACTIVE]);
$this->attrCrudite = ItemAttribute::create(['name' => 'Garnitures', 'status' => Status::ACTIVE]);
```

> ℹ️ RÈGLE : Remplacer `'status' => 1` par `'status' => Status::ACTIVE` partout sans exception dans ce fichier. Ne toucher PAS à `'status' => $this->config['settings']['status_active']` qui est déjà correct.

---

## FIX-SEEDER-03 : Corriger `config/menu.php`

### Vérifier la valeur actuelle

```bash
grep -n "status_active" config/menu.php
```

### Si la valeur est 1 (incorrect) → corriger

```php
// AVANT
'settings' => [
    'status_active' => 1,  // ← FAUX
],

// APRÈS
'settings' => [
    'status_active' => \App\Enums\Status::ACTIVE,  // = 5
],
```

---

## ✅ TESTS OBLIGATOIRES KIMI (Sprint Seeder)

### TEST-SEEDER-01 : Vérifier que le use Statement est ajouté

```bash
grep -n "use App\\\\Enums\\\\Status" database/seeders/MenuSeeder.php
# ATTENDU : 1 résultat
```

### TEST-SEEDER-02 : Vérifier qu'AUCUN `'status' => 1` reste

```bash
grep -n "'status' => 1" database/seeders/MenuSeeder.php
# ATTENDU : Aucun résultat
```

### TEST-SEEDER-03 : Relancer le Seeder et mesurer

```bash
# Le Seeder va purger et recréer si données anglaises détectées
php artisan db:seed --class=MenuSeeder
```

### TEST-SEEDER-04 : Vérifier en DB que les items sont ACTIFS (status=5)

```bash
php artisan tinker --execute="
\$total = \App\Models\Item::count();
\$active = \App\Models\Item::where('status', 5)->count();
\$inactive = \App\Models\Item::where('status', 1)->count();
echo 'Total items: ' . \$total . PHP_EOL;
echo 'Items ACTIFS (status=5): ' . \$active . ' (DOIT être > 0)' . PHP_EOL;
echo 'Items INACTIFS (status=1): ' . \$inactive . ' (DOIT être 0)' . PHP_EOL;
"
```

### TEST-SEEDER-05 : Idempotence (2ème run ne doit pas re-créer)

```bash
php artisan db:seed --class=MenuSeeder
# ATTENDU : "✅ French menu already exists and is valid. Skipping..."
```

### TEST-SEEDER-06 : Test API POS retourne des items

```bash
# L'API admin/item doit retourner des items avec status=5
php artisan tinker --execute="
\$admin = \App\Models\User::first();
auth()->login(\$admin);
\$count = \App\Models\Item::where('status', 5)->count();
echo 'Items visibles via API POS: ' . \$count . PHP_EOL;
// ATTENDU : 53+
"
```

---

## 📄 Auto-Audit KIMI

```bash
echo "=== Status=1 restants dans Seeder ==="
grep -n "'status' => 1" database/seeders/MenuSeeder.php
# DOIT : Aucun résultat

echo "=== Comptes items actifs ==="
php artisan tinker --execute="\App\Models\Item::where('status', 5)->count();"
# DOIT : > 50

echo "=== Config menu ==="
grep "status_active" config/menu.php
# DOIT : Status::ACTIVE ou 5
```

---

## 📋 Tableau de Confirmation à Remplir

Copier dans `reports/execution/latest.md` :

| Check | Attendu | Réel | ✅/❌ |
|-------|---------|------|-------|
| `use App\Enums\Status;` dans MenuSeeder | 1 ligne | ___ | ___ |
| `'status' => 1` restants | 0 | ___ | ___ |
| Item::where('status',5)->count() | > 50 | ___ | ___ |
| Item::where('status',1)->count() | 0 | ___ | ___ |
| 2ème run seeder → Skipping | "Skipping..." | ___ | ___ |
| config/menu.php status_active | 5 (ou Status::ACTIVE) | ___ | ___ |
