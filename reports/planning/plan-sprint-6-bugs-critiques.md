# 📋 PLAN SPRINT 6 : Réparation des 3 Bugs Critiques E2E

> **Date:** 10 Mars 2026  
> **Source:** Rapport E2E Playwright / E2E verification (`report-e2e-audit-01.md`)  
> **Mission:** Réparer les 3 bugs bloquant le tunnel de commande POS + Kiosk

---

## 🎯 SYNTHÈSE DES BUGS

| # | Bug | Fichier(s) | Impact | Sévérité |
|---|-----|------------|--------|----------|
| 1 | **Binding pavé numérique** cassé | `PaymentComponent.vue` | Impossible de finaliser commande CASH | 🔴 CRITIQUE |
| 2 | **Token type validation** (int vs string) | `PosOrderRequest.php`, `PosComponent.vue` | Requête 422 sur Token | 🔴 CRITIQUE |
| 3 | **Faute d'orthographe** "Reciept" | `ReceiptComponent.vue` | UI non professionnelle | 🟡 Mineur |
| 4 | **faviconLogo null pointer** | Backend multiple fichiers | Crash 500 Kiosk | 🔴 CRITIQUE |

---

## 🔧 TÂCHE 1 : Bug Binding Pavé Numérique (CRITIQUE)

### Problème
Le pavé numérique remplit l'input visuellement mais ne met pas à jour le modèle Vue.js. Quand `confirmOrder()` est appelé, `this.$refs.cashInput.value` est vide ou ne correspond pas à ce qui est affiché.

### Root Cause
Les fonctions globales `solve()`, `Back()`, `Clear()` (probablement dans un fichier JS externe) modifient l'input HTML directement sans passer par Vue.

### Solution
Dans `PaymentComponent.vue`, ligne 206-210, s'assurer que la valeur est lue correctement AU MOMENT du clic sur Confirm.

**Fichier:** `resources/js/components/admin/pos/PaymentComponent.vue`

**Code à modifier:**
```javascript
// Ligne 206-210 (ACTUEL - problématique)
if (this.$props.props.form.pos_payment_method === this.posPaymentMethodEnum.CASH && this.$refs.cashInput.value) {
    this.$props.props.form.pos_received_amount = this.$refs.cashInput.value;
}

// CORRECTION: Forcer la lecture directe de l'élément DOM
if (this.$props.props.form.pos_payment_method === this.posPaymentMethodEnum.CASH) {
    const cashValue = document.getElementById('cashInput')?.value;
    if (cashValue) {
        this.$props.props.form.pos_received_amount = parseFloat(cashValue);
    }
}
```

**Alternative si ça ne marche pas:**
Ajouter un `v-model` bidirectionnel synchrone sur l'input.

---

## 🔧 TÂCHE 2 : Bug Validation Token (CRITIQUE)

### Problème
Le backend attend `token` comme string, mais le frontend envoie un integer (car `type="number"`).

### Fichiers concernés
1. `app/Http/Requests/PosOrderRequest.php` ligne 32
2. `resources/js/components/admin/pos/PosComponent.vue` ligne 74, 496

### Solution (Option A - Backend)
**Fichier:** `app/Http/Requests/PosOrderRequest.php`

```php
// AVANT (ligne 32):
'token' => ['nullable', 'string'],

// APRÈS:
'token' => ['nullable', 'string', 'numeric'], // Accepte string ou numeric
```

### Solution (Option B - Frontend)
**Fichier:** `resources/js/components/admin/pos/PosComponent.vue`

```javascript
// Ligne 74: Changer l'input
// AVANT:
type="number" id="token" v-model="checkoutProps.form.token"

// APRÈS:
type="text" id="token" v-model="checkoutProps.form.token" 
// + ajouter validation numérique locale
```

**RECOMMANDATION:** Appliquer Option A (Backend) car c'est plus sûr et couvre tous les cas.

---

## 🔧 TÂCHE 3 : Faute Orthographe (Mineur)

### Problème
"Reciept" au lieu de "Receipt"

### Fichier
`resources/js/components/admin/pos/ReceiptComponent.vue`

### Solution
Rechercher "Reciept" et remplacer par "Receipt".

---

## 🔧 TÂCHE 4 : faviconLogo Null Pointer (CRITIQUE)

### Problème
Crash 500 quand Kiosk crée commande → notification → chargement settings → faviconLogo null.

### Fichiers à corriger (déjà partiellement fait)
1. `app/Http/Resources/SettingResource.php` - Déjà null-safe ✅
2. `resources/views/payment.blade.php` - Déjà corrigé ✅
3. **Vérifier:** `app/Services/OrderPushNotificationBuilder.php`
4. **Vérifier:** `app/Http/Controllers/Frontend/PaymentController.php`
5. **Vérifier:** Tous les fichiers mentionnés dans le grep faviconLogo

**Pattern de correction:**
```php
// AVANT:
$faviconLogo->faviconLogo

// APRÈS:
$faviconLogo?->faviconLogo ?? asset('images/theme/theme-favicon-logo.png')
```

---

## 📝 VALIDATION (Checklist pour Playwright / E2E verification)

### Test 1: Pavé Numérique
- [ ] Ouvrir POS → Ajouter item → Cliquer Payer
- [ ] Choisir "Cash"
- [ ] Saisir montant reçu via pavé numérique (ex: 20.00)
- [ ] Cliquer "Confirm & Print Receipt"
- [ ] **Attendu:** Commande créée avec succès (200), pas d'erreur 422

### Test 2: Token
- [ ] Ouvrir POS → Ajouter item Takeaway
- [ ] Saisir Token No: 5001
- [ ] Payer
- [ ] **Attendu:** Commande créée, pas d'erreur "token must be a string"

### Test 3: Orthographe
- [ ] Vérifier que le bouton dit "Receipt" pas "Reciept"

### Test 4: Kiosk
- [ ] Exécuter test T06 AntiGravityTest
- [ ] **Attendu:** 201 Created, pas d'erreur 500 faviconLogo

---

## 🗂️ FICHIERS À MODIFIER (Résumé pour Kimi)

| Priorité | Fichier | Ligne(s) | Action |
|----------|---------|----------|--------|
| 🔴 P0 | `PaymentComponent.vue` | 206-210 | Fix binding pavé numérique |
| 🔴 P0 | `PosOrderRequest.php` | 32 | Token validation string\|numeric |
| 🔴 P0 | `FrontendOrderService.php` | Vérifier | Null-safe sur settings si notification |
| 🟡 P1 | `ReceiptComponent.vue` | Rechercher | Fix orthographe "Reciept" |
| 🟡 P1 | `SettingResource.php` | Vérifier | Déjà fait? |

---

## 🚀 PLAN D'EXÉCUTION

### Phase 1: Binding Pavé Numérique (30 min)
1. Lire `PaymentComponent.vue` ligne 206-210
2. Implémenter correction avec `document.getElementById`
3. Test manuel sur interface

### Phase 2: Token Validation (15 min)
1. Modifier `PosOrderRequest.php` ligne 32
2. Ajouter `'numeric'` à la règle

### Phase 3: faviconLogo (30 min)
1. Grep tous les fichiers avec `faviconLogo`
2. S'assurer que tous sont null-safe
3. Exécuter test T06

### Phase 4: Orthographe (5 min)
1. Search & Replace "Reciept" → "Receipt"

---

## ✅ CRITÈRE DE SUCCÈS

```bash
# Tests AntiGravity doivent tous passer
php artisan test --filter=AntiGravityTest
# Expected: 18/18 passed

# Test E2E manuel:
# 1. Créer commande POS Cash avec pavé numérique → Succès
# 2. Créer commande Takeaway avec Token → Succès  
# 3. Créer commande Kiosk → Succès 201
```

---

## 📚 RÉFÉRENCES

- Rapport E2E: `reports/antigravity/report-e2e-audit-01.md`
- Plan Massif: `reports/planning/plan-massive-001.md`
- Menu Seeders: `database/seeders/GrillHouseMenuSeeder.php`
- POS Wizard: `public/js/pos-wizard.js`

---

**Plan prêt pour exécution par Kimi.**
