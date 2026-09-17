# Gate Brief – ORDER-INTEGRITY-KDS-LOYALTY-20260914 – 2026-09-14

## Trigger

Le mandat requiert simultanément une migration de données, un changement du contrat de prix et de sceau commande, des fichiers frozen POS/Pricing/OrderService, un flux fidélité public et une vérification UX critique.

## Affected Subsystems

- `app/Services/Pricing/`, `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php`
- POS : composition, panier, encaissement, ticket
- bornes : fidélité, panier et commandes
- schéma / instantané fiscal de commande, reçu et KDS
- site web : texte d'information uniquement

## Invariants at Risk

- Prix : backend source de vérité ; aucun montant issu du navigateur n'est accepté.
- Isolation `branch_id`, immutabilité fiscale, dispatch après commit.
- Parité explicite `OrderService` / `FrontendOrderService`.
- Authentification et protection des données fidélité.

## Decision Required

Autoriser le cycle critique et le modèle de supplément libre : le caissier saisit un libellé optionnel et un montant ; le serveur l'autorise, le borne et l'enregistre comme ligne de vente immuable avec le traitement fiscal configuré, puis il apparaît sur le ticket.

## Options

1. Modèle approuvé — supplément libre fiscalisé, contrôlé et inscrit dans le sceau serveur. **Recommandé.**
2. Restreindre aux seuls suppléments du catalogue ; aucun montant libre.
3. Annuler le cycle.

## Approval

[x] Approved — option selected: 1
Approved by: Propriétaire — message « fait tout et deploy » du 2026-09-14, répondant explicitement à cette décision.
Date: 2026-09-14

---

## Resumption Protocol

L'autorisation écrite ci-dessus permet la planification et l'exécution bornées. Chaque modification conserve les contrôles de prix serveur, de fiscalité, de rôle opérateur et d'isolation de branche ; un nouveau risque hors périmètre ouvre un nouveau gate.
