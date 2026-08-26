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
| Date/heure | Vague | Tâche | Action | Preuve | Verdict | Commit |
|---|---|---|---|---|---|---|
| | W0 | | | | | |

Fiches de renvoi : ONB-02 (prévisualisation d'import, taxe par défaut, `ItemRequest` messages) · ONB-03 (schéma de règle, `applyTemplate`) · ONB-05 (menu, réglage `assistant.enabled`) · ONB-13 (journal unifié, idempotence, upload) · ONB-12 (fixtures génériques sans produit Cayenne) · État final : —
