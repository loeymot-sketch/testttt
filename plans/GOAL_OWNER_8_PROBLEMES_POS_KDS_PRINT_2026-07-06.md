# GOAL — 8 problématiques owner : boissons caisse, perf POS, notes KDS, oignon cuit, tickets, impression
Date : 2026-07-06 · Branche : pos/category-first-caisse-2026-06-23 · HEAD : 24e8a09c3
Mission slug : `owner-8-problemes` · Rapports : `reports/test-e2e/owner-8-problemes/`

## §0 Préambule
- **§0.1 Working tree** : sources non-commitées d'autres sessions (routes/api.php, KioskIdleScreen…) → NE PAS committer ; chaque commit = fichiers explicites. Vieux staging `.env.bak*` (secrets AWS) déjà dé-stagé — gate owner G4.
- **§0.2 Pipeline par tâche** : `ultra-audit-profond` (audit 5 spécialistes → TDD → RED → visual). Validation finale : `test-e2e` adversarial jusqu'à convergence (2 cycles propres, P0+P1=0).
- **§0.3 Rapports externes owner** (`/Users/1millnonstop/Downloads/FoodKing_Audit_20260706/`) = ADVISORY. Chaque claim repro-vérifié avant action (verify-before-trust). Déjà invalidé partiellement : QUEUE_CONNECTION=redis en local (.env:20).
- **§0.4 Convergence** : P0+P1=0 sur 2 cycles consécutifs identiques, frozen diff 0, CHAIN OK, Vitest+PHPUnit verts, captures analysées.

## §1 Map des systèmes (ancres vérifiées 2026-07-06)

| # | Système | Ancre vérifiée | État |
|---|---|---|---|
| S1 | Wizard caisse — boissons | `public/js/pos-wizard.js:920-985` step `boisson_choice` EXISTE (gated boissonItems non-vide, GENERIC_OPTION_REGEX filtre « Boisson/Boisson Seule ») ; `PosComponent.vue:537,2189` drinksCatalog ; DB `item_addons` role=drink → addon_item_id=3 générique partout | Step présent, DATA manquante |
| S2 | Data boissons | DB items (11 boissons), noms à vérifier « Hawaï »/« Fuze Tea » ; seeders boissons (commit 56e196b4e) | Renommage + addons |
| S3 | Perf POS | `resources/js/store/index.js:3` vuex-persistedstate ; bundle monolithique (rapport A1-A5 à vérifier) ; `.env:20` QUEUE=redis local | Audit-first |
| S4 | Images viandes caisse | pos-wizard.js:4388+ `.viande-suppl-toggle` = pastilles couleur CSS (FROZEN) ; images réelles dispo `public/images/kiosk-attract/` + menu_images | Gate assets owner |
| S5 | Notes/remarques KDS | `kdsSymbolic.js:113-116` ne lit instruction QUE pour sauce frites ; KdsOrderLine/KdsOrderCard : 0 rendu de note client ; PHP `cleanInstruction` (KitchenTicketSymbolicFormatter:271) existe pour le ticket | Fix JS |
| S6 | Oignon cuit O̲ | Symboles STO : `kdsSymbolic.js` + `KitchenTicketSymbolicFormatter::cruditeSymbol:96` ; extras/crudités dans composition_snapshot | Nouvelle option + parité |
| S7 | Boissons sur KDS/ticket cuisine | KdsOrderLine (lignes symboliques) + renderer cuisine ; boissons actuellement absentes cuisine | Fix affichage |
| S8 | Ticket borne = design caisse | `kioskPrinter.js` (client-side builder) vs `EscPosTicketBytesService`/`OrderReceiptEscPosRenderer` (serveur, utilisé caisse) — unification connue en reste (memoire ticket_widthsafe) | Unifier vers serveur |
| S9 | Impression 20 s + écran gris + flash terminal | `posLocalPrinter.js:49-70` health 800ms / raw 5000ms, fallback `window.print()` (= écran gris) ; flash terminal = relanceur pont machine (VBS caché déjà documenté cowork) | Diag + fix timeouts |

## §2 Sous-systèmes & tâches

### S1+S2 — Boissons wizard caisse + data (Wave 2)
Contract : au step formule (Menu Complet frites+boisson / Boisson), la caisse propose la VRAIE liste des 11 boissons (mêmes items que la borne, même item_id, stock partagé). Nom « Hawaï » (pas « Fanta Hawai »). Prix : boisson INCLUSE dans le prix formule (décision owner de câbler — attention `menuRoleAdjustedAddonPrice` : role sans préfixe `menu_` = prix plein).
- T-2.1 Audit : pourquoi boissonItems vide sur sandwich/burger/tacos — dump `item_addons` d'un item type (22), tracer `addonItems` → wizard. anchor: pos-wizard.js:920-985 (READ-ONLY), PosComponent.vue:2189. test: (TO BE CREATED tests/Feature/Pos/PosMenuDrinkChoiceTest.php)
- T-2.2 Renommage : « Fanta Hawai » → « Hawaï » ; vérifier « Fuze Tea » exact ; DB + seeder + images slug (`Item::thumb` = menu_images.items.<slug>). anchor: seeder boissons commit 56e196b4e. test: (TO BE CREATED tests/Feature/Menu/DrinkNamingTest.php)
- T-2.3 DATA : attacher les 11 boissons réelles en addons des items menu-capables avec role/pricing menu-inclus correct (option : role `menu_drink` si le pricing backend le supporte, sinon extension PricingService NON-frozen ? PricingService EST frozen → si logique pricing à changer ⇒ LOCK owner-gaté G1). Vérifier borne NON régressée (kioskDrinkAddons.js symétrie).
- T-2.4 Si le step wizard nécessite modif code frozen pos-wizard.js ⇒ `lock-plan` + gate owner G1 (owner a PRÉ-mandaté le câblage boissons — LOCK documentaire quand même).
- Acceptance : e2e caisse — sandwich → Menu Complet → liste 11 boissons choisissable → panier/ticket/KDS montrent la boisson ; devis backend = prix formule inchangé ; tests ci-dessus + tests/Feature/Pos/ existants verts ; borne : commande boisson OK (non-régression).

### S5+S6+S7 — Écran cuisine : notes, oignon cuit, boissons (Wave 3)
- T-3.1 Notes client sur KDS : afficher `instruction` nettoyée (réutiliser la logique `sanitizeKdsInstruction`/parité `cleanInstruction`) sur la carte. anchor: KdsOrderLine.vue, kdsSymbolic.js:113. test: (TO BE CREATED tests/js/kdsInstructionDisplay.spec.js)
- T-3.2 Option « oignon cuit » : crudités par produit + oignon CRU (défaut) ⊕ CUIT exclusifs ; symbole O souligné (rendu : `O̲` U+0332 ou `<u>O</u>`/ESC-POS underline) à côté de STO. Parité PHP (`cruditeSymbol`) + JS (kdsSymbolic) + fixture parité regénérée. anchor: KitchenTicketSymbolicFormatter.php:96, kdsSymbolic.js. tests: tests/js/kitchenParityRealData.spec.js (existant) + (TO BE CREATED tests/Unit/Hardware/OnionCookedSymbolTest.php). Wizard : si le toggle exige pos-wizard.js ⇒ inclure au LOCK G1 (même vague de gel).
- T-3.3 Boissons visibles cuisine : lignes boisson sur carte KDS ET ticket cuisine (préparation), y compris commandes borne. anchor: renderer cuisine OrderReceiptEscPosRenderer:289-324 + KdsOrderCard. test: (TO BE CREATED tests/Feature/Hardware/KitchenTicketDrinksTest.php)
- Acceptance : capture KDS analysée (note visible, O̲ à côté de STO, boisson listée) + ticket cuisine décodé + parité 391-rows verte.

### S8+S9 — Impression : unification borne + instantanéité + flash (Wave 4)
- T-4.1 DIAG chrono réel : pourquoi ~20 s ? tracer clic Imprimer → bytes → pont (health 800 ms → raw 5 s → fallback window.print = écran gris). Hypothèse : pont absent/mal configuré → cascade timeouts + dialog. anchor: posLocalPrinter.js:29-70, ReceiptComponent (fallback). test: mesure avant/après loggée au rapport.
- T-4.2 Fix : impression asynchrone non-bloquante (fire-and-forget + toast résultat), timeouts serrés, JAMAIS de window.print automatique (fallback = bouton manuel explicite), zéro gel UI. test: (TO BE CREATED tests/js/posPrintNonBlocking.spec.js)
- T-4.3 Ticket borne = MÊME design que caisse : borne consomme le renderer serveur (`GET .../escpos` PosTicketBytesController) au lieu du builder client kioskPrinter.js. anchor: kioskPrinter.js, PosTicketBytesController. test: (TO BE CREATED tests/Feature/Hardware/KioskTicketServerParityTest.php — bytes borne == bytes caisse modulo en-tête)
- T-4.4 Flash terminal : côté code, vérifier qu'AUCUNE page ne lance de process/console ; le flash récurrent = relanceur du pont machine → runbook VBS caché (déjà écrit, message cowork) = gate owner G3. Documentation seulement côté repo.
- Acceptance : impression caisse < 2 s pont présent, 0 dialog gris pont absent ; ticket borne rendu identique caisse (bytes décodés comparés).

### S3+S4 — Perf POS + images viandes (Wave 5)
- T-5.1 VÉRIFIER les claims externes A1-A5/B1-B3/C1-C2/D1-D3 un par un (repro locale : taille bundle, cache headers, poids images, profiling interaction). Rejeter les faux. anchor: webpack.mix.js, store/index.js:3.
- T-5.2 Quick-wins sûrs validés : split bundle POS/borne du back-office si A1 confirmé ; fingerprint/cache si A2/A3 confirmés ; compression images produits (WebP/resize) si A4 confirmé ; throttle/scope persistedstate si B1 confirmé. Chaque fix = tâche scindée + mesure avant/après. tests: appBundleFreshness sentinels existants + (TO BE CREATED tests/js/sentinels/posBundleBudgetSentinel.spec.js)
- T-5.3 Images RÉELLES viandes caisse : remplacer pastilles couleur par photos réelles (source : photos owner si dispo — gate G2 ; sinon images items existantes). Touche pos-wizard.js (FROZEN) ⇒ inclure au LOCK G1.
- Acceptance : mesures chiffrées avant/après (TTI POS, poids transfert, latence ajout-panier) au rapport ; visuel wizard viandes avec photos analysé.

### Wave 6 — Convergence finale
`test-e2e` 3 vagues (caisse wizard+perf / cuisine KDS+tickets / borne flux+impression) + superviseurs adversaires, boucle P0+P1=0 ×2 cycles. Puis rebuild bundles, script deploy, message cowork machines.

## §A Armée d'agents
Fan-out par tâche selon matrice ultra-audit-profond (frontend visual : Architect+Security+UX+Implementer+RED+QA/RED Visual ; backend : +DBA). Implémenteurs JAMAIS en parallèle sur mêmes fichiers. Rapports sur disque `reports/test-e2e/owner-8-problemes/<wave>-<role>.json`.

## §X Vagues
| Wave | Scope | Parallélisme | Checkpoint |
|---|---|---|---|
| 1 | Pre-flight : branche backup, baselines (PHPUnit count, audit_logs hash), repro des 9 symptômes | séquentiel | baselines commitées au rapport |
| 2 | S1+S2 boissons caisse + naming | audit fan-out ∥, implém séquentiel | e2e boisson choisie + prix OK + commit |
| 3 | S5+S6+S7 cuisine (notes, O̲, boissons) | ∥ avec W4 (fichiers disjoints SI W2 close) | captures + parité + commit |
| 4 | S8+S9 impression | ∥ avec W3 | chrono <2 s + bytes parité + commit |
| 5 | S3+S4 perf + images viandes | séquentiel après W2-4 (touche bundle global) | mesures avant/après + commit |
| 6 | Convergence test-e2e adversarial | fan-out ∥ | 2 cycles propres + tag |
Interrupt-resume : commit WIP `wip(owner8-wN)` + manifest `INTERRUPT_<wave>.md` + BRAIN §2.

## §G Gates owner
| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G1 | LOCK frozen pos-wizard.js (boissons step data-hook, oignon-cuit toggle, images viandes) — owner a PRÉ-mandaté dans le goal | Claude rédige LOCK, owner countersigne implicite (mandat goal) | LOCK_POSWIZARD_OWNER8.md | plans/ + commit tag | PRE-APPROVED (mandat goal 2026-07-06) |
| G2 | Photos réelles viandes (si owner veut SES photos vs images items existantes) | Owner physique | fichiers images | public/images/ | OPEN — défaut = images items existantes |
| G3 | Machines : pont caché VBS + hard-reload (flash terminal) | Owner/cowork physique | photos preuve | reports/handoff/ | PENDING (runbook déjà livré) |
| G4 | Purge .env.bak* + rotation clés AWS | Owner physique | rotation receipt | BRAIN §2 | PENDING |
| G5 | Deploy VPS final + test vraies machines | Owner (script fourni) | log deploy | scratchpad script | PENDING fin de goal |

## §F Règle finale
DONE = les 9 symptômes owner reproduits en W1 sont NON-reproductibles en W6, convergence adversariale 2 cycles, frozen diff 0 (hors LOCK G1 documenté), CHAIN OK ×4, mesures perf chiffrées, commits scopés non poussés (gate owner) + script deploy prêt.
