# PLAN_08 — D-007 : Alignement Token POS Frontend/Backend
**Phase :** P2 — Moyenne
**Test-Type :** Kimi-test
**Impact :** 🟡 UX — Frontend bloque si token vide, backend accepte null → comportements divergents
**Fichiers :**
- `resources/js/components/admin/pos/PosComponent.vue`
- `app/Http/Requests/PosOrderRequest.php`

---

## 1. Contexte & Problème

### Situation actuelle
| Couche | Comportement | Ligne |
|--------|-------------|-------|
| Frontend (Vue) | Affiche erreur "Token requis" si vide | PosComponent.vue ~L872 |
| Backend (Laravel) | `token` nullable → accepte sans token | PosOrderRequest.php |

**Conséquences :**
- Le caissier se retrouve bloqué sans token alors que le backend accepterait
- Ou : si on contourne le frontend (Postman), une commande sans token passe

### Décision architecturale (Claude recommande Option B)
**Token = optionnel** — il sert de numéro de file d'attente ou de borne kiosk,
mais une commande POS manuelle sans token est légitime.

> **Si vous préférez Option A (obligatoire partout)**, inverser les changements :
> rendre `required` dans PosOrderRequest et garder la validation frontend.

---

## 2. Fichiers à Modifier

| Fichier | Action |
|---------|--------|
| `resources/js/components/admin/pos/PosComponent.vue` | Rendre token optionnel frontend |
| `app/Http/Requests/PosOrderRequest.php` | S'assurer que token est `nullable` |

---

## 3. Implémentation — Option B (Token Optionnel)

### 3.1 PosComponent.vue — Retirer la validation obligatoire frontend

**Localiser :** ligne ~872 (validation avant ouverture du modal paiement)

```javascript
// AVANT — bloque si token vide
if (!this.checkoutProps.form.token || this.checkoutProps.form.token.trim() === '') {
    this.$notify({
        type: 'error',
        title: this.$t('message.error'),
        text: 'Token requis'
    });
    return;
}

// APRÈS — ne plus bloquer, token optionnel
// Supprimer ou commenter le bloc de validation du token
// Garder seulement les validations nécessaires (type de commande, items)
```

**Mettre à jour le placeholder du champ token :**
```html
<!-- AVANT -->
<input type="text" id="token" v-model="checkoutProps.form.token"
       placeholder="Token No" class="form-control">

<!-- APRÈS — indiquer que c'est optionnel -->
<input type="text" id="token" v-model="checkoutProps.form.token"
       placeholder="N° File / Borne (optionnel)" class="form-control">
```

### 3.2 PosOrderRequest.php — Confirmer nullable

**Localiser :** `app/Http/Requests/PosOrderRequest.php`

```php
// Vérifier/s'assurer que token est nullable
public function rules(): array
{
    return [
        // ...
        'token' => ['nullable', 'string'],
        // ...
    ];
}
```

> Si `'token' => ['required', 'string']` → le changer en `['nullable', 'string']`

---

## 4. Tests

### 4.1 Test Feature (backend)
```php
/** @test */
public function pos_order_can_be_placed_without_token()
{
    $admin = User::factory()->admin()->create();
    $item  = Item::factory()->create(['price' => 8.00]);

    $response = $this->actingAs($admin)->postJson('/api/admin/pos', [
        'items'  => json_encode([['item_id' => $item->id, 'quantity' => 1]]),
        'type'   => 2, // takeaway
        'token'  => null, // ← pas de token
    ]);

    $response->assertStatus(200);
}

/** @test */
public function pos_order_can_be_placed_with_token()
{
    $admin = User::factory()->admin()->create();
    $item  = Item::factory()->create(['price' => 8.00]);

    $response = $this->actingAs($admin)->postJson('/api/admin/pos', [
        'items'  => json_encode([['item_id' => $item->id, 'quantity' => 1]]),
        'type'   => 2,
        'token'  => '123',
    ]);

    $response->assertStatus(200);
}
```

### 4.2 Compilation Vue
```bash
npm run dev
# Vérifier : 0 erreur de compilation
```

### 4.3 Test PHPUnit
```bash
php artisan test --filter="pos_order_can_be_placed"
```

---

## 5. Critères de Succès

- [ ] Sans token → modal de paiement s'ouvre normalement (pas de blocage frontend)
- [ ] Avec token → comportement inchangé
- [ ] `PosOrderRequest` a `token` en `nullable`
- [ ] Placeholder mis à jour pour indiquer le caractère optionnel
- [ ] Tests passent
- [ ] 0 régression

---

## 6. NE PAS Toucher

- La validation des autres champs (type de commande, items)  
- La gestion du token côté Kiosk (différent — le token est l'ID de la machine)
- Le type `text` du champ (déjà corrigé dans Bug #2 précédent)
