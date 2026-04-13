# 🏗️ ARCHITECTURE BASE REFONDÉE - FoodKing

> **Mission:** Créer une structure INFAILLIBLE qui empêche toute duplication ou confusion
> **Date:** 10 Mars 2026
> **Statut:** Plan d'action complet

---

## 🔴 PROBLÈMES STRUCTURAUX IDENTIFIÉS

### 1. DUPLICATION DES MENUS
```
❌ PROBLÈME:
- ItemTableSeeder.php (menu anglais - DEMO mode)
- GrillHouseMenuSeeder.php (menu français)
- CompleteFrenchMenuSeeder.php (nouveau - non exécuté)

RÉSULTAT: Selon l'ordre d'exécution, on a un menu différent !
```

### 2. CONFIGURATION FRAGMENTÉE
```
❌ PROBLÈME:
- config/app.php: 'locale' => 'en' (puis modifié en 'fr')
- DatabaseSeeders: Certains avec DEMO check, d'autres sans
- Prix: Certains en $, d'autres en €

RÉSULTAT: Inconsistance selon l'environnement
```

### 3. SEEDERS EN CONFLIT
```
❌ PROBLÈME:
- DatabaseSeeder.php appelle plusieurs seeders
- Ordre d'exécution non contrôlé
- Pas de "purge" systématique avant création
```

---

## ✅ SOLUTION STRUCTURALE - UNE SEULE SOURCE DE VÉRITÉ

### Principe: "SINGLE SOURCE OF TRUTH"

```
┌─────────────────────────────────────────────────────────┐
│           UNE SEULE CONFIGURATION                        │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  📄 config/menu.php (nouveau)                           │
│  ├── 'locale' => 'fr'                                  │
│  ├── 'currency' => 'EUR'                               │
│  ├── 'currency_symbol' => '€'                          │
│  └── 'timezone' => 'Europe/Paris'                      │
│                                                         │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  🔒 MenuSeeder.php (UN SEUL)                             │
│  ├── Purge COMPLETE de tout ancien menu                  │
│  ├── Création catégories FR                              │
│  ├── Création items FR avec prix €                       │
│  └── VÉRIFICATION post-création                         │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

---

## 🛡️ MÉCANISMES DE PROTECTION

### 1. Protection Anti-Duplication
```php
// Dans LE SEUL MenuSeeder.php
public function run()
{
    // BLOQUER si menu existe déjà
    if (Item::count() > 0) {
        $this->command->warn('⚠️  Menu existe déjà !');
        $this->command->info('Utilisez: php artisan menu:reset pour recréer');
        return;
    }
    
    // Sinon créer
    $this->createMenu();
}
```

### 2. Commande Artisan Dédiée
```bash
# Créer le menu (une seule fois)
php artisan menu:create

# Forcer recréation (supprime tout d'abord)
php artisan menu:reset

# Vérifier intégrité
php artisan menu:verify
```

### 3. Configuration Centralisée
```php
// config/menu.php
return [
    'restaurant_name' => 'Le Grill House',
    'locale' => 'fr',
    'currency' => 'EUR',
    'currency_symbol' => '€',
    'timezone' => 'Europe/Paris',
    
    'categories' => [
        'nos-tacos' => ['name' => 'Nos Tacos', 'sort' => 1],
        'nos-sandwichs' => ['name' => 'Nos Sandwichs', 'sort' => 2],
        // ...
    ],
    
    'items' => [
        // Tous les items définis ici
    ],
];
```

---

## 📋 PLAN D'ACTION MULTI-AGENTS

### AGENT 1: Structure Base (Claude - Maintenant)
**Mission:** Créer l'architecture infrangible

1. Créer `config/menu.php` (configuration unique)
2. Créer `database/seeders/MenuSeeder.php` (seeder unique)
3. Créer `app/Console/Commands/MenuCommand.php` (commandes artisan)
4. Modifier `config/app.php` (forcer 'locale' => 'fr')
5. Supprimer les anciens seeders conflictuels

### AGENT 2: Corrections 29 Bugs (Kimi)
**Mission:** Corriger tous les problèmes de code

**Priorité 🔴 Critique:**
- [ ] `FirebaseService.php:72` - Null-safe
- [ ] `ThemeService.php` - Null-safe multiple
- [ ] `LoyaltyController` - Validation
- [ ] `ItemImport.php` - SQL injection

**Priorité 🟡 Haute:**
- [ ] Blade views null-safe (3 fichiers)
- [ ] Notification builders - Cache
- [ ] Order services - N+1 queries
- [ ] JSON decode error handling

### AGENT 3: Tests Massifs (Playwright / E2E verification)
**Mission:** Tester CHAQUE fonctionnalité

**Module 1: Authentification**
- [ ] Login admin (Web)
- [ ] Login kiosk (API)
- [ ] Session unique
- [ ] Timeout
- [ ] Accès non autorisé

**Module 2: Caisse Complète**
- [ ] Wizard Tacos M (1 viande)
- [ ] Wizard Tacos L (2 viandes)
- [ ] Wizard Tacos XL (3 viandes)
- [ ] Wizard Tacos XXL (4 viandes)
- [ ] Calcul sauce (1 gratuite)
- [ ] Calcul suppléments
- [ ] Panier temps réel
- [ ] Paiement Cash (pavé numérique)
- [ ] Paiement Carte (TPE)
- [ ] Ticket généré
- [ ] Anti-falsification prix

**Module 3: Borne Android (API)**
- [ ] Login kiosk
- [ ] Création commande
- [ ] Variations stockées
- [ ] Prix recalculé serveur
- [ ] Notification KDS

**Module 4: KDS**
- [ ] Vue commandes temps réel
- [ ] Changement PREPARING
- [ ] Changement PREPARED
- [ ] Notification client
- [ ] Items agrégés

**Module 5: Livraison**
- [ ] Calcul distance
- [ ] Calcul frais livraison
- [ ] Adresse validation

### AGENT 4: Documentation (Claude)
**Mission:** Documenter pour éviter reproduction

- [ ] Architecture technique
- [ ] Guide développeur
- [ ] Procédures déploiement
- [ ] Checklist maintenance

---

## 🎯 VALIDATION FINALE - CRITÈRES STRICTS

### Avant validation:
- [ ] UN SEUL seeder exécutable
- [ ] Menu UNIQUEMENT en français
- [ ] Prix UNIQUEMENT en euros
- [ ] Locale UNIQUEMENT 'fr'
- [ ] 29 bugs corrigés
- [ ] 34 tests E2E passent
- [ ] 0 duplication possible

### Tests de non-régression:
```bash
# Test 1: Impossible de créer menu anglais
php artisan db:seed --class=ItemTableSeeder
# DOIT échouer ou être ignoré

# Test 2: Menu français intact
php artisan menu:verify
# DOIT afficher: "✓ Menu français validé"

# Test 3: Pas de conflit de devise
grep -r "dollar\|USD\|\\$" database/seeders/
# DOIT retourner vide
```

---

## 🚀 EXÉCUTION IMMÉDIATE

### Phase 1: Base Structure (30 min)
1. Créer config/menu.php
2. Créer MenuSeeder.php unique
3. Créer MenuCommand.php
4. Supprimer seeders conflictuels

### Phase 2: Corrections (2h)
1. Corriger 4 bugs critiques
2. Corriger 8 bugs haute priorité
3. Vérifier null-safe partout

### Phase 3: Tests (4h)
1. Exécuter tous les tests E2E
2. Valider chaque fonctionnalité
3. Documenter résultats

### Phase 4: Validation (1h)
1. Vérifier structure infaillible
2. Tester non-régression
3. Approuver pour production

---

**Prêt à exécuter avec agents multiples.**
