# Mega plan d'audit et de correction - V1 finition FoodKing

Date : 2026-05-05  
Statut : STEP_1_PLAN_ONLY  
Perimetre : POS caisse, borne kiosk, KDS/OSS cuisine, backoffice commandes, admin produits/stock/tableaux de bord, preuves E2E et captures.  
Objectif : transformer les rapports Cursor + Codex en une sequence de correction page par page, puis permettre une execution controlee avec preuves visuelles et fonctionnelles.

## 1. Sources consolidees

### 1.1 Paquet audit Cursor

- `reports/audit/order-sync-journey-doc-2026-05-05/RAPPORT_AUDIT_SYNC_COMPLET_CONSOLIDE.md`
- `reports/audit/order-sync-journey-doc-2026-05-05/MANIFEST.json`
- `reports/audit/order-sync-journey-doc-2026-05-05/raw-trace.json`
- `reports/audit/order-sync-journey-doc-2026-05-05/screenshots/*.png`
- Test source : `tests/e2e/audit-max-sync-order-journey-documentation.spec.js`
- Helper commun : `tests/e2e/helpers/sync-journey-trace.js`

### 1.2 Rapports Codex / antigravity deja passes

- `reports/execution/RUN_V1-FINITION-FR-E2E-MASSIVE_2026-05-05.md`
- `reports/antigravity/d1-kiosk-design-audit.json` : `PASS_LOCAL_D1_SMOKE`
- `reports/antigravity/d2-pos-design-audit.json` : `PASS_LOCAL_D2_SMOKE`
- `reports/antigravity/d3-kds-oss-design-audit.json` : `PASS_LOCAL_D3_SMOKE`
- `reports/antigravity/d4-admin-management-design-audit.json` : `PASS_LOCAL_D4_SMOKE`
- `reports/antigravity/central-management-dashboard-crud.json` : `PASS_DASHBOARD_CRUD_RUNTIME_LOCAL`
- `reports/antigravity/c3-runtime-multi-surface.json` : `PASS_RUNTIME_LOCAL`
- `reports/antigravity/global-pos-kiosk-order-trace.json` : `PASS_GLOBAL_POS_KIOSK_TRACE`

### 1.3 Resultat global avant correction

Le coeur metier passe localement : creation de commandes POS et borne, transfert KDS, transitions de statuts, traces backend, fiscalisation locale et mouvements stock sont coherents dans les rapports.  
Le produit n'est pas encore "release-perfect" parce que plusieurs preuves visuelles et surfaces operationnelles restent ambiguës ou insuffisamment lisibles.

## 2. Invariants FoodKing a conserver

- Prix : aucune logique metier de prix ne doit etre deplacee cote frontend. Le backend reste la seule source de verite.
- `OrderStatus` : utiliser l'enum unique, jamais de chaines magiques ajoutees dans les corrections.
- `branch_id` : toute correction de liste, filtre, commande, KDS ou stock doit conserver l'isolation stricte par branche.
- Dispatch : aucun evenement/job avant commit DB.
- Parite `OrderService` / `FrontendOrderService` : si un flux de commande est modifie, verifier et noter explicitement la symetrie.
- Frozen zones, migrations, schema, auth profonde : pas d'edition sans gate approuve.

## 3. Synthese des ecarts detectes

### Ecart A - Preuves visuelles du test documentaire non fiables a certains points

Le manifeste montre plusieurs captures identiques alors qu'elles representent des etats differents :

- `05-pos-after-wizard-cancel.png`, `06-pos-cart-panel-open.png`, `07-pos-after-order-api-success.png`
- `09-pos-backoffice-order-list.png`, `10-pos-backoffice-queue-not-visible-in-grid.png`
- `26-kiosk-wizard-step-1.png`, `27-kiosk-after-api-order-and-confirm.png`
- `30-kds-initial-load.png`, `31-kds-pos-line-visible.png`, `32-kds-kiosk-queue-visible.png`, `33-kds-addon-name-visible.png`, `34-kds-pos-order-preparing.png`, `35-kds-pos-order-prepared.png`

Interpretation : le test backend est fort, mais la preuve screenshot ne prouve pas assez les changements d'etat. Il faut corriger le contrat du test avant de traiter toutes les captures comme preuve produit.

### Ecart B - POS backoffice ne montre pas la commande POS creee

La commande POS `#66`, queue `A86243273`, existe dans `raw-trace.json`, arrive au KDS et termine en status final `8`. Pourtant `/admin/pos-orders` montre un etat vide dans la capture du paquet Cursor.  
C'est le probleme operationnel le plus important de cette vague : un responsable doit pouvoir retrouver la commande sans fouiller les traces backend.

### Ecart C - Borne : capture grille categorie et confirmation de commande insuffisantes

La capture `23-kiosk-category-grid-target.png` montre encore `Demarrage en cours...` alors qu'elle est censee prouver la grille categorie cible.  
La capture `27-kiosk-after-api-order-and-confirm.png` est identique a l'etape wizard precedente, donc elle ne prouve ni confirmation, ni recu, ni retour operationnel.

### Ecart D - KDS : etats visibles et signal temps reel ambigus

Les captures KDS initiales et de transition sont en grande partie identiques. Une banniere indique `Connexion temps reel perdue` alors que le polling semble continuer. Les cartes affichent bien POS et borne, mais les badges et informations sont parfois serres ou superposes.  
Il faut que la cuisine voie sans ambiguite : file, source, recap produit, options, instructions, statut et action suivante.

### Ecart E - Admin / gestion : pas de blocage critique, mais dette de finition

Les audits D4 sont en `PASS_LOCAL`, sans chevauchement grave. Il reste beaucoup de petites cibles d'interaction, surtout :

- catalogue produits : `smallTargetCount` eleve
- dashboard / commandes caisse / commandes en ligne : petites actions a agrandir ou regrouper
- KDS : beaucoup de petites cibles, a traiter si la page cuisine reste dense

## 4. Discipline d'execution imposee

1. Corriger une vague a la fois, dans l'ordre ci-dessous.
2. Pour chaque vague : inspecter, corriger, tester, capturer, relire les captures.
3. Ne pas passer a la vague suivante si une preuve critique reste ambiguë.
4. Si deux validations consecutives echouent sur la meme vague, stopper et produire un gate brief.
5. Toute modification produit doit etre reservee dans `reports/AGENT_ACTIVITY_LOG.md` via `scripts/agent-activity-log.sh start`.
6. Les corrections doivent rester minimales et testables. Pas de refactor global.

Routing de plan :

- `CLAUDE` : audit, arbitrage, gate, verification des invariants, interpretation produit.
- `KIMI` : etiquette de sous-tache implementable selon la competence `report-to-plan`; dans ce depot, l'execution effective reste `codex-extension` sauf decision humaine contraire.

## 5. Vague 0 - Stabiliser le contrat de preuve E2E

Priorite : P0  
Routing : KIMI / codex-extension  
Risque : moyen, car un mauvais test peut masquer un mauvais produit.

### Pages concernees

- POS `/admin/pos`
- POS commandes `/admin/pos-orders`
- Borne `/kiosk/idle` et categorie cible
- KDS `/admin/kitchen-display-system`

### Fichiers a inspecter

- `tests/e2e/audit-max-sync-order-journey-documentation.spec.js`
- `tests/e2e/helpers/sync-journey-trace.js`
- `tests/e2e/global-pos-kiosk-order-trace.spec.js`

### Corrections attendues

1. Ajouter des assertions visuelles avant chaque capture critique.
2. Refuser les captures prises pendant un etat de chargement generique si la capture pretend montrer une grille ou une confirmation.
3. Attendre un changement observable avant les captures de transition KDS.
4. Comparer les hash des captures critiques en fin de test et faire echouer le test si deux captures semantiquement differentes sont identiques, sauf whitelist explicite documentee.
5. Ajouter dans le rapport genere une table `preuve visuelle` avec :
   - nom capture
   - assertion Playwright associee
   - texte principal detecte
   - etat attendu
   - resultat `OK` / `A_REVOIR`

### Criteres d'acceptation

- `23-kiosk-category-grid-target.png` ne montre plus un simple chargement.
- `27-kiosk-after-api-order-and-confirm.png` prouve une confirmation, un recu, une commande acceptee ou un retour explicite.
- Les captures KDS `34`, `35`, `36`, `37` montrent des etats differents quand les statuts changent.
- Les captures POS `06` et `07` ne sont plus identiques si elles representent des etats differents.
- Le manifeste signale explicitement les doublons autorises et echoue sur les doublons non autorises.

### Commandes de validation

```bash
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/audit-max-sync-order-journey-documentation.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/global-pos-kiosk-order-trace.spec.js --project=chromium
```

## 6. Vague 1 - Caisse POS et liste des commandes caisse

Priorite : P0  
Routing : KIMI / codex-extension  
Risque : eleve, car la visibilité backoffice de la commande est operationnelle.

### Page 1A - Caisse `/admin/pos`

Problemes observes :

- Capture `06-pos-cart-panel-open.png` montre un ticket vide alors que le nom annonce un panneau panier.
- Le test annule le wizard pour eviter une interception de pointeur sur le chip panier.
- La commande est creee par API, mais la preuve UI ne montre pas clairement le panier ou l'etat apres creation.

Hypotheses racine :

- Le wizard bloque le clic panier si son overlay n'est pas ferme proprement.
- Le test contourne une vraie friction UX.
- Le panneau ticket ne se met pas a jour avant la capture.

Fichiers probables a inspecter :

- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/admin/pos/PosCartComponent.vue`
- `resources/js/components/admin/pos/PosProductWizard*.vue`
- `resources/css/pos-v5.css`
- tests POS deja presents sous `tests/e2e/`

Corrections attendues :

1. Verifier que l'ajout au ticket via wizard est faisable sans clic bloque.
2. Garantir que le ticket affiche nom produit, quantite, variation, extras, instructions et total fourni par backend.
3. S'assurer que les textes sont en francais metier : pas de wording technique dans les actions visibles.
4. Si la commande est creee par API dans le test documentaire, renommer la capture ou ajouter une vraie preuve UI post-creation.

Acceptation :

- Le test peut ajouter un produit au ticket sans contourner l'UI principale.
- Le panneau ticket affiche le produit attendu et les options.
- Aucun calcul de prix metier n'est ajoute cote frontend.

### Page 1B - Commandes caisse `/admin/pos-orders`

Probleme observe :

- La commande POS existe backend/KDS mais la grille `/admin/pos-orders` reste vide dans l'audit Cursor.

Hypotheses racine a verifier :

- Filtre de date ou statut exclut la nouvelle commande.
- Filtre de source/type exclut les commandes POS.
- Rafraichissement de store absent apres creation.
- Pagination ou tri met la commande hors premiere page.
- Mauvaise cle `branch_id` ou contexte branche cote requete.
- Endpoint backoffice different de l'endpoint trace.

Fichiers probables a inspecter :

- `resources/js/components/admin/posOrders/PosOrderListComponent.vue`
- modules store Vue lies aux commandes POS
- controllers/API de liste POS orders
- routes admin POS orders
- ressources de traduction associees

Corrections attendues :

1. Identifier le chemin exact utilise par `/admin/pos-orders`.
2. Ajouter une assertion backend/UI dans le test : la queue creee doit etre visible ou recherchable.
3. Corriger le filtre ou le rafraichissement sans casser `branch_id`.
4. Afficher dans la grille un recap metier utile :
   - numero de file
   - canal : caisse / borne / livraison si pertinent
   - statut en francais
   - total
   - heure
   - action detail
5. Si la grille a un etat vide legitime a cause d'un filtre, afficher clairement le filtre actif et permettre `Reinitialiser`.

Acceptation :

- La queue POS du run audit est visible dans `/admin/pos-orders` en moins de 15 secondes, ou visible apres recherche automatique dans le test.
- La capture `10-pos-backoffice-queue-not-visible-in-grid.png` disparait ou devient une preuve positive de visibilité.
- Aucun contournement par suppression de filtre branche.

### Tests Vague 1

```bash
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/audit-max-sync-order-journey-documentation.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/global-pos-kiosk-order-trace.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/d2-pos-design-audit.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/pos-receipt-kds-instruction-sync.spec.js --project=chromium
```

## 7. Vague 2 - Borne kiosk

Priorite : P0  
Routing : KIMI / codex-extension  
Risque : moyen a eleve, car la borne doit etre autonome pour le client.

### Page 2A - Idle et choix du mode `/kiosk/idle`

Etat actuel :

- Les rapports D1 sont en `PASS_LOCAL_D1_SMOKE`.
- L'auth machine fonctionne dans le paquet Cursor.

Points a verifier :

- Tous les libelles visibles doivent etre francais client, pas technique.
- Le choix `sur place` doit mener a une grille stable.
- Les boutons doivent rester grands et sans chevauchement sur viewport borne.

### Page 2B - Categories et grille produit

Probleme observe :

- `23-kiosk-category-grid-target.png` montre encore un chargement.

Hypotheses racine :

- Le test capture trop vite.
- La route categorie profonde ne garantit pas que le menu soit charge.
- L'etat machine/auth redirige ou retarde le store menu.
- Les produits de fixture sont charges mais le selecteur d'attente est trop faible.

Fichiers probables a inspecter :

- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
- store kiosk/menu
- routes frontend kiosk
- helper d'auth machine dans les tests

Corrections attendues :

1. Renforcer l'attente Playwright sur un produit ou une categorie reelle.
2. Si la page reste reellement bloquee, corriger la sequence de chargement menu/auth.
3. Ne pas afficher un spinner permanent sans etat de secours.
4. Verifier que les noms produits/options affiches sont metier et comprehensibles.

Acceptation :

- La capture categorie cible montre au moins une carte produit exploitable.
- Le client peut ouvrir le wizard depuis la grille sans rechargement ou ecran noir.

### Page 2C - Wizard, panier, paiement, confirmation

Probleme observe :

- `26-kiosk-wizard-step-1.png` et `27-kiosk-after-api-order-and-confirm.png` sont identiques.
- Le rapport ne prouve pas visuellement la confirmation finale.

Corrections attendues :

1. Le wizard doit afficher les choix en francais : tailles, extras, boissons, instructions.
2. Le bouton suivant ne doit pas etre capture comme bloque sans raison metier visible.
3. Apres paiement simule/API, l'interface doit montrer un etat clair :
   - commande acceptee
   - numero de file
   - paiement carte confirme ou simulation TPE
   - retour automatique ou bouton retour accueil
4. Si le test cree volontairement la commande par API sans piloter le paiement UI, la capture doit etre renomme et le rapport doit l'indiquer. Mais pour la validation V1, preferer une vraie preuve UI.

Acceptation :

- Screenshot final borne different de l'etape wizard.
- Numero de file kiosk visible ou recapitulatif explicite visible.
- Aucune logique de prix ajoutee dans le frontend.

### Tests Vague 2

```bash
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/d1-kiosk-design-audit.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/audit-max-sync-order-journey-documentation.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/global-pos-kiosk-order-trace.spec.js --project=chromium
```

## 8. Vague 3 - KDS cuisine et OSS

Priorite : P0  
Routing : KIMI / codex-extension  
Risque : eleve, car la cuisine depend de la lisibilité immediate.

### Page 3A - KDS `/admin/kitchen-display-system`

Etat confirme par les traces :

- POS et kiosk arrivent au KDS.
- Les instructions, variation, extra et addon sont detectes dans les assertions.
- Les transitions status vont jusqu'a prepare.

Problemes visuels :

- Captures de transitions KDS identiques entre initial, ligne visible, queue visible, addon visible, preparing, prepared.
- Banniere `Connexion temps reel perdue` visible alors que le polling semble fonctionner.
- Badges de queue/source parfois trop serres.
- La liste gauche contient des donnees de fixture nombreuses qui peuvent polluer la lecture.

Fichiers probables a inspecter :

- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- composants de carte commande KDS
- store KDS / services de polling
- CSS KDS
- tests D3 et tests de synchronisation POS/KDS

Corrections attendues :

1. Clarifier le statut temps reel :
   - si websocket absent mais polling actif, afficher un message moins alarmant, en francais metier ;
   - si websocket attendu, verifier la config locale et ne pas masquer une vraie panne.
2. Garantir que chaque carte commande montre clairement :
   - file
   - source : caisse / borne
   - produits
   - variation
   - extras
   - instructions cuisine
   - statut actuel
   - prochaine action
3. Eviter chevauchements de badges et actions sur desktop et format cuisine.
4. Ajouter des attentes Playwright apres changement de statut :
   - `En preparation` visible pour la commande cible
   - `Preparee` visible pour la commande cible
   - card ou colonne modifiee avant capture
5. Nettoyer ou isoler les donnees fixture dans les tests pour que la capture ne soit pas noyee par des anciennes commandes.

Acceptation :

- La cuisine peut comprendre quoi preparer sans ouvrir un detail.
- Les captures `34`, `35`, `36`, `37` montrent des changements de statut ou de colonne.
- Le message temps reel n'effraie pas l'utilisateur si le mode polling est normal.

### Page 3B - OSS `/admin/order-status-screen`

Etat actuel :

- Le rapport Cursor indique que la page est chargee sous compte caisse si elle repond sous 10 secondes.
- Les rapports D3 sont en PASS avec peu de petites cibles sur OSS.

Corrections attendues :

1. Verifier que l'ecran client montre les numeros de file en francais clair.
2. Harmoniser les statuts avec l'enum backend, sans chaines magiques.
3. Ajouter au test documentaire une preuve que la commande POS ou kiosk apparait si le scope du test le permet.

Acceptation :

- Queue visible cote OSS ou raison documentee si l'ecran ne doit pas afficher cette commande dans le run.
- Aucun timeout silencieux transforme en PASS ambigu.

### Tests Vague 3

```bash
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/d3-kds-oss-design-audit.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/04-kds-status-flow.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/pos-receipt-kds-instruction-sync.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/audit-max-sync-order-journey-documentation.spec.js --project=chromium
```

## 9. Vague 4 - Admin, produits, stock et gestion globale

Priorite : P1/P2  
Routing : KIMI / codex-extension  
Risque : moyen, car ce n'est pas le blocage principal mais cela affecte la finition.

### Page 4A - Dashboard admin

Etat actuel :

- `PASS_LOCAL_D4_SMOKE`
- petites cibles restantes : environ 18

Corrections attendues :

1. Verifier que les tuiles principales et actions sont nommees en francais metier.
2. Agrandir les cibles d'action importantes.
3. Eviter les textes tronques dans les cartes denses.

Acceptation :

- Aucun chevauchement.
- Actions principales utilisables sur desktop et mobile raisonnable.

### Page 4B - Catalogue produits

Etat actuel :

- `PASS_LOCAL_D4_SMOKE`
- `smallTargetCount` tres eleve, environ 109.

Corrections attendues :

1. Prioriser les petites actions repetees dans les tableaux.
2. Regrouper les actions secondaires dans menus si necessaire.
3. Garder la densite admin mais rendre les commandes principales touchables.
4. Ne pas modifier pricing metier ni structure produit sans gate.

Acceptation :

- Reduction forte des petites cibles sur les actions produit.
- Les noms produits/categories restent lisibles.
- CRUD central reste PASS.

### Page 4C - Stock / rupture / inventaire

Etat actuel :

- `stock-rupture` a peu de petites cibles.
- Le trace audit prouve des mouvements stock coherents apres commandes.

Corrections attendues :

1. Verifier que le mouvement stock reste lie a la bonne branche.
2. Verifier que les libelles stock sont francais metier.
3. Ne pas toucher aux migrations.

Acceptation :

- Trace stock post-commande toujours coherente.
- Gestion stock visible et sans libelle technique.

### Page 4D - Commandes caisse et commandes en ligne

Etat actuel :

- `commandes-caisse` : petites cibles restantes.
- `commandes-en-ligne` : petites cibles restantes.

Corrections attendues :

1. Harmoniser les listes de commandes avec la correction `/admin/pos-orders`.
2. Afficher numero, canal, statut, paiement, heure et action detail.
3. Conserver l'isolation branche et les statuts backend.

Acceptation :

- Les listes admin sont coherentes entre POS, borne et online.
- Les statuts visibles sont francais et actionnables.

### Tests Vague 4

```bash
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/d4-admin-management-design-audit.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/central-management-dashboard-crud.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --project=chromium
```

## 10. Vague 5 - Validation globale et rapport final

Priorite : P0  
Routing : CLAUDE + codex-extension  
Risque : faible si vagues precedentes passent, mais indispensable pour close.

### Actions

1. Regenerer le paquet audit documentaire Cursor/Codex.
2. Recalculer le manifeste et verifier les doublons de captures.
3. Relire manuellement les captures dans l'ordre :
   - admin prelude
   - POS menu / panier / commande / tracker / liste commandes / OSS
   - borne idle / categories / wizard / paiement / confirmation
   - KDS initial / POS visible / kiosk visible / transitions / final
4. Relancer les rapports antigravity D1-D4.
5. Relancer les tests trace globaux.
6. Ecrire un rapport d'execution final avec preuves et ecarts restants.

### Matrice de validation finale

```bash
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/audit-max-sync-order-journey-documentation.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/global-pos-kiosk-order-trace.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/d1-kiosk-design-audit.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/d2-pos-design-audit.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/d3-kds-oss-design-audit.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/d4-admin-management-design-audit.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --project=chromium
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome npx playwright test tests/e2e/central-management-dashboard-crud.spec.js --project=chromium
npm run test:unit -- --runInBand
```

La commande `npm run test:unit -- --runInBand` doit etre adaptee si le depot utilise un autre script Jest/Vitest actif. Ne pas inventer un PASS si le script n'existe pas.

## 11. Definition de done V1 finition

La V1 finition peut etre consideree validee localement seulement si tous les points suivants sont vrais :

- POS : commande ajoutable, panier lisible, commande envoyee, liste `/admin/pos-orders` retrouve la queue creee.
- Borne : parcours client lisible de l'idle jusqu'a confirmation, sans capture de chargement comme preuve finale.
- KDS : POS et borne visibles, instructions cuisine lisibles, transitions d'etat visibles, badges non chevauchants.
- OSS : numero de file et statut client visibles ou comportement explicitement documente.
- Admin : produits, stock, commandes et dashboard sans chevauchement grave ; petites cibles restantes justifiees ou reduites.
- Backend : totals, payment status, fiscal sequence et mouvements stock restent coherents.
- Invariants : aucun prix frontend, aucune fuite `branch_id`, aucune chaine magique `OrderStatus`, aucun dispatch deplace avant commit.
- Rapports : audit documentaire, trace global, D1, D2, D3, D4, C3, central CRUD tous PASS.
- Captures : aucun doublon semantique non autorise dans les captures critiques.

## 12. Risques et controles

| Risque | Impact | Controle |
| --- | --- | --- |
| Corriger le test au lieu du produit | Faux sentiment de validation | Chaque correction de test doit avoir une assertion metier observable |
| Casser l'isolation branche dans les listes commandes | Grave | Verifier requetes et payloads avec `branch_id` |
| Ajouter du prix cote frontend pour afficher plus vite | Grave | Affichage uniquement a partir payload backend |
| Transformer les statuts en strings locales | Moyen/grave | Mapping d'affichage depuis enum/backend, pas logique metier |
| Masquer une panne realtime KDS | Grave cuisine | Distinguer polling de secours et websocket attendu |
| Trop elargir l'admin | Derive scope | P1/P2 apres P0, diffs minimaux |

## 13. Ordre d'execution recommande

1. Vague 0 : fiabiliser le test documentaire et le manifeste.
2. Vague 1 : POS caisse et `/admin/pos-orders`.
3. Vague 2 : borne, categorie, wizard, confirmation.
4. Vague 3 : KDS et OSS.
5. Vague 4 : admin produits/stock/commandes, seulement apres P0 stable.
6. Vague 5 : run global, captures, rapport final, audit externe.

## 14. Decision de cette passe

Cette passe ne modifie pas le produit. Elle fixe le plan de correction a executer ensuite.  
Le premier bloc a executer doit etre la Vague 0, car sans preuves screenshot fiables on ne peut pas juger proprement les corrections page par page.
