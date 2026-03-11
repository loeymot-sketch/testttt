# 🚨 PLAN D'ACTION GLOBAL - FoodKing Correction Totale

> **Date:** 10 Mars 2026  
> **Problèmes critiques identifiés:**
> 1. Menu anglais utilisé au lieu du menu français
> 2. Projet configuré en anglais (locale=en) au lieu de français
> 3. Deux seeders en conflit (ItemTableSeeder vs GrillHouseMenuSeeder)
> 4. 29 problèmes de code identifiés
> 5. Plan Sprint 3 de Claude à exécuter

---

## 🔴 PROBLÈMES CRITIQUES CONFIRMÉS

### Problème 1: Langue Incorrecte
**Fichier:** `config/app.php` ligne 85
```php
'locale' => 'en',  // ❌ DOIT ÊTRE 'fr'
```

### Problème 2: Deux Menus en Conflit
| Seeder | Langue | Contenu | Statut |
|--------|--------|---------|--------|
| **ItemTableSeeder.php** | 🇬🇧 Anglais | Dumplings, Egg Rolls, Burgers US | ⚠️ **UTILISÉ PAR DÉFAUT** |
| **GrillHouseMenuSeeder.php** | 🇫🇷 Français | Tacos, Sandwichs, Assiettes | ✅ Devrait être utilisé |

**Anti-Gravity teste avec le menu anglais (Dumplings)** - PAS notre menu français !

### Problème 3: Devises Confondues
- **ItemTableSeeder:** Prix en dollars ($2.5, $1.5)
- **GrillHouseMenuSeeder:** Prix en euros (6.50€, 8.50€)
- **Configuration:** Doit être EUR (€)

### Problème 4: Catégories en Anglais
ItemCategoryTableSeeder crée:
- "Appetizers", "Flame Grill Burgers", "Hot Chicken Entrees"

Au lieu de:
- "Nos Tacos", "Nos Sandwichs", "Nos Burgers"

---

## 🎯 SOLUTIONS IMMÉDIATES

### ÉTAPE 1: Corriger la Langue (2 minutes)
**Fichier:** `config/app.php`

```php
// AVANT (ligne 85):
'locale' => 'en',

// APRÈS:
'locale' => 'fr',
```

### ÉTAPE 2: Fusionner les Seeders (Critique)
Créer UN SEUL seeder qui:
1. Supprime TOUS les items existants
2. Crée les catégories en FRANÇAIS
3. Crée les items du menu Grill House en FRANÇAIS avec prix en EUROS
4. Configure la devise EUR

### ÉTAPE 3: Exécuter Plan Sprint 3 de Claude
**Fichier source:** `/Users/1millnonstop/.gemini/antigravity/brain/c5650a98-6267-40ad-8b0e-bfb015166975/implementation_plan.md.resolved`

#### Tâche 3.1: Prix POS Anti-Falsification
**Fichier:** `app/Services/OrderService.php` méthode `posOrderStore()`

Modifier le calcul des prix pour recalculer depuis la DB (comme FrontendOrderService).

#### Tâche 3.2: Notifications KDS pour POS
**Fichier:** `app/Services/OrderService.php` après `DB::transaction()`

Ajouter:
```php
SendOrderGotMail::dispatch(['order_id' => $this->order->id]);
SendOrderGotSms::dispatch(['order_id' => $this->order->id]);
SendOrderGotPush::dispatch(['order_id' => $this->order->id]);
```

#### Tâche 3.3: Tests PHPUnit
Ajouter dans `AntiGravityTest.php`:
- `test_t08b_pos_order_forged_price_uses_db_price`
- `test_t08c_pos_kds_notification_dispatched`

#### Tâche 3.4: Build Vue.js
Vérifier que `public/js/app.js` est compilé avec les dernières modifications.

### ÉTAPE 4: Corriger les 29 Problèmes
Priorité 🔴 Critique:
1. `cashfreeJs.blade.php` - Null-safe
2. `paymentSuccess.blade.php` - Null-safe
3. `FirebaseService.php` - Null-safe
4. `LoyaltyController` - Ajouter validation

---

## 📋 PLAN DÉTAILLÉ PAR AGENT

### Pour Kimi (Builder) - À faire immédiatement:

1. **Corriger config/app.php** - ligne 85 → `'locale' => 'fr'`

2. **Créer seeder unifié** `database/seeders/CompleteFrenchMenuSeeder.php`
   - Supprimer anciens items/categories
   - Créer catégories en français
   - Créer menu Grill House complet
   - Prix en euros

3. **Exécuter Tâche 3.1** - Prix POS anti-falsification (OrderService.php)

4. **Exécuter Tâche 3.2** - Notifications KDS pour POS

5. **Corriger 4 problèmes critiques** (null-safe + validation)

6. **Créer tests PHPUnit** (Tâche 3.3)

### Pour Anti-Gravity (QA) - Test E2E:

**NOUVEAUX scénarios avec menu français:**

1. **Test POS Tacos L (2 viandes)**
   - Sélectionner Tacos L
   - Choisir Viande 1: Poulet
   - Choisir Viande 2: Kebab
   - Sauce: Algérienne (gratuite)
   - Supplément: Cheddar (+1€)
   - Menu: Oui (+3€)
   - Paiement Cash: 20€
   - **Vérifier:** Total correct, commande créée, apparition KDS

2. **Test KDS Notification**
   - Créer commande POS
   - **Vérifier:** Commande apparaît dans KDS automatiquement

3. **Test Anti-Falsification Prix**
   - Intercepter requête
   - Modifier prix item: 0.01€
   - **Vérifier:** Commande créée avec prix DB correct (pas 0.01€)

### Pour Claude (Architect) - Audit:

1. **Revue architecture** (plan-audit-complet-claude.md)
2. **Validation logique livraison** (calcul distance)
3. **Différence Borne vs Caisse** (UI/UX)
4. **Sécurité** (authentification, autorisation)

---

## 🔧 CONFIGURATION FINALE ATTENDUE

### Menu Français (50+ items):
- **Tacos:** M, L, XL, XXL (1-4 viandes)
- **Sandwichs:** Terminator, Méga, Suprême, Cayenne, Panini
- **Burgers:** Cheese, Double Cheese, Fish, Chicken, Grill, Big
- **Assiettes:** Poulet, Kefta, Merguez, Mixte
- **Ojja:** Bœuf, Poulet, Viande Hachée, Merguez
- **Omelettes:** Nature, Fromage, Champignons
- **Salades:** César, Chèvre, Royale, Saumon, Tunisienne
- **Chicken:** Wings 6/12, Tenders 6/12
- **Frites:** Moyenne, Grande
- **Desserts:** Glace, Tiramisu, Tarte Daim
- **Boissons:** 10 variétés

### Paiements:
- Cash (Espèces) avec pavé numérique
- Carte (TPE sans contact ou insertion)

### Langue/Devise:
- Langue: Français
- Devise: EUR (€)
- Timezone: Europe/Paris

---

## ✅ CHECKLIST VALIDATION FINALE

### Après corrections:
- [ ] Config: `'locale' => 'fr'`
- [ ] Menu: UNIQUEMENT items français (pas de Dumplings/Egg Rolls)
- [ ] Prix: Tous en euros (€)
- [ ] Catégories: "Nos Tacos", "Nos Sandwichs" (pas "Appetizers")
- [ ] Tests: 20/20 passent (18 actuels + 2 nouveaux)
- [ ] E2E: Parcours Tacos L complet fonctionne
- [ ] KDS: Notification automatique à la création commande
- [ ] Anti-falsification: Prix DB utilisé (pas prix client)

---

## 🚀 PROCHAINES ÉTAPES

1. **Maintenant:** Kimi exécute les corrections
2. **Dans 2h:** Anti-Gravity teste avec menu français
3. **Demain:** Claude audit architecture complète
4. **Cette semaine:** Mise en production test (une branche)

---

**ACTION REQUISE:** Approuver ce plan pour que Kimi commence immédiatement les corrections.
