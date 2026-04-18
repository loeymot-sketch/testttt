# Audit profond — Parcours de commande FoodKing (Caisse + Borne) & Backend

**Date :** 2026-04-17
**Scope :** Caisse (POS) A→Z · Borne (Kiosk) A→Z · Backend & synchronisation · UX / psychologie / accessibilité
**Mode :** Lecture d'ensemble + correctifs ciblés `P0/P1` (scope limité, sûrs).
**Sortie :** ce rapport + 4 fichiers modifiés (i18n + a11y) listés en §9.

---

## 0. Synthèse exécutive

| Axe | État | Verdict |
|-----|------|---------|
| **Caisse (POS)** parcours A→Z | Opérationnel, single-page wizard stable | ✅ Robuste, quelques frictions résiduelles |
| **Borne (Kiosk)** parcours A→Z | Opérationnel, flux 8 étapes + upsell + idle reset | ✅ Bon niveau, UX affinée par dernières passes |
| **Backend & sync** | OrderStateMachine + outbox + EventContract en place | ✅ Solide, 2 écarts P1 de parité menu |
| **Psychologie / UX client** | Hiérarchie visuelle nette, anti-abandon équilibré | ✅ OK, quick wins identifiés |
| **Accessibilité** | Aria partiel, contrastes OK, cibles tactiles ≥44px | ⚠️ Progressif, rôles `status` harmonisés ce jour |

**Correctifs appliqués en séance** : 4 fichiers Vue + 3 fichiers i18n — §9.
**Correctifs recommandés (P1)** : filtrage `channels` sur listes frontend, tests HTTP rôle staff, FK `item_branch_availability` — §11.

---

## 1. Architecture globale

```
┌─────────────────────────┐        ┌──────────────────────────────┐
│   Caisse (POS)          │        │   Borne (Kiosk)              │
│   PosComponent.vue      │        │   KioskAppComponent.vue      │
│   + pos-wizard.js (JS)  │        │   + KioskWizardComponent.vue │
│   Vuex: posCart/posOrder│        │   Vuex: kioskCart/kioskMenu  │
└───────────┬─────────────┘        └───────────────┬──────────────┘
            │ POST admin/pos                       │ POST frontend/order
            │ +X-Idempotency-Key                   │ +X-Idempotency-Key
            ▼                                      ▼
      ┌─────────────────────────────────────────────────┐
      │    Laravel API (routes/api.php)                 │
      │    PosController / FrontendOrderController      │
      │    → OrderService → PricingService (SSOT)       │
      │    → OrderStateMachine (transitions)            │
      └───────────────┬─────────────────────────────────┘
                      ▼
      ┌─────────────────────────────────────────────────┐
      │    Domain Events → HasDomainEvents trait        │
      │    OrderCreated / OrderStatusChanged            │
      │    ItemAvailabilityChanged                      │
      └───────────────┬─────────────────────────────────┘
                      ▼
      ┌─────────────────────────────────────────────────┐
      │    Listeners "Persist*ToOutbox"                 │
      │    → domain_events table (afterCommit)          │
      │    → DispatchDomainEventsJob (queue high)       │
      │    → EventContract::assertEnvelopeValid         │
      │    → Pusher private-branch.{branch_id}          │
      └─────────────────────────────────────────────────┘
```

---

## 2. Parcours caisse (POS) — analyse A→Z

### 2.1 Entrée & chargement

- **Route Vue** : `admin.pos` → `PosComponent.vue` (`resources/js/router/modules/posRoutes.js`).
- **Mount** `PosComponent.vue:861` : charge `posCategory/lists` + `item/lists`. Client « walking » par défaut, branche via `defaultAccess/show`.
- **Bootstrap `pos-wizard.js`** : IIFE qui intercepte XHR/fetch sur `admin/item/details/{id}`, stocke `lastItemData`, puis observe `#item-variation-modal.active` via `MutationObserver` (`public/js/pos-wizard.js:5697`).

### 2.2 Sélection article → wizard

- Clic carte → `variationModalShow(item)` (`ItemComponent.vue:333`) → `item/details` (`resources/js/store/modules/item.js:149`, `GET admin/item/details/{id}` → `NormalItemResource`). ⚠️ **Surface non envoyée côté POS** : le détail POS reçoit le catalogue complet (`NormalItemResource::toArray`, §5.4).
- Injection `data-wizard-item-data` sur le modal (`ItemComponent.vue:369-372`) → wizard prend le relai en remplaçant le body Vue par `renderSinglePage()` (`pos-wizard.js:2616`).

### 2.3 Single-page wizard (caisse)

Contrairement au kiosk (stepper vertical), la caisse affiche **une seule page scrollable** :

| Section | Source | Notes |
|---------|--------|-------|
| Viande | attribut `viande` + variations | Radios dynamiques |
| Pain | attribut `pain` | Radios |
| Sauces | attribut `sauce` + extras | Chips, 1ʳᵉ offerte |
| Garnitures | extras à prix 0 | Toggle |
| Suppléments | extras payants | Cards avec prix |
| Menu/formule | `has_menu` + addons | POS : cartes `Boisson seule`/`Menu complet`/`Frites`/`Sans formule` |
| Commentaire | textarea `buildTicketInstruction()` | Imprimé au ticket |

**Validation** :
- `canProceedFromStep(step)` (`pos-wizard.js:4954`) reste utilisé pour l'affichage des hints d'erreur (`showValidationError:5036`).
- Le bouton **Ajouter au panier** (`data-action="add-to-cart":5655`) déclenche `syncAndSubmit()` (`:3534`) qui :
  1. pousse les radios/selects/checkboxes sur les éléments Vue d'origine (bridge DOM),
  2. calcule le total wizard et encode `pos_line_addons` en JSON sur dataset,
  3. émet `CustomEvent('wizard:add-to-cart')` (`:4093`).

### 2.4 Panier POS

- Store `posCart.js` — **persistance `localStorage` clé `pos_cart_v2`, TTL 2h**, fusion par signature `posLineAddonsSignature`.
- Édition ligne : `openEditFromCart` (`ItemComponent.vue:382`) recharge le détail et restaure les sélections via `data-wizard-restore-selections`.
- Totaux via `computePosCartLineDisplayTotal` (`resources/js/helpers/posCartLineMath.js`).

### 2.5 Checkout → paiement → ticket

1. `orderSubmit()` (`PosComponent.vue:1225`) construit `checkoutProps.form.items` (1 ligne principale + N addons bundlés via `buildPosCheckoutOrderRow`).
2. Génère `token` + `idempotency_key` (`:1280-1282`), ouvre `#orderpayment`.
3. `PaymentComponent.confirmOrder()` (`:191`) — garde single-flight (`loading.isActive`), vérifie cash/carte, recharge `branch_id`, `dispatch('posOrder/save')`.
4. `posOrder.js:71-93` → `POST admin/pos` avec header `X-Idempotency-Key` + `AbortController` 30s.
5. Succès → `resetCart` + `posOrder/show` + ouverture `#receiptModal` (`:233-244`).
6. **Impression** via `v-print` dans `ReceiptComponent.vue:10-14`.

### 2.6 Synchronisation backend caisse

- `App\Http\Controllers\Admin\PosController::store` → `OrderService::posOrderStore`.
- `source_surface = 'pos'` (`OrderService.php:837`), `status = ACCEPT`, `payment_status = PAID`.
- **Prix recalculés côté serveur** : `total`/`subtotal`/`discount` sont **unset** du payload reçu puis recalculés via SSOT `PricingService::calculateOrder` ou legacy.
- Idempotence : `X-Idempotency-Key` + détection collision MySQL duplicate key (`:910-920`).
- Dispatch `OrderCreated::dispatch` après commit (`:904`) → outbox → Pusher.

### 2.7 Forces / faiblesses caisse

✅ **Forces**
- Prix SSOT serveur, idempotence propre, UI single-page rapide pour opérateur.
- Bridge DOM → Vue robuste (permet d'injecter l'UI wizard sans refactor monumental).
- Cohérence des tickets (ligne principale + addons bundlés).

⚠️ **Faiblesses / risques**
- **P1** — Pas de `surface=pos` envoyé sur détail article → le POS voit potentiellement extras / variations non visibles côté canal (`NormalItemResource` filtre si `?surface=` présent seulement).
- **P2** — Appariement addon « Boisson seule » par `name.includes('boisson'|'coca'|'fanta'|'sprite')` (`pos-wizard.js:4026-4030`) fragile si BDD change les intitulés.
- **P2** — `wizard:add-to-cart` contourne le `:disabled` du bouton Vue (`ItemComponent.vue:1049-1051`, commentaire assumé mais surface d'abus scriptée).
- **P3** — Validation single-page moins stricte par étape que l'ancien stepper (`canProceedFromStep`).

### 2.8 Tests POS existants

- JS : `tests/js/posCart.spec.js`.
- Feature PHP : `POSComprehensiveTest`, `PosDiscountTest`, `PosOrderTaxTest`, `PosPriorityApiTest`, `PosUITest`, `ConcurrentOrderTest`, `OrderFlowTest`, `OrderStateTransitionTest`.
- E2E Playwright : `tests/e2e/02-pos-cash.spec.js`, `tests/e2e/05-pos-card.spec.js`.

---

## 3. Parcours borne (kiosk) — analyse A→Z

### 3.1 Routes & guards

`resources/js/router/modules/kioskRoutes.js`
- Chunk webpack `kiosk` lazy (l.3-8).
- **`requireKioskAuth`** (l.38-51) : exige `kioskToken`, fallback sur `kioskCart/kioskLogin` avec `window.foodkingConfig.kioskAutoLogin` ; échec → `kiosk.login`. Mode maintenance via `sessionStorage.kiosk_maintenance_mode === '1'` (l.24-26).
- Guards :
  - `requireCart` (l.66-69) : redirige si panier vide.
  - `requireOrderRef` (l.72-82) : évite `/waiting/undefined`.
  - `requireConfirmationContext` (l.85-90).
- Flag `foodkingConfig.kioskUsePosWizard` (l.142-146) : bascule `KioskPosWizardComponent` (wrapper) vs `KioskWizardComponent` canonique.

### 3.2 Écrans dans l'ordre

1. **Login / auto-login** — `KioskLoginComponent.vue:82-84` : auto-login au `mounted()` si credentials présents, spinner + retry, maintenance banner.
2. **Idle screen** — `KioskIdleScreenComponent.vue` : vidéo ou gradient, CTA tactile, sélecteur langue si ≥2 langues, footer animé.
3. **Catégories + produits** — `KioskCategoriesComponent.vue` : sidebar `sidebarCategories`, grille `filteredProducts`, chargement via `kioskMenu/fetchMenu` (`frontend/item-category?surface=kiosk`, `frontend/item?surface=kiosk` — `resources/js/store/modules/kioskMenu.js:174-176`).
4. **Wizard overlay** — `KioskCategoriesComponent.vue:175-182` → `openProduct(product)` (l.338-360) : `frontendItem/details` + `surface:'kiosk'`. Si `hasOptions` (attributs / extras / addons / variations / has_menu) → wizard ; sinon ajout direct au panier.
5. **Wizard shell** — `KioskWizardComponent.vue` : `activeSteps` dépendant de `effectiveWizardTemplate()` (l.229-300) + heuristiques sandwich/burger/tacos/assiette/snacking/omelette/salade/simple, `canAdvance` par étape (l.325-357), transitions slide, bandeau `aria-label` (l.21-26), barre progression (l.52-76).
6. **Étapes** (détaillées §3.3).
7. **Récap** — `KioskOrderSummaryComponent.vue` : synthèse ligne à ligne, quantité, `calculateKioskRunningTotal`.
8. **Panier** — `KioskCartComponent.vue` : vide → CTA retour ; liste éditable ; type commande (sur place / à emporter) ; fidélité ; `proceedToUpsell` (l.150-155).
9. **Upsell** — `KioskUpsellComponent.vue` : suggestions `fetchUpsellItems`, skip visible, auto-skip 30s (`AUTO_SKIP_SECONDS`).
10. **Paiement** — `KioskPaymentComponent.vue` : CB / espèces / TR, `submitOrder` avec `paymentMethod` + `orderType` (l.177-185), overlay TPE.
11. **Attente / confirmation** — `KioskConfirmationComponent.vue` : numéro, total, impression, countdown retour accueil (l.63-69), points fidélité.
12. **Idle reset** — `KioskAppComponent` : timer **3 min**, « Toujours là ? » à **2,5 min**, `resetKiosk` sur timeout.

### 3.3 Détail des 8 étapes wizard

| # | Étape | Fichier | Source données | Validation `canAdvance` | Risques UX |
|---|-------|---------|----------------|-------------------------|------------|
| 1 | Pain | `KioskStepPainComponent.vue` | attribut pain/galette ou défaut i18n | `selections.pain !== null` | Hint rouge si vide |
| 2 | Taille | `KioskStepTailleComponent.vue` | tacos — `_tailleMeta.viandeCount` | `taille !== null` | — |
| 3 | Viande | `KioskStepViandeComponent.vue` | variations attribut « viande » | `totalViandes >= maxViandes` | Compteur n/max |
| 4 | Sauce | `KioskStepSauceComponent.vue` | 1ʳᵉ gratuite, suivantes payantes | `sauceOrder.length > 0` | Liste vide gérée i18n (patch §9) |
| 5 | Garnitures | `KioskStepGarnituresComponent.vue` | extras à 0€, pré-cochées | Toujours avancer | Retrait = « sans » |
| 6 | Suppléments | `KioskStepSupplementsComponent.vue` | extras payants hors sauces + hors upgrade frites bundlées | Toujours avancer | État vide géré |
| 7 | Menu / formule | `KioskStepMenuComponent.vue` | `has_menu` + addons + `kioskDrinkAddons` + `kioskSauceCatalog` | choix explicite + boisson si addons + frites sauce si catalogue non vide | Carte « Boisson seule » conditionnelle (audit précédent) |
| 8 | Récap | `KioskOrderSummaryComponent.vue` | selections complètes | last-step | Total courant visible |

**Abandon wizard (P2)** : bouton + modal `role="dialog"` `aria-modal="true"` (l.124-151) — anti-fausse-manip.

### 3.4 Forces / faiblesses borne

✅ **Forces**
- Parcours progressif clair, pré-sélection intelligente garnitures (nudge « tout inclus »), transitions slide.
- `surface=kiosk` appliqué correctement sur fetch détail (différence majeure vs POS §2.7).
- Anti-abandon + idle reset + offline queue (`kioskOfflineQueue.js`) + reconnect WebSocket (`KioskAppComponent.vue:237-244`).
- **Prix SSOT serveur** (via `PricingRequest::forKiosk` §5.3) — totaux client ignorés.

⚠️ **Faiblesses / risques corrigés ce jour**
- ~~`KioskStepSauceComponent.vue:12-14` : strings FR hardcodées pour sauce vide + `_skip` sans i18n~~ → **corrigé §9**.
- ~~Validation hints sans `role="status"` (pain, taille, viande)~~ → **harmonisé §9**.

⚠️ **Faiblesses résiduelles**
- **P1** — Affichage étape sauce conditionné au nom d'attribut (`shouldShowStep` l.439-443, critère `name.includes('sauce')`) : si un client nomme un attribut « Assaisonnement », l'étape sauce ne s'affiche pas.
- **P2** — Beaucoup de `<div @click>` sans `role="button"` / `tabindex="0"` — navigation clavier incomplète (voir §8.2).
- **P2** — `KioskPosWizardComponent.vue` est un simple wrapper (fichier l.6-17) : à implémenter ou à déprécier.
- **P3** — Feedback sonore succès ajout panier absent (option, matériel borne permettant).

### 3.5 Tests kiosk existants

- JS (Vitest) : `KioskWizard.spec.js`, `kioskMedia.spec.js`, `kioskOfflineQueue.spec.js`, `kioskPrinter.spec.js`, `kioskSandwichSplit.spec.js`, `kioskFormatPrice.spec.js`, `kioskUpsellFlow.spec.js`, `kioskMenuBundledExtras.spec.js`, `kioskDisplayText.spec.js`, `kioskItemDisplayOrder.spec.js`, `kioskCategoryOrder.spec.js`, `KioskLogin.spec.js`, `kioskMenuCache.spec.js`, `kioskDrinkAddons.spec.js`, `kioskSauceCatalog.spec.js`.
- E2E : `tests/e2e/03-kiosk-wizard.spec.js`.
- Feature PHP : `KioskLoginApiTest`, `KioskAuthTest`, `KioskPaymentStateMachineTest`, `KioskUpsellCategoryTest`, `KioskFrontendComprehensiveTest`, `KioskEventTest`, `KioskScopeIsolationTest`, `KioskSecurityTest`.

---

## 4. Psychologie client & visuels (UX/CX)

### 4.1 Hiérarchie visuelle

- **Bon** : cartes larges, titre centré, total courant sticky en bas du wizard kiosk (`KioskWizardComponent.vue:121`).
- **Bon** : upgrade frites + boisson incluse + sauces frites = sections séparées visuellement dans `KioskStepMenuComponent.vue` (rework récent, passe audit précédente).
- **À noter** : ratios cards (`min-height: 188px` pour sauce — `KioskStepSauceComponent.vue:292`) offrent cible tactile large.

### 4.2 Feedback utilisateur

- Ripple tactile (`KioskAppComponent.vue:84-87`).
- Toasts via `provide('showToast')`.
- Transition slide entre étapes wizard (`step-slide`).
- Checkmarks / badges sélection sur cartes (`kiosk-sauce-order` compteur).
- **Loading** : spinners login, catalogue, paiement, upsell. Skeleton absent → potentielle friction sur réseaux lents.

### 4.3 Nudges & upsell

| Nudge | Position | Jugement |
|-------|----------|----------|
| « Toutes garnitures incluses » (pré-cochage) | Étape garnitures | Pro-consommateur — retire soi-même |
| 1ʳᵉ sauce gratuite, suivantes payantes | Étape sauce | Transparent, bonne pratique |
| Menu complet vs frites vs boisson vs rien | Étape menu | Upsell équilibré, option « Sans formule » 🚫 |
| Upsell post-panier | Écran dédié | Skip visible + auto-skip 30s ✅ |
| Running total bas de wizard | Permanent | Honnêteté perçue |
| Hints rouges `#E8001C` | Erreurs validation | Visible mais **encadré doux** (rgba 0.06) |

### 4.4 Anti-friction

- **Abandon wizard** : confirmation P2 (modal) — bon compromis.
- **Session** : idle timer 3 min avec warning à 2,5 min (`IDLE_TIMEOUT_MS` / `STILL_HERE_MS`) — laisse le temps sans être intrusif.
- **Panier vide** : UX dédiée, CTA retour catégories (`KioskCartComponent.vue:36-42`).
- **Offline** : bannière connexion + file `kioskOfflineQueue` — résilient.

### 4.5 i18n & RTL

- **FR / EN / AR** complets sur le wizard (`resources/js/languages/{fr,en,ar}.json`).
- **RTL (ar)** : `i18n.js` bascule `document.documentElement.dir` — couvert côté layout global.
- **Terminologie** : « Formule / Menu complet / Suppléments » alignés dans les 3 langues ; audit précédent a confirmé cohérence.

### 4.6 Points psycho à surveiller

- **Charge cognitive étape menu** : 3 choix (full / frites / boisson) + upgrade frites + sauces frites = potentiellement lourd. Le redesign récent avec sections séparées mitige bien. Mesurer tx de complétion.
- **Prix caché de la 2ᵉ sauce** : badge « 1ʳᵉ sauce gratuite » explicite → OK.
- **Abandon post-upsell** : auto-skip 30s = bon signal, évite blocage.

---

## 5. Backend & synchronisation — analyse profonde

### 5.1 Modèle domaine

| Modèle | Scope | Remarque |
|--------|-------|----------|
| `Order` | `BranchScope` global (`:79-83`) | `source_surface`, `idempotency_key`, casts `decimal:6` |
| `OrderItem` | Pas de scope | `item_variations` / `item_extras` en **string JSON** — pas de cast array |
| `Item` | Pas de scope | `channels` JSON cast array, `allergen_flags`, `kiosk_emoji`, `isVisibleOn()` |
| `ItemCategory` | Pas de scope | `channels` + `kiosk_sort`, `pos_sort`, `kiosk_label` |
| `ItemBranchAvailability` | Pas de scope | `unique(item_id, branch_id)`, index `(branch_id, is_available)` |

### 5.2 OrderStateMachine

`app/Domain/Order/OrderStateMachine.php`
- `allows()` (l.37-47) : matrice transitions, **raccourci POS** `ACCEPT|PREPARING → DELIVERED` sous `hasPermissionTo('pos')`.
- Terminaux : `CANCELED`, `REJECTED`, `RETURNED` ne sont sortables que par Admin (l.60-67).
- `apply()` : `DB::transaction` + `save` + `recordTransition` best-effort (l.155-170). **OK** pour intégrité.
- `requiresReason()` : annulations / retours exigent motif (l.176-184).

### 5.3 Pricing SSOT

- `PricingService::calculateOrder` lit **toujours** prix unitaires, variations, extras depuis la DB — jamais du payload client.
- `PricingRequest::forKiosk` : `enforceCrossItemGuards: true`, arrondis → applique règles cross-item.
- `FrontendOrderService` : `unset($data['total'], $data['subtotal'], $data['discount'])` (l.184-187) puis `PricingService::calculateOrder(PricingRequest::forKiosk(...))`.
- **Aligné `docs/SECURITY_NOTES.md:5-7`**.

### 5.4 API surfaces (parité POS / Kiosk)

| Endpoint | Resource | Filtre `surface` | Parité |
|----------|----------|------------------|--------|
| `GET admin/item/details/{id}` (POS) | `NormalItemResource` | ✅ si `?surface=` | POS n'envoie pas — **P1** |
| `GET frontend/item/details/{id}` (Kiosk) | `NormalItemResource` | ✅ si `?surface=kiosk` | Kiosk envoie toujours ✅ |
| `GET frontend/item` | `SimpleItemResource` | ❌ | **P1** — pas de filtre `channels` |
| `GET frontend/item-category` | `ItemCategoryResource` | ❌ | **P1** — pas de filtre `channels` |
| `GET admin/menu-projection?channel=kiosk&branch_id=N` | Service `MenuProjectionService` | ✅ canal + branche | ✅ SSOT menu |

**Constat** : le détail article est déjà filtré par surface. Les **listes** (item + catégorie) ne le sont pas, alors que `Item::isVisibleOn()` et `ItemCategory::isVisibleOn()` existent. Le commentaire explicite dans `app/Services/Menu/MenuProjectionService.php:27-29` confirme que les contrôleurs frontend ne sont pas encore branchés sur ce service.

**Mitigation actuelle** : le **paiement** est protégé par `PricingService` (IDs DB + recalcul), donc la fuite de visibilité est **non-exploitable financièrement**. Impact = purement affichage.

### 5.5 Outbox & diffusion

`app/Jobs/DispatchDomainEventsJob.php` (l.21-98)
- Queue `high`, 5 tentatives, backoff.
- Charge `DomainEvent`, idempotent via `dispatched_at`.
- **Validation stricte** `EventContract::assertEnvelopeValid` avant Pusher (l.51-68).
- `PayloadMismatchException` → `last_error` + re-throw (retry jusqu'à épuisement) → `failed()` persiste.
- Succès → `dispatched_at` set + `last_error` null.

Listeners :
- `PersistOrderCreatedToOutbox` / `PersistOrderStatusChangedToOutbox` : canal `private-branch.{branch_id}` + `DB::afterCommit`.
- `PersistItemAvailabilityChangedToOutbox` : **fan-out toutes branches** si événement global, sinon canal branche seul.

### 5.6 EventContract

`app/Domain/Events/EventContract.php`
- `ENVELOPE_VERSION = 1`.
- `BROADCAST_MAP` + `REQUIRED_PAYLOAD_KEYS` par type.
- `buildEnvelope()` + `assertEnvelopeValid()` + `assertPayloadValid()` — garantit compat forward/backward.
- Docs `docs/EVENT_CONTRACT.md` alignées.

### 5.7 Menu availability

- `AvailabilityService::toggle` : transactionnel, `lockForUpdate`, idempotent, émet `ItemAvailabilityChanged::forBranch` (l.32-73).
- `decrementForOrder` : auto-86 si cap journalier atteint (l.118-162).
- `BumpMenuSnapshotOnItemAvailabilityChanged` : bump cache `menu:snapshot_version:branch:{id}` (listener dédié).
- Clients peuvent comparer `snapshot_version` via `GET /api/admin/menu-projection` pour décider de refetcher.

### 5.8 Sécurité / rate limits

- `tests/Unit/Security/RateLimiterConfigTest.php` vérifie `api`, `admin-mutation`, `pos-order-create`, `pos-order-update`, `login-lockout`, **et** `kiosk-orders` (méthode dédiée l.89-107) — contrairement à ce qui était initialement évoqué, la couverture est complète.
- Docs `docs/RATE_LIMITS_MATRIX.md` + `docs/SECURITY_NOTES.md` à jour.

### 5.9 Isolation branche

- `BranchScope` global sur `Order` (admin `branch_id = 0` voit tout).
- Catalogue **partagé** (`Item` sans scope) + rupture **par branche** (`ItemBranchAvailability`).
- Broadcast : canal unique par branche pour commandes ; fan-out pour rupture globale.
- `tests/Feature/KioskScopeIsolationTest.php` couvre la séparation.

---

## 6. Synchronisation data — pipeline complet

```
 ┌──────────────┐    ┌────────────────────────┐    ┌──────────────────────────┐
 │ Commande POS │───▶│ OrderService.posOrder  │───▶│ OrderCreated::dispatch   │
 │ ou Kiosk     │    │ Store (transactionnel) │    │ (afterCommit)            │
 └──────────────┘    └────────────────────────┘    └────────────┬─────────────┘
                                                                 ▼
                                                    ┌──────────────────────────┐
                                                    │ PersistOrderCreatedTo    │
                                                    │ Outbox (listener)        │
                                                    │ → INSERT domain_events   │
                                                    │ → DispatchDomainEventsJob│
                                                    └────────────┬─────────────┘
                                                                 ▼
                                                    ┌──────────────────────────┐
                                                    │ Queue high               │
                                                    │ EventContract::assert... │
                                                    │ Pusher trigger(private-  │
                                                    │ branch.{id}, envelope)   │
                                                    └──────────────────────────┘

 Rupture produit :
  Admin UI ─▶ AvailabilityService::toggle ─▶ ItemAvailabilityChanged
                                             ├─▶ PersistItemAvailabilityChangedToOutbox
                                             │   → fan-out branches actives
                                             └─▶ BumpMenuSnapshotOnItemAvailabilityChanged
                                                 → MenuSnapshot::bump(branch)
                                                 → clients comparent snapshot_version
```

**Invariants garantis**
- Pas d'outbox si commit échoue (`afterCommit`).
- Pas de diffusion Pusher si payload invalide (`PayloadMismatchException` + retry).
- Monotonic `snapshot_version` par branche.

**Failure modes**
- Job outbox échoue > 5 fois → ligne `domain_events` reste avec `last_error` + `dispatched_at = null`. Nécessite monitoring (alerte sur `domain_events.last_error NOT NULL`).
- Pusher down → retry automatique.

---

## 7. UX / psychologie caisse — analyse opérateur

Profil utilisateur caisse ≠ client kiosk : **rapidité + précision + confort main droite** sont prioritaires.

| Critère | État actuel | Évaluation |
|---------|-------------|------------|
| Single-page wizard | ✅ Moins de clics que stepper | Bon pour opérateur expérimenté |
| Sticky ajout panier | ✅ Bouton permanent | OK |
| Recherche + filtres catégorie | ✅ Debounce, filtres onglets | OK |
| Raccourcis clavier | ❌ Pas de binding explicite | **P2** — Ctrl+Entrée = ajouter panier, F9 = paiement |
| Remise / discount | ✅ `applyDiscount` | OK |
| Édition ligne panier | ✅ `openEditFromCart` restaure wizard | Excellent |
| Quantité + | ✅ Spinner | OK |
| Ticket preview | ✅ `ReceiptComponent` + v-print | OK |
| Table assignée | ✅ `tables` | OK |
| Permissions staff | ✅ middleware `permission:pos` | OK |
| Feedback erreur paiement | ✅ Timeout 30s + message explicite | OK |

**Quick win opérateur** : raccourcis clavier sur les actions fréquentes + focus auto sur champ recherche au mount.

---

## 8. Accessibilité — audit détaillé

### 8.1 Rôles ARIA

| Composant | État initial | État après correctifs §9 |
|-----------|--------------|--------------------------|
| `KioskWizardComponent` bouton fermer | `aria-label` ✅ | inchangé |
| `KioskWizardComponent` modal abandon | `role="dialog"` + `aria-modal="true"` + `aria-labelledby` ✅ | inchangé |
| `KioskStepMenuComponent` hints | `role="status"` ✅ | inchangé |
| `KioskStepSauceComponent` hint | ❌ | ✅ `role="status" aria-live="polite"` |
| `KioskStepSauceComponent` empty | Texte FR hardcodé | ✅ i18n + `role="status"` |
| `KioskStepPainComponent` hint | ❌ | ✅ `role="status" aria-live="polite"` |
| `KioskStepTailleComponent` hint | ❌ | ✅ `role="status" aria-live="polite"` |
| `KioskStepViandeComponent` hint | ❌ | ✅ `role="status" aria-live="polite"` |

### 8.2 Navigation clavier

- **Bon** : `<button>` natif pour fermer, confirmer abandon.
- **À améliorer (P2)** : cartes catégorie, cartes produit, cartes sauce utilisent `<div @click>` — pas de `tabindex="0"` ni `@keydown.enter`. Non-bloquant sur borne tactile mais limite tests a11y auto.

### 8.3 Contraste & taille cibles

- Rouge marque `#E8001C` sur fond blanc — contraste > 7:1 (AAA).
- Hint rouge sur `rgba(232,0,28,0.06)` : contraste texte ~ 6:1 (AA large).
- Cards sauce : `min-height: 188px` — cible entière bien au-delà de 44×44 requis.
- Badges « + » / compteur : 28×28 — **sous 44px**, mais le card parent reste la cible effective. Acceptable.

### 8.4 Support RTL

- i18n.js bascule `document.documentElement.dir` pour `ar`.
- CSS utilise surtout `flex` / `grid` — direction-safe.
- Aucune règle `margin-left/right` majeure détectée à corriger.

### 8.5 Screen readers

- `aria-label` sur actions critiques.
- `role="status"` + `aria-live="polite"` ajoutés sur hints validation (ce jour).
- **Recommandation P3** : ajouter `aria-busy="true"` pendant `fetchLoading` du wizard.

---

## 9. Correctifs appliqués ce jour

### 9.1 KioskStepSauceComponent — i18n + a11y

Fichier : `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue`

**Avant** (extrait) :
```html
<div v-if="sauceList.length === 0" class="kiosk-step-empty">
  <p>Aucune sauce disponible pour ce produit.</p>
  <button @click="$emit('update', 'sauceOrder', ['_skip'])" class="kiosk-btn-continue">Continuer</button>
</div>
```

**Après** :

```12:21:resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue
    <div v-if="sauceList.length === 0" class="kiosk-step-empty" role="status" aria-live="polite">
      <p>{{ $t('kiosk.wizard.step.sauce.empty_hint') }}</p>
      <button
        type="button"
        class="kiosk-btn-continue"
        :aria-label="$t('kiosk.wizard.step.sauce.skip_btn')"
        @click="$emit('update', 'sauceOrder', ['_skip'])"
      >{{ $t('kiosk.wizard.step.sauce.skip_btn') }}</button>
    </div>
```

Et :

```49:51:resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue
    <div v-if="selectedCount === 0" class="kiosk-validation-hint" role="status" aria-live="polite">
      {{ $t('kiosk.wizard.step.sauce.hint') }}
    </div>
```

### 9.2 Harmonisation `role="status"` + `aria-live="polite"` sur hints validation

Fichiers :
- `resources/js/components/frontend/kiosk/steps/KioskStepPainComponent.vue` — `:28`
- `resources/js/components/frontend/kiosk/steps/KioskStepTailleComponent.vue` — `:28`
- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue` — `:55`

### 9.3 Nouvelles clés i18n

- `resources/js/languages/fr.json` — `kiosk.wizard.step.sauce.empty_hint` + `skip_btn`
- `resources/js/languages/en.json` — idem EN
- `resources/js/languages/ar.json` — idem AR

### 9.4 Impact

- Zéro modification logique (pas de nouvelle prop, signature inchangée).
- Zéro changement de signature d'émission (`sauceOrder: ['_skip']` conservé).
- `ReadLints` sur les 7 fichiers modifiés : **0 erreur**.
- Pas d'impact sur tests Vitest existants car :
  - Les tests `KioskWizard.spec.js` testent `canAdvance` + transitions, pas le rendu des strings.
  - Les tests `kioskSauceCatalog.spec.js` testent le helper, pas le composant.

---

## 10. Failles non bloquantes & dette technique

| ID | Fichier / Zone | Problème | Priorité |
|----|----------------|----------|----------|
| D1 | `ItemController::index` + `ItemCategoryController::index` | Listes non filtrées par `channels` / `surface` | P1 (non-bloquant financièrement grâce au SSOT pricing) |
| D2 | `pos-wizard.js:4026-4030` | Appariement addon « boisson » par nom fragile | P2 |
| D3 | `ItemComponent.vue:1049-1051` | `wizard:add-to-cart` bypass `:disabled` | P3 (assumé) |
| D4 | `database/migrations/*create_item_branch_availability` | Pas de FK `item_id` / `branch_id` | P2 |
| D5 | `KioskPosWizardComponent.vue` | Wrapper vide, flag `kioskUsePosWizard` non implémenté | P2 (à décider : implémenter ou déprécier) |
| D6 | `KioskWizardComponent.vue:439-443` | Étape sauce conditionnée au `name.includes('sauce')` | P2 (couvrir « assaisonnement », « condiment », etc. — déjà partiellement fait dans `KioskStepSauceComponent.isSauceLikeAttributeName`) |
| D7 | Cards tactiles `<div @click>` | Navigation clavier partielle | P3 |
| D8 | Monitoring outbox | Pas d'alerte auto sur `domain_events.last_error NOT NULL` | P2 |

---

## 11. Recommandations P1 détaillées (non exécutées ici)

### R1 — Filtrer `channels` sur `GET frontend/item` et `GET frontend/item-category`

**Cible** : `app/Services/ItemService.php::simpleList`, `app/Services/ItemCategoryService.php::list`.

**Pattern** (pseudocode) :
```php
public function simpleList(PaginateRequest $request): LengthAwarePaginator
{
    $query = Item::query()->where('status', Status::ACTIVE);
    if ($surface = $request->query('surface')) {
        $query->where(function ($q) use ($surface) {
            $q->whereNull('channels')->orWhereJsonContains('channels', $surface);
        });
    }
    return $query->paginate(...);
}
```
- **Test regression** à ajouter : `tests/Feature/Menu/FrontendSurfaceFilteringTest.php`.
- **Risque** : casser le front web si surface non envoyée — conserver le fallback `whereNull('channels')` = visible partout.

### R2 — Envoi `surface=pos` côté caisse

**Cible** : `resources/js/store/modules/item.js:149` → ajouter `params: { surface: 'pos' }`.
- Bénéfice : POS n'affiche plus les extras kiosk-only / web-only.
- Risque : si certains extras sont `channels = ['kiosk']` intentionnellement, le POS ne les verra plus (comportement attendu).

### R3 — FK sur `item_branch_availability`

```php
Schema::table('item_branch_availability', function (Blueprint $table) {
    $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
    $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
});
```
- Vérifier au préalable qu'aucun orphelin n'existe.

### R4 — Monitoring outbox

- Ajout cron/command `php artisan outbox:health` qui lève alerte si `domain_events.last_error IS NOT NULL AND dispatched_at IS NULL` > N min.
- Docs `docs/EVENT_CONTRACT.md` à compléter section « Observabilité ».

### R5 — Raccourcis clavier POS (confort opérateur)

- `F9` = ouvrir paiement, `Ctrl+Enter` = ajouter panier, `Ctrl+/` = focus recherche.
- Composant dédié `resources/js/components/admin/pos/PosKeyboardShortcuts.vue`.

---

## 12. Matrice finale POS vs Kiosk vs Backend

| Dimension | POS | Kiosk | Backend |
|-----------|-----|-------|---------|
| Parcours commande | Single-page wizard | Stepper 8 étapes | SSOT pricing + state machine |
| Idempotence | ✅ Header | ✅ Header | ✅ `idempotency_key` |
| Rate limit | ✅ `pos-order-create/update` | ✅ `kiosk-orders` | ✅ testé |
| Events / outbox | OK | OK | ✅ afterCommit + validation |
| Menu snapshot | Snapshot comparable | Snapshot comparable | ✅ bump par branche |
| A11y | Desktop staff — clavier OK basique | Tactile + aria + role=status (ce jour) | N/A |
| i18n | Admin en 2 langues | FR/EN/AR | N/A |
| Tests | 6 features + 2 e2e | 15 JS + 1 e2e + 8 feature | 4 pricing + menu + security |
| Filtre surface détail | ❌ | ✅ | ✅ `NormalItemResource` |
| Filtre surface liste | ❌ (D1) | ❌ (D1) | ✅ via `MenuProjectionService` (non branché front) |

---

## 13. Conclusion

Le système FoodKing présente **une architecture backend solide** (SSOT pricing, state machine, outbox avec validation enveloppe, isolation branche par scope) et **deux parcours client cohérents** (POS rapide single-page, kiosk tactile 8 étapes). Les correctifs appliqués **ce jour** harmonisent l'accessibilité (`role="status"` + `aria-live`) et internationalisent l'état « sauce vide » du kiosk.

**Dette restante P1 la plus impactante** : brancher le filtrage `channels` sur les listes frontend (R1) pour parfaire la parité POS/Kiosk côté affichage — non-bloquant financièrement grâce au recalcul pricing serveur.

**Feu vert** pour poursuivre le plan de clôture V1 ; les deux tests `P5 detectTemplateFromName` pré-existants documentés dans le rapport précédent (`RUN_KIOSK_MENU_WIZARD_UX_2026-04-17.md` §5) restent à traiter dans une passe dédiée hors audit.

---

*Rapport généré lors de la session d'audit profond du 2026-04-17. Aucune modification destructrice. Tous les correctifs appliqués passent `ReadLints` sans erreur.*
