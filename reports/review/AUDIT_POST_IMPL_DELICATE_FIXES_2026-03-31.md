# Audit Post-Implémentation — Corrections Délicates (Claude)

**Date** : 2026-03-31  
**Agent** : Claude (Architect & Reviewer)  
**Contexte** : Audit des phases 1-3 implémentées + corrections des problèmes délicats avant délégation à Kimi

---

## 1. Problèmes Délicats Identifiés et Corrigés

### 1.1 KDS : `payment_method` en exact match (K-1) — NON CORRIGÉ
**Fichier** : `app/Services/KitchenDisplaySystemOrderService.php` L76-82  
**Problème** : Le filtre `payment_method` passe encore par `LIKE '%'.$request.'%'` au lieu d'un match exact comme `status`.  
**Impact** : Recherche par `payment_method=1` (cash) pourrait matcher `10`, `11`, `21`...  
**Status** : À corriger — mais attendu dans le plan Kimi (P1)

### 1.2 KDS : LIKE wildcard escaping (K-2) — NON CORRIGÉ
**Fichier** : `app/Services/KitchenDisplaySystemOrderService.php` L80  
**Problème** : Les caractères `%` et `_` dans les valeurs de recherche ne sont pas échappés.  
**Impact** : Injection de wildcard possible dans les filtres texte (nom, email...).  
**Status** : À corriger — mais attendu dans le plan Kimi (P1)

### 1.3 Coupon : mauvais message "pas encore actif" (K-3) — CORRECTION IMMÉDIATE
**Fichier** : `app/Services/CouponService.php` L268-270  
**Problème** : Le check `start_date` utilise le message `coupon_date_expired` au lieu d'un message spécifique "pas encore actif".  
**Impact** : UX confuse — l'utilisateur pense que le coupon est expiré alors qu'il n'est pas encore valide.  
**Action** : Correction immédiate par Claude — création clé `coupon_not_yet_active`

### 1.4 Coupon : `couponDateWise()` crash sur dates null (K-4) — CORRECTION IMMÉDIATE
**Fichier** : `app/Services/CouponService.php` L186-189  
**Problème** : `Carbon::now()->between($item->start_date, $item->end_date)` crash si les dates sont null.  
**Impact** : Erreur 500 sur l'API si un coupon sans dates existe.  
**Action** : Correction immédiate par Claude — guard `if ($item->start_date && $item->end_date)`

### 1.5 Sanitize noms produits dans toasts catalogue (K-5) — NON CORRIGÉ
**Fichier** : `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue` L352, L356, L390, L107  
**Problème** : Les appels `showToast` utilisent `detail.name` et `product.name` sans sanitize.  
**Impact** : Injection XSS potentielle si le nom contient du HTML malicieux.  
**Status** : À corriger — mais attendu dans le plan Kimi (P1)

---

## 2. Analyse du Code Actuel — Observations pour Kimi

### 2.1 `KioskStepMenuComponent.vue` — Code mort identifié (K-9)
```javascript
// L227-229 — computed jamais utilisé
hasBoissonIds() {
  return this.boissonList.some(b => typeof b.id === 'number');
},
```
**Confirmation** : Le computed `hasBoissonIds` n'est jamais référencé dans le template ou les méthodes.  
**Action** : Supprimer (tâche K-9 du plan Kimi).

### 2.2 `KioskConfirmationComponent.vue` — Emit mort (K-10)
```javascript
// L132 — déclaré mais jamais écouté
emits: ['close'],
// L279 — émis mais aucun parent n'écoute
this.$emit('close');
```
**Confirmation** : Aucun parent n'utilise `@close` sur ce composant.  
**Action** : Supprimer `emits` et le `$emit` (tâche K-10 du plan Kimi).

### 2.3 `kioskRoutes.js` — Query param mort (K-8)
```javascript
// L47 — auto_failed jamais lu par aucun composant
.catch(() => next({ name: 'kiosk.login', query: { auto_failed: '1' } }));
```
**Confirmation** : Aucun composant ne lit `this.$route.query.auto_failed`.  
**Action** : Supprimer le query param (tâche K-8 du plan Kimi).

### 2.4 `KioskUpsellComponent.vue` — Toast hardcodé FR (K-12)
```javascript
// L192-194
this.showToast(
  count === 1 ? `${this.selectedItems[0].name} ajouté !` : `${count} articles ajoutés !`,
  'success'
);
```
**Confirmation** : Textes FR hardcodés, pas de clés i18n.  
**Action** : Remplacer par clés i18n (tâche K-12 du plan Kimi).

### 2.5 `FrontendOrderService.php` — Variable inutilisée dans `use()` (K-7)
```php
// L126-154 — $isKioskMachineOrder capturé par référence mais jamais lu après la transaction
DB::transaction(function () use (
    $request,
    $idempotencyKey,
    &$isKioskMachineOrder,  // ← jamais lu après
    ...
```
**Confirmation** : La variable est écrite L154 mais jamais lue après la transaction.  
**Action** : La garder locale à la closure au lieu de la capturer par référence (tâche K-7 du plan Kimi).

---

## 3. Validation des Corrections Critiques (Claude)

### 3.1 State Machine Paiement — OK
- `paymentConfirm()` avec `lockForUpdate` : OK
- `finalizePaidKioskOrder()` avec atomicité : OK
- Séparation cash (immédiat) vs carte/TR (différé) : OK

### 3.2 Coupon Centralisé — OK
- `resolveCouponById()`, `resolveCouponByCode()`, `validateCouponForOrder()` : OK
- `calculateDiscountAmount()` avec cap sur subtotal : OK
- Priorité coupon sur fidélité : OK

### 3.3 Runtime Hardening — OK
- `KioskLoginComponent` respecte maintenance mode : OK
- `requireConfirmationContext` guard : OK
- Allowlist KDS sort : OK

---

## 4. Résumé pour Délégation Kimi

### ✅ Problèmes délicats CORRIGÉS par Claude

| # | Problème | Fichier(s) | Correction |
|---|----------|-----------|------------|
| **K-3** | Mauvais message coupon "pas encore actif" | `app/Services/CouponService.php` L268-270 | Message `coupon_date_expired` remplacé par `coupon_not_yet_active` |
| **K-4** | Crash `couponDateWise()` sur dates null | `app/Services/CouponService.php` L186-189 | Guard ajouté `if ($item->start_date && $item->end_date)` |

**Clés i18n ajoutées** :
- `lang/fr/all.php` : `'coupon_not_yet_active' => "Le coupon n'est pas encore actif (valide à partir du :date)"`
- `lang/en/all.php` : `'coupon_not_yet_active' => 'The coupon is not yet active (valid from :date)'`
- `lang/ar/all.php` : `'coupon_not_yet_active' => 'القسيمة غير نشطة بعد (صالحة من :date)'`

---

### ⏳ Tâches P1 (fort impact, simple) — Pour Kimi (~25 min)

| # | Tâche | Fichier | Effort | Test |
|---|-------|---------|--------|------|
| K-1 | KDS `payment_method` exact match (comme `status`) | `KitchenDisplaySystemOrderService.php` L74-80 | 5 min | PHPUnit |
| K-2 | KDS LIKE wildcard escaping (`%` → `\%`) | `KitchenDisplaySystemOrderService.php` L80 | 10 min | PHPUnit |
| K-5 | Sanitize noms produits dans toasts | `KioskCategoriesComponent.vue` L352, L356, L390, L107 | 10 min | No-test |

---

### ⏳ Tâches P2 (nettoyage, cohérence) — Pour Kimi (~40 min)

| # | Tâche | Fichier | Effort | Test |
|---|-------|---------|--------|------|
| K-6 | Validation numérique `orderId` | `kioskRoutes.js` L79 | 5 min | No-test |
| K-7 | Variable `$isKioskMachineOrder` en local | `FrontendOrderService.php` L126-154 | 5 min | No-test |
| K-8 | Supprimer `auto_failed` query param | `kioskRoutes.js` L47 | 2 min | No-test |
| K-9 | Supprimer `hasBoissonIds` computed mort | `KioskStepMenuComponent.vue` L227-229 | 2 min | No-test |
| K-10 | Supprimer emit `close` mort | `KioskConfirmationComponent.vue` L132, L279 | 2 min | No-test |
| K-11 | Debounce `addAndContinue` upsell | `KioskUpsellComponent.vue` | 5 min | No-test |
| K-12 | i18n toast upsell FR hardcode | `KioskUpsellComponent.vue` + i18n | 10 min | Kimi-test |

---

### ⏳ Tâches P3 (améliorations futures) — À planifier (~6h30)

| # | Tâche | Description | Effort |
|---|-------|-------------|--------|
| K-13 | Extraire `calculateRunningTotal` helper | Unifier les 3 implémentations du calcul de prix | 2h |
| K-14 | Ratio menu 60/40/100 en config | Hardcodé en 3 endroits → constante | 30 min |
| K-15 | `Intl.NumberFormat` → `kioskPriceMixin` | 3 composants wizard à unifier | 1h |
| K-16 | Expiration token Sanctum kiosk | `'expiration' => 480` (8h) dans `config/sanctum.php` | 1h |
| K-17 | Injection conditionnelle blade | `kioskAutoLogin` seulement si route `/kiosk/*` | 1h |
| K-18 | Tiers loyalty dynamiques | Hardcode `[100, 250, 500, 1000, 2000]` → API config | 1h |

---

*Audit Claude — Prêt pour délégation Kimi*


---

*Audit Claude — Prêt pour délégation Kimi*
