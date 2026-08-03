> **2026-05-05 — Mega plan V1 finition POS / borne / KDS / admin** : document courant [`MEGA_PLAN_AUDIT_CURSOR_CODEX_V1_FINITION_2026-05-05.md`](./MEGA_PLAN_AUDIT_CURSOR_CODEX_V1_FINITION_2026-05-05.md) (consolidation Cursor + Codex, ecarts captures, vagues de correction page par page, matrice de validation).

> **2026-03-31 — Audit profond global + plan massif + diagrammes** : document canonique [`AUDIT_PROFOND_PLAN_MASSIF_2026-03-31.md`](./AUDIT_PROFOND_PLAN_MASSIF_2026-03-31.md) (architecture, synchro, inventaire tests, backlog Phases A–E, Mermaid). Le reste de ce fichier conserve l’historique handoff Kimi du 2026-03-27.

---

# Plan de Handoff Claude — Bugs Complexes Restants

**Date** : 2026-03-27  
**Auteur** : Kimi  
**Type de test** : local-validation (PHPUnit ciblé login borne)  
**Priorité** : HAUTE

---

## 0. Identifiants / auth — corrigé par Kimi + pièges pour Claude

### 0.1 Ce qui bloquait en pratique (captures utilisateur)

| Symptôme | Cause probable | Correctif Kimi |
|----------|----------------|----------------|
| Connexion borne avec `chef@example.com` dans « Identifiant machine » | Confusion : le champ attend le **`username` de `kiosk_machines`**, pas un compte staff | UI : texte d’aide + placeholder `kiosk-lecayenne` + refus immédiat si `@` (front + API 422) |
| Admin / démo : boutons rapides remplissaient `@demo.foodking.app` | Defaults `config('app.demo_credentials')` ne correspondaient pas au `UserTableSeeder` Le Cayenne | Defaults alignés `admin@lecayenne.fr` / `123456`, etc. + fallbacks JS `LoginComponent.vue` |
| « Invalid Api Key » sur toute l’SPA | `.env` avec seulement `API_KEY` alors que Laravel lit **`MIX_API_KEY`** (`config/app.php`) | `MIX_API_KEY` documenté dans `.env.example` + repli `API_KEY` si `MIX_API_KEY` vide |
| En-tête catalogue « NOS NOS BURGERS » | Préfixe UI `NOS` + libellé catégorie déjà « Nos … », ou double « Nos » en base | `stripLeadingNos` en **boucle** + même logique sur **sidebar** (`displayCategoryName`) |

### 0.2 Référence seed local (hors `APP_ENV=production`)

- **Admin** : `admin@lecayenne.fr` / `123456`
- **POS** : `pos@lecayenne.fr` / `123456`
- **Borne** : utilisateur machine `kiosk-lecayenne` / `kiosk123` (`KioskMachineTableSeeder`, branche 1)
- **Important** : `chef@example.com` n’existe que si `DEMO=true` au moment du seed utilisateurs ; ce n’est **jamais** un identifiant borne.

### 0.3 Check-list bugs / risques résiduels (à traiter par Claude si besoin)

- [ ] **P0 prod** : refuser démarrage ou log clair si `config('app.api_key')` est vide en `production` (aujourd’hui `'' === ''` peut être ambigu selon clients HTTP).
- [ ] **P1** : document d’onboarding unique (`README` + `docs/AUDIT_LOGIN_ACCOUNTS.md`) synchronisé après chaque changement de seeder.
- [ ] **P1** : flux « première borne » sans seed (création machine uniquement admin) — message d’erreur métier si aucune ligne `kiosk_machines` pour la branche.
- [ ] **P2** : internationaliser `kiosk_username_not_email` pour `ar`, `bn`, `de` (seuls `en` / `fr` ajoutés).

---

## 1. Compréhension architecture actuelle

- FoodKing repose sur Laravel côté backend et Vue 3 côté surfaces client/admin.
- La borne, la caisse, le KDS et l’écran client partagent le même noyau métier commande/prix/statuts.
- Le temps réel principal passe par `OrderCreated` / `OrderStatusChanged` sur `private-branch.{branch_id}`.
- Le push secondaire passe par FCM avec des topics par surface (`kitchen_branch_X`, `pos_branch_X`, etc.).
- Le tunnel borne est maintenant beaucoup plus proche de GUR visuellement, mais les invariants critiques restent backend/synchro.
- La numérotation de file, les états de paiement et l’apparition KDS doivent rester parfaitement cohérents entre POS et borne.
- Le projet n’a toujours pas de vrai modèle “rupture / stock bloquant” exploité de bout en bout.
- Les prochains sujets demandent une décision d’architecture, pas juste un patch local.

---

## 2. Ce que Kimi a corrigé facilement

- Nettoyage du header catalogue pour éviter `NOS NOS ...` quand le nom de catégorie contient déjà `Nos`.
- Badge produit changé de `MENU` vers `PERSONNALISER` pour mieux refléter l’ouverture du wizard.
- Suppression du faux hint `Panier` dans le wizard, qui créait une pollution visuelle inutile.
- Suppression du doublon de titre dans `KioskOrderSummaryComponent.vue`.
- Suppression du double loader sur l’écran paiement pour garder un seul état d’attente clair.

Ces changements sont faibles en risque et déjà compilés.

---

## 3. Bugs complexes à reprendre par Claude

### P0 — Cycle de vie commande vs paiement CB/TR

**Constat**
- `FrontendOrderService` auto-accepte les commandes borne avant confirmation TPE.
- `KioskPaymentComponent.vue` tente ensuite de “void” côté client si le paiement est refusé ou annulé.

**Références**
- `app/Services/FrontendOrderService.php`
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`

**Pourquoi c’est complexe**
- Le KDS peut voir trop tôt une commande qui n’est pas encore réellement payée.
- La cohérence dépend aujourd’hui d’un rollback applicatif piloté par le frontend, donc fragile en cas de crash réseau/browser/TPE.
- Claude doit décider si la borne doit créer une commande en statut transitoire, ou différer l’acceptation métier jusqu’à la confirmation paiement.

**Attendu Claude**
- Définir une vraie state machine borne/TPE/KDS.
- Garantir qu’aucune commande fantôme ne parte en production cuisine.

### P0 — Numérotation `queue_number` inter-surfaces encore fragile

**Constat**
- POS et borne calculent le prochain numéro via `MAX(queue_number) + 1`.
- En cas d’échec du lock, le fallback timestampé peut générer un numéro de secours potentiellement collisionnable.

**Références**
- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`

**Pourquoi c’est complexe**
- Le besoin métier est strict : si la caisse fait `70`, la borne doit faire `71`, puis la caisse `72`.
- Le fallback actuel privilégie la continuité plutôt que l’unicité garantie.
- Claude doit choisir un mécanisme plus robuste : compteur transactionnel, table dédiée, séquence par branche/date, ou verrou DB fort.

**Attendu Claude**
- Supprimer le risque de doublon ou de trou incohérent en heure de pointe.

### P0 — Modèle de rupture / indisponibilité absent

**Constat**
- Aucun champ/flux explicite `stock`, `out_of_stock`, `rupture`, `available` n’est exploité côté backend + kiosque.
- L’objectif utilisateur exige qu’un produit en rupture soit bloqué immédiatement sur borne avec affichage clair.

**Références**
- Audit par absence de modèle dédié dans `app/` et côté composants borne.

**Pourquoi c’est complexe**
- Le sujet touche admin, borne, POS, KDS, potentiellement impression et cohérence prix/menus.
- Il faut distinguer produit indisponible, variation indisponible, extra indisponible, indisponibilité par branche et éventuellement temporaire.

**Attendu Claude**
- Proposer le modèle de données et les règles d’exposition multi-surfaces.

### P1 — Topologie temps réel : Echo vs FCM vs polling

**Constat**
- Echo est utilisé pour les mises à jour live par branche.
- FCM envoie en parallèle des notifications par topics surface.
- Le fallback polling reste actif sur plusieurs surfaces.

**Références**
- `app/Events/OrderCreated.php`
- `app/Events/OrderStatusChanged.php`
- `app/Listeners/SendFcmOnOrderCreated.php`
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`

**Pourquoi c’est complexe**
- Il faut clarifier quelle couche est source de vérité pour quel usage : UI temps réel, réveil device, push passif, reprise après perte réseau.
- Risque de doublons d’événements, de logique redondante et de comportements différents selon la surface.

**Attendu Claude**
- Définir un contrat temps réel simple et durable par surface.

### P1 — Limite KDS fixe à 50 commandes actives

**Constat**
- `KitchenDisplaySystemOrderService` tronque la liste à `50`.

**Référence**
- `app/Services/KitchenDisplaySystemOrderService.php`

**Pourquoi c’est complexe**
- En service chargé, des commandes actives peuvent disparaître de l’écran sans être terminées.
- La bonne solution dépend du modèle d’exploitation réel : pagination, priorisation, colonnes, station cuisine, ou fenêtres actives.

**Attendu Claude**
- Choisir une stratégie de charge KDS sans casser la lisibilité opérationnelle.

### P1 — Sémantique FCM “web/kiosk/app” à revalider

**Constat**
- `SendFcmOnOrderCreated` annonce “POS notification for non-POS orders” mais la condition actuelle exclut aussi `WEB`.

**Référence**
- `app/Listeners/SendFcmOnOrderCreated.php`

**Pourquoi c’est complexe**
- Ce n’est pas juste un `if` à inverser : il faut confirmer la vraie matrice des notifications par source, par surface et par moment métier.

**Attendu Claude**
- Valider la matrice de notification cible avant correction.

**Modèle de matrice à remplir (brouillon pour Claude)**

| Événement | Source commande | Topic / canal | Destinataire visé | Condition actuelle (fichier) | Décision cible |
|-----------|-----------------|---------------|-------------------|------------------------------|----------------|
| `OrderCreated` | POS | `kitchen_branch_*` | Cuisine | Skippé si status ACCEPT/PREPARING | À valider |
| `OrderCreated` | Kiosk/Web/App | `pos_branch_*` | Caisse | `!in_array(source, [POS, WEB])` — **WEB exclu** | À valider |
| `OrderCreated` | * | `customer_order_{id}` | Client | Toujours dispatch | À valider (token / opt-in) |
| `OrderStatusChanged` | * | FCM via listener dédié | KDS/OSS/mobile | Voir `SendFcmOnOrderStatusChange` | Aligner avec Echo |

---

## 4. Ordre recommandé pour Claude

0. **Valider auth multi-surfaces** : clé API, Sanctum, borne vs admin (pas de régression après changements Kimi).
1. Formaliser la state machine `commande borne -> paiement -> visibilité KDS`.
2. Refondre la génération `queue_number` avec garantie d’unicité inter-surfaces.
3. Définir le modèle `rupture / indisponibilité` multi-branches.
4. Simplifier la stratégie temps réel et push.
5. Revenir sur la limite KDS et la matrice FCM (remplir le tableau section 3).

---

## 5. Risque global si on n’agit pas

- Risque de commande vue trop tôt en cuisine.
- Risque résiduel de collision ou d’incohérence sur les numéros d’appel.
- Risque de vendre un produit indisponible.
- Risque de comportements divergents entre borne, POS, KDS et écran client sous charge ou réseau dégradé.

---

## 6. Verdict

Les irritants UI faciles ont été absorbés. Le reste n’est plus du cosmétique : ce sont des sujets de cohérence métier et d’architecture qui doivent être repris par Claude avant validation production.

---

## 7. Wizard borne — P0 menu explicite + objectif « 0 faute » (2026-03-28)

**Type de test** : Kimi-test (Vitest `tests/js/KioskWizard.spec.js`).

### 7.1 Implémenté (P0) — audit de pérennité

- **`canAdvance`** : à l’étape `menu`, passage bloqué tant que `menuChoice` n’est pas défini (`null` / `undefined` / `''` interdits). Les valeurs `full`, `frites`, `boisson`, `none` sont valides.
- **UI** : bandeau d’aide sur `KioskStepMenu` tant qu’aucune carte menu n’a été touchée (`role="status"`).
- **Tests** : `KioskWizardComponent` réel (shallow + stubs) + `KioskStepMenuComponent` (hint).
- **Re-audit 2026-03-28** : règle inchangée après P1 / P2 / P3 / P4 / **P5** ; pas de régression (Vitest P0–P5).

### 7.1b Implémenté (P1)

- **`kioskDrinkAddons.js`** : même heuristique boisson / anti-food que l’étape menu (une seule source de vérité).
- **`canAdvance` (menu)** : si `menuChoice` ∈ `{ full, boisson }` **et** `kioskDrinkAddonRowsFromItem(item).length > 0`, alors `boissonChoice` obligatoire (non null / non vide). Si aucun addon boisson détecté, `full` reste valable sans boisson (comportement inchangé vs avant P1).
- **UI** : bandeau « Sélectionnez une boisson pour continuer » sous le titre boisson.
- **Nettoyage** : passage en `none` ou `frites` → `boissonChoice` + `localBoisson` remis à zéro ; `updateSelection` efface `_boissonMeta` quand la boisson est effacée.
- **Tests** : `tests/js/kioskDrinkAddons.spec.js` + cas wizard P1 + hint boisson dans `KioskWizard.spec.js`.
- **Re-audit P1 (post P2)** : helper + `canAdvance` boisson inchangés ; `kioskDrinkAddons.spec.js` + tests P1 verts.

### 7.1c Implémenté (P2)

- **Modale abandon** : boutons « × » et « ABANDONNER L'ARTICLE » ouvrent une confirmation (overlay + `role="dialog"` / `aria-labelledby`) ; « Continuer » ou clic fond → fermeture sans quitter ; « Oui, abandonner » → `performCloseWizard()` (`onClose` ou `router.go(-1)`).
- **Fermeture effective** : `performCloseWizard()` ; transition `fade` sur l’overlay ; `z-index: 120` au-dessus du contenu wizard (overlay parent catalogue `z-index: 50`).
- **Tests** : `KioskWizard.spec.js` — ouverture sans `onClose`, annulation, confirmation appelle `onClose`.
- **Re-audit P2 (post P3)** : flux modale inchangé ; libellés passent par `$t('kiosk.wizard.*')` ; tests P2 + **P3** verts.

### 7.1d Implémenté (P3) — i18n wizard

- **Clés** : `kiosk.wizard.*` dans `resources/js/languages/fr.json`, `en.json`, `ar.json` (prompts d’étapes, navigation, modale abandon, chargement / erreurs fetch, sauces frites, bloc **menu** (cartes + badges + hints), **récap** `KioskOrderSummary`, **suppléments** `KioskStepSupplements`).
- **Composants** : `KioskWizardComponent.vue`, `KioskStepMenuComponent.vue`, `KioskOrderSummaryComponent.vue`, `KioskStepSupplementsComponent.vue`.
- **Tests** : `createI18n` + messages `fr` dans les mounts du wizard réel et des tests menu ; cas **P3** vérifie les libellés modale vs `fr.json`.
- **Re-audit P3 (post P4)** : clés `kiosk.wizard` inchangées pour menu / récap / modale ; extension **`step.*`** sans casser P3 ; Vitest P3 + P4 verts.

### 7.1e Implémenté (P4) — i18n étapes Pain, Taille, Viande, Sauce, Garnitures

- **Clés** : `kiosk.wizard.step.{pain,taille,viande,sauce,garnitures}` (fr / en / ar) — titres, hints, libellés viandes/tailles, sauces fallback, garnitures (AVEC/SANS, résumé).
- **Composants** : `KioskStepPainComponent`, `KioskStepTailleComponent`, `KioskStepViandeComponent`, `KioskStepSauceComponent`, `KioskStepGarnituresComponent`.
- **Tests** : i18n sur les `mount(KioskStepSauceComponent)` existants ; **P4** smoke pain + sauce vs `fr.json`.
- **Re-audit P4 (post P5)** : chaînes `kiosk.wizard.step.*` inchangées ; seule la résolution de template évolue ; Vitest P4 + P5 verts.

### 7.1f Implémenté (P5) — heuristique `wizard_template`

- **`detectTemplateFromName`** : extension **omelette**, **salade**, **snacking** (nuggets, tenders, goujon(s), crousti, strip, catégorie contenant `snack`) pour coller aux branches `activeSteps` quand `wizard_template` est absent en base.
- **Priorité** : inchangée — `wizard_template` explicite **prime** toujours sur l’heuristique.
- **Tests** : `KioskWizard.spec.js` — 9 cas représentatifs + override admin + smoke `activeSteps` burger.

### 7.2 Check-list pré-prod wizard (réduire les fautes restantes)

| # | Contrôle | Statut |
|---|-----------|--------|
| 1 | Boisson : si liste addons boisson non vide, exiger un choix avant Suivant (P1 métier) | **Fait** |
| 2 | Recalcul prix **serveur** à la soumission commande (ne jamais faire confiance au total client seul) | **OK** — `OrderService` recalcule sous-total / total à partir des prix DB et variations/extras chargés en masse (champs financiers client non fiables) ; voir création commande `[AUDIT-FIX P0]` |
| 3 | `wizard_template` catégorie vs heuristique nom produit : pas d’écart sur 5 produits représentatifs par template | **Partiellement automatisé (P5)** — Vitest 9 cas + snacking/omelette/salade ; **compléter en QA manuel** sur catalogue réel |
| 4 | Abandon article : confirmation modale (évite erreur tactile) | **Fait** (P2) |
| 5 | i18n clés wizard si borne multilingue | **Fait** (P3 shell + P4 **toutes** les étapes wizard) |
| 6 | Après merge : `npm run production` + smoke sur flux burger menu + tacos + snacking | Obligatoire |

### 7.3 Fichiers modifiés (trace revue)

- `resources/js/helpers/kioskDrinkAddons.js` — filtre addons boisson (partagé)
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` — `canAdvance` menu + P1 boisson ; `_boissonMeta` ; **P2** modale ; **P3** `$t` shell / prompts / erreurs fetch ; **P5** `detectTemplateFromName` (omelette / salade / snacking)
- `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue` — idem P0/P1 + **P3** `$t` cartes menu / hints / badges / sauces frites
- `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` — **P3** `$t` sections récap
- `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue` — **P3** `$t` titre / badge / vide / desc défaut
- `resources/js/components/frontend/kiosk/steps/KioskStepPainComponent.vue`, `KioskStepTailleComponent.vue`, `KioskStepViandeComponent.vue`, `KioskStepSauceComponent.vue`, `KioskStepGarnituresComponent.vue` — **P4** `$t` `kiosk.wizard.step.*`
- `resources/js/languages/fr.json`, `en.json`, `ar.json` — **`kiosk.wizard`** + **`step`**
- `tests/js/KioskWizard.spec.js` — P0–P5 + plugin i18n
- `tests/js/kioskDrinkAddons.spec.js` — helper

### 7.4 Suite « 0 faute » (priorisée)

1. ~~Modale abandon~~ → §7.1c.
2. ~~i18n wizard~~ → §7.1d + §7.1e (shell, menu, récap, suppléments, modale, **pain, taille, viande, sauce, garnitures**).
2b. ~~Heuristique `wizard_template` (snacking / omelette / salade) + tests Vitest~~ → §7.1f (**compléter** en QA catalogue réel §7.2 #3).
3. **QA manuel** : valider sur **données réelles** les articles hors couverture test (noms atypiques) + **changement de langue** (fr / en / ar) sur tout le wizard.
4. **Gate release** : `npm run production` + smoke burger menu + tacos + snacking (§7.2 #6).
5. **Option métier** : flag catalogue boisson si besoin hors heuristique nom.
