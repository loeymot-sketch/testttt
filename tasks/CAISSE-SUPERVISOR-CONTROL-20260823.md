# Task — CAISSE-SUPERVISOR-CONTROL-20260823

## Objective

Corriger avec une précision maximale les défauts confirmés par l'audit superviseur du 2026-08-23, puis prouver les corrections par tests backend, frontend et parcours navigateur ciblés.

## Scope

- Santé POS : isolation fiscale exacte par `branch_id`, cache par branche, états de sonde honnêtes et fraîcheur visible.
- File hors-ligne héritée : empêcher tout rejeu infini ou toute promesse de synchronisation impossible, sans perdre silencieusement une trace locale.
- QA multi-écrans : aligner le serveur Playwright sur la base URL, supprimer les fixtures d'article codées en dur et rendre le timeout multi-produits diagnostique.
- Supervision : rendre les alertes SLA actionnables, hiérarchisées et explicitement fraîches ou périmées.
- Accessibilité/productivité : corriger les activateurs non sémantiques et les champs POS prioritaires sans modifier prix, paiement, statuts ou services de commande.

## Acceptance

- Un caissier ne voit que la santé fiscale de sa branche ; deux branches distinctes sont couvertes par un test de non-régression.
- Toute sonde indisponible remonte `unknown`/dégradé et ne peut pas produire un faux « Système OK ».
- Une ancienne entrée hors-ligne expirée ou définitivement non rejouable n'est plus retentée toutes les 30 secondes ; l'opérateur reçoit un état explicite et récupérable.
- `PLAYWRIGHT_BASE_URL` et le serveur démarré utilisent le même hôte/port ; les fixtures E2E sont résolues depuis les données de test.
- Les alertes SLA indiquent chargement, erreur, dernière actualisation, priorité et synthèse exploitable sans rendre 300 cartes coûteuses.
- Les actions et champs ciblés sont utilisables au clavier avec un nom et un focus visibles.
- Les invariants prix serveur, `OrderStatus`, `branch_id`, dispatch après commit et symétrie des services restent intacts.

## Human authorization

Après présentation du verdict superviseur et de la règle de séquencement, l'utilisateur a explicitement demandé : « corrige avec max précision et test ». Cette instruction libère le périmètre caisse pour un cycle distinct ; elle ne vaut pas approbation du gate UX Wheel, qui reste en attente et hors scope.
