# PLAN POS V4 IMPL MASTER — Ré-audit & intégration design

**Date** : 2026-04-26  
**Mission** : `POS_V4_IMPL_MASTER_001`  
**Double validation** : contenu structurant **§1–14** = second avis **GPT-5.5 (pro / high) via `codex-terminal`** ; **§15** = **re-audit terminal Claude** (orchestrateur) — *à lire ensemble*.  
**Statut** : plan maître de référence pour l’**exécution** (supersède la granularité opérationnelle de `PLAN_POS_V4_EXPORT_READINESS_2026-04-25` sans annuler l’**historique** ni les invariants).  
**Preuve** : `missions/POS_V4_IMPL_MASTER_001/output_codex.json`

Documents entrants :

- `plans/PLAN_POS_V4_EXPORT_READINESS_2026-04-25.md`
- `reports/audit/RAPPORT_ECARTS_DESIGN_POS_V4_SYSTEMIQUE_2026-04-25.md`

## 0. Décision exécutive

### Verdict

Le POS v4 est **apte à devenir un POS SaaS compétitif** à condition que l’intégration design soit menée comme une stabilisation produit complète, et non comme un simple remplacement visuel.

Le plan entrant `PLAN_POS_V4_EXPORT_READINESS_2026-04-25` est validé dans son intention : il cible correctement la réduction d’écarts entre design, gabarit, styles et expérience d’encaissement. Le rapport systémique `RAPPORT_ECARTS_DESIGN_POS_V4_SYSTEMIQUE_2026-04-25` est également validé comme base de diagnostic, notamment sur les écarts structurels entre composants, états, accessibilité et comportement opérationnel.

Ce plan maître les **supersède** en apportant :

- une séquence d’exécution plus fine par flux parallèles ;
- des micro-sorties vérifiables tous les 1 à 2 jours ;
- un ordre de fusion SFC basé sur le risque ;
- des KPI comparables à une concurrence POS SaaS réelle ;
- une matrice de tests reliée explicitement aux SFC ;
- une red-team de modes d’échec avant export readiness.

### Position produit attendue

Le POS v4 doit atteindre un niveau où un opérateur peut :

- ouvrir une session et identifier le contexte de vente sans ambiguïté ;
- rechercher, sélectionner et modifier des produits avec un minimum de friction ;
- récupérer d’une erreur sans perdre le panier ;
- finaliser l’encaissement avec un temps-to-cash stable ;
- comprendre les statuts et blocages sans formation longue ;
- utiliser les interactions principales au clavier et avec aides techniques.

Le succès ne se mesure pas à la fidélité visuelle seule, mais à la combinaison : **vitesse, robustesse, apprentissage court, accessibilité, absence de divergence métier**.

## 1. Portée et limites

### Portée autorisée

Ce plan couvre uniquement :

- les gabarits / templates POS v4 ;
- les styles POS v4, dont `pos-v4.css` ;
- la préparation d’une intégration SFC ordonnée ;
- la validation fonctionnelle côté UI autour du design intégré ;
- la documentation des gates et tests associés.

### Hors portée

Sont explicitement hors portée :

- modification de logique backend de prix ;
- introduction de règles de prix côté frontend ;
- changement non autorisé des statuts de commande ;
- modification de scripts gelés ;
- dispatch de jobs/events ;
- requêtes ou écritures multi-branches non documentées ;
- refonte des services commande sans plan de parité `OrderService` / `FrontendOrderService`.

### Règle de priorité

En cas de conflit entre fidélité design et invariants métier, les invariants métier priment toujours. L’UI doit afficher, guider et empêcher les erreurs ; elle ne doit pas réinventer le métier.

## 2. Validation et amélioration des documents entrants

### 2.1 Validation de `PLAN_POS_V4_EXPORT_READINESS_2026-04-25`

Points validés :

- orientation vers une préparation export / readiness plutôt qu’un simple patch visuel ;
- reconnaissance de la nécessité d’aligner template, style et design system ;
- prudence sur les zones gelées ;
- conservation du backend comme source de vérité.

Améliorations apportées par le présent plan :

- ajout de vagues `W0` à `W4` avec sorties vérifiables en 1 à 2 jours ;
- séparation du travail en workstreams parallélisables ;
- ajout d’un ordre de merge SFC avec score de risque ;
- ajout de KPI concurrentiels mesurables ;
- ajout d’une red-team structurée ;
- ajout d’une matrice PHPUnit / Vitest liée aux SFC.

### 2.2 Validation de `RAPPORT_ECARTS_DESIGN_POS_V4_SYSTEMIQUE_2026-04-25`

Axes validés :

- écarts de structure entre design cible et template existant ;
- dette de style autour des états actifs, erreurs, loading et focus ;
- risques d’incohérence d’expérience entre composants ;
- insuffisance probable de tests UI sur les scénarios d’encaissement ;
- besoin d’une checklist de validation export.

Améliorations apportées par le présent plan :

- traduction des écarts en flux d’exécution actionnables ;
- priorisation par risque d’intégration SFC ;
- ajout de critères quantitatifs ;
- formalisation des propriétaires de gates ;
- rattachement des tests aux composants affectés.

## 3. Smart breakdown

### 3.1 Workstreams parallèles

#### Workstream A — Structure & shell POS

Objectif : stabiliser le squelette de l’interface POS v4 sans toucher aux règles métier.

Périmètre :

- layout global ;
- zones header, catalogue, panier, paiement, messages ;
- états responsive principaux ;
- hiérarchie visuelle ;
- focus management visible côté template/style.

Livrables :

- shell cohérent sur desktop POS ;
- conteneurs nommés et classes alignées ;
- structure prête pour intégration SFC progressive.

#### Workstream B — Catalogue, recherche, sélection produit

Objectif : rendre l’exploration produit rapide, lisible et résiliente.

Périmètre :

- grille / liste produits ;
- recherche ;
- catégories ;
- états vide / loading / erreur ;
- interaction clavier minimale ;
- affichage des prix venant du backend uniquement.

Livrables :

- parcours de sélection fluide ;
- feedback visuel sur produit ajouté ;
- aucune règle de pricing côté UI.

#### Workstream C — Panier, édition ligne, récupération erreur

Objectif : sécuriser la manipulation du panier et la correction des erreurs opérateur.

Périmètre :

- lignes panier ;
- quantités ;
- suppression / annulation visuelle ;
- messages de validation ;
- états désactivés ;
- récupération après action impossible.

Livrables :

- panier lisible à forte densité ;
- erreurs récupérables sans perte de contexte ;
- total affiché en cohérence avec backend.

#### Workstream D — Paiement, finalisation, accessibilité et QA

Objectif : finaliser l’expérience d’encaissement avec qualité exportable.

Périmètre :

- panneau paiement ;
- sélection moyen de paiement ;
- confirmation ;
- états succès / échec ;
- accessibilité ;
- tests Vitest / PHPUnit associés ;
- gates de sortie.

Livrables :

- parcours time-to-cash mesurable ;
- états de fin compréhensibles ;
- matrice de tests prête pour export readiness.

## 4. Vagues d’exécution W0-W4

Chaque vague doit produire une micro-sortie vérifiable en 1 à 2 jours. Les workstreams peuvent avancer en parallèle lorsque les gates précédents sont respectés.


| Vague                               | Durée cible | Objectif                                                             | Workstreams actifs | Micro-exit obligatoire                                                                             |
| ----------------------------------- | ----------- | -------------------------------------------------------------------- | ------------------ | -------------------------------------------------------------------------------------------------- |
| W0 — Baseline & garde-fous          | 1 jour      | Figer le périmètre, cartographier SFC/styles, confirmer zones gelées | A, D               | Liste des fichiers autorisés, invariants relus, screenshot baseline ou état de référence documenté |
| W1 — Shell design intégré           | 1-2 jours   | Aligner structure globale et zones principales                       | A                  | Shell POS utilisable sans régression de navigation majeure                                         |
| W2 — Catalogue & panier utilisables | 1-2 jours   | Intégrer sélection produit et panier avec états visuels              | B, C               | Ajouter/modifier/supprimer une ligne panier avec feedback clair et prix SSOT conservé              |
| W3 — Paiement & récupération erreur | 1-2 jours   | Stabiliser finalisation, erreurs et états de blocage                 | C, D               | Scénario paiement succès + scénario erreur récupérable validés                                     |
| W4 — Export readiness               | 1-2 jours   | Finaliser a11y, tests, revue red-team, gates                         | A, B, C, D         | Matrice tests verte ou écarts documentés, gates signés, dette résiduelle classée                   |


### Critères de sortie globaux W4

- Aucune règle de calcul de prix introduite côté frontend.
- Aucun statut de commande magique ajouté.
- Aucun flux ne traverse `branch_id` sans autorisation explicite.
- Aucun dispatch nouveau introduit avant commit.
- Les scripts gelés ne sont pas modifiés.
- Les tests critiques liés aux SFC modifiés sont verts ou les écarts sont explicitement acceptés par gate owner.

## 5. Ordre de fusion SFC avec score de risque

Score : 1 = faible, 5 = critique. Le risque combine complexité UI, proximité métier, impact sur encaissement, accessibilité et probabilité de régression.


| Ordre | SFC / zone logique                       | Risque | Rationale                                                                                                         | Gate avant merge            |
| ----- | ---------------------------------------- | ------ | ----------------------------------------------------------------------------------------------------------------- | --------------------------- |
| 1     | POS shell / layout racine                | 3      | Impact large mais logique métier limitée ; nécessaire pour stabiliser les autres merges                           | Gate structure A            |
| 2     | Header / contexte session / branche      | 4      | Risque de confusion opérationnelle si branche/session mal affichée ; invariant `branch_id` indirectement sensible | Gate contexte branche       |
| 3     | Catalogue produits                       | 3      | Fort usage, mais risque métier limité si les prix restent affichés depuis backend                                 | Gate pricing SSOT           |
| 4     | Recherche / filtres / catégories         | 2      | Risque surtout UX/performance ; faible risque métier si lecture seule                                             | Gate état vide/loading      |
| 5     | Carte produit / tuile produit            | 3      | Interaction fréquente ; risque d’afficher prix ou disponibilité incohérents                                       | Gate affichage prix backend |
| 6     | Panier / lignes panier                   | 5      | Zone critique : quantité, suppression, totaux, erreurs utilisateur                                                | Gate panier C               |
| 7     | Résumé total / taxes / remises affichées | 5      | Très sensible au pricing SSOT ; aucune règle frontend admise                                                      | Gate pricing strict         |
| 8     | Panneau paiement                         | 5      | Dernière étape time-to-cash ; erreurs coûteuses ; états async sensibles                                           | Gate paiement D             |
| 9     | Modal confirmation / reçu / succès       | 4      | Peut induire double validation ou statut ambigu                                                                   | Gate OrderStatus enum       |
| 10    | Messages erreurs / notifications POS     | 4      | Recovery critique ; risque de masquer une erreur backend                                                          | Gate red-team recovery      |
| 11    | États loading / skeleton / disabled      | 3      | Risque d’action double ou confusion si états incomplets                                                           | Gate anti-double-action     |
| 12    | Accessibilité focus / clavier            | 4      | Impact formation, conformité et rapidité opérateur                                                                | Gate a11y                   |
| 13    | Responsive / densité écran               | 2      | Impact utilisabilité multi-écrans, faible risque métier                                                           | Gate visual QA              |
| 14    | Nettoyage CSS `pos-v4.css`               | 3      | Risque cascade/régression visuelle globale                                                                        | Gate CSS diff               |


### Politique de merge

- Ne pas merger une zone de risque 5 sans test ou checklist ciblée.
- Ne pas merger le paiement avant stabilisation panier + total affiché.
- Ne pas merger les états succès / reçu avant validation des statuts autoritatifs.
- Les merges de style purs restent soumis aux captures avant/après sur les scénarios critiques.

## 6. Edge vs concurrence : KPI mesurables

Les KPI ci-dessous servent à comparer le POS v4 à une solution POS SaaS compétitive, sans promesse marketing vague.


| Axe              | KPI                                          | Cible W4                | Méthode de mesure                          | Pourquoi c’est concurrentiel                             |
| ---------------- | -------------------------------------------- | ----------------------- | ------------------------------------------ | -------------------------------------------------------- |
| Time-to-cash     | Temps médian pour vendre 3 produits et payer | <= 45 s opérateur formé | Chronométrage scénario standard            | Les POS SaaS performants réduisent la friction en caisse |
| Time-to-cash     | Nombre d’actions UI pour vente simple        | <= 10 actions           | Comptage clics/touches                     | Moins d’actions = moins de files d’attente               |
| Error recovery   | Récupération après ajout mauvais produit     | <= 2 actions            | Scénario suppression/correction            | Correction rapide sans abandon panier                    |
| Error recovery   | Perte de panier après erreur paiement        | 0 perte                 | Test erreur paiement simulée               | Un POS compétitif préserve le travail opérateur          |
| Training surface | Concepts visibles nécessaires pour encaisser | <= 5 concepts           | Audit labels/actions                       | Réduit onboarding saisonniers                            |
| Training surface | Ambiguïtés de libellé critiques              | 0                       | Revue UX + test opérateur                  | Évite erreurs en situation de rush                       |
| A11y             | Navigation clavier du parcours vente simple  | 100% étapes principales | Test clavier manuel + Vitest si applicable | Améliore robustesse et conformité                        |
| A11y             | Focus visible sur actions critiques          | 100% actions critiques  | Audit visuel                               | Prévention erreurs et usage assisté                      |
| Fiabilité        | Double-submit paiement empêché               | 100% scénarios async    | Test UI + backend si existant              | Évite commandes doublées                                 |
| Cohérence prix   | Écarts frontend/backend                      | 0                       | Assertion affichage issu source backend    | Garantie métier non négociable                           |


## 7. Red-team : 8 modes d’échec et mitigations


| #   | Mode d’échec                                                     | Impact                                      | Détection                                | Mitigation                                                                      |
| --- | ---------------------------------------------------------------- | ------------------------------------------- | ---------------------------------------- | ------------------------------------------------------------------------------- |
| 1   | Le design introduit un total recalculé côté UI                   | Prix faux, pertes financières, rupture SSOT | Revue diff + tests panier/total          | Affichage uniquement depuis données backend ; interdiction de règle frontend    |
| 2   | Un statut de commande est comparé en string magique              | Succès/échec mal interprété                 | Revue code + tests statut                | Utiliser enum / représentation autoritative uniquement                          |
| 3   | Le contexte branche est masqué ou ambigu                         | Vente associée à mauvaise branche           | QA contexte session + tests si existants | Afficher branche/session clairement ; aucune requête cross-branch non autorisée |
| 4   | Paiement double-cliquable pendant chargement                     | Commandes ou paiements doublés              | Test async double action                 | État disabled/loading strict sur action critique                                |
| 5   | Erreur paiement vide le panier                                   | Perte opérationnelle, frustration           | Scénario erreur paiement                 | Préserver panier et afficher action de récupération                             |
| 6   | CSS POS v4 casse un autre écran                                  | Régression hors périmètre                   | Diff CSS + smoke visuel                  | Scoper les sélecteurs POS v4 ; gate CSS avant merge                             |
| 7   | Accessibilité focus absente sur actions critiques                | Erreurs clavier, non conformité             | Audit clavier                            | Styles focus visibles et ordre de tabulation logique                            |
| 8   | Les tests couvrent les composants isolés mais pas le flux caisse | Fausse impression de readiness              | Matrice tests par scénario               | Ajouter tests parcours : sélection, panier, paiement, erreur recovery           |


## 8. Gates de validation


| Gate                      | Moment        | Owner                         | Critères d’entrée                    | Critères de sortie                                              | Blocant                       |
| ------------------------- | ------------- | ----------------------------- | ------------------------------------ | --------------------------------------------------------------- | ----------------------------- |
| G0 — Scope & frozen zones | Avant W0      | Tech Lead / Orchestrateur     | Mission et fichiers autorisés connus | Aucune zone gelée touchée sans clearance                        | Oui                           |
| G1 — Invariants métier    | W0            | Backend owner + Tech Lead     | Invariants listés                    | Pricing SSOT, OrderStatus, branch_id, dispatch commit respectés | Oui                           |
| G2 — Shell POS            | Fin W1        | Frontend owner                | Layout intégré                       | Navigation principale stable, pas de régression évidente        | Oui                           |
| G3 — Catalogue            | Fin W2 part B | Frontend owner + QA           | Catalogue stylé                      | Recherche/sélection/états vides validés                         | Non, sauf régression critique |
| G4 — Panier & total       | Fin W2 part C | Backend owner + QA            | Panier intégré                       | Totaux affichés cohérents backend, pas de calcul UI nouveau     | Oui                           |
| G5 — Paiement             | Fin W3        | Backend owner + QA            | Panier stable                        | Succès, erreur, loading et anti-double-action validés           | Oui                           |
| G6 — Accessibilité        | W3-W4         | QA / Accessibility owner      | Flux fonctionnel                     | Clavier, focus, contrastes critiques validés                    | Oui pour actions critiques    |
| G7 — Tests                | W4            | QA + Tech Lead                | Matrice définie                      | Tests verts ou écarts acceptés                                  | Oui                           |
| G8 — Export readiness     | Fin W4        | Orchestrateur / Product owner | Gates G0-G7 traités                  | Go export ou report motivé                                      | Oui                           |


## 9. Matrice de tests PHPUnit + Vitest liée aux SFC

Cette matrice n’impose pas de nouveaux tests hors périmètre si les fichiers de test n’existent pas encore ; elle définit la couverture attendue pour déclarer la readiness.


| Type    | SFC / zone                          | Scénario                            | Assertion clé                                           | Invariant lié                   | Priorité |
| ------- | ----------------------------------- | ----------------------------------- | ------------------------------------------------------- | ------------------------------- | -------- |
| Vitest  | POS shell / layout racine           | Rendu initial avec contexte minimal | Zones catalogue, panier, paiement présentes             | branch_id indirect              | P1       |
| Vitest  | Header / contexte session / branche | Affichage branche/session           | Le contexte n’est pas vide ni ambigu                    | branch_id                       | P0       |
| Vitest  | Catalogue produits                  | Liste produits chargée              | Les produits sont rendus sans recalcul de prix          | pricing_ssot                    | P0       |
| Vitest  | Recherche / filtres                 | Aucun résultat                      | État vide lisible, pas d’erreur JS                      | Aucun                           | P2       |
| Vitest  | Carte produit                       | Ajout produit                       | Feedback visible et action déclenchée une fois          | pricing_ssot                    | P0       |
| Vitest  | Panier / lignes panier              | Modifier quantité                   | Ligne mise à jour, total affiché depuis source attendue | pricing_ssot                    | P0       |
| Vitest  | Panier / suppression                | Supprimer mauvais produit           | Panier reste cohérent, recovery possible                | pricing_ssot                    | P0       |
| Vitest  | Résumé total                        | Afficher taxes/remises              | Aucune règle de calcul locale nouvelle                  | pricing_ssot                    | P0       |
| Vitest  | Panneau paiement                    | Cliquer paiement pendant loading    | Bouton désactivé, pas de double action                  | commit_before_dispatch indirect | P0       |
| Vitest  | Modal confirmation / reçu           | Succès paiement                     | État final affiché selon statut autoritatif             | order_status                    | P0       |
| Vitest  | Messages erreurs                    | Erreur paiement                     | Panier conservé et message actionnable                  | pricing_ssot                    | P0       |
| Vitest  | Accessibilité focus                 | Parcours clavier vente simple       | Focus visible sur actions critiques                     | Aucun                           | P1       |
| PHPUnit | API / endpoint panier si existant   | Total panier retourné               | Total calculé backend cohérent                          | pricing_ssot                    | P0       |
| PHPUnit | API / endpoint commande si existant | Création commande branche courante  | Commande liée à la branche attendue                     | branch_id                       | P0       |
| PHPUnit | API / statut commande si existant   | Transition commande                 | Enum / représentation autoritative utilisée             | order_status                    | P0       |
| PHPUnit | API / paiement si existant          | Finalisation transactionnelle       | Effets post-commit uniquement si dispatch concerné      | commit_before_dispatch          | P0       |
| PHPUnit | API / erreur paiement si existant   | Échec paiement                      | Pas de perte de commande/panier incohérente             | pricing_ssot                    | P0       |


### Politique de tests

- Les tests P0 sont requis pour déclarer une zone critique prête.
- Les tests Vitest couvrent interaction, rendu, états et non-régression UI.
- Les tests PHPUnit couvrent les invariants backend quand un endpoint ou service est impliqué.
- Les tests ne doivent pas légitimer une règle métier frontend nouvelle.

## 10. Checklist de readiness par vague

### W0

- Fichiers autorisés confirmés.
- Scripts gelés identifiés.
- Invariants relus.
- SFC et styles affectés listés.
- Captures ou baseline disponibles.

### W1

- Layout principal stable.
- Zones critiques visibles.
- Aucune logique métier modifiée.
- Régression responsive majeure absente.

### W2

- Catalogue utilisable.
- Recherche/filtres avec états vides.
- Panier modifiable sans perte de cohérence.
- Total affiché sans calcul frontend nouveau.

### W3

- Paiement succès validé.
- Erreur paiement récupérable.
- Double-submit empêché.
- Statut final non ambigu.

### W4

- Tests P0 verts ou exception signée.
- A11y critique validée.
- Red-team passée.
- Gates signés.
- Dette résiduelle priorisée.

## 11. INVARIANTS_AT_RISK

Les invariants ci-dessous sont à surveiller activement pendant l’intégration POS v4.


| Invariant                | Risque dans POS v4                                                           | Règle d’exécution                                                                                                        | Gate associé |
| ------------------------ | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ | ------------ |
| `pricing_ssot`           | Le design peut inciter à recalculer prix, remises, taxes ou totaux côté UI   | Tous les nombres métier affichés doivent venir de la source backend autoritative ; aucune règle ad hoc frontend          | G1, G4, G7   |
| `order_status`           | Les écrans succès/échec peuvent utiliser des strings magiques                | Utiliser uniquement l’enum / représentation autoritative du codebase                                                     | G1, G5, G7   |
| `branch_id`              | Contexte branche peu visible ou requêtes non isolées                         | Aucun accès cross-branch non autorisé ; branche/session doivent rester explicites                                        | G1, G2, G7   |
| `commit_before_dispatch` | Paiement/finalisation peut déclencher effets avant commit si logique touchée | Aucun nouveau dispatch UI/backend dans cette intégration ; si un flux backend est touché, effets après commit uniquement | G1, G5, G7   |


## 12. SUBSYSTEMS

Sous-systèmes concernés par ce plan :


| Sous-système              | Niveau d’impact                               | Notes                                                                 |
| ------------------------- | --------------------------------------------- | --------------------------------------------------------------------- |
| POS v4 template / SFC     | Élevé                                         | Cœur de l’intégration design ; merge ordonné par risque               |
| `pos-v4.css` / styles POS | Élevé                                         | Risque de cascade ; scoping strict requis                             |
| Catalogue produits UI     | Moyen à élevé                                 | Lecture / sélection ; prix affichés depuis backend uniquement         |
| Panier UI                 | Élevé                                         | Zone critique pour quantité, suppression, totaux, recovery            |
| Paiement UI               | Élevé                                         | Zone critique pour time-to-cash, loading, erreurs, anti-double-action |
| Tests Vitest              | Moyen à élevé                                 | Couverture interaction et rendu des SFC                               |
| Tests PHPUnit             | Moyen                                         | Couverture invariants backend si endpoints/services impliqués         |
| Backend pricing           | Sensible mais non modifié                     | Source de vérité ; ne pas déplacer la logique                         |
| Order lifecycle           | Sensible mais non modifié sauf plan explicite | Statuts autoritatifs uniquement                                       |
| Branch/session context    | Sensible                                      | Affichage clair et isolation respectée                                |


## 13. Définition du prêt-à-export

Le POS v4 est prêt à l’export lorsque :

1. Les gates G0 à G8 sont signés ou les exceptions sont explicitement acceptées.
2. Les KPI critiques sont mesurés et n’ont pas de dérive bloquante.
3. Les tests P0 de la matrice sont verts ou justifiés par exception formelle.
4. Aucune dette critique ne concerne pricing, statuts, branche ou finalisation paiement.
5. Le design intégré améliore réellement la vitesse et la récupération d’erreur.
6. L’accessibilité minimale des actions critiques est validée.
7. Les zones gelées n’ont pas été modifiées sans clearance.

## 14. Décision de supersession

Ce document devient le plan maître de référence pour l’intégration design POS v4 à partir du 2026-04-26.

Il ne remplace pas les invariants projet ni les décisions humaines de gate. Il remplace, pour l’exécution POS v4, la séquence opérationnelle des documents entrants lorsqu’il y a divergence de priorisation ou de granularité.

Résumé :

- `PLAN_POS_V4_EXPORT_READINESS_2026-04-25` reste une source historique validée.
- `RAPPORT_ECARTS_DESIGN_POS_V4_SYSTEMIQUE_2026-04-25` reste le diagnostic d’écarts validé.
- `PLAN_POS_V4_IMPL_MASTER_2026-04-26` devient la référence d’exécution, de gates et de readiness.

## 15. Re-validation orchestrateur (Claude Code, 2026-04-26)

*Source : `claude -p` re-audite `PLAN_POS_V4_EXPORT_READINESS_2026-04-25` — à lire en complément des §0–14.*

**Verdict : ADOPTÉ avec amendements ciblés.**

- Phases *Docs → Faisabilité → Stabilisation → Intégration → Durcissement* : **validées** (alignement FoodKing : vision avant vitesse).
- Invariants (pricing SSOT, `OrderStatus`, `branch_id`) : **intacts**.
- **Amendement 1 — Parallélisation** : l’**ADR couleur (0.1)** et la **BINDING_MAP (1.1)** peuvent démarrer **en parallèle** dès J0 — point de **JOIN** obligatoire : *aucune Phase 2 sans ADR signé **et** binding map complète.*
- **Amendement 2 — Métriques** : vitesse caisse = **seuils** dans `TEST_PLAN_POS_V4` (ex. N clics add/pay/park, pas de re-layout sur grille en throttle CPU), pas seulement recommandation.
- **Amendement 3 — CSS** : enforcer un **namespace** (ex. `.fk-pos-v4`) dès le pack + garde-fou (grep/CI) pour éviter la contamination admin hors scope dark.

**Ordre SFC (Claude) vs §5 du plan maître** : **Receipt → Parked → Floorplan → Pos (principal) → Payment** en dernier (gate `human-verification` / E2E). Le plan maître §5 a une **granularité plus fine** (14 zones) ; l’**ordre logique** reste le même : finir le **paiement** après panier + totaux stables.

**Risque rollout (probabilité) :**


| Risque                           | P   | Mitigant                                              |
| -------------------------------- | --- | ----------------------------------------------------- |
| Binding orphelin (ref, `@click`) | H   | `BINDING_MAP` + review structurelle avant merge SFC   |
| Conflit couleur, QA invalide     | H   | Aucun merge SFC sans ADR produit + `preview` cohérent |
| Contamination dark hors POS      | M   | Namespace + check CI                                  |
| Logique mock (prix / statuts)    | M   | PR : zéro calcul Vue                                  |
| Régression flow paiement         | M   | Payment en dernier + QA sur matériel cible            |
| Jank caisse                      | L   | Budget perf + throttle avant merge lourd              |
| Dérive API / scope               | L   | `SYMMETRY` si `FrontendOrderService` touché           |


**Synthèse** : seuls **binding** + **charte** sont des risques **H** pouvant **invalider** le cycle **silencieusement** — à traiter comme **gates absolus** avant toute exécution lourde Phase 3.