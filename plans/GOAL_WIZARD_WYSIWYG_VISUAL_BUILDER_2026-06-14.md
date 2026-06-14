# GOAL — Wizard WYSIWYG Visual Builder (« Shopify de la borne »)
**Date:** 2026-06-14 · **Branch:** `goal/wizard-wysiwyg-builder-2026-06-14` (base HEAD `adfe0531e`)
**Lane:** BORNE + CAISSE (composition/personnalisation) — *respecte la session CENTRAL (sync/stock) active dans `integration-v1-2026-06-12`*
**Mode:** raisonnement + planification approfondis → exécution en boucle test-e2e jusqu'à 100%
**Méthode:** copie (le builder actuel + les wizards frozen restent intacts ; le nouveau builder vit à côté)

---

## 0. VISION (owner, verbatim → reformulée)

> « Comme Shopify : ultra simple, on compose visuellement. Un **vrai wizard qu'on voit devant nous (la borne)**, on le configure, on **ajoute les images**, pour chaque produit on dit « page suivante on fait quoi ». **Voir + modifier** directement, pas juste configurer techniquement. Écran de personnalisation **vertical** (réaliste vs la borne). Règles bien pensées : combien de choix, plusieurs ou pas, facturé si on prend plus / gratuit / 1er gratuit puis payant / chacun son prix. Si ça risque de casser l'existant → **le faire dans une copie**. »

**Traduction produit :** transformer le rendu **live de la borne** (cf. `kiosk-02-wizard-step1.png` : barre orange, icônes-images d'étapes, anneau actif, barre de progression, panneau « VOTRE COMPOSITION », cartes produit avec images + « + », « 1re sauce gratuite », footer Total) en une **surface éditable en place** : l'opérateur édite **exactement ce que le client verra**.

---

## 1. ÉTAT ACTUEL — décomposition (ancrée code, 3 sous-agents + captures)

### 1.1 Modèle de données (existant, non-frozen)
- `item_wizard_profiles` (`item_id` XOR `item_category_id`, `template`, `version`, `is_published`, `published_at`, `branch_id_scope`) — migration `2026_04_27_143100`.
- `item_wizard_steps` : `step_key`, `label`, `source_type` ∈ {item_attribute, extra_group, addon, fixed(legacy)}, `source_ref`, `source_item_attribute_id`, **`min_select`**, **`max_select`**, **`allow_repeat`**, `visible_on` (JSON kiosk/pos/web), `stockable_choices`, `position`, `is_active`, `addon_role` — migration `2026_04_27_143110`.
- `item_wizard_step_versions` : snapshot JSON **immuable** (insert-only, NF525-style) — migration `2026_05_04_000010`.
- **AUCUN champ prix sur un step** (✓ NF525 SSOT). Le prix vit sur les **constructs catalogue** : `ItemVariation.price`, `ItemExtra.price`, `ItemAddon → addonItem.price`.
- **Image/description par option : N'EXISTENT PAS en DB.** `thumb` = accessor name-match sur `config/menu_images.php` (read-only). Seul `Item` a un vrai média (Spatie). ⇒ pour le vrai WYSIWYG « ajoute l'image », **colonnes additives requises** (`image_path`/`description` sur `item_variations` + `item_extras`, ou table média dédiée).

### 1.2 Moteur de règles (existant)
- **Sélection** = triplet `(min_select, max_select, allow_repeat)` :
  - single requis = `(1,1,false)` · single optionnel = `(0,1,false)` · multi 0–N = `(0,N,false)` · multi requis = `(1,N,false)` · répétable = `allow_repeat=true`.
  - Validé à la **publication** (`ComposerProfileService::assertPublishable`) **ET** à la **commande** (`PricingService::assertComposerStepConstraints`, **FROZEN**).
- **Facturation (CRUX) :** la **seule** règle *data-driven* aujourd'hui = **« chacun son prix catalogue »**. Le « 1re sauce gratuite puis +0,90€ » visible sur la borne est **codé en dur dans le composant kiosk FROZEN** (display) ; la charge réelle est calculée par `PricingService` (SSOT, **FROZEN**). ⇒ rendre les modes (gratuit / N-gratuits-puis-payant / quota / bundle) **configurables par groupe** = **modifier PricingService = GATE OWNER (NF525 §7/§10)**.

### 1.3 Builder admin actuel (non-frozen, à conserver = « la copie » préserve celui-ci)
- `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue` (45KB) : 3 colonnes — sidebar liste draggable / formulaire central / **aperçu TEXTE** (`ItemPreviewComponent`, pas un rendu visuel borne).
- Routes : `/admin/items/:id/composer` (gated `FEATURE_WIZARD_PER_ITEM_DEMO`), `/admin/categories/:id/composer` (non-gated).
- Backend (tout non-frozen) : `ComposerProfileController`, `ComposerStepController`, `ComposerProfileService`, `ComposerTemplateService` (bug connu : `source_ref => ''` hardcodé), `ComposerDiffService`, `ComposerProfileProjection` (résout step→choix concrets via `source_type/source_ref`).
- **Gaps vs Shopify :** pas de rendu visuel borne, pas d'image par option, pas d'édition inline des options, pas de drag dans l'éditeur principal, pas de preview verticale.

### 1.4 Wizards live (FROZEN — la preview doit les RÉPLIQUER, jamais les modifier)
- **Kiosk** `KioskWizardComponent.vue` (composer-first via `item.composer_profile.steps`), step renderers `KioskStep*` (pain/taille/viande/sauce/garnitures/supplements/menu/frites_style/**generic_choices**/recap), layout **portrait**, CSS tokens `--kiosk-primary #F4501E`, `--kiosk-touch-min 64px`, grilles `auto-fit minmax(180px)`, steppers quantité, badges prix.
- **POS** `public/js/pos-wizard.js` (Vanilla, composer-aware **gated `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` défaut FALSE** → gate owner pour l'activer ; sinon legacy heuristique).
- Contrat de données consommé : `composer_profile.steps[].choices[]` `{id,name,label,source_type,item_attribute_id,addon_item_id,status,is_available,price,thumb}`.

### 1.5 Preuves visuelles capturées (read-only, clone `foodking_e2e` :8766)
- `kiosk-01-idle.png` — accueil portrait Cayenne.
- `kiosk-02-wizard-step1.png` — wizard live item 41 : **la cible exacte du WYSIWYG**.

---

## 2. DÉCISIONS D'ARCHITECTURE

### D-1 — Copie, pas remplacement (owner mandate)
Nouveau builder = **nouveaux fichiers non-frozen** montés sur une **nouvelle route** (`/admin/items/:id/wizard-studio` + `/admin/categories/:id/wizard-studio`), **réutilisant les mêmes endpoints API + le même modèle de données** que le builder actuel. Donc : les wizards frozen kiosk/POS **rendent le résultat à l'identique** (même `composer_profile`), et l'ancien builder reste 100% fonctionnel. Aucune régression possible sur l'existant.

### D-2 — Le WYSIWYG = VRAI rendu kiosk live (iframe preview) + panneau de réglages [RÉVISÉ post-audit]
**Pattern Shopify (theme editor) :** preview live à gauche (écran **vertical** portrait borne) + panneau de réglages à droite. Les édits → save draft → la preview se re-rend instantanément.

**La preview N'EST PAS une réplique** (l'audit a prouvé que ré-implémenter le rendu kiosk dérive → l'opérateur voit un mensonge). La preview = **le VRAI composant kiosk frozen rendu dans une iframe**, alimenté par un **endpoint backend non-frozen `GET /admin/composer/{item|category}/{id}/preview-projection`** qui passe le **brouillon** par **EXACTEMENT la même projection** (`ComposerProfileProjection`) que le kiosk publié. ⇒ rendu **fidèle par construction**, **zéro modification du frozen**, **zéro dérive** (pas besoin de test de parité fragile). L'iframe charge une route kiosk en mode `preview=<token>` qui lit cette projection au lieu de la projection publiée.

> Édition = dans le chrome du Studio (pas dans l'iframe). « Page suivante » = ajout/réordonnancement d'un step → re-render iframe immédiat. Aucune édition DOM dans l'iframe (isolation = fidélité).

### D-3 — Moteur de facturation : split non-frozen / gate
| Mode | Sémantique | Faisable sans toucher frozen ? |
|---|---|---|
| **Gratuit** | toutes options à 0 | ✅ OUI (prix catalogue option = 0) |
| **Chacun son prix** | chaque option à son prix catalogue | ✅ OUI (natif) |
| **1er(s) gratuit(s) puis payant** | N inclus puis supplément | ❌ NON data-driven → **GATE PricingService** (aujourd'hui hardcodé sauce) |
| **Quota inclus puis supplément** | généralisation du précédent | ❌ **GATE PricingService** |
| **Bundle / formule prix fixe** | ratio menu (full/0.6/0.4) ou prix fixe | ❌ **GATE PricingService** (ratios hardcodés/config) |

**[RÉVISÉ post-audit — preuve DB item 41]** Le vrai menu Le Cayenne n'utilise PAS de surcharge runtime « 1er gratuit ». Il modélise :
- **« N gratuits plafonnés »** = options en **ItemVariation à 0,00€** + `max_select` (ex. sauce bol : 11 variations à 0€, max=2). → 100% data-driven.
- **« Chacun son prix »** = options en **ItemExtra prix>0** (ex. supplément bol 0,90€/2,00€). → natif.
- **« 1er gratuit puis payant »** = exprimé par le **pattern 2-steps** (un step « inclus » plafonné + un step « payant » séparé) — déjà la structure réelle. → sans frozen.

`PricingService` est **transport-agnostic** (somme `price × qty` des constructs ; confirmé en source) ⇒ **AUCUN gate facturation requis en V1**. Le « +0,90€ / 1re gratuite » affiché par le kiosk est une **heuristique frozen d'affichage**, pas une vraie charge ici. ❌ **G-PRICE RETIRÉ du chemin critique.** Une surcharge runtime *configurable* (mode avancé futur) toucherait le **marshalling frontend frozen** — hors V1, présenté seulement si owner le demande.

### D-4 — Images/descriptions par option
Colonnes additives non-frozen `image_path` (+ `description` optionnel) sur `item_variations` et `item_extras`, exposées dans la projection `composer_profile.choices[].thumb/description`. Stockage : `storage/app/public` + symlink (choix G-MEDIA). **Aucune** rupture du contrat frozen (le kiosk lit déjà `choices[].thumb`).

### D-5 — POS (caisse)
La caisse rend via `pos-wizard.js` (frozen) **composer-aware gated** (défaut OFF). Le builder produit le même `composer_profile`. **Activer le composer-aware POS = GATE `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED`** (owner). Sans gate : le builder est pleinement utile côté **borne** (composer-first, non-gated) ; côté caisse il reste en legacy jusqu'au flip owner. La preview studio offre un onglet « Aperçu Caisse » (réplique POS) pour montrer le futur rendu.

---

## 3. FRONTIÈRE FROZEN / NF525 (règle dure §7/§10)
**JAMAIS modifiés sans LOCK+gate :** `PricingService.php`, `pos-wizard.js`, `pos-wizard.css`, `admin-pos-v4.blade.php`, `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue`, services Fiscal, `OrderStateMachine`, `BranchScope`.
**Gates owner identifiés [RÉVISÉ post-audit] :**
- ~~G-PRICE~~ **RETIRÉ** — facturation V1 = 100% non-frozen (preuve DB : free-capped + each-priced). Surcharge runtime configurable = futur hors-V1.
- **G-POS-COMPOSER** : flip `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true` (`config/catalog_v15.php:104` défaut FALSE — confirmé) pour que la caisse rende le composer. Borne non-gatée.
- **G-MEDIA** : choix stockage images options (défaut proposé : `storage/app/public` + symlink).
- **G-PUSH** : push de la branche.

Tout le reste (nouveau builder, preview iframe, **endpoint preview-projection**, projection, colonnes média, endpoints) = **non-frozen, autonome**. ⚠️ La route kiosk `preview` doit rester **read-only** (aucune création d'ordre) et token-gardée admin.

---

## 4. VAGUES D'EXÉCUTION (chaque vague : TDD → test technique → capture visuelle analysée → checkpoint commit)

- **W0 — Socle** ✅ : route `wizard-studio` + composant coquille (fait, `746da44c0` ; goBack corrigé). [Le « test de parité » est ABANDONNÉ — remplacé par iframe-truth, cf. D-2.]
- **W1 — Preview iframe VRAI rendu kiosk (D-2)** : endpoint `preview-projection` (draft→même projection) + route kiosk `preview` read-only + iframe portrait dans le Studio. Validé : iframe == `kiosk-02-wizard-step1.png` rendu sur le brouillon. **+ Afficher l'héritage** (« hérité de la catégorie X » vs « propre à l'item ») — piège précédence `resolveForItem` CATEGORY-wins.
- **W2 — Édition de structure** : ajout/suppression/réordonnancement de steps (drag), renommage, page suivante → re-render iframe immédiat. Persiste via endpoints existants. **Gérer 409** (lock version) : UX reload/merge explicite, pas de perte silencieuse.
- **W3 — Règles de sélection claires** : UI explicite single/multi/requis/répétable (min/max/allow_repeat) avec libellés humains + aperçu de l'effet sur la carte.
- **W4 — Édition d'options inline + images (D-4)** : ajouter/retirer une option, **uploader image + description**, voir le rendu immédiat. Migration additive + projection.
- **W5 — Moteur de facturation non-frozen (D-3)** : modes **Gratuit** + **Chacun-son-prix** pleinement configurables (édition prix construct), affichage badges « +X € » / « inclus » comme la borne. Modes gatés présents mais marqués.
- **W6 — Templates turnkey** : corriger `source_ref=''` (auto-fill au apply), aperçu visuel des templates, mapping des ~vrais wizards Le Cayenne.
- **W7 — Onglet Aperçu Caisse** : réplique POS (montre le futur composer-aware sans flip).
- **W8 — Validation e2e bout-en-bout** : builder → publish → rendu kiosk live identique (boucle test→correct→capture jusqu'au vert), parité prix backend, branch-isolation, sentinelles.
- **GATES** : G-PRICE / G-POS-COMPOSER / G-MEDIA / G-PUSH présentés au owner.

**W1–W7 ne nécessitent AUCUN gate frozen.** Seuls les modes facturation avancés + flip POS + push sont gatés.

---

## 5. STRATÉGIE DE TEST (evidence triple, CLAUDE.md §13)
- **Technique** : PHPUnit filter (Composer*/Pricing*/Menu projection) + Vitest filter (wizard-studio specs) + **parité preview/frozen**.
- **DB-SAFE** : mutations uniquement sur clone disposable dédié (jamais `foodking` op ni les DB des autres sessions `foodking_2dot0`/integration). Pattern `.env.e2e`/clone.
- **Visuel (mandate §6)** : captures Playwright analysées via Read à chaque vague (studio + rendu kiosk live publié).
- **Frozen diff** : `git diff --stat` sur les fichiers §7 = **0 ligne**.
- **NF525** : prix calculés backend identiques builder↔commande ; snapshot intact.
- **Boucle** : test fail/visual fail → self-correct (max 3) → escalate.

## 6. RISQUES
- **Disque 100%** (4,2 Gi) — vendor/node_modules symlinkés (0 coût), pas d'autre worktree, surveiller ; éviter rebuilds massifs qui gonflent `public/js`.
- **Couplage frozen** — la preview réplique, ne dépend pas → sentinelle de parité contre la dérive.
- **PricingService gate** — ne JAMAIS auto-flip ni patcher (drift §12) ; livrer le non-frozen, présenter le gate.
- **Collision multi-session** — lane disjointe (composer/builder), zéro edit zone §6 sauf append registre (déclaré).

## 7. DÉFINITION DE « FAIT » (anti faux-vert)
Convergence déclarée seulement si : specs existent + vertes (counts collés), frozen diff 0, rendu kiosk publié == preview (capture analysée), parité prix backend prouvée, et gates owner explicitement listés (non auto-signés).
</content>
</invoke>
