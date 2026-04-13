# Plan Kimi — Taches simples post-audit Claude

**Date** : 2026-03-31  
**Auteur** : Claude (Architect)  
**Type de test** : Kimi-test sauf mention contraire  
**Contexte** : Apres l'audit global du tunnel borne et les corrections delicates (state machine, races, atomicite), il reste des taches simples et localisees a implementer par Kimi.

---

## ✅ Corrections deja effectuees par Claude (delicates)

| # | Tache | Fichier(s) | Status |
|---|-------|-----------|--------|
| K-3 | **Coupon : mauvais message pour coupon pas encore actif** — `CouponService.php` L268-270 modifie pour utiliser `coupon_not_yet_active`. | `app/Services/CouponService.php` | ✅ FAIT |
| K-4 | **Coupon : `couponDateWise()` crash sur dates null** — Guard ajoute pour verifier les dates avant Carbon::between(). | `app/Services/CouponService.php` | ✅ FAIT |

**Note** : Les cles i18n `coupon_not_yet_active` ont ete ajoutees dans `lang/fr/all.php`, `lang/en/all.php`, `lang/ar/all.php`.

---

## Taches par priorite (restantes pour Kimi)

### P1 — Correctifs simples a fort impact

| # | Tache | Fichier(s) | Effort | Test |
|---|-------|-----------|--------|------|
| K-1 | **KDS : `payment_method` en exact match** — Dans `KitchenDisplaySystemOrderService::list()`, ajouter `payment_method` au meme traitement que `status` (cast `(int)` + `where` exact au lieu de `LIKE`). | `app/Services/KitchenDisplaySystemOrderService.php` L74-80 | 5 min | Kimi-test (PHPUnit) |
| K-2 | **KDS : echapper les wildcards LIKE** — Dans la boucle de filtres `list()`, echapper `%` et `_` dans les valeurs avant le `LIKE '%'.$request.'%'`. Utiliser `str_replace(['%','_'], ['\%','\_'], $request)`. | `app/Services/KitchenDisplaySystemOrderService.php` L80 | 10 min | Kimi-test |
| K-5 | **Sanitize noms produits dans les toasts catalogue** — Dans `KioskCategoriesComponent.vue`, remplacer `detail.name` et `product.name` par `this.sanitizeItemName(...)` dans les 3 appels `showToast`. Aussi dans `img :alt`. | `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue` L352, L356, L390, L107 | 10 min | No-test |

### P2 — Nettoyage et coherence

| # | Tache | Fichier(s) | Effort | Test |
|---|-------|-----------|--------|------|
| K-6 | **`requireOrderRef` : valider que orderId est numerique** — Ajouter `/^\d+$/` ou `/^(offline_)?\d+/` au test `isValidParam`. | `resources/js/router/modules/kioskRoutes.js` L79 | 5 min | No-test |
| K-7 | **Supprimer `$isKioskMachineOrder` du `use()` de la transaction** — Variable capturee par reference mais jamais lue apres la transaction dans `myOrderStore`. La garder locale a la closure. | `app/Services/FrontendOrderService.php` L126-154 | 5 min | No-test |
| K-8 | **`auto_failed` query param** — Supprimer la reference dans le guard `requireKioskAuth` (L47) car aucun composant ne le lit. | `resources/js/router/modules/kioskRoutes.js` L47 | 2 min | No-test |
| K-9 | **Dead code wizard : `hasBoissonIds`** — Supprimer le computed inutilise dans `KioskStepMenuComponent.vue`. | `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue` | 2 min | No-test |
| K-10 | **Dead emit `close` dans `KioskConfirmationComponent`** — Supprimer `emits: ['close']` et `this.$emit('close')` dans `goHome()` car aucun parent ne l'ecoute. | `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` L132, L279 | 2 min | No-test |
| K-11 | **Upsell : debounce `addAndContinue`** — Ajouter un flag `_adding` pour empecher le double-tap sur le bouton ajouter. | `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` | 5 min | No-test |
| K-12 | **Upsell : toast FR hardcode** — Remplacer les textes `ajoute !` par des cles i18n dans les 3 langues. | `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`, fichiers i18n | 10 min | Kimi-test |

### P3 — Ameliorations futures (a planifier, pas urgent)

| # | Tache | Description | Effort |
|---|-------|-------------|--------|
| K-13 | **Extraire `calculateRunningTotal` en helper partage** | Wizard + summary + cart utilisent 3 implementations paralleles du meme calcul. Creer un helper unique. | 2h |
| K-14 | **Extraire ratio menu 60/40/100 en constante config** | Hardcode en 3 endroits. | 30 min |
| K-15 | **Remplacer `Intl.NumberFormat` hardcode par `kioskPriceMixin`** | Dans `KioskStepSauceComponent`, `KioskStepSupplementsComponent`, `KioskStepMenuComponent`. | 1h |
| K-16 | **Configurer expiration token Sanctum pour kiosk** | Ajouter `'expiration' => 480` (8h) dans `config/sanctum.php` pour les tokens kiosk. | 1h |
| K-17 | **Conditionner injection `kioskAutoLogin` dans blade** | Ne l'injecter que si la route commence par `/kiosk`. | 1h |
| K-18 | **Charger tiers loyalty depuis l'API config** | `KioskLoyaltyComponent` utilise des tiers hardcodes `[100, 250, 500, 1000, 2000]`. | 1h |

---

## Regles d'execution

1. **Chaque tache = un diff isole** — ne pas melanger les fichiers entre taches sauf si elles touchent le meme fichier.
2. **Lancer `npm test` apres chaque tache JS** et `php artisan test` apres chaque tache PHP.
3. **Lancer `npm run production` une fois toutes les taches JS terminees** pour verifier le build.
4. **Ne pas toucher** aux fichiers corriges par Claude dans cet audit (state machine, races, atomicite) sauf si explicitement demande.

---

## Resume pour Kimi

**Total taches** : 12 taches P1/P2 + 6 ameliorations P3  
**Deja corrigees par Claude** : 2 (K-3, K-4)  
**Restant pour Kimi** : 10 taches P1/P2 (~70 min) + 6 taches P3 (~6h30)

### Ordre recommande d'execution

**Phase P1 (fort impact)** :
1. K-1 → K-2 (KDS — filtres exacts)
2. K-5 (Sanitize toasts)

**Phase P2 (nettoyage)** :
3. K-7 (Variable inutilisee PHP)
4. K-8 (Query param mort)
5. K-6 (Validation orderId)
6. K-9 (Computed mort)
7. K-10 (Emit mort)
8. K-11 (Debounce upsell)
9. K-12 (i18n toast upsell)

**Phase P3 (améliorations)** :
10. K-14 (Ratio config — 30 min)
11. K-15 (Intl.NumberFormat mixin — 1h)
12. etc.

---

*Plan genere par Claude (architecte) — 2026-03-31.*
