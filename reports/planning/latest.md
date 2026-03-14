# PLAN KIMI — FIX PERMANENT Menu Vide POS
**Émetteur :** Claude (Architecte)  
**Destinataire :** KIMI (Builder)  
**Date :** 12 Mars 2026  
**Source :** Audit profond `database/seeders/MenuSeeder.php` + `app/Services/ItemService.php`

---

## 🔴 DIAGNOSTIC ROOT CAUSE (Claude Certifié)

**Bug :** Le menu POS est vide à chaque fois que le Seeder est exécuté.

**Pourquoi :** `MenuSeeder.php` utilise le bon config pour les **catégories** seulement (L320). Pour les 14 autres entités — Items, Attributs, Extras, Variations — il hardcode `'status' => 1`. Or `Status::ACTIVE = 5`. Donc dès qu'un seed est relancé, tous les items tombent à status=1 et `ItemService::simpleList()` ne les retourne plus.

```
MenuSeeder.php       →  items créés avec status=1   ← BUG SOURCE
ItemService           →  filtre pas status (pass-through)
POS Frontend (Vue)  →  demande items avec status=5 (ACTIVE)
Résultat            →  0 items retournés → menu vide
```

---

## 🔧 PLAN D'ACTION KIMI — 3 Étapes

### Étape 1 — Ajouter `use App\Enums\Status;` en tête de fichier

**Fichier :** `database/seeders/MenuSeeder.php`  
**Ligne 14** (après les autres `use` statements) :

```php
// AVANT : pas de use Status
use App\Models\ItemAddon;

// APRÈS : ajouter
use App\Models\ItemAddon;
use App\Enums\Status;  // ← AJOUTER ICI
```

---

### Étape 2 — Remplacer TOUTES les occurrences de `'status' => 1` par `'status' => Status::ACTIVE`

**Lignes à modifier : L338, L339, L340, L341, L342, L343, L370, L424, L524, L546, L564, L594, L607, L627, L637**

Commande grep pour localiser :
```bash
grep -n "'status' => 1" database/seeders/MenuSeeder.php
```

**Remplacement global :**
```bash
# NE PAS UTILISER sed car il écrase toutes les occurrences
# Corriger manuellement chaque 'status' => 1
# en 'status' => Status::ACTIVE
```

**Exemple de correction :**
```php
// AVANT (L338-343) — createAttributes()
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

> ⚠️ IMPORTANT KIMI : Ne pas modifier `'status' => $this->config['settings']['status_active']` (L320). C'est déjà correct.

---

### Étape 3 — Mettre à jour `config/menu.php` pour que `status_active` soit cohérent

**Fichier :** `config/menu.php`

```bash
# Vérifier la valeur actuelle
grep -n "status_active" config/menu.php
```

**Si la valeur est 1, la corriger à 5 :**
```php
// AVANT
'settings' => [
    'status_active' => 1,   // ← FAUX
    ...
]

// APRÈS
'settings' => [
    'status_active' => \App\Enums\Status::ACTIVE,  // = 5
    ...
]
```

---

## ✅ TESTS OBLIGATOIRES KIMI

### Test 1 — Vérifier le Seeder corrigé
```bash
# Relancer le seeder (le seeder purge et re-crée)
php artisan db:seed --class=MenuSeeder

# Vérifier le compte des items ACTIFS (doit retourner 53+)
php artisan tinker --execute="\App\Models\Item::where('status', 5)->count();"
# ATTENDU: > 0 (53 minimum)

# Vérifier aussi les attributs
php artisan tinker --execute="\App\Models\ItemAttribute::where('status', 5)->count();"
# ATTENDU: > 0
```

### Test 2 — Vérifier l'API POS sans token
```bash
php artisan tinker --execute="
\$admin = \App\Models\User::first();
\$token = auth()->login(\$admin);
echo 'Admin ID: ' . \$admin->id . PHP_EOL;
echo 'Item count (status=5): ' . \App\Models\Item::where('status', 5)->count() . PHP_EOL;
echo 'Item count (status=1): ' . \App\Models\Item::where('status', 1)->count() . ' (doit être 0)' . PHP_EOL;
"
```

### Test 3 — Vérifier que le Seeder est idempotent
```bash
# Deuxième run du seeder (ne doit pas re-créer si français existe)
php artisan db:seed --class=MenuSeeder
# ATTENDU: "✅ French menu already exists and is valid. Skipping..."
```

---

## 📄 Protocole de Retour KIMI → Claude

Écrire dans `reports/execution/latest.md` :

```markdown
# FIX Menu Vide POS — Exécution KIMI

## Lignes corrigées dans MenuSeeder.php
- [ ] use App\Enums\Status; ajouté (ligne X)
- [ ] status => 1 → Status::ACTIVE sur L338 (attrViande1)
- [ ] status => 1 → Status::ACTIVE sur L339 (attrViande2)
- [ ] status => 1 → Status::ACTIVE sur L340 (attrViande3)
- [ ] status => 1 → Status::ACTIVE sur L341 (attrViande4)
- [ ] status => 1 → Status::ACTIVE sur L342 (attrSauce)
- [ ] status => 1 → Status::ACTIVE sur L343 (attrCrudite)
- [ ] status => 1 → Status::ACTIVE sur L370 (createAddons)
- [ ] status => 1 → Status::ACTIVE sur L424 (createItems)
- [ ] status => 1 → Status::ACTIVE sur L524 (variation)
- [ ] status => 1 → Status::ACTIVE sur L546 (variation)
- [ ] status => 1 → Status::ACTIVE sur L564 (variation)
- [ ] status => 1 → Status::ACTIVE sur L594 (extras)
- [ ] status => 1 → Status::ACTIVE sur L607 (extras)
- [ ] status => 1 → Status::ACTIVE sur L627 (extras)
- [ ] status => 1 → Status::ACTIVE sur L637 (extras)

## config/menu.php
- [ ] status_active = 5 (ou Status::ACTIVE) confirmé

## Tests
- [ ] Seeder relancé → résultat console
- [ ] Item::where('status', 5)->count() → XXX items
- [ ] Item::where('status', 1)->count() → 0 items
- [ ] Deuxième run seeder → "Skipping..."
```
