# Plan de remédiation — caisse, gestion, borne, KDS et accessibilité

**Entrée :** `reports/audit/CAISSE_ADMIN_SYNC_ACCESSIBILITY_AUDIT_2026-08-23.md`  
**Règle de séquencement :** attendre la clôture ou une libération explicite du cycle Wheel actuellement en EXECUTE ; chaque lot ci-dessous doit ensuite être un `TASK_ID` et suivre `run-cycle`.

## Objectif de sortie

Obtenir une preuve répétable du parcours **borne → quote backend → paiement → KDS → suivi POS**, sans affaiblir le backend comme source de vérité de prix, l'isolation `branch_id`, les `OrderStatus`, ni l'outbox après commit. En parallèle, éliminer les barrières clavier/lecteur d'écran les plus visibles pour le caissier, le responsable et le client borne.

## Lot 1 — Restaurer la preuve E2E de synchronisation

**Priorité : P1 — à faire avant toute nouvelle affirmation de readiness multi-écrans.**  
**Responsable de raisonnement : Claude (plan/audit). Exécution : Codex complex selon le contrat FoodKing.**

1. Remplacer l'identifiant statique `361` de la suite Wave E par une fixture versionnée ou une résolution dynamique d'article vendable de la branche de test.
2. Vérifier explicitement catégorie, disponibilité par branche et prix avant l'appel de quote ; échouer avec un diagnostic métier lisible si le prérequis manque.
3. Scinder le parcours multi-produits en `test.step` : provisionnement, quote, store, confirmation paiement, apparition KDS, apparition POS, transition cuisine, annulation et nettoyage.
4. Remplacer le timeout global de six minutes par des délais courts, adaptés à chaque étape, et collecter URL, erreurs réseau, trace de commande et captures à l'échec.
5. Écrire les captures dans `test-results/` ou un répertoire temporaire par exécution, jamais dans un rapport/audit persistant. Mettre la suppression de fixture en `finally`.
6. Corriger le test multi-appareil en ciblant le tableau applicatif par rôle, conteneur ou `data-testid` ; ne jamais employer `locator('table')` au niveau document.

**Fichiers attendus :** uniquement les specs et helpers concernés, plus la configuration/seed nécessaire ; aucune modification de code de prix ou de cycle de commande sans nouveau plan.  
**Validation :** deux exécutions consécutives vertes sur une base isolée, plus conservation d'un artefact par étape.  
**Gate :** si une réparation touche provisioning de branche, paiement, statut de commande ou outbox, arrêter et ouvrir un plan complexe avec les invariants explicités.

## Lot 2 — Rendre la borne utilisable intégralement au clavier

**Priorité : P1.**  
**Responsable de raisonnement : Claude (plan/audit). Exécution : Codex complex.**

1. Reconcevoir l'activation de la carte produit `KioskCategoriesComponent.vue` pour que l'action équivalente au clic soit une action native accessible au clavier.
2. Préserver un unique comportement pour clic, Entrée et Espace ; éviter qu'une carte bouton contienne un autre bouton invalide.
3. Conserver le bouton « ajouter » explicite ou séparer clairement « voir/configurer » de « ajouter », avec état indisponible et chargement annoncés.
4. Ajouter un test Playwright clavier : focus visible, activation, produit indisponible, produit avec configuration et prévention de double ajout.

**Invariants à revalider :** prix serveur, branch scoping du catalogue, commandes/quote inchangés, pas de logique prix frontend.  
**Validation :** axe/Playwright ou assertions DOM de rôle+nom, puis parcours borne sans régression du panier.

## Lot 3 — Accessibilité opérationnelle du POS

**Priorité : P2, correctif rapide mais à isoler de la logique de paiement.**  
**Responsable de raisonnement : Claude (plan/audit). Exécution : Codex complex ou routine seulement si le plan confirme l'absence totale d'invariant.**

1. Associer les labels visibles aux champs identité client, téléphone, nom/téléphone/adresse de livraison.
2. Ajouter des noms accessibles localisés aux actions uniquement iconiques, dont l'effacement d'adresse confirmée.
3. Remplacer le retrait d'outline sans compensation par un style `:focus-visible` contrasté et homogène POS.
4. Vérifier les attributs adaptés (`autocomplete`, type téléphone, erreurs/liste d'adresse) sans modifier la validation ou les données envoyées.

**Validation :** test de nom accessible et focus sur chacun des champs, essai clavier de livraison, smoke POS et suite de prix/remise inchangée.  
**Interdit :** aucun calcul de prix ou statut métier côté frontend.

## Lot 4 — Dashboard accessible au responsable

**Priorité : P2.**  
**Responsable de raisonnement : Claude (plan/audit). Exécution : Codex, patch Vue limité.**

1. Remplacer les quatre `span @click` de préréglages de date par l'action accessible recommandée par le composant Datepicker ou un bouton natif.
2. Conserver l'intitulé, l'état sélectionné et la mise à jour de plage existants.
3. Écrire une régression clavier couvrant les quatre cartes (résumé commandes, statistiques, ventes, clients).
4. Étudier séparément l'agrégation des alertes et le nettoyage de données de démonstration : décision produit/ops préalable, pas de suppression de données par défaut.

**Validation :** Tab/Entrée/Espace, lecture de nom accessible, mise à jour correcte des données sans erreur réseau.

## Lot 5 — Clôturer le cas Wheel et la cohérence de navigation

**Priorité : P1, propriétaire : cycle Wheel actif.**

1. Ne pas intervenir avant la fin de son cycle ou une autorisation de périmètre.
2. Corriger les deux traductions rendues brutes.
3. Décider si Wheel est une surface SPA standard ou volontairement plein écran ; adapter alors son contrat de test sans masquer une page blanche/erreur.
4. Rejouer `dashboard-nav-buttons-reachability` et consigner 34/34.

## Lot 6 — Finition qualité d'interface

**Priorité : P3.**  
**Responsable de raisonnement : Claude (audit UX). Exécution : Codex par petits lots.**

1. Remplacer progressivement `transition: all` par les propriétés réellement animées.
2. Étendre la vérification `prefers-reduced-motion` aux écrans caisse, paiement, borne et KDS.
3. Ajouter une revue visuelle mobile/tablette et une session avec lecteurs d'écran réels avant la release finale.

## Ordre et critères de clôture

1. Lot 1 : rendre la preuve de sync fiable.
2. Lot 5 : laisser le cycle Wheel fermer son propre défaut, puis valider 34/34 routes.
3. Lots 2 et 3 : supprimer les blocages clavier au point de vente et à la borne.
4. Lot 4 : rendre le pilotage dashboard atteignable au clavier.
5. Lot 6 : optimisation UX et mouvement.

Une certification de parcours complet nécessite, dans le même environnement isolé :

- deux exécutions consécutives vertes de la suite borne/KDS/POS réparée ;
- smoke caisse/KDS vert ;
- test multi-appareil vert sans dépendance au Debugbar ;
- navigation admin 34/34 après Wheel ;
- audit Claude puis audit final Codex PASS ;
- aucune violation constatée des invariants prix, `OrderStatus`, `branch_id`, dispatch après commit et parité `OrderService`/`FrontendOrderService` si l'un de ces services a été touché.
