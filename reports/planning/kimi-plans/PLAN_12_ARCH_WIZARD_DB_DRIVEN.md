# PLAN_12 — ARCH-02 : Wizard POS + Kiosk piloté par DB (remplacement detectCategory hardcodé)
**Phase :** P3 — Architecture (à faire APRÈS PLAN_11)
**Test-Type :** Anti-Gravity
**Pré-requis :** PLAN_11 doit être complété et les champs `wizard_template`, `has_menu` doivent être en DB
**Fichiers :**
- `public/js/pos-wizard.js`
- `app/Http/Resources/ItemResource.php` (ou équivalent)
- `app/Http/Controllers/Admin/ItemController.php` (endpoint item details)
- Projet Flutter Kiosk : `lib/helpers/category_helper.dart`

---

## 1. Contexte & Problème

Après PLAN_11, la DB contient `wizard_template` par catégorie. Ce plan **remplace le hardcode JS**
par une lecture de cette valeur depuis l'API.

### Flux actuel (à remplacer)
```
Clic item → XHR /admin/item/{id} → detectCategory(data) → string matching "tacos" → buildSteps
```

### Flux cible (piloté DB)
```
Clic item → XHR /admin/item/{id} → response inclut category.wizard_template → buildSteps direct
```

---

## 2. Fichiers à Modifier

| Fichier | Action |
|---------|--------|
| `app/Http/Resources/ItemResource.php` | Inclure `category.wizard_template` dans la réponse |
| `public/js/pos-wizard.js` | Lire `wizard_template` depuis `lastItemData`, simplifier `detectCategory` |
| Flutter `category_helper.dart` | Lire `wizard_template` depuis l'API |

---

## 3. Implémentation

### 3.1 Backend — Enrichir ItemResource

```php
// Dans app/Http/Resources/ItemResource.php
public function toArray($request): array
{
    return [
        // ... champs existants
        'category_name'      => $this->itemCategory?->name,
        'wizard_template'    => $this->itemCategory?->wizard_template ?? 'simple',
        'has_menu'           => $this->itemCategory?->has_menu ?? false,
        'default_menu_kiosk' => $this->itemCategory?->default_menu_kiosk ?? false,
        // ... autres champs
    ];
}
```

S'assurer que la relation `itemCategory` est chargée (eager loading) dans le controller :
```php
// Dans ItemController::show() ou getItemDetails()
$item = Item::with('itemCategory')->findOrFail($id);
```

### 3.2 pos-wizard.js — Remplacer detectCategory()

**AVANT (hardcodé) :**
```javascript
function detectCategory(data) {
    var cat = (data.category_name || '').toLowerCase();
    if (cat.includes('tacos')) return 'tacos';
    if (cat.includes('sandwich')) return 'sandwich';
    // ...
}
```

**APRÈS (piloté par DB) :**
```javascript
function detectCategory(data) {
    // [ARCH-02] Priorité 1 : wizard_template depuis l'API (PLAN_11)
    if (data.wizard_template && data.wizard_template !== 'simple') {
        console.log('[POS-WIZARD] wizard_template from API:', data.wizard_template);
        return data.wizard_template;
    }
    
    // [ARCH-02] Fallback legacy (si l'API n'est pas encore mise à jour)
    var cat = (data.category_name || data.item_category_name || '').toLowerCase();
    var name = (data.name || '').toLowerCase();
    
    if (cat.includes('tacos') || name.includes('tacos')) return 'tacos';
    if (cat.includes('sandwich') || name.includes('sandwich')) return 'sandwich';
    if (cat.includes('burger')   || name.includes('burger'))   return 'burger';
    if (cat.includes('assiette') || name.includes('assiette')) return 'assiette';
    if (cat.includes('salade')   || name.includes('salade'))   return 'salade';
    if (cat.includes('omelette') || name.includes('omelette')) return 'omelette';
    if (cat.includes('ojja')     || name.includes('ojja'))     return 'ojja';
    if (cat.includes('snacking') || name.includes('nuggets')
        || name.includes('wings') || name.includes('tenders')
        || name.includes('frites')) return 'snacking';
    
    return 'simple';
}
```

> **Stratégie de migration douce :** Le fallback legacy est conservé.
> Si `wizard_template` est dans la réponse API → utilisé en priorité.
> Si non → fallback sur le string matching existant.
> Cela permet un déploiement progressif sans casser l'existant.

### 3.3 pos-wizard.js — Utiliser has_menu (optionnel, phase suivante)

```javascript
// Dans getAllowedSteps(), remplacer le test sur addonItems.length
// AVANT
if (allowed.indexOf('menu') !== -1 && addonItems.length > 0) {

// APRÈS — aussi vérifier has_menu depuis l'API
if (allowed.indexOf('menu') !== -1 && addonItems.length > 0 && lastItemData.has_menu !== false) {
```

### 3.4 Flutter — Kiosk category_helper.dart

```dart
// Dans lib/helpers/category_helper.dart
// AVANT
String detectCategory(String categoryName, String itemName) {
    final cat = categoryName.toLowerCase();
    if (cat.contains('tacos')) return 'tacos';
    // ...
}

// APRÈS — lire depuis les données item
String detectCategory(Map<String, dynamic> itemData) {
    // Priorité : wizard_template de l'API
    final template = itemData['wizard_template'] as String?;
    if (template != null && template != 'simple') return template;
    
    // Fallback
    final cat = (itemData['category_name'] ?? '').toLowerCase();
    if (cat.contains('tacos')) return 'tacos';
    // ...
    return 'simple';
}
```

---

## 4. Tests

### 4.1 Test API
```bash
curl -H "Authorization: Bearer TOKEN" http://127.0.0.1:8000/api/admin/item/4
# Vérifier : "wizard_template": "tacos", "has_menu": true dans la réponse
```

### 4.2 Test PHPUnit
```php
/** @test */
public function item_api_returns_wizard_template_from_category()
{
    $category = ItemCategory::where('name', 'Nos Tacos')->first();
    $item = Item::where('item_category_id', $category->id)->first();

    $response = $this->getJson('/api/admin/item/' . $item->id);
    $response->assertStatus(200)
             ->assertJsonPath('data.wizard_template', 'tacos')
             ->assertJsonPath('data.has_menu', true);
}
```

### 4.3 Test Anti-Gravity — Régression Wizard
1. POS → Tacos L → wizard doit s'ouvrir normalement (viande_sauce → perso → menu → recap)
2. POS → Nos Salades → wizard doit s'ouvrir (sauce_supplements → recap)
3. POS → Nos Boissons → pas de wizard (direct panier)
4. Console JS → observer `[POS-WIZARD] wizard_template from API: tacos`

---

## 5. Critères de Succès

- [ ] `GET /api/admin/item/{id}` retourne `wizard_template` et `has_menu`
- [ ] `detectCategory()` lit `data.wizard_template` en priorité
- [ ] Tous les wizards existants fonctionnent identiquement (régression 0)
- [ ] Console JS : `wizard_template from API: tacos` visible (pas le fallback)
- [ ] Ajouter une catégorie "Wrap" avec `wizard_template=sandwich` → wizard sandwich correct SANS modifier le JS

---

## 6. NE PAS Toucher

- Les étapes de chaque type de wizard (viande_sauce, perso, etc.) — inchangées
- La logique `buildSteps()` — seule la valeur d'entrée change
- Les animations et styles CSS du wizard
- Les tests AntiGravityTest existants

---

## 📊 Résumé Architecture Post-PLAN_11+12

```
item_categories.wizard_template ─────────────────────────────────┐
item_categories.has_menu       ──────────────────────────────┐    │
                                                              │    │
GET /api/admin/item/{id}       ── ItemResource ──────────────┘    │
                                                                   │
pos-wizard.js detectCategory()                                     │
  ├── data.wizard_template ◄─────────────────────────────────────-┘
  └── fallback string matching (legacy)

Flutter CategoryHelper.detectCategory(itemData)
  ├── itemData['wizard_template'] ────────────────────── API
  └── fallback string matching (legacy)
```
