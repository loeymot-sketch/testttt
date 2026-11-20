# GOAL — ONB-04 EXTRACTION DE MENU PAR IA & ASSISTANT DE MISSIONS LOCALES
## FoodKing — Onboarding commerçant · une photo de la carte → catégories, produits, prix, options et règles proposés → validation humaine → création via les API existantes ; puis un assistant qui exécute des missions locales (« ajoute la sauce X à tous les tacos ») en proposant d'abord, en agissant ensuite

- **Slug** : `ONB04_EXTRACTION_MENU_IA_ET_ASSISTANT_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **HEAD** : `43b120c7d` · **Branche de base** : `pos/category-first-caisse-2026-06-23`
- **Voie SYSTEM_MAP** : CENTRAL — nouvelle sous-voie « assistant » (`app/Services/MenuExtraction/**`, `app/Http/Controllers/Admin/Assistant/**`, `admin/assistant/**`) ; **consomme** les API de ONB-02/03 sans les modifier
- **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB04_EXTRACTION_MENU_IA_ET_ASSISTANT.md`
- **Port de session** : **8804** · **Dépend de** : ONB-02 (API catalogue), ONB-03 (schéma de règle) · **Persona** : Karim photographie sa carte plastifiée ; il veut voir apparaître catégories, produits, prix, options ; corriger ; créer. Puis : « ajoute la sauce algérienne à tous les tacos », « passe les desserts en station froide ».

> **En cinq lignes.** Le problème : **aucune extraction de menu, aucun assistant** n'existe (`Z0_carte §9` : grep `anthropic|chatbot|extract|ocr` → rien) ; mais un **motif éprouvé** existe
> deux fois — contrat + implémentation OpenAI Vision + mock à fixture (`Purchasing/Vision`, `Uber/Vision`), clé unique `OPENAI_API_KEY`, `OPENAI_VISION_ENABLED=false` par défaut, modèle
> `gpt-4o-mini`. FINI = pipeline **mock-first** (le GOAL converge sans clé) : entrée photo/PDF/texte → brouillon structuré versionné → écran de validation avec diff → application
> **idempotente** via `ItemCategoryController`/`ItemController`/composer → journal ; et un assistant de missions qui **propose un plan, attend une confirmation, exécute via les API,
> journalise, refuse tout ce qui touche caisse/fiscal/utilisateurs** (C1..C7). L'IA ne touche jamais la base directement, ne calcule jamais un prix. Premier geste : W0 puis lire les deux
> pipelines Vision existants.

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
- **Worktree dédié** `.claude/worktrees/onb04-assistant`, branche `goal/onb04-assistant-2026-08-26`, depuis **HEAD** (après ONB-02 ; ONB-03 pour le schéma de règle — sinon règles `paid` par défaut).
- Pré-vol : `.env` → `APP_URL=http://127.0.0.1:8804` ; `.env.testing` ; liens durs ; `ReflectionClass(App\Services\Purchasing\Vision\MockInvoiceVisionService::class)` → worktree ; serveur 8804 ; `PLAYWRIGHT_BASE_URL` ; **`OPENAI_VISION_ENABLED=false`** (mock) jusqu'à G-IA.
- Base partagée : tout ce que l'assistant crée porte le préfixe de lot `GOAL-ONB04-<lot>` (catégorie/articles/étapes) et est supprimé définitivement en fin de vague (script de purge par lot = livrable) ; ⛔ jamais de commande ; jamais `migrate:fresh` ; `safe-test.sh --phpunit "MenuExtraction|Assistant|Catalog|Composer"`.
- ⚠️ Dépense : aucune requête vers l'API OpenAI sans G-IA (clé + plafond) ; fixtures mock versionnées sous `tests/fixtures/menu-extraction/`.
- Filet : `git branch backup/pre-onb04-2026-08-26` + dump catalogue.

## §0.2 — Périmètre : DANS / HORS / voisins
| DANS | Fichiers POSSÉDÉS (tous À CRÉER sauf mention) |
|---|---|
| S1 Pipeline d'extraction | `app/Services/MenuExtraction/{MenuExtractionContract,OpenAiMenuExtractionService,MockMenuExtractionService,MenuDraft,MenuDraftSchema,MenuDraftNormalizer}.php`, `app/Providers/MenuExtractionServiceProvider.php`, `config/assistant.php`, migration `menu_drafts`, `app/Models/MenuDraft.php`, `app/Http/Requests/Assistant/MenuExtractionUploadRequest.php`, `tests/fixtures/menu-extraction/*.json` |
| S2 Écran de validation | `resources/js/components/admin/assistant/{MenuImportComponent,MenuDraftReviewComponent,MenuDraftDiffTable,MenuDraftRuleEditor}.vue`, route `assistantRoutes.js`, `fr.json` (bloc `label.assistant_*`) |
| S3 Application idempotente | `app/Services/MenuExtraction/MenuDraftApplier.php`, `app/Http/Controllers/Admin/Assistant/{MenuExtractionController,MenuDraftController}.php`, migration `assistant_actions` (journal), `app/Models/AssistantAction.php` |
| S4 Assistant de missions | `app/Services/Assistant/{MissionPlanner,MissionCatalogue,MissionExecutor,MissionRefusalPolicy}.php`, `app/Http/Controllers/Admin/Assistant/MissionController.php`, `app/Http/Requests/Assistant/MissionRequest.php`, `resources/js/components/admin/assistant/AssistantChatComponent.vue` |

| HORS | Porté par |
|---|---|
| `ItemController`, `ItemCategoryController`, `Item*Request`, `Composer*Controller`, `Composer*Request` (l'assistant les **appelle**, ne les modifie pas) | ONB-02 / ONB-03 |
| `PricingService` (gelé), tout calcul de prix | jamais — l'IA ne propose que des **prix catalogue** que l'humain valide |
| `Purchasing/Vision/*`, `Uber/Vision/*` (motifs réutilisés en lecture) | ONB-08 / voie CAISSE |
| Clé, fournisseur, budget | G-IA (propriétaire) |
| Caisse, fiscal, utilisateurs, rôles, réglages : **missions refusées** par politique | jamais |
| Visibilité de « Assistant » dans le menu | ONB-05 |

Zones à coordonner : `routes/api.php` (routes `assistant/*`), `resources/js/router/index.js` (module), `config/app.php` (provider), `fr.json`, `DatabaseSeeder.php` (aucun seed).

## §0.3 — Drapeaux d'expansion
SCOPE-1 gelé (pricing, kiosk, POS) · SCOPE-2 3 boucles · SCOPE-3 migrations prévues (`menu_drafts`, `assistant_actions`) : G-DATA ; toute autre = STOP · SCOPE-4 NF525 : l'assistant ne touche jamais commandes, paiements, Z, snapshots · SCOPE-5 : une mission qui exige une API absente (ex. « planifie une promo le mardi ») → fiche de renvoi, jamais un contournement direct en base.

## §0.4 — Pipeline
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · TDD · `systematic-debugging`. Non redécrit.

## §0.5 — Convergence et critères chiffrés
Rejets Axe 6 · **deux cycles consécutifs P0+P1 = 0 aux constats identiques**.

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | Mock-first | tout le GOAL (tests + E2E) converge avec `OPENAI_VISION_ENABLED=false` ; 3 fixtures (carte simple / carte à options / carte piégée) | **VRAI** |
| C2 | Rien n'est écrit sans validation | annulation à la validation → 0 ligne en base ; l'IA n'a aucun accès en écriture (test : contrat sans dépendance DB) | **0** |
| C3 | Application idempotente | appliquer deux fois le même brouillon → 0 doublon ; reprise après interruption à la ligne k → k lignes exactement | **VRAI** |
| C4 | Fidélité du brouillon | sur les 3 fixtures : catégories, produits, prix, options extraits = attendus (précision/rappel mesurés) ; erreurs signalées, pas inventées | **≥ 95 %** sur la fixture simple, 100 % des ambiguïtés signalées |
| C5 | Assistant sûr | catalogue de missions fermé ; 10 missions interdites (caisse, fiscal, utilisateurs, réglages, suppression) → refus expliqué ; 0 exécution sans confirmation | **10/10 refus, 0 exécution implicite** |
| C6 | Effet réel | un brouillon appliqué → articles visibles borne/caisse (API menu) avec leurs règles (ONB-03) et station | **VRAI** |
| C7 | Journal | chaque action IA/humain : qui, quand, quoi, résultat, lot ; purge par lot prouvée | **100 %** |

## §0.6 — Base héritée
PHPUnit 5 194 · Vitest 3 644 · gelé 0 · motif Vision : `app/Services/Purchasing/Vision/{InvoiceVisionContract,MockInvoiceVisionService,OpenAiInvoiceVisionService}.php`, `app/Providers/PurchasingServiceProvider.php:30-34`, `config/services.php:71-90` (`openai.key`, `enabled`, `model gpt-4o-mini`, `base_url :87`, `timeout`, `mock_fixture`) ; `app/Services/Uber/Vision/*`, `UberVisionServiceProvider.php:30-42`, `config/uber_photo.php:31-38` (`max_files 6`, `max_kb 12288`) ; consommateur `PurchasingScanController.php:12,87-89` ; UI `admin/purchasing/PurchaseScanComponent.vue` ; sécurité `tests/Feature/Security/{FileUploadHardenedSentinelTest,ExcelFormulaInjectionGuardTest}.php` ; `.env.example:500,553` (clé partagée).
API consommées : catégories `routes/api.php:513-523`, articles `:858-900`, composer `:915-939`, disponibilité `:357-369`.

## §0.7 — Contradictions tranchées
- **C-CONST** (index) : G0 ; **C-CLOUD** — `CONSTITUTION §3.3` « no-cloud sans ordre explicite » vs API OpenAI : le projet appelle **déjà** OpenAI (factures, Uber) ; tranché : même clé, même contrat, **mock par défaut**, appel réel derrière G-IA — pas une dérive nouvelle.
- **C-IA-ÉCRIT** — un « chatbot qui fait tout » (mandat) vs NF525/SSOT. Tranché : l'IA **propose**, l'humain **valide**, le système **applique via les API existantes** ; jamais d'écriture directe, jamais de prix calculé par l'IA (les prix extraits sont des **données saisies** validées par l'humain, comme un import Excel).
- **C-RÈGLES** — sans ONB-03, « 1 sauce incluse » n'a pas de colonne. Tranché : le schéma du brouillon porte la règle (`free/included/paid`) ; si ONB-03 n'est pas livré, l'applicateur retombe sur `paid` et **signale** la perte.
- **C-VOCAB** — « chatbot » évoque un assistant conversationnel libre. Tranché : **catalogue de missions fermé** (intentions reconnues), conversation limitée à préciser une mission ; hors catalogue → « je ne sais pas faire ça, voici où le faire à la main ».

## §0.8 — Le commerçant-type et ses questions
Karim : 1. « Je photographie ma carte, et après ? » 2. « Elle s'est trompée sur un prix : je corrige où, avant que ça entre ? » 3. « Elle a créé un doublon ? » 4. « "Ajoute la sauce algérienne à tous les tacos" — elle le fait, ou elle me demande ? » 5. « Elle peut toucher à ma caisse ? »

# §1 — CARTE DU SYSTÈME (ancrages vérifiés + à créer)

| Sous-système | Maturité | Ancrage réel (motif) | À créer |
|---|---|---|---|
| S1 Extraction | **INEXISTANTE ; motif prouvé** | `InvoiceVisionContract.php`, `MockInvoiceVisionService.php` (fixture), `OpenAiInvoiceVisionService.php`, `PurchasingServiceProvider.php:30-34` (binding conditionnel), `config/services.php:71-90` | `MenuExtraction/*`, `config/assistant.php`, `menu_drafts` |
| S2 Validation | **INEXISTANTE ; motif écran** | `admin/purchasing/PurchaseScanComponent.vue` (upload → cibles → validation), `admin/items/ItemUploadComponent.vue` (import Excel) | `admin/assistant/*` |
| S3 Application | **API CIBLES EXISTANTES** | `ItemCategoryController` (`:513-523`), `ItemController` (`:858-900`, `ItemRequest`), `ItemVariationController`, `ItemExtraController`, `ItemAddonController`, composer (`:915-939`) | `MenuDraftApplier`, `assistant_actions` |
| S4 Missions | **INEXISTANTE** | mêmes API + `AvailabilityController` (`:357-369`) + `PUT item` (station) | `Assistant/*` |

**Sortie d'ancrage brute** : `ls app/Services/Purchasing/Vision` → 3 · `ls app/Services/Uber/Vision` → 3 · `grep -n openai config/services.php` → `:83-90` · `grep -rli "anthropic\|chatbot\|menu.extract" app resources/js` → 0 (hors `.env.anthropic.example`) · routes cibles vérifiées `routes/api.php:513-523,858-900,915-939,357-369` · `ls tests/Feature/Security | grep -i "upload\|excel"` → `FileUploadHardenedSentinelTest.php`, `ExcelFormulaInjectionGuardTest.php`.

# §2 — ÉTAT CONNU LE 2026-08-26
Aucune fonction d'extraction ni d'assistant (`Z0_carte §9`). Le scan de factures est un **modèle complet** : upload gardé (type/taille), vision (mock/réel), classification non-LLM (`InvoiceClassificationService`), cibles proposées, application en stock (`PurchasingScanController.php:51-55,208`, avec garde RCE). Import Excel existant = second modèle (prévisualisation absente — ONB-02 la crée). Clé OpenAI partagée (`.env.example:553`). **Mesure W1** : rejouer le scan de facture en mock pour documenter le motif de bout en bout.

# §3 — SOUS-SYSTÈME 1 : PIPELINE D'EXTRACTION (mock-first)

## Sub 1.1 — Contrat, schéma, mock
**Ancrages** : motif `InvoiceVisionContract` / `MockInvoiceVisionService` / `PurchasingServiceProvider.php:30-34`, `config/services.php:71-90`.
**Tâches**
- **T-1.1.1** — Schéma `MenuDraftSchema` (JSON versionné `v1`) : `categories[] {name, sort, items[] {name, description?, price, tax_hint?, station_hint?, channels?, options[] {group, choices[] {name, price?}, rule {mode: free|included|paid, included_count?, min, max}}, confidence, source_ref}}` ; ambiguïtés explicites (`issues[]` : prix illisible, doublon probable, option sans prix).
  • test : (À CRÉER à `tests/Feature/MenuExtraction/MenuDraftSchemaTest.php`)
- **T-1.1.2** — `MenuExtractionContract::extract(Upload $file, ExtractionContext $ctx): MenuDraft` ; `MockMenuExtractionService` (3 fixtures : simple, à options, piégée — noms accentués, prix « 8,50 », « 2 sauces au choix », doublon, produit sans prix) ; `MenuExtractionServiceProvider` (binding mock/réel sur `assistant.enabled` + clé).
  • test : (À CRÉER à `tests/Feature/MenuExtraction/MockExtractionFixturesTest.php`) · C1
- **T-1.1.3** — `OpenAiMenuExtractionService` (réel, derrière G-IA) : prompt structuré → JSON validé par le schéma, timeout, taille max, coût estimé journalisé, **aucune donnée personnelle** envoyée (la carte seulement) ; test avec `OPENAI_MOCK_FIXTURE` (motif existant).
  • test : (À CRÉER à `tests/Feature/MenuExtraction/OpenAiExtractionContractTest.php` — sauté sans clé, avec motif)
- **T-1.1.4** — Upload : `MenuExtractionUploadRequest` (jpg/png/pdf ≤ 12 Mo, 6 fichiers, type par contenu — réutiliser les gardes de `FileUploadHardenedSentinelTest`), texte collé accepté (carte tapée).
  • test : (À CRÉER à `tests/Feature/MenuExtraction/UploadGuardsTest.php`)
**Acceptation** : C1 · C4 (mesure de fidélité sur fixtures) · 4 tests VERTS.

## Sub 1.2 — Normalisation et rapprochement
**Tâches**
- **T-1.2.1** — `MenuDraftNormalizer` : virgule → point, TVA par défaut (réglage ONB-02), station par indice (« boisson » → `bar`), canaux par défaut, **rapprochement** avec l'existant (nom normalisé + catégorie : « existe déjà », « proche de … », « nouveau ») — jamais une fusion automatique.
  • test : (À CRÉER à `tests/Feature/MenuExtraction/NormalizerAndMatchingTest.php`)
**Acceptation** : test VERT · question 3 de Karim = OUI (doublons signalés, jamais créés sans choix).

# §4 — SOUS-SYSTÈME 2 : ÉCRAN DE VALIDATION

**Ancrages** : motif `PurchaseScanComponent.vue`, tiroirs du catalogue (ONB-02).
**Tâches**
- **T-2.1.1** — `MenuImportComponent` : dépôt de fichiers/texte → brouillon (mock/réel) → `MenuDraftReviewComponent` : tableau catégories/produits/options éditable inline (prix, taxe, station, canaux, règle via `MenuDraftRuleEditor` — trois boutons ONB-03), badges « existe déjà / proche / nouveau / à vérifier », cases « inclure », bouton « Appliquer N lignes ».
  • test : (À CRÉER à `tests/js/menuDraftReview.spec.js`) · visuel : `http://127.0.0.1:8804/admin/assistant/import` à 1366/1024/768
  • au-delà : annulation → 0 écriture (C2) ; rechargement → brouillon conservé en base (`menu_drafts`, statut `draft`) ; deux onglets → verrou optimiste ; 200 lignes (pagination/virtualisation).
- **T-2.1.2** — Diff avant application (`MenuDraftDiffTable`) : ce qui sera créé / mis à jour / ignoré, avec compte ; aucune suppression jamais proposée.
**Acceptation** : C2 · test VERT · captures lues · questions 1, 2 = OUI.

# §5 — SOUS-SYSTÈME 3 : APPLICATION IDEMPOTENTE ET JOURNAL

**Ancrages** : API cibles (ONB-02/03), `ItemImport` (ONB-02, idempotence), `config/idempotency.php` (ONB-13).
**Tâches**
- **T-3.1.1** — `MenuDraftApplier` : transaction par ligne, clé d'idempotence par (brouillon, ligne), appels **internes aux services** de ONB-02/03 (`ItemCategoryService`, `ItemService`, `ComposerProfileService`… — jamais SQL direct), reprise après interruption, rapport ligne à ligne (créé / mis à jour / refusé + motif de validation FormRequest).
  • test : (À CRÉER à `tests/Feature/MenuExtraction/MenuDraftApplierIdempotencyTest.php`) · C3
  • au-delà : ligne refusée par `ItemRequest` (prix négatif) → les autres passent, le rapport le dit ; interruption ligne 30 → reprise à 31 ; brouillon appliqué deux fois → 0 doublon.
- **T-3.1.2** — Journal `assistant_actions` (qui, quand, quoi, lot, résultat, coût IA) + purge par lot (script) ; visible dans l'écran (historique) ; branchement au journal ONB-13 si livré.
  • test : (À CRÉER à `tests/Feature/MenuExtraction/AssistantActionsJournalTest.php`) · C7
- **T-3.1.3** — Effet réel : brouillon fixture appliqué → `GET /api/frontend/menu` et POS contiennent les articles, règles (ONB-03), stations ; purge → disparus.
  • test : (À CRÉER à `tests/Feature/MenuExtraction/AppliedDraftVisibleOnSurfacesTest.php`) · C6
**Acceptation** : C3, C6, C7 · 3 tests VERTS.

# §6 — SOUS-SYSTÈME 4 : ASSISTANT DE MISSIONS LOCALES

**Tâches**
- **T-4.1.1** — `MissionCatalogue` fermé (V1) : ajouter/retirer une option (extra) à tous les articles d'une catégorie ; changer la station d'une catégorie ; passer un article/extra en rupture ou en disponible ; créer une catégorie ; dupliquer un article ; renommer ; appliquer un template de règles à une catégorie ; « montre-moi les articles sans station ». **Refusés** : caisse, paiements, Z, utilisateurs, rôles, réglages, suppression définitive, prix libres (`MissionRefusalPolicy`).
  • test : (À CRÉER à `tests/Feature/Assistant/MissionCatalogueAndRefusalTest.php`) · C5
- **T-4.1.2** — `MissionPlanner` : intention (mock : règles + synonymes ; réel derrière G-IA) → **plan** (« Ajouter l'extra “Sauce algérienne” (0,50 €) à 12 articles de “Tacos” : liste ») → confirmation explicite → `MissionExecutor` via les services (idempotent, journalisé) → compte rendu.
  • test : (À CRÉER à `tests/Feature/Assistant/MissionPlanConfirmExecuteTest.php`)
  • au-delà : mission ambiguë (« les tacos » = 2 catégories) → question ; mission partiellement impossible → plan partiel explicite ; double confirmation → une exécution ; annulation après plan → 0 écriture.
- **T-4.1.3** — `AssistantChatComponent` : fil de conversation, plan affiché en liste vérifiable, bouton « Confirmer », historique, aide « ce que je sais faire ».
  • test : (À CRÉER à `tests/js/assistantChatPlanConfirm.spec.js`) · visuel : `/admin/assistant`
**Acceptation** : C5 · 3 tests VERTS · questions 4, 5 = OUI.

# §S — SCÉNARIOS ADVERSES OBLIGATOIRES
| Fonction \ scénario | annulation | rechargement | double soumission | deux onglets | rôle inférieur | données vides | volume | réseau/IA coupée | effet borne / caisse | retour arrière | valeurs limites |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Upload/extraction | — | brouillon persisté | même fichier 2× → 2 brouillons, 0 article | — | `catalog.compose` 403 | image vide → 422 | 6 × 12 Mo | IA timeout → brouillon vide + message | — | supprimer un brouillon (jamais les articles) | `.php`, PDF 200 pages, texte 100 Ko |
| Validation | annuler → 0 écriture (C2) | idem | — | verrou optimiste | 403 | brouillon sans ligne | 200 lignes | — | — | désélectionner | prix « 8,50 », négatif, nom 191 |
| Application | interruption ligne 30 → reprise | — | idempotent (C3) | — | `catalog.publish` pour publier | — | 200 lignes | DB coupée → rollback ligne | C6 | purge par lot | ligne refusée par `ItemRequest` |
| Mission | annuler après plan → 0 | — | double confirmation → 1 exécution | — | 403 | mission vide | 12 articles | IA coupée → mock/refus | disponibilité borne | mission inverse | mission interdite (10 cas) |
| Journal | — | — | — | — | lecture `settings` | — | 10 000 actions | — | — | purge par lot | — |

# §A — ARMÉE D'AGENTS
**Architecte** (contrat, schéma, frontière IA/API, mock-first) · **Sécurité** (upload, injection de prompt via la carte → jamais d'exécution, données envoyées, clé, coût) · UX/A11y (écran de validation, chat) · **Psychologie commerçant** (confiance : « rien n'entre sans moi » ; l'IA se trompe et le dit) ·
DBA (`menu_drafts`, `assistant_actions`, idempotence) · SRE (timeouts, coût, quotas) · Implémenteur unique · ROUGE (tente de faire écrire l'IA directement, d'exécuter une mission interdite, de créer un doublon) · QA visuel + ROUGE visuel · **Jalonneur**.
Disque `reports/test-e2e/ONB04_EXTRACTION_MENU_IA_ET_ASSISTANT/<round>/wave-<W>-<rôle>.json` ; contrat de constat ; ~1 200-1 500 mots.

# §X — VAGUES DE CONVERGENCE
| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol, filet, bases, mode mock | séquentiel | ONB-02 stabilisé |
| **W1** | Reconnaissance : rejouer le scan de facture (mock) de bout en bout ; lire les deux pipelines Vision ; inventaire des API cibles et de leurs FormRequests | fan-out lecture seule | — |
| **W2** | S1 contrat, schéma, mock, upload, normalisation (T-1.*) | séquentiel | **G-DATA** (`menu_drafts`) |
| **W3** | S3 application + journal (T-3.*) — avant l'écran : on prouve l'idempotence sur des brouillons de fixture | séquentiel | G-DATA (`assistant_actions`) |
| **W4** | S2 écran de validation (T-2.*) | séquentiel | — |
| **W5** | S4 assistant de missions (T-4.*) | séquentiel | — |
| **W6** | Réel : `OpenAiMenuExtractionService` + planner réel sur 3 cartes réelles du propriétaire, coût mesuré | séquentiel | **G-IA** |
| **W7** | Convergence : deux cycles, `safe-test.sh --phpunit "MenuExtraction|Assistant|Catalog|Composer"`, Vitest, Playwright `tests/e2e/onb04-*.spec.js` (mock), BRAIN | séquentiel | — |
**§X.8** 6 points · **§X.9** STOP/`STUCK_*`/4 options · **§X.10** `wip`/`INTERRUPT_*`/BRAIN.

# §G — GATES PROPRIÉTAIRE
| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Amendement constitutionnel (index) | Propriétaire | ligne | `CONSTITUTION.md` | EN ATTENTE — ne bloque pas |
| **G-DATA** | Tables `menu_drafts`, `assistant_actions` | Propriétaire | accord | `docs/gates/GATE_LOG.md` | EN ATTENTE — bloque W2/W3 |
| **G-IA** | Fournisseur (OpenAI, clé existante), modèle, plafond de dépense mensuel, données envoyées (carte seulement) | Propriétaire | clé `.env` + plafond écrit | MISSION §6 | EN ATTENTE — **bloque W6 seulement** |
| **G-CATALOGUE-MISSIONS** | Liste des missions autorisées / interdites V1 | Propriétaire | validation de la liste | MISSION §6 | EN ATTENTE — bloque T-4.1.1 (défaut : liste du chef de projet) |
| **G-CACHE** | Entrée de menu « Assistant » (exécutée par ONB-05) | Propriétaire | tableau | `MISSION_ONB05` §6 | EN ATTENTE |

# §R — RÉFÉRENCES
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · `CONSTITUTION.md §3.3` · `CLAUDE.md §3bis (SSOT), §8` · `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · `_FICHES_GOAL.md` (ONB-04) · `recon/Z0_carte_dashboard.md §9` · `recon/Z0_modele_catalogue_wizard_reglages.md §F` ·
`app/Services/Purchasing/Vision/*`, `app/Providers/PurchasingServiceProvider.php`, `config/services.php`, `PurchasingScanController.php`, `admin/purchasing/PurchaseScanComponent.vue`, `app/Services/Uber/Vision/*` · `plans/GOAL_OPS_RELIABILITY_SWAP_MULTIMARQUE_2026-08-12.md` (B5 « pilotable par IA ») · `GOAL_ONB02`, `GOAL_ONB03`.

# §F — RÈGLE FINALE
TERMINÉ quand et seulement quand : 1. 7 vagues closes (W6 peut être différée par écrit si G-IA non tranché : le GOAL est alors « mock-complet ») ; 2. C1..C7 VRAIS ; 3. PHPUnit ≥ 5 194 + ≥ 12 tests créés VERTS, Vitest ≥ 3 644 ; 4. diff gelé 0 ; 5. NF525 intacte (aucune commande, aucun prix IA) ; 6. gates tranchés ; 7. BRAIN §6 (décision : l'IA propose, l'humain valide, le système applique) ; 8. deux cycles identiques ; 9. fiches (ONB-02/03 API, ONB-05 menu, ONB-13 journal/idempotence, ONB-12 fixtures génériques).
**Interdit** : écriture directe en base par l'IA · prix calculé par l'IA · appel OpenAI sans G-IA · mission hors catalogue exécutée · suppression proposée · approuver un gate.
> Le sens : Karim photographie sa carte, corrige deux prix, clique « Appliquer 45 lignes », et sa borne vend sa carte — sans qu'une seule ligne soit entrée sans lui.
