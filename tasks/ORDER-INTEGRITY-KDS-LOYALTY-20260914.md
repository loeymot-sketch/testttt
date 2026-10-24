# Task — ORDER-INTEGRITY-KDS-LOYALTY-20260914

## Mandate

Corriger de bout en bout les incohérences de commande signalées par le propriétaire : inscription fidélité borne qui mène à une page blanche, options (sauces, viandes et suppléments) qui disparaissent ou modifient le total à l'encaissement, rendu ambigu en cuisine, édition POS qui perd des sauces et ajout encadré d'un supplément libre imprimé au ticket.

Le propriétaire a explicitement autorisé, le 2026-09-14 : « fait tout et deploy », en réponse à la demande de remplacement du cycle audit en cours et au modèle de supplément libre fiscalisé.

## Desired outcome

- Le montant visible, le montant scellé par le serveur, le paiement, le ticket et le KDS sont identiques pour toute composition valide.
- La borne ne peut ni se bloquer ni basculer vers un état incomplet quand un client fidélité est nouveau ou connu.
- Cuisine : `HH` pour Harissa, `X` pour Sans sauce, `Tacos` sans « Galette », et les sauces indiquent toujours leur produit ou leurs frites sans libellé générique ambigu.
- L'édition d'une ligne POS préserve toutes les sélections, notamment plusieurs sauces.
- Un opérateur habilité peut ajouter un supplément libre au montant et au ticket, avec libellé optionnel, sans contourner la source de vérité serveur ni la chaîne fiscale.
- Le site web communique honnêtement : emporter confirmé ; livraison par nos livreurs annoncée comme prochainement disponible, sans activer un flux de livraison inexistant.

## Boundaries

In scope: POS, kiosk loyalty, pricing/order sealing, receipt/KDS composition rendering, tests de contrat et E2E ciblés, copie du site concernée.

Out of scope: changement de tarifs catalogue, activation effective de livraison web, nouveaux prestataires, toute modification de paiement en ligne, toute suppression de données historiques.

## Mandatory verification

`playwright-critical-flow` pour borne fidélité, POS options/édition/encaissement, KDS/ticket ; tests PHP/JS ciblés puis suite proportionnée ; aucun paiement réel et aucune commande de production de test.
