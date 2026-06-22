# Rapport d’écarts systémique — Adaptation du design POS (4) v4 à FoodKing

**Mission :** POS_V4_ECARTS_RAPPORT_001  
**Date :** 2026-04-25  
**Nature :** second avis API sur les manques restants  
**Modèle :** gpt-5.5-h/pro via `codex-terminal` (mission `POS_V4_ECARTS_RAPPORT_001`)  
**Second avis :** §6 **Claude Code** (orchestrateur — décision priorités)  
**Périmètre :** analyse documentaire uniquement, sans modification applicative (hors ce fichier)  
**Objet :** identifier ce qui manque encore pour considérer que le design pack externe POS (4) v4 est adapté aux objectifs FoodKing.

## Synthèse courte

Le design pack POS (4) v4 peut être une base visuelle utile, mais il ne garantit pas encore que le POS FoodKing fonctionne comme un hub opérationnel cohérent avec le kiosque, le KDS, l’administration, l’isolation par branche, le pricing backend SSOT et le cycle de vie des commandes.

L’écart principal n’est pas seulement esthétique. Il porte sur la traduction du design en comportements vérifiables : états de commande, files cuisine, paiements, reçus, commandes parkées, plan de salle, erreurs offline, contraintes de branche et cohérence avec les autres surfaces FoodKing. Avant de déclarer le design adapté, il faut une carte de binding explicite, des décisions produit fermées, des critères d’intégration et une validation orientée flux réels de caisse.

---

## 1) Alignement avec le but global FoodKing

### Axe 1 — Caisse : le POS comme outil de vente rapide et sûr

Le design ne garantit pas encore que la caisse reste utilisable dans les moments critiques : rush, erreur de paiement, reprise d’une commande parkée, impression de reçu, modification du panier ou changement de table.

Manques principaux :

- absence de définition précise du comportement attendu pour le total courant, les remises, taxes, frais, remboursements ou annulations ;
- absence de preuve que les montants affichés restent strictement dérivés du backend, sans règle de prix ad hoc côté interface ;
- absence de parcours priorisés pour paiement échoué, paiement partiel, paiement relancé, reçu réimprimé ;
- absence de critères de performance caisse : temps de rendu, temps d’ajout article, temps de recherche produit, temps de reprise d’une commande parkée ;
- absence de hiérarchie claire entre actions primaires de caisse : encaisser, parker, envoyer cuisine, imprimer, annuler, retourner au plan de salle.

Le design peut montrer une caisse attractive, mais il ne prouve pas encore qu’elle est robuste opérationnellement.

### Axe 2 — Sync cuisine : le POS ne doit pas casser le flux KDS

FoodKing ne peut pas traiter le POS comme un écran isolé. Une action caisse peut déclencher ou modifier un flux cuisine. Le design ne documente pas encore suffisamment les états visibles côté POS lorsque la cuisine est concernée.

Manques principaux :

- pas de représentation stable de la file cuisine : à envoyer, envoyé, en préparation, prêt, servi, erreur d’envoi ;
- pas de règles visuelles pour distinguer une commande payée mais non envoyée, envoyée mais non payée, ou parkée avant envoi ;
- pas de contrat clair sur les actions autorisées selon l’état de commande ;
- pas de preuve que les statuts affichés correspondent à l’énumération autoritative du backend ;
- pas de prise en compte explicite du principe “dispatch après commit” pour les événements ou jobs liés aux commandes.

Un design adapté doit rendre impossible, ou au minimum très visible, une divergence entre l’état POS et l’état KDS.

### Axe 3 — Kiosque : cohérence client, cohérence prix, cohérence parcours

Le kiosque et le POS peuvent exposer les mêmes produits, options, prix, taxes et disponibilités, mais pas dans le même contexte utilisateur. Le design pack ne garantit pas encore cette cohérence.

Manques principaux :

- pas de comparaison systématique POS / Kiosk pour les cartes produit, options, tailles, suppléments, indisponibilités et messages d’erreur ;
- pas de règle de cohérence visuelle entre prix kiosque et prix caisse lorsque le backend renvoie les mêmes montants ;
- pas de traitement explicite des cas où un article disponible au POS est indisponible au kiosque, ou inversement ;
- pas de mention des contraintes de parité éventuelle entre OrderService et FrontendOrderService si les flux commande étaient touchés ;
- pas de parcours clair pour commandes commencées ailleurs, reprises ou visibles au POS.

Le design ne doit pas recréer une logique commerciale parallèle. Il doit consommer et afficher les décisions backend.

### Axe 4 — Branche : isolation opérationnelle et sécurité des données

FoodKing impose une isolation par `branch_id`. Le design pack ne démontre pas encore comment cette contrainte devient visible, testable et non ambiguë dans le POS.

Manques principaux :

- pas d’indicateur fiable de branche active pour éviter les erreurs de contexte ;
- pas de comportement décrit en cas de changement de branche, session expirée ou branche non autorisée ;
- pas de règles d’affichage pour tables, commandes parkées, reçus, paiements et produits limités à une branche ;
- pas de scénario de validation garantissant qu’aucune commande d’une autre branche n’apparaît dans les listes POS ;
- pas de séparation visuelle ou fonctionnelle des données branchées dans les écrans sensibles.

Un design adapté à FoodKing doit rendre la branche courante explicite sans surcharger l’interface.

### Axe 5 — Conformité : lifecycle, auditabilité et comportements vérifiables

Le design pack ne contient pas encore assez d’éléments pour prouver que l’interface respecte le cycle de vie réel des commandes et les contraintes de gouvernance du dépôt.

Manques principaux :

- pas de binding map reliant composants design, composants repo, routes, contrôleurs, services et tests ;
- pas de définition des états autorisés et transitions interdites ;
- pas de stratégie d’erreurs : offline, API 403, paiement refusé, imprimante absente, KDS indisponible ;
- pas de distinction nette entre décision produit, intention design et faisabilité technique ;
- pas de critères de validation rattachés aux tests existants.

Les tests réels à considérer incluent notamment : `tests/Feature/PosParkedOrderTest.php`, `tests/Feature/Pos/FloorplanControllerTest.php`, `tests/Feature/Pos/PosPurgeParkedScheduleTest.php`, `tests/Feature/Admin/POS/ReceiptPrintControllerTest.php`, `tests/Feature/PosUITest.php`, ainsi que les suites Vitest `tests/js/posComponent.spec.js`, `posParked.spec.js`, `posFloorplan.spec.js`, `posReceiptBuilder.spec.js` et `posPaymentItemsNormalize.spec.js`. Les noms `ParkedOrdersStoreTest` et `ReceiptServiceTest` cités par le README export semblent plutôt être des placeholders et ne doivent pas être traités comme preuve d’existence sans vérification repo.

---

## 2) Matrice des manques

| Dimension | Ce qui manque encore | Risque si non traité | Sortie attendue |
|---|---|---|---|
| Décision produit | Définition des priorités caisse : encaisser, parker, envoyer cuisine, imprimer, gérer table, reprendre commande. | Interface belle mais ambiguë en rush. | Décisions produit écrites par parcours critique. |
| Décision produit | Règles de comportement selon état de commande. | Actions interdites possibles ou statuts incohérents. | Tableau des transitions autorisées basé sur l’état backend. |
| Décision produit | Positionnement du POS face au kiosque : même catalogue, exceptions, indisponibilités. | Divergence prix / options / disponibilité. | Charte de cohérence POS-Kiosk. |
| Décision produit | Stratégie offline et dégradation partielle. | Caisse inutilisable ou dangereuse en incident. | Parcours offline, retry, blocage et reprise. |
| Design pack | Conflit chromatique signalé entre `#FF006B` et `#0084FF`. | Identité visuelle incohérente et priorités UI contradictoires. | Décision de palette et tokens validés. |
| Design pack | `preview.html` incohérent avec les attentes d’intégration. | Mauvaise interprétation de la cible réelle. | Preview corrigée ou déclarée non normative. |
| Design pack | Scope dark mode indéfini. | Travail design non borné et dette UI. | Décision : inclus, différé ou exclu. |
| Design pack | Prévisualisations JSX alors que l’intégration cible des SFC `.vue`. | Écart entre maquette et implémentation. | Correspondance explicite entre previews et composants Vue. |
| Gouvernance repo | Absence de binding map. | Impossible de relier design, fichiers, routes, tests et responsabilités. | `BINDING_MAP` validée avant intégration. |
| Gouvernance repo | Script export gelé et charte en tension. | Risque de modifier une zone gelée ou de propager un artefact externe non validé. | Gate explicite avant toute retouche en zone gelée. |
| Gouvernance repo | URL design API en 403. | Source externe non vérifiable ou non reproductible. | Copie exploitable, hashée ou documentée. |
| Intégration | Pas de preuve de respect pricing backend SSOT. | Règles prix dupliquées côté interface. | Contrat d’affichage prix dérivé backend uniquement. |
| Intégration | Pas de preuve d’isolation `branch_id`. | Fuite de commandes, tables ou reçus entre branches. | Scénarios et contrôles branchés. |
| Intégration | Pas de représentation complète du cycle KDS. | Cuisine désynchronisée du POS. | États KDS visibles et actions POS conditionnées. |
| Validation | Tests cités partiellement inexacts ou placeholders. | Fausse confiance dans la couverture. | Liste de tests réels et gaps de tests assumés. |
| Validation | Pas de critères de performance caisse. | POS lent malgré une interface conforme visuellement. | Budgets de performance et scénarios chronométrés. |
| Validation | Pas de validation end-to-end des flux reçus / paiements / parké / plan de salle. | Régressions sur flux opérationnels existants. | Jeux de scénarios E2E ou feature tests alignés. |

---

## 3) Priorisation P0 / P1 / P2 avec propriétaire suggéré

### P0 — Bloquants avant de dire que le design est adaptable

1. **Établir une binding map design → repo → tests**  
   **Propriétaire suggéré : lead tech**  
   Relier chaque écran ou bloc du design aux composants, routes, contrôleurs, services et tests réels. Sans cette carte, l’intégration reste spéculative.

2. **Fermer les décisions produit sur le cycle de commande POS**  
   **Propriétaire suggéré : produit**  
   Définir les états visibles, transitions autorisées, actions interdites et priorités caisse. Les statuts doivent rester alignés avec la représentation autoritative backend.

3. **Confirmer que tous les prix affichés viennent du backend**  
   **Propriétaire suggéré : lead tech**  
   Le design doit être compatible avec le pricing backend SSOT. Aucun calcul métier de prix ne doit être induit par la maquette.

4. **Définir l’isolation de branche dans les écrans POS**  
   **Propriétaire suggéré : lead tech + produit**  
   Les tables, commandes parkées, reçus, paiements et listes POS doivent être branchés. Le design doit prévoir les états de branche active, non autorisée ou expirée.

5. **Résoudre la cohérence visuelle et normative du pack**  
   **Propriétaire suggéré : design**  
   Trancher le conflit `#FF006B` vs `#0084FF`, clarifier `preview.html`, décider du statut des previews JSX et du scope dark mode.

6. **Documenter les états d’incident opérationnel**  
   **Propriétaire suggéré : produit + design**  
   Prévoir les états offline, paiement refusé, KDS indisponible, imprimante indisponible, API 403/session expirée.

### P1 — Nécessaire avant intégration sérieuse

1. **Comparer POS et Kiosk sur les surfaces communes**  
   **Propriétaire suggéré : produit + design**  
   Identifier les éléments devant être cohérents : catalogue, options, indisponibilités, prix, taxes, erreurs, messages de confirmation.

2. **Définir les budgets de performance caisse**  
   **Propriétaire suggéré : lead tech**  
   Fixer des seuils pour recherche produit, ajout panier, changement quantité, reprise parkée, chargement plan de salle et impression/reçu.

3. **Formaliser les parcours reçus et impression**  
   **Propriétaire suggéré : produit + lead tech**  
   S’assurer que le design couvre impression initiale, réimpression, erreur imprimante et cohérence avec l’administration POS.

4. **Aligner les scénarios de validation sur les tests réels**  
   **Propriétaire suggéré : lead tech**  
   Utiliser les tests existants connus : parked orders, floorplan, purge schedule, receipt print, POS UI, normalization paiement, receipt builder.

5. **Définir le comportement des commandes parkées**  
   **Propriétaire suggéré : produit**  
   Clarifier création, reprise, expiration/purge, rattachement table, paiement et envoi cuisine.

### P2 — Améliorations importantes mais non bloquantes si P0/P1 sont fermés

1. **Raffiner les micro-interactions de rush**  
   **Propriétaire suggéré : design**  
   Feedback tactile, raccourcis visuels, animations sobres, prévention double-clic paiement.

2. **Définir une stratégie dark mode si elle est retenue**  
   **Propriétaire suggéré : design + produit**  
   Décider si le dark mode est requis pour caisse, KDS, kiosque ou seulement certaines surfaces.

3. **Améliorer la documentation des composants transverses**  
   **Propriétaire suggéré : design + lead tech**  
   Tokens, composants partagés, règles responsive, accessibilité, états vides et chargements.

4. **Créer une grille de compatibilité matériel POS**  
   **Propriétaire suggéré : produit + lead tech**  
   Écrans tactiles, imprimantes, terminaux paiement, réseau instable, densité d’affichage.

5. **Ajouter des exemples de données réalistes**  
   **Propriétaire suggéré : produit**  
   Menus longs, options multiples, taxes, commandes multi-lignes, tables occupées, erreurs de paiement.

---

## 4) Convergence ou désaccord avec l’audit 2026-04-24 et le plan READINESS 2026-04-25

### Points de convergence

Ce second avis converge avec l’audit du 2026-04-24 sur plusieurs constats :

- l’export externe ne suffit pas à démontrer l’adaptation au contexte FoodKing ;
- la charte visuelle est en tension, notamment sur le conflit de couleurs ;
- le `preview.html` ne peut pas être pris comme source unique de vérité ;
- le mélange template + CSS et les previews non alignées avec l’architecture cible créent un risque d’intégration ;
- le script ou mécanisme d’export étant gelé, il ne faut pas corriger la source externe par opportunisme sans gate humain ;
- l’URL design API en 403 fragilise la reproductibilité de l’audit.

Ce second avis converge aussi avec le plan READINESS du 2026-04-25 :

- les phases et gates sont nécessaires avant intégration ;
- une définition de “design exploitable” est indispensable ;
- la `BINDING_MAP` doit devenir un artefact central ;
- l’ADR ou équivalent de décision est nécessaire pour éviter que le design soit interprété différemment par produit, design et technique ;
- le POS doit être évalué comme partie d’un système : commande, table, reçu, paiement, KDS, kiosque, administration.

### Nuances ajoutées par ce second avis

Ce rapport insiste davantage sur les conséquences opérationnelles :

- un design POS peut être visuellement prêt mais systémiquement dangereux s’il ne rend pas visibles les états de cuisine, paiement, branche et commande ;
- le risque pricing n’est pas un détail d’implémentation : il doit être évité dès le design en empêchant toute règle de prix implicite côté interface ;
- la branche active doit être conçue comme un contexte de sécurité et pas seulement comme un filtre technique ;
- les états d’incident ne sont pas secondaires pour une caisse : offline, paiement refusé, impression impossible et KDS indisponible doivent être designés ;
- la cohérence avec le kiosque doit être validée sur les données et les comportements, pas seulement sur les composants visuels.

### Désaccords éventuels

Aucun désaccord frontal n’est identifié avec l’audit 2026-04-24 ni avec le plan READINESS 2026-04-25. La différence est un renforcement du critère d’acceptation : un design ne devrait pas être considéré adapté tant qu’il ne prouve pas sa compatibilité avec les invariants métier et techniques FoodKing.

En pratique, la question ne doit pas être : “le pack est-il joli et intégrable ?” mais plutôt : “le pack permet-il d’exécuter sans ambiguïté une journée de caisse FoodKing complète, avec cuisine, kiosque, paiements, reçus, branches et erreurs ?”.

---

## 5) Liste de contrôle finale avant de dire “le design est adapté”

1. La binding map relie chaque écran design aux fichiers, routes, services et tests réels du repo.
2. Les prix affichés sont explicitement dérivés du backend, sans logique métier frontend ajoutée.
3. Les statuts de commande affichés correspondent à la représentation autoritative backend.
4. Les actions POS sont définies selon l’état de commande : autorisées, interdites ou nécessitant confirmation.
5. L’isolation `branch_id` est visible, testable et couverte pour tables, commandes, reçus et paiements.
6. Les flux KDS sont représentés : à envoyer, envoyé, en préparation, prêt, erreur ou équivalent validé.
7. Les commandes parkées couvrent création, reprise, rattachement, expiration/purge et paiement.
8. Le plan de salle est cohérent avec les commandes actives et la branche courante.
9. Les parcours paiement couvrent succès, refus, retry, doublon évité et erreur réseau.
10. Les reçus couvrent impression, réimpression, erreur imprimante et cohérence admin POS.
11. Les états offline, API 403/session expirée et KDS indisponible ont un traitement design explicite.
12. La cohérence POS-Kiosk est vérifiée pour catalogue, options, prix, disponibilités et messages.
13. Le conflit de charte `#FF006B` vs `#0084FF` est tranché et documenté.
14. Le scope dark mode est décidé : inclus, différé ou exclu.
15. Les scénarios de validation s’appuient sur les tests réels connus et ne citent pas des placeholders comme preuve.

---

## Conclusion

Le design pack POS (4) v4 n’est pas encore démontré comme pleinement adapté à FoodKing. Il peut servir de matière première, mais il lui manque des garanties systémiques : binding map, décisions produit, cohérence POS-Kiosk-KDS, isolation de branche, pricing backend SSOT, cycle de vie commande, états d’incident et validation par tests réels.

La prochaine décision utile n’est pas de modifier l’application, mais de fermer les écarts P0 : carte de correspondance, décisions de lifecycle, conformité pricing, isolation branche et normalisation design. Une fois ces points établis, le design pourra être évalué comme un candidat intégrable plutôt que comme un export externe encore ambigu.

---

## 6) Décision orchestrateur — Claude Code (terminal, 2026-04-25)

*Avis structurant complémentaire au second avis API ci-dessus. Source : exécution `claude -p` sur `AUDIT_…2026-04-24` + `PLAN_…READINESS_2026-04-25`.*

**Verdict (3 phrases).** Le v4 constitue une **direction** défendable (3 colonnes, tokens, process en 5 étapes, script gelé). Il **ne suffit pas** à la production tant que les gates Phase 0–1 ne sont pas fermées : **charte unique**, **binding map** documentée, **scope dark** borné. Tout merge SFC prématuré (Payment / Pos) expose la caisse à des **régressions silencieuses** (refs, clics, `branch_id` implicite).

| Sujet | Urg. (1–5) | Pourquoi |
|--------|------------|----------|
| ADR couleur `#FF006B` vs `#0084FF` | 5 | Bloque QA, `preview.html` non normatif tant que flou. |
| `BINDING_MAP_POS_V4` (100 % interactions) | 5 | Sans table, merge aveugle sur SFC critiques. |
| Modals Payment (découpe / ownership) | 4 | Surface sensible, testabilité, revue. |
| Noms de tests **réels** (repo, pas README export) | 4 | Sinon CI « fictive ». |
| Perfs caisse + scope dark (contamination admin) | 3 | Jank, `fk-dark` sans périmètre. |

**Décisions tranchées (synthèse).** (a) **Produit** : trancher la charte et le **périmètre dark (POS seul, pas toute l’admin)** avant la moindre retouche template. (b) **Ingénierie** : exiger **aucun merge** Pos/Payment sans binding map + suite de tests **vérifiée** + build ; critères perfs min avant merge catalogue. (c) **Hors lot** : tout sauf `template` + `style` des 5 SFC ; pas de `OrderService` / `OrderStatus` / `branch` API / prix Vue — arrêt + revue.

**Convergence requise avec GPT-5.5 (§1–4)** : alignement **total** sur (1) **pricing SSOT**, (2) **statuts d’ordre** sans invention UI, (3) **`branch_id`**. Tout désaccord sur ces points = **blocage**, pas compromis.

**Traçabilité** : `missions/POS_V4_ECARTS_RAPPORT_001/output_codex.json` ; `execution_trace.delegation` = `codex-terminal`.