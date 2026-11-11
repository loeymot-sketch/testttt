# Gate Brief – CATALOG-POS-KDS-20261111 – 2026-11-11

## Trigger

Le propriétaire demande de modifier simultanément le catalogue partagé, les prix, les règles de personnalisation, la caisse et l'affichage cuisine. La caisse vanilla et le moteur de prix sont des zones gelées ; le catalogue doit demeurer la source de vérité commune au site, à l'application, à la borne, au POS et au KDS.

## Affected Subsystems

- Catalogue serveur et publications de menu
- Règles de viandes incluses et supplément viande
- Règles de sauces et suppléments
- POS caisse (wizard frozen), KDS et ticket cuisine
- Frontends web, borne et application mobile

## Invariants at Risk

- Prix calculés exclusivement par le backend
- Immutabilité du `composition_snapshot` après création de commande
- Affichage fiable et exhaustif des options sur le ticket cuisine
- Parité OrderService / FrontendOrderService si un flux de commande est touché
- Zones POS gelées

## État constaté avant décision

- Le mécanisme commun de viande supplémentaire existe déjà et facture actuellement **2,50 €** par extra ; il est conçu pour apparaître sur le ticket cuisine avec le nom de la viande.
- Les frites possèdent déjà une première sauce incluse et une seconde sauce à **0,50 €** via l'extra serveur ; cette règle est couverte par des tests caisse et cuisine.
- Le Sandwich Classique est actuellement configuré avec une seule viande incluse. Passer à deux viandes incluses à 9,00 € est donc un changement de règle explicite, pas une simple correction visuelle.
- « Façon Cayenne » et le prix particulier d'une viande de bol ne sont pas définis dans le catalogue courant ; ils ne doivent pas être devinés.

## Decision Required

Valider le périmètre métier exact ci-dessous afin qu'il puisse être planifié et exécuté côté serveur, puis projeté de manière identique sur le site, l'application, la borne, la caisse et le KDS.

## Options

1. **Approuver le catalogue et les règles telles qu'énoncées par le propriétaire** :
   - créer « 5 Tenders » à 5,00 €, sans sauce ni accompagnement ;
   - faire du Sandwich Classique un produit à 2 viandes incluses à 9,00 € ; toute viande additionnelle utilise le supplément serveur existant ;
   - ajouter « Façon Cayenne » comme supplément de composition ;
   - permettre les viandes supplémentaires pour les bols, avec le prix serveur confirmé par le propriétaire et un libellé KDS ;
   - rendre deux sauces sandwich gratuites ; garder une sauce frites incluse et la seconde payante ;
   - corriger les crudités : uniquement les choix effectivement demandés doivent apparaître comme sélectionnés et imprimés ;
   - renommer les libellés produit demandés (« steak haché frais », galette croustillante, base fromage et crème fraîche).
2. **Réduire le périmètre** à un sous-ensemble que le propriétaire indiquera.
3. **Annuler** les changements multi-surfaces et conserver seulement le travail visuel mobile.

## Approval

[x] Approved — option selected: 1
[ ] Cancelled
Approved by: Propriétaire Le Cayenne (instruction conversationnelle)
Date: 2026-11-11

### Confirmation tarifaire à compléter

- Prix de « Façon Cayenne » : 1,50 €
- Prix d'une viande supplémentaire dans un bol : 2,50 €
- À défaut d'instruction contraire, la seconde sauce frites reste à 0,50 € (règle existante).

## Resumption Protocol

Après approbation humaine et enregistrement dans `docs/gates/GATE_LOG.md`, créer le cycle serveur dédié avec plan de parité POS/KDS/site/borne/app et tests de bout en bout.
