# Audit global Kiosk FoodKing — 2026-04-18

**Périmètre.** Double-check exhaustif de toutes les alimentations des phases 0 → 8, étendu à une vérification en profondeur de la synchronisation catalogue/produits entre admin, API kiosk, POS, KDS, et écrans écran de salle (OSS), ainsi qu'un audit page-par-page du wizard kiosk (8 étapes + recap) et un audit UX global des flows non-wizard (idle, cart, promo, upsell, paiement, confirmation, erreurs, admin, consent, loyalty, healthcheck, i18n/a11y).

**Méthode.** 4 explorations parallèles en mode lecture seule sur l'intégralité du repo (`/testttt/`). Aucun fichier modifié. Chaque finding porte une référence `fichier:ligne`.

**Verdict global.** Le socle est **solide et ambitieux** :

- Backend SSOT pricing strict, idempotency, state machine + audit atomique, outbox, branch isolation, channels privés avec ability-checks.
- Frontend : design system DS home-made, composables a11y, offline queue, snapshot IDB, design tokens AAA/PMR, i18n FR/EN/AR complet, RGPD 3-consents.

Mais **trois axes structurels** compromettent la promesse produit et imposent une Phase 9 corrective avant toute itération sur de nouvelles fonctionnalités :

1. **Non-wirings silencieux** : plusieurs services créés côté front (virtual keyboard, TTS speech, haptic, scan QR/NFC, `/pricing/preview` debouncer) existent mais ne sont **jamais consommés**. Résultat : promesses UX cassées (saisie Electron impossible, TTS placebo, code promo inutilisable, divergence prix).
2. **Désync catalogue** : cache `/menu` TTL 60 s **sans invalidation explicite**, mutation Vuex `UPDATE_ITEM` ignore `is_available`, item retiré mid-wizard invisible jusqu'au submit.
3. **Contrats backend partiels** : `ItemRequest`/`ItemCategoryRequest` n'acceptent pas les 12 nouveaux champs diététiques + hiérarchie + canaux → impossibles à éditer depuis l'admin UI standard.

Les chaînes critiques (création commande → POS → KDS → OSS → Kiosk waiting) **fonctionnent correctement** (tests E2E verts sur les flows principaux), mais des gaps fonctionnels secondaires (allergens non snapshotés, POS drawer minimaliste, pas de filtrage KDS par station, kiosk deferred stale) laissent une dette notable.

---

## 1. Bilan phase-par-phase (Phases 0 → 8)


| Phase                                                                                                          | Périmètre officiel                                                                                 | État réel                                                                                                                                                                                                         | Dette résiduelle                                                                                                                                                                                                                                                         |
| -------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **0** Design system + tokens                                                                                   | 7 atoms Ks* + tokens.css + AAA + PMR                                                               | ✅ Livré + Phase 8 : `KsFilterChip`, `KsAllergenBadge` ajoutés                                                                                                                                                     | **PMR selector ne cible pas `[role=radio/checkbox]`** (P2). Close/arrows 34-36 px sous floor 48 (P3).                                                                                                                                                                    |
| **1** Prérequis backend (schéma + API)                                                                         | Migrations parent_id, allergens pivot, upsell_rules, kiosk_promos, available_locales + 6 endpoints | ✅ Endpoints en place, migrations OK                                                                                                                                                                               | `**ItemCategoryHierarchyService` jamais créé** (bloque profondeur 2). `ItemRequest`/`ItemCategoryRequest` sous-validés. **Codes allergènes EN vs DATA_CONTRACT FR**. FK manquantes sur `item_branch_availability`.                                                       |
| **2** Restyle composants existants                                                                             | 13 composants restylés + `data-testid`                                                             | ✅ Atoms + surfaces existantes                                                                                                                                                                                     | `**data-testid` manquants sur tous les `KioskStep*Component`** (trou E2E).                                                                                                                                                                                               |
| **3** Écrans manquants                                                                                         | CashInstruction + 4 erreurs                                                                        | ✅ Tous livrés                                                                                                                                                                                                     | **URL analytics incohérente** : écrans d'erreur utilisent `/frontend/kiosk/event`, le reste `frontend/kiosk-event` (les deux routes existent mais payloads différents). **Pas d'auto-return** timer. `**retryPayment` émet un event mort** si composant monté deep-link. |
| **4** A11y + i18n + audio + RTL + virtual keyboard                                                             | AAA/PMR/audio toggles + KsVirtualKeyboard + useKioskSpeech                                         | ⚠️ Toggles fonctionnent + tokens OK, mais `**KsVirtualKeyboard` jamais invoqué** et `**useKioskSpeech` jamais importé** → inputs loyalty non utilisables en Electron, TTS = placebo, **EAA 2025 non-conformité**. |                                                                                                                                                                                                                                                                          |
| **5** Hardware bridge + healthcheck + idle + consent + analytics                                               | Service `kioskHardware`, consent modal, analytics whitelist                                        | ✅ Service unifié OK + stub dev + healthcheck 90 s + consent 3-cases + analytics FIFO offline                                                                                                                      | `**haptic()`, `scanQR()`, `readNFC()` jamais appelés**. **Healthcheck sans debounce** → faux positifs `critical`. **Consent modal event name mismatch** (`@accept` vs `@accepted`) → loyalty register bloqué.                                                            |
| **6** Analytics instrumentation + consent gating                                                               | Plugin Vuex + whitelist                                                                            | ✅ Plugin actif, whitelist maintenue                                                                                                                                                                               | **Analytics gatée par consent** → si client refuse, **0% tracking funnel** (wizard_step_*, add_to_cart, etc.). Révision légale nécessaire : split ops (legitimate interest) vs marketing (opt-in).                                                                       |
| **7** Structural a11y audit + broadcast isolation                                                              | Tests Vitest a11y + PHPUnit KioskEventBranchIsolation                                              | ✅ Tests verts sauf 3 pré-existants `FrontendSurfaceFilteringTest` (422 sur SQLite, likely `whereJsonContains`).                                                                                                   |                                                                                                                                                                                                                                                                          |
| **8** Diet flags + carousel + inactivity overlay + consent mobile_transfer + audio_description + reducedMotion | Migrations, services, composants, i18n                                                             | ✅ Tous livrés, 325 Vitest + 22 Loyalty verts, build prod 25 s                                                                                                                                                     | **Champs diet flags non validés par `ItemRequest`** (silent drop), **carousel promo affiche codes mais panier n'a pas de champ d'application**, `**idle_warning` event name mismatch** (`idle_warning` vs `idle_warning_shown` whitelist).                               |


---

## 2. Synchronisation catégories / produits / stock

### 2.1 Ce qui fonctionne (chaîne de propagation vérifiée)

```
Admin modifie Item
  → ItemService::update()  (app/Services/ItemService.php:189-284)
    → ItemAvailabilityChanged::fromItem($item, $type)  (:263-276)
      → PersistItemAvailabilityChangedToOutbox listener
        → DomainEvent insert + DispatchDomainEventsJob::dispatch(onQueue='high')
          → EventContract::buildEnvelope() + assertEnvelopeValid()
            → Pusher private-branch.{id} broadcast
              → Front Echo listener `eventContract.js` + KioskAppComponent:381-396
                → commit('kioskMenu/UPDATE_ITEM', payload)
```

### 2.2 Trous de désync (critiques)


| #    | Risque                                                                    | Cause                                                                                                                                                                            | Impact client                                                                                                              |
| ---- | ------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| C-1  | **Cache menu stale 60 s**                                                 | `MenuController::kiosk()` utilise `Cache::remember` sans `Cache::forget` sur `ItemAvailabilityChanged`. Le bump `MenuSnapshot` est décoratif (non consommé par contrôleur).      | Produit 86 invisible jusqu'à TTL expiration même avec Echo reçu.                                                           |
| C-2  | **Mutation `UPDATE_ITEM` ignore `is_available`** (`kioskMenu.js:159-173`) | Ne copie que `status` et `price` du payload                                                                                                                                      | Overlay "rupture" jamais mis à jour, client peut customiser un produit indisponible.                                       |
| C-3  | `**NormalItemResource` n'expose pas `is_available**`                      | Resource legacy conçue pour POS                                                                                                                                                  | Wizard ne détecte jamais un retrait mid-session → 409 au submit avec perte complète de la personnalisation.                |
| C-4  | **Codes allergènes EN vs DATA_CONTRACT FR**                               | `AllergensSeeder:22-35` utilise `crustaceans/eggs/peanuts/...` vs contrat `crustaces/oeufs/arachides/...`                                                                        | Collision allergène client ↔ item **techniquement impossible** (comparaison de codes non-alignés).                         |
| C-5  | **Double source allergens**                                               | Colonne legacy `items.allergen_flags` (JSON) + pivot `item_allergen` sans listener de synchro                                                                                    | Admin modifie JSON → pivot obsolète (ou vice-versa). `AllergenService::projectFlags` cité dans migration mais jamais créé. |
| C-6  | **Hiérarchie catégories non enforcée**                                    | `ItemCategoryHierarchyService` cité dans migration, jamais créé. `canAttachUnder()` défini sur le model mais **jamais appelé**. `ItemCategoryRequest` n'accepte pas `parent_id`. | Un admin peut créer arborescence depth ≥ 3 non supportée par `projectCategories`.                                          |
| C-7  | **Aucun endpoint admin pour `AvailabilityService::toggle`**               | Seule voie = auto-86 via stock journalier                                                                                                                                        | Impossible pour manager de forcer un 86 manuel depuis UI (tinker only).                                                    |
| C-8  | **FK manquantes sur `item_branch_availability`**                          | Migration utilise `unsignedBigInteger` sans `->constrained`                                                                                                                      | Suppression d'item/branche → rows orphelines silencieuses.                                                                 |
| C-9  | `**UpsellController` ne filtre pas `items.channels**`                     | Logique par branche mais pas par surface                                                                                                                                         | Un item `channels=['pos']` pourrait être suggéré sur kiosk.                                                                |
| C-10 | **3 tests `FrontendSurfaceFilteringTest` rouges**                         | `whereJsonContains` likely KO sur SQLite (CI)                                                                                                                                    | Régression cachée sur feature `?surface=` par contrôleur.                                                                  |


### 2.3 Divergence `DATA_CONTRACT.md` vs payload réel


| Champ attendu (contrat)                            | Payload actuel                                | Verdict                                                |
| -------------------------------------------------- | --------------------------------------------- | ------------------------------------------------------ |
| `base_price_cents` int                             | `price` float euros                           | **Unit drift**                                         |
| `items[].name` `LocaleString`                      | `string` mono-locale                          | **Bloquant multilangue réel**                          |
| `categories[].emoji`, `image_path`, `depth`        | Absents                                       | Manquants                                              |
| `categories[].items[]` nested                      | Flat `items[]` + `child_ids`                  | **Structure divergente**                               |
| `wizard_config` sur items                          | Via `wizard_template` catégorie               | **Localisation déplacée**                              |
| `promos[]` = carousel visuel                       | `promos[]` = code promo saisissable           | **Domaine diverge** (2 entités distinctes à dédoubler) |
| `upsell_rules_loaded: bool` + `/upsell?basket=...` | `upsell_rules[]` bruts exposés                | Couplage front/backend inutile                         |
| `allergens[]` `AllergenCode[]`                     | `Array<{id, code, name_key, icon, is_trace}>` | Plus riche ≠ conforme                                  |
| `branch.currency` = `'EUR'`                        | Symbole `'€'`                                 | Typage divergent                                       |


---

## 3. Centralisation commandes Kiosk → POS → KDS → OSS → Kiosk waiting

### 3.1 Chaîne fonctionnelle (vérifiée)

- **Création** : `POST /api/frontend/order` avec `X-Idempotency-Key` UUIDv4 → `FrontendOrderService::myOrderStore` → SSOT pricing (unset total/subtotal/discount puis `PricingService::calculateOrder`) → queue number atomique via `Cache::lock` → `OrderCreated::dispatch` via `DB::afterCommit()`.
- **Broadcast** : enveloppe V1 validée (`version, type, aggregate_id, aggregate_type, branch_id, correlation_id UUID, occurred_at ISO8601, payload`) via outbox (`domain_events` table) → `DispatchDomainEventsJob` sur queue `high` → Pusher sur `private-branch.{branch_id}` scope par `KioskMachine->branch_id` ou staff `branch_id`.
- **POS** : FAB "Kiosk Cash" dans `PosComponent.vue:565-571` → drawer listant `status IN [ACCEPT, PREPARING, PREPARED]`. Action encaisser → status 13 DELIVERED.
- **KDS** : `KitchenDisplaySystemComponent.vue` → colonne dédiée Kiosk (filtrage client-side `order_type === 25`) → affiche quantité, item_name, variations, extras, instructions. Actions PREPARING/PREPARED broadcast.
- **OSS** : `PreparingAndReadyComponent.vue` → écoute `OrderStatusChanged` → `_markNewReady` + son bruiteur.
- **Kiosk waiting** : `KioskWaitingComponent:201-228` → re-fetch status sur event + redirige vers `KioskConfirmationComponent` à `PREPARED`.

### 3.2 Invariants respectés

- ✅ SSOT pricing (aucun prix lu du payload).
- ✅ `branch_id` scopé serveur (jamais du payload) + défense en profondeur (FormRequest → Service → `BranchScope`).
- ✅ State machine transitions centralisées + audit atomique dans `order_status_transitions`.
- ✅ `DB::afterCommit()` pour tous dispatches.
- ✅ Idempotency lock + colonne `UNIQUE`.
- ✅ Envelope V1 validée strictement (refus si schéma cassé).
- ✅ Queue number atomique `Cache::lock`.

### 3.3 Gaps fonctionnels


| #   | Gap                                                                              | Criticité | Impact                                                                                                                                                   |
| --- | -------------------------------------------------------------------------------- | --------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| O-1 | **Kiosk deferred (card/TR) jamais cleanup**                                      | P1        | Commande PENDING +15 min après TPE timeout → consomme queue_number + stock, invisible KDS/POS. Besoin : `CleanupStalePendingKioskOrders` job cron 5 min. |
| O-2 | **Allergens non snapshotés dans OrderItem**                                      | P2        | KDS ne peut pas afficher les allergens **refusés par le client** (seul le pivot item existe).                                                            |
| O-3 | **POS drawer sans variations/extras/instructions**                               | P2        | Caissier doit ouvrir KDS pour détail → friction opérationnelle.                                                                                          |
| O-4 | **Pas de filtrage KDS par station**                                              | P2        | Cuisine 3 stations → tout s'affiche partout, bruit cognitif.                                                                                             |
| O-5 | `**order_type=POS (15)` non affiché dans KDS**                                   | P2        | Saisie POS "sur place" orpheline, trou fonctionnel.                                                                                                      |
| O-6 | `**idempotency_key` UNIQUE global (pas scopé branch)**                           | P1        | Risque faux positif cross-branch (UUIDv4 limite le risque, mais défense en profondeur requise).                                                          |
| O-7 | **Pas d'event `OrderItemUpdated`** bien que `EventType::ORDER_ITEM_ADDED` existe | P3        | Mapping obsolète dans `BROADCAST_MAP`.                                                                                                                   |
| O-8 | **Client envoie prix (ignorés)**                                                 | P3        | Pollution payload, bruit debug réseau.                                                                                                                   |


### 3.4 Tests manquants

- `test_kiosk_deferred_payment_confirm_promotes_to_ACCEPT_and_dispatches_OrderCreated`
- `test_kds_sees_kiosk_order_with_variations_extras_instructions` (E2E)
- `test_replayed_order_same_idempotency_key_returns_existing`
- `test_cross_branch_events_never_leak_on_private_channel`
- `test_dispatch_domain_events_job_retries_on_envelope_mismatch`

---

## 4. Wizard page-par-page (perspective client + caissier)

### 4.1 Scénarios de rupture (3 scénarios critiques mentalisés)


| Scénario                                                                                                            | Problème                                                                                       | Fichier:ligne                                                                                        |
| ------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| Client allergique fruits à coque clique "Tacos Royal" → customise 3 min → supplément "noisette crumble" sélectionné | **Aucune alerte allergène dans le wizard** (`KsAllergenBadge` seulement dans grille catégorie) | `KioskWizardComponent.vue` header L17-29, manquant                                                   |
| Admin retire "Menu Kebab" de la DB pendant que client est au step "viande"                                          | **Client termine wizard, 409 Conflict au submit, tout est perdu**                              | `NormalItemResource.php:35-79` n'expose pas `is_available`                                           |
| Promo active côté serveur "-2€ dès 15€"                                                                             | **Running total affiché = faux**, découverte de la vraie somme sur la page paiement            | `calculateKioskRunningTotal` (`kioskPricing.js`) + `**/api/frontend/pricing/preview` jamais appelé** |


### 4.2 Analyse step-par-step (synthèse)


| Step            | Source options                                                           | Validation                            | Prix                                                          | Facultatif                            | `data-testid` | Bugs majeurs                                                                  |
| --------------- | ------------------------------------------------------------------------ | ------------------------------------- | ------------------------------------------------------------- | ------------------------------------- | ------------- | ----------------------------------------------------------------------------- |
| **Pain**        | Attribut DB matché par substring `'pain'/'galette'`                      | `selections.pain !== null`            | Stable                                                        | Non (step affiché si attribut existe) | **Absent**    | Détection fragile EN/AR                                                       |
| **Taille**      | Attribut DB OU **fallback S/M/L/XL fabriqué** `viandeCount: 1/2/3/4`     | `taille !== null`                     | Selon variation                                               | Non                                   | **Absent**    | **Fallback inventé** casse produits acceptant max 2 viandes                   |
| **Viande**      | `kioskViandeCatalogForItem` (variations + extras payants)                | `totalViandes >= detectViandeCount()` | Variations gratuites, extras `+N.NN€` via `_viandeMeta.price` | Non                                   | **Absent**    | Boutons `+/-` 44 px < floor 48/64 PMR                                         |
| **Sauce**       | `kioskSauceVariationRowsForItem` (DB only)                               | `sauceOrder.length > 0` OR skip       | 1re gratuite, extras = **first-priced**                       | Skip dispo                            | **Absent**    | **Under/overcharge** si prix sauce hétérogènes en DB                          |
| **Garnitures**  | `partitionKioskExtras(item).garnitures`                                  | `return true`                         | Gratuit                                                       | Oui                                   | **Absent**    | **Toutes pré-cochées**, pas de "tout désélectionner"                          |
| **Supplements** | `partitionKioskExtras(item).supplements`                                 | `return true`                         | `extra.price` DB                                              | Oui                                   | **Absent**    | i18n clés à plat (`supplements_step_title`) vs `step.*.title`                 |
| **Menu**        | Cartes statiques + `kioskDrinkAddons` + `kioskSauceVariationRowsForItem` | Selon choice + drinks + frites_sauce  | `getKioskMenuAddonPrice`                                      | Option "none"                         | **Absent**    | `default_menu_kiosk=true` **pré-sélectionne `full`** sans confirmation        |
| **Recap**       | `KioskOrderSummaryComponent`                                             | —                                     | `runningTotal`                                                | —                                     | ✅ **Présent** | `wizard_abandoned` jamais tracké si abandon sur recap (step le plus critique) |


### 4.3 Règles de conduite cassées (résumé)

- **Détection attributs par substring FR** (`.includes('pain')`, `'viande'`, `'sauce'`, `'taille'/'size'`) casse en EN/AR dès que l'admin renomme.
- **Catalogues hardcoded** (`kioskSauceCatalog`, `kioskViandeCatalog`, `kioskDrinkAddons`, `kioskExtrasPartition`, `kioskMenuBundledExtras`) basent tout sur le **nom** de l'item/extra/variation → admin-dépendant.
- `**shouldAskTacosTaille`** substring match `.includes('tacos l')` / `.includes('xl')` → faux positifs "Tacos L'Royal", "XLent".
- **Pas de snapshot allergens** dans `OrderItem` → impossible de différencier "sauce samouraï sans arachides" vs "avec traces".

---

## 5. UX global non-wizard

### 5.1 Non-wirings silencieux (services présents, 0 consumer)


| Service                                   | Fichier                                                                                | Conséquence                                                                           |
| ----------------------------------------- | -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `KsVirtualKeyboard`                       | `resources/js/components/frontend/kiosk/ds/KsVirtualKeyboard.vue`                      | **Saisie loyalty impossible** sur borne Electron sans clavier hardware externe.       |
| `useKioskSpeech`                          | `resources/js/composables/useKioskSpeech.js`                                           | Toggle "Audio" drawer A11y = **placebo**, EAA 2025 non-conformité.                    |
| `kioskHardware.haptic()`                  | `resources/js/services/kioskHardware.js:204`                                           | Pas de retour tactile sur tap (différenciation premium manquante).                    |
| `kioskHardware.scanQR()`, `readNFC()`     | `kioskHardware.js:211-221`                                                             | Scan loyalty one-tap jamais implémenté.                                               |
| `/api/frontend/pricing/preview`           | `app/Http/Controllers/Frontend/PricingPreviewController.php` + `PricingPreviewService` | Wizard **ne prévoit jamais** le total serveur → divergence avec promo/coupon/loyalty. |
| Route `POST /api/frontend/promo/validate` | `app/Http/Controllers/Frontend/PromoController.php::check()`                           | Panier **n'a aucun champ code promo** → carousel de promos inutilisable.              |
| Snapshot cart localStorage                | —                                                                                      | **Receipt perdu sur F5** (reset immédiat au mount confirmation).                      |


### 5.2 Event name mismatches (silent breaks)


| #   | Côté émetteur                                                                                              | Côté récepteur/whitelist                                                               | Effet                                                                                                  |
| --- | ---------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| M-1 | `KioskInactivityOverlayComponent:130` → `track('idle_warning')`                                            | Whitelist `kioskAnalytics.js:65` = `'idle_warning_shown'`                              | **Event dropped** silencieusement → tracking idle invisible.                                           |
| M-2 | `KsConsentModal` émet `@accepted`                                                                          | `KioskLoyaltyComponent:228` écoute `@accept`                                           | **Loyalty register jamais finalisé** si consent pas déjà en store.                                     |
| M-3 | Écrans d'erreur + `KioskCashInstruction` → `axios.post('/frontend/kiosk/event', {type, subtype, context})` | Reste : `axios.post('frontend/kiosk-event', {type: 'analytics', event_name, payload})` | Deux schemas cohabitent (les 2 routes existent, backend tolérant) → shape drift et tracking fragmenté. |
| M-4 | `EventType::ORDER_ITEM_ADDED` dans `BROADCAST_MAP`                                                         | Aucune classe Event PHP ne le dispatche                                                | Mapping orphelin.                                                                                      |


### 5.3 Dead UI (client perdu)

- `KioskCategoriesComponent:24-43` : chips "My Account" / "Allergens" **affichés sans handler** → tap = rien, frustration.
- `KioskErrorPaymentRefusedComponent::retryPayment:78-83` : `$emit('retry')` **sans parent** si monté via deep-link → bouton mort.

### 5.4 UX frustrations critiques (top 10)

1. **Code promo affiché sans UI d'application** (promesse mensongère).
2. **Virtual keyboard + TTS non wirés** (saisie + audio cassés).
3. `**idle_warning` dropped** (tracking idle invisible).
4. **Erreur paiement → toast + reste sur payment** au lieu d'écran dédié.
5. **Receipt perdu sur F5** (reset immédiat confirmation).
6. **Badge hardware critique sans debounce** (faux positifs).
7. **Loyalty register bloqué par event mismatch**.
8. **Chips Account/Allergens dead UI**.
9. **Navigation double-clic idle → 2 orders** (touchstart + click synthétisé).
10. **Filtres catégorie perdus entre navigations** (`activeFilters` local).

### 5.5 Forces à préserver

- Offline queue (`kioskOfflineQueue.js`) avec replay + X-Idempotency-Key persisté.
- Snapshot IDB menu 5 min.
- State machine + audit atomique.
- Enveloppe V1 validée strictement.
- Tokens AAA/PMR + sélecteur data-* sur `<html>`.
- Consent RGPD 3-cases non pré-cochées + refus explicite + backdrop = decline.

---

## 6. Priorité consolidée (50 findings triés)

### P0 — Bloquants (safety, RGPD, funnel, UX trompeuse)


| #     | Finding                                                          | Référence                                                         | Criticité                             |
| ----- | ---------------------------------------------------------------- | ----------------------------------------------------------------- | ------------------------------------- |
| P0-1  | **Allergens absents du wizard** (`KsAllergenBadge` catalog only) | `KioskWizardComponent.vue:17-29`                                  | Safety / conformité INCO              |
| P0-2  | `**NormalItemResource` n'expose pas `is_available`**             | `app/Http/Resources/NormalItemResource.php:35-79`                 | UX catastrophique (3 min perso → 409) |
| P0-3  | `**/api/frontend/pricing/preview` jamais appelé**                | `kioskPricing.js` + `KioskWizardComponent.vue`                    | Divergence total client ↔ serveur     |
| P0-4  | **Cache menu stale 60 s sans invalidation**                      | `MenuController::kiosk()` + `EventServiceProvider.php:101-104`    | Désync runtime                        |
| P0-5  | **Mutation `UPDATE_ITEM` ignore `is_available`**                 | `resources/js/store/modules/kioskMenu.js:159-173`                 | Désync Echo reçu mais overlay stale   |
| P0-6  | **Code promo affiché sans UI application**                       | `KioskPromoCarouselComponent.vue` + `KioskCartComponent.vue`      | UX trompeuse                          |
| P0-7  | `**KsVirtualKeyboard` non wiré**                                 | `resources/js/components/frontend/kiosk/ds/KsVirtualKeyboard.vue` | Saisie Electron impossible            |
| P0-8  | `**useKioskSpeech` non wiré**                                    | `resources/js/composables/useKioskSpeech.js`                      | TTS placebo, EAA 2025 KO              |
| P0-9  | **Event `idle_warning` dropped (whitelist mismatch)**            | `KioskInactivityOverlayComponent:130` vs `kioskAnalytics.js:65`   | Tracking idle invisible               |
| P0-10 | **Event `@accept` vs `@accepted` → loyalty bloqué**              | `KioskLoyaltyComponent.vue:228` vs `KsConsentModal.vue:297`       | Register silencieusement KO           |
| P0-11 | **Erreur paiement reste sur écran payment**                      | `KioskPaymentComponent.vue:348-354`                               | UX bloquée sur toast                  |
| P0-12 | **Receipt perdu sur F5**                                         | `KioskConfirmationComponent.vue:236`                              | UX catastrophique                     |
| P0-13 | **Chips Account/Allergens dead UI**                              | `KioskCategoriesComponent.vue:24-43`                              | Client perdu                          |
| P0-14 | **3 tests `FrontendSurfaceFilteringTest` rouges**                | `tests/Feature/Menu/FrontendSurfaceFilteringTest.php`             | CI non green                          |


### P1 — Hauts (fragilité, tracking partiel, compétitivité)


| #     | Finding                                                         | Référence                                       |
| ----- | --------------------------------------------------------------- | ----------------------------------------------- |
| P1-1  | Détection attributs par substring FR (pain/viande/sauce/taille) | `kioskSauceCatalog.js`, `kioskViandeCatalog.js` |
| P1-2  | Fallback S/M/L/XL fabriqué sans DB taille                       | `KioskStepTailleComponent.vue` fallback         |
| P1-3  | Prix sauce extra = first-priced                                 | `KioskWizardComponent.vue:578-586`              |
| P1-4  | `data-testid` manquants sur tous les `KioskStep`*               | 7 composants                                    |
| P1-5  | Analytics gatées par consent → funnel 0% si refus               | `kioskAnalytics.js:222-239`                     |
| P1-6  | `ItemRequest`/`ItemCategoryRequest` sous-validés                | `app/Http/Requests/ItemRequest.php:28-49`       |
| P1-7  | Codes allergènes EN vs DATA_CONTRACT FR                         | `AllergensSeeder:22-35`                         |
| P1-8  | Hiérarchie catégories non enforcée                              | `ItemCategoryHierarchyService` jamais créé      |
| P1-9  | Double source allergens (JSON + pivot)                          | `AllergenService::projectFlags` absent          |
| P1-10 | Pas de route admin `AvailabilityController::toggle`             | Admin impossible force-86                       |
| P1-11 | FK manquantes sur `item_branch_availability`                    | Migration 2026_04_15_230100                     |
| P1-12 | Pas de rate limit sur `GET /menu`                               | `routes/api.php:929-932`                        |
| P1-13 | Kiosk deferred stale PENDING jamais cleanup                     | `FrontendOrderService` flow card                |
| P1-14 | `idempotency_key` UNIQUE global non scopé branch                | Migration 2026_03_25_002938                     |
| P1-15 | Navigation double-clic idle → 2 orders                          | `KioskIdleScreenComponent.vue:4-11`             |
| P1-16 | Filtres catégorie perdus entre navigations                      | `KioskCategoriesComponent.vue:664-687`          |
| P1-17 | Impression bloque countdown auto-return                         | `KioskConfirmationComponent.vue:306-308`        |
| P1-18 | Healthcheck sans debounce (faux critical)                       | `kioskHardware.js:287-304`                      |
| P1-19 | Admin `beforeUnmount` dupliqué                                  | `KioskAdminComponent.vue:400-408 + 761-766`     |
| P1-20 | Pas de `cart_viewed` event                                      | `kioskAnalytics.js:37-70`                       |
| P1-21 | URL analytics `kiosk/event` vs `kiosk-event` dualistes          | écrans d'erreur + cash-instruction              |
| P1-22 | `haptic()`, `scanQR()`, `readNFC()` jamais appelés              | `kioskHardware.js:204-221`                      |
| P1-23 | `MenuSnapshot::bump` décoratif (non consommé par client)        | `BumpMenuSnapshotOnItemAvailabilityChanged`     |


### P2 — Moyens


| #     | Finding                                                  | Référence                                   |
| ----- | -------------------------------------------------------- | ------------------------------------------- |
| P2-1  | PMR selector n'inclut pas `[role=radio/checkbox]`        | `tokens-pmr.css:73-80`                      |
| P2-2  | `wizard_abandoned` pas tracké sur recap                  | `KioskWizardComponent.vue:1060`             |
| P2-3  | `default_menu_kiosk` pré-select `full` sans confirm      | `KioskStepMenuComponent.vue`                |
| P2-4  | `shouldAskTacosTaille` substring fragile                 | `KioskWizardComponent.vue:466-498`          |
| P2-5  | Garnitures toutes pré-cochées sans "tout désélectionner" | `KioskStepGarnituresComponent.vue`          |
| P2-6  | Allergens non snapshotés `OrderItem`                     | `app/Models/OrderItem.php` + migration      |
| P2-7  | POS drawer sans variations/extras/instructions           | `PosComponent.vue:599-605`                  |
| P2-8  | Pas de filtrage KDS par station                          | `KitchenDisplaySystemComponent.vue`         |
| P2-9  | `order_type=POS (15)` non affiché dans KDS               | `KitchenDisplaySystemComponent.vue:491-494` |
| P2-10 | Vidéo idle ne respecte pas `reducedMotion`               | `KioskIdleScreenComponent.vue:60`           |
| P2-11 | Changement langue = `window.location.reload()`           | `KioskIdleScreenComponent.vue:189`          |
| P2-12 | Pas de filtre recherche catalog full-text                | Absent                                      |
| P2-13 | Auto-skip upsell sans pause sur scroll                   | `KioskUpsellComponent.vue:92-109`           |
| P2-14 | Pas de QR code sur receipt                               | `KioskConfirmationComponent.vue`            |


### P3 — Faibles (cosmétique)


| #    | Finding                                                         |
| ---- | --------------------------------------------------------------- |
| P3-1 | i18n `wizard.step.supplements.*` à plat vs pattern autres steps |
| P3-2 | Boutons close 34 px / arrow 36 px < floor 48 WCAG 2.2 AA        |
| P3-3 | RTL chevrons ne flippent pas                                    |
| P3-4 | Client envoie prix ignorés (pollution payload)                  |
| P3-5 | Icônes emojis sans fallback SVG                                 |
| P3-6 | `payment_method_selected` émis 3x si client hésite              |
| P3-7 | Marquee promo-carousel ne s'inverse pas en RTL                  |
| P3-8 | `isElectron` stub false-positive en dev                         |


---

## 7. Conclusion & recommandation

Le projet Kiosk FoodKing V1 est **largement prêt** sur ses rails principaux (POS/KDS/OSS/Kiosk waiting fonctionnels, SSOT respecté, state machine auditée, events validés), mais **8 non-wirings silencieux** et **4 désyncs catalogue** compromettent la promesse UX et la conformité légale.

**Recommandation.** Phase 9 orchestrée en **10 vagues P9.1 → P9.10** priorisant safety/RGPD/tracking en P0, puis robustness backend + frontend, puis différenciation compétitive.

Plan détaillé : voir `reports/execution/PLAN_PHASE_9_KIOSK_2026-04-18.md`.