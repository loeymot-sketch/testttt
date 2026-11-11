# MOBILE-HOME-ONBOARDING-20261111

## Owner request

Modernise l'application mobile Le Cayenne, indépendamment du site web :

- faire du Cayenne la suggestion principale de l'accueil ;
- afficher un rail horizontal "Meilleures ventes" sans descriptions, dans cet ordre : Cayenne, Tacos L, Terminator, Grill Burger, Big Burger, Bol Frites, Tacos XL ;
- unifier les teasers et l'onboarding autour des photographies réelles Le Cayenne fournies par le propriétaire, sans illustrations ni produits de substitution ;
- conserver une expérience de commande accessible et sans introduire de prix ou de règles métier côté mobile.

## Deferred, multi-surface owner requirements

Ces demandes sont tracées dans `docs/gates/GATE_CATALOG_POS_KDS_20261111.md` : nouveau produit 5 Tenders à 5 €, Sandwich Classique 2 viandes à 9 €, supplément Façon Cayenne, sauces incluses, corrections crudités, options de viandes et propagation caisse/KDS.

## Acceptance criteria

1. L'accueil mobile affiche le Cayenne comme héro et ne montre pas sa description dans la carte.
2. Le rail horizontal est scrollable, lisible au tactile et respecte strictement l'ordre propriétaire lorsque les produits sont publiés par le catalogue.
3. Les quatre écrans d'onboarding possèdent une esthétique cohérente et ne dépendent plus d'illustrations de produits incompatibles avec le thème photo.
4. Aucun prix, nombre de viandes, supplément ou règle de sauces n'est défini ou modifié côté mobile dans ce cycle.
5. Les tests statiques mobile concernés et la vérification manuelle de l'onboarding passent.
