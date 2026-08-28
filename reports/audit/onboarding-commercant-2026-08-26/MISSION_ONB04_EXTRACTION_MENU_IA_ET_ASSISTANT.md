# MISSION ONB-04 — EXTRACTION DE MENU PAR IA & ASSISTANT · Rapport de mission
- GOAL : `plans/GOAL_ONB04_EXTRACTION_MENU_IA_ET_ASSISTANT_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du **2026-08-26** (HEAD `43b120c7d`, `:8766`, base `foodking_e2e`)
- Port : **8804** · Voie : CENTRAL, nouvelle sous-voie « assistant » · **Vague B** : après ONB-02 (API catalogue) et ONB-03 (schéma de règle) ; compatible en parallèle avec 11/13 (audits)

## 0. COMMENT LANCER
```
Tu es le chef de mission du GOAL ONB-04 (extraction de menu par IA et assistant de missions). Lis : CONSTITUTION.md (§3.3 no-cloud), CLAUDE.md §3bis et §8,
PROJECT_BRAIN.md §2, SYSTEM_MAP.md, PARALLEL_PROTOCOL.md, plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md (§2, §3, §5),
reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB04_EXTRACTION_MENU_IA_ET_ASSISTANT.md, plans/GOAL_ONB04_EXTRACTION_MENU_IA_ET_ASSISTANT_2026-08-26.md,
puis recon/Z0_carte_dashboard.md (§9), recon/Z0_modele_catalogue_wizard_reglages.md (§F), les GOAL ONB-02 et ONB-03 (§0.2 et §3 : API que tu consommes).
Pré-vol §0.1 : worktree .claude/worktrees/onb04-assistant depuis HEAD, APP_URL=http://127.0.0.1:8804, .env.testing, liens durs, serveur 8804, PLAYWRIGHT_BASE_URL,
OPENAI_VISION_ENABLED=false (mock), filet backup/pre-onb04. ⛔ Aucun appel OpenAI sans G-IA ; l'IA n'écrit jamais en base ; aucun prix calculé par l'IA ; jamais de commande ;
toute entité créée porte un préfixe de lot GOAL-ONB04-<lot> et est purgée par lot. Puis « lance le GOAL » : W0 → W1 (rejoue le scan de facture en mock, lis les deux
pipelines Vision et les FormRequests des API cibles) → W2 (contrat/schéma/mock, G-DATA) → W3 (applicateur idempotent + journal) → W4 (écran) → W5 (missions) →
W6 (réel, G-IA) → W7. Pipeline ultra-audit-profond, Architecte + Sécurité en tête, implémenteur unique, ROUGE tente l'écriture directe et les missions interdites,
Jalonneur, matrice §S, deux cycles identiques. Jamais de push. Gates §G : proposer. Compte rendu : FIXÉ / VÉRIFIÉ / BLOQUÉ.
```

## 1. CONTEXTE ET VISION
Le propriétaire veut qu'un nouveau commerçant mette sa carte « avec un extracteur d'intelligence artificielle qui agit et extrait, comme un chat, liste, extrait, crée les wizards », puis un
« chatbot pour faire des missions localement ». Rien de tel n'existe, mais le projet a **déjà** deux pipelines vision (factures, tickets Uber) avec un motif contrat/OpenAI/mock qui a survécu
à la production. Ce GOAL réutilise ce motif, avec deux règles non négociables : **l'IA propose, l'humain valide, le système applique via les API existantes** ; **l'IA ne calcule jamais un prix**.

## 2. ÉTAT CONNU LE 2026-08-26
| Fait | Preuve |
|---|---|
| Aucune extraction de menu, aucun chatbot, aucun Anthropic dans l'application | `Z0_carte §9` (grep `openai|anthropic|claude|gpt|extract|ocr|chatbot`) |
| OpenAI Vision : clé `OPENAI_API_KEY`, `OPENAI_VISION_ENABLED` (défaut **false**), modèle `gpt-4o-mini`, `OPENAI_BASE_URL`, `OPENAI_TIMEOUT` 30, `OPENAI_MOCK_FIXTURE` | `config/services.php:83-90` |
| Motif factures : contrat + mock + réel, binding conditionnel | `app/Services/Purchasing/Vision/{InvoiceVisionContract,MockInvoiceVisionService,OpenAiInvoiceVisionService}.php`, `PurchasingServiceProvider.php:30-34`, `PurchasingScanController.php:12,87-89`, `admin/purchasing/PurchaseScanComponent.vue` |
| Motif Uber : idem, `vision_enabled` défaut false, 6 fichiers ≤ 12 Mo | `UberVisionServiceProvider.php:30-42`, `config/uber_photo.php:31-38`, `app/Services/Uber/Vision/*` |
| Classification non-LLM des lignes | `app/Services/Purchasing/InvoiceClassificationService.php` |
| Import Excel articles (idempotence à prouver — ONB-02) | `ItemController.php:218-226`, `app/Imports/ItemImport.php` |
| Gardes d'upload et de formules | `tests/Feature/Security/{FileUploadHardenedSentinelTest,ExcelFormulaInjectionGuardTest}.php` |
| API cibles | catégories `routes/api.php:513-523` · articles `:858-900` · composer `:915-939` · disponibilité `:357-369` |
| Clé partagée documentée | `.env.example:500,553` |

## 3. CE QUI A DÉJÀ ÉTÉ FAIT
- 2026-08-12 `GOAL_OPS_RELIABILITY_SWAP_MULTIMARQUE` B5 « swap piloté par IA » : planifié, jamais exécuté ; sa distinction paramétrer ≠ multi-tenant est reprise par l'index.
- ARCH_STOCK_INTELLIGENT_BOM P3c : scan de facture (vision) livré ; canal Uber photo (08-10/12) livré.
- Décisions en vigueur : SSOT produits = table `items` (jamais inventer un produit — l'IA ne fait que **proposer** ce qu'elle lit) ; pricing backend ; no-cloud sauf usage existant.

## 4. ANCRAGES CODE (existants) + À CRÉER
| Rôle | Fichier | Note |
|---|---|---|
| Motif vision | `app/Services/Purchasing/Vision/*.php`, `app/Providers/PurchasingServiceProvider.php:30-34`, `config/services.php:71-90` | à copier, pas à modifier |
| Écran modèle | `resources/js/components/admin/purchasing/PurchaseScanComponent.vue`, `admin/items/ItemUploadComponent.vue` | motif upload → cibles → validation |
| API cibles | `ItemCategoryController`, `ItemController` (+ `ItemRequest`), `ItemVariationController`, `ItemExtraController`, `ItemAddonController`, `ComposerProfileController`, `ComposerStepController`, `AvailabilityController` | consommées via leurs **services** |
| À créer — services | `app/Services/MenuExtraction/{MenuExtractionContract,OpenAiMenuExtractionService,MockMenuExtractionService,MenuDraft,MenuDraftSchema,MenuDraftNormalizer,MenuDraftApplier}.php`, `app/Services/Assistant/{MissionPlanner,MissionCatalogue,MissionExecutor,MissionRefusalPolicy}.php`, `app/Providers/MenuExtractionServiceProvider.php`, `config/assistant.php` | |
| À créer — HTTP | `app/Http/Controllers/Admin/Assistant/{MenuExtractionController,MenuDraftController,MissionController}.php`, `app/Http/Requests/Assistant/{MenuExtractionUploadRequest,MissionRequest}.php`, routes `assistant/*` | |
| À créer — données | migrations `menu_drafts`, `assistant_actions`, modèles homonymes, `tests/fixtures/menu-extraction/{simple,options,piegee}.json` | G-DATA |
| À créer — UI | `resources/js/components/admin/assistant/{MenuImportComponent,MenuDraftReviewComponent,MenuDraftDiffTable,MenuDraftRuleEditor,AssistantChatComponent}.vue`, `assistantRoutes.js` | |
| À créer — tests | `tests/Feature/MenuExtraction/{MenuDraftSchemaTest,MockExtractionFixturesTest,OpenAiExtractionContractTest,UploadGuardsTest,NormalizerAndMatchingTest,MenuDraftApplierIdempotencyTest,AssistantActionsJournalTest,AppliedDraftVisibleOnSurfacesTest}.php`, `tests/Feature/Assistant/{MissionCatalogueAndRefusalTest,MissionPlanConfirmExecuteTest}.php`, `tests/js/{menuDraftReview,assistantChatPlanConfirm}.spec.js`, `tests/e2e/onb04-*.spec.js` | |

## 5. BASES CHIFFRÉES
`safe-test.sh --phpunit "Catalog|Composer|Purchasing"` → figer W0 · fidélité sur fixtures (C4) → mesurée W2 · coût par extraction réelle (W6) → mesuré.

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
| Gate | Question | Recommandation | Si non tranché |
|---|---|---|---|
| G-DATA | Tables `menu_drafts`, `assistant_actions` | oui | W2/W3 bloquées |
| G-IA | Fournisseur/modèle/plafond, clé existante réutilisée ? données envoyées = la carte seulement | OpenAI `gpt-4o-mini` (motif existant), plafond mensuel écrit | GOAL « mock-complet », W6 différée |
| G-CATALOGUE-MISSIONS | Liste des missions V1 autorisées/interdites | liste §5 T-4.1.1 du GOAL | liste par défaut |
| G-CACHE (ONB-05) | Entrée « Assistant » dans le menu | oui | page par URL |

## 7. RISQUES, PIÈGES, INSTRUMENTS
- **Injection de prompt par la carte** (texte sur la photo : « ignore les règles… ») : la sortie IA est un **JSON validé par schéma**, jamais exécutée ; missions issues d'un catalogue fermé.
- Ne pas confondre « l'IA a lu 8,50 » et « le prix est 8,50 » : c'est une donnée saisie validée par l'humain, taxée par le réglage par défaut (ONB-02).
- Mock-first : sans clé, tout doit converger ; W6 réel est optionnelle par écrit.
- Un doublon proposé n'est jamais fusionné automatiquement ; l'applicateur refuse une ligne invalide sans bloquer les autres.
- `:8000` = autre worktree ; ta session = **:8804**.

## 8. JOURNAL DE MISSION (rempli par la session)

### 8.1 Ce qui est LIVRÉ et prouvé

| Date | Livrable | Fichiers | Preuve | Verdict |
|---|---|---|---|---|
| 2026-08-27 | Contrat d'extraction + bouchon déterministe | `app/Services/Menu/Vision/{MenuExtractionContract,MockMenuExtractionService}.php` | `ExtractionCarteBouchonTest` (8) | **LIVRÉ** |
| 2026-08-27 | Choix bouchon / réel isolé hors `AppServiceProvider` (qui porte les gardes NF525) | `app/Providers/AssistantServiceProvider.php` | idem | **LIVRÉ** |
| 2026-08-27 | Lecture → proposition → validation humaine → application | `MenuExtractionController`, `app/Services/Menu/MenuDraftApplier.php`, routes `POST admin/assistant/menu/{lecture,application}` | `LectureDeCartePuisApplicationTest`, `ApplicationDUneCarteProposeeTest` | **LIVRÉ** |
| 2026-08-27 | Écran de validation | `resources/js/components/admin/assistant/MenuImportComponent.vue` | — | **LIVRÉ** |
| 2026-08-28 | **L'écran était ENTIÈREMENT MORT** : ses 3 appels redoublaient le préfixe `/api` déjà posé par `axios-setup.js:75`, et la route des taxes n'existe pas sous `admin/taxes` mais sous `admin/setting/tax`. Le `catch` vidait la liste en silence, le menu TVA restait vide, le bouton « Créer ces produits » restait grisé sans un mot. | `MenuImportComponent.vue` | `tests/js/lesUrlDesEcransExistent.spec.js` (3) — lit les routes RÉELLES via `php artisan route:list --json` | **FIXÉ** |
| 2026-08-28 | Doublon interne à une lecture rangé en « déjà dans votre carte » → **le second produit était perdu** en affirmant qu'il existait déjà | `MenuDraftApplier`, `MenuExtractionController::resume()` | `UnDoublonNEstPasUnArticleDejaLaTest` (4) | **FIXÉ** |
| 2026-08-28 | Catégories créées AVANT les articles → une catégorie dont tous les articles échouaient survivait **vide** sur la borne | `MenuDraftApplier` (création paresseuse) | idem | **FIXÉ** |
| 2026-08-28 | Banc **tautologique** du « double verrou » : il affirmait « deux verrous, jamais un » alors que les DEUX branches renvoient le bouchon — vert avec un verrou, zéro verrou, ou n'importe quelle condition | `ExtractionCarteBouchonTest` | Remplacé par la vérité mesurable + un garde qui ÉCHOUE le jour où une implémentation réelle apparaît (prouvé en déposant une implémentation factice) | **FIXÉ** |

### 8.2 Le chatbot de missions locales — LIVRÉ le 2026-08-28

Il n'existait pas (`grep` = zéro fichier), alors qu'il est explicitement au mandat
(§0.1 « chatbot de missions locales sur le profil ») et au périmètre de ce GOAL.

**Il ne dépend d'aucun gate.** La doctrine du programme est « la machine propose,
l'humain valide, le système applique ». L'interpréteur est DÉTERMINISTE : grammaire
déclarée, aucun appel sortant, refus explicite quand il ne comprend pas. Trois
raisons de ne pas y mettre un modèle aujourd'hui :

1. G-IA n'est pas tranché, et il porte surtout sur un **plafond de dépense** que ce
   projet n'a pas (`assistant.budget.plafond_mensuel_euros` vaut 0, délibérément).
2. Ces missions ÉCRIVENT dans le catalogue. « J'ai compris à peu près » sur cinquante
   produits se découvre trois jours plus tard, quand un client commande.
3. Le jour venu, un modèle remplacera UNIQUEMENT `InterpreteDeMission` — le plan, la
   confirmation et l'écriture validée ne bougent pas. Même architecture que
   l'extraction de carte : contrat, bouchon, implémentation réelle plus tard.

| Livrable | Fichiers | Preuve |
|---|---|---|
| Grammaire + refus nommant les formes comprises | `app/Services/Assistant/MissionLocale/{Mission,InterpreteDeMission}.php` | `UneMissionLocaleProposeAvantDEcrireTest` (12) |
| Plan (diff) sans aucune écriture, exclusions dites | `PlanificateurDeMission.php` | `…::test_la_phrase_exacte_du_mandat_est_comprise_et_ne_change_rien`, `…::test_le_plan_dit_ce_qu_il_ecarte…` |
| Application via `ItemRequest` / `ItemExtraRequest` | `ExecuteurDeMission.php` | `…::test_le_changement_de_prix_passe_par_les_regles_du_catalogue` |
| Routes `POST admin/assistant/mission/{lecture,application}`, garde `items_edit` | `MissionLocaleController.php`, `routes/api.php` | `route:list` |
| Écran (fil de conversation, plan, confirmation) | `resources/js/components/admin/assistant/MissionLocaleComponent.vue` | `tests/js/lAssistantDeMissionsEstAtteignableEtProposeAvantDEcrire.spec.js` (6) |

**Trois missions comprises aujourd'hui** : ajouter une option (sauce, supplément…) à
toute une catégorie, gratuite ou payante ; fixer le prix de toute une catégorie ;
activer ou désactiver toute une catégorie. Tout le reste est refusé en NOMMANT les
formes connues.

**Ce que la construction a révélé, et qui comptait plus que le chatbot lui-même :**
`grep -rn "admin.items.import" resources/js/` hors routeur rendait **zéro** résultat.
L'écran d'import de carte, livré le 27, n'a JAMAIS eu de lien — atteignable seulement
en tapant son URL, exactement ce que l'audit ONB-05 reproche à la page TVA. Les deux
écrans ont désormais leur porte depuis le Studio, et un banc l'exige.

Trois sentinelles existantes ont mordu pendant cette livraison, et elles avaient
raison : deux classes d'icônes n'existaient pas dans la fonte (`lab-message-line`,
`lab-upload-line`) — mes boutons auraient affiché un carré vide ; et le fil d'Ariane
rend `$t('menu.' + breadcrumb)`, pas `label.` — la page aurait affiché sa clé brute.

**Mesure honnête de ce qui reste** : la grammaire est volontairement étroite. Elle
couvre l'exemple du mandat et deux gestes voisins. L'élargir se fait forme par forme,
chacune avec son banc ; deviner à la place du commerçant, jamais.

### 8.3 Ce qui reste au propriétaire (ne pas trancher ici)

- **G-IA** — fournisseur, clé, et surtout **plafond de dépense** : le projet n'a aujourd'hui aucun compteur de coût, et `assistant.budget.plafond_mensuel_euros` vaut 0 par défaut, ce qui est délibéré. Tant que ce n'est pas tranché, `MockMenuExtractionService` reste la seule implémentation, et un garde le vérifie désormais.

**État final ONB-04 : les DEUX livrables du périmètre sont en place.** La chaîne
extraction → validation → application est éprouvée et corrigée de quatre défauts
(dont un écran entièrement mort et une perte silencieuse de produit) ; le chatbot de
missions locales est livré, avec son écran et sa porte. Reste au propriétaire le seul
gate G-IA — dont l'enjeu réel est le plafond de dépense, pas le fournisseur.
