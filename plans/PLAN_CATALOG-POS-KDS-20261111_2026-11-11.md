# Plan – CATALOG-POS-KDS-20261111 – 2026-11-11

## TASK_ID

CATALOG-POS-KDS-20261111

## EXECUTION_TIER

complex

## PRIOR_CONTEXT

- Le propriétaire a approuvé l'option 1 du gate `GATE_CATALOG_POS_KDS_20261111` et confirmé explicitement : viande supplémentaire 2,50 € et Façon Cayenne 1,50 €.
- `EnsureViandeSupplementExtrasCommand` porte déjà l'extra serveur générique à 2,50 € et le ticket cuisine sait restituer la viande réelle depuis l'instruction.
- `EnsureFritesSauceStepCommand` et `EnsureSauceSupplementExtrasCommand` portent déjà une sauce frites incluse, puis 0,50 € ; cette règle doit être préservée.
- Le mobile ne doit jamais fixer un prix ou une règle métier : il consomme le catalogue publié.

## GATE

`docs/gates/GATE_CATALOG_POS_KDS_20261111.md` — APPROVED, consigné dans `docs/gates/GATE_LOG.md`.

## SUBSYSTEMS_TOUCHED

| Subsystem | Scope | branch_id | Dispatch |
|---|---|---:|---|
| `app/Console/Commands/SyncOwnerCatalog20261111Command.php` | Nouvelle synchronisation idempotente des données catalogue approuvées et des extras | Catalogue global : aucun `branch_id` de produit inventé ; disponibilité reste filtrée par les services existants | `CatalogChanged::dispatch` après la transaction ; `branchId=null` uniquement pour l'invalidation globale autorisée |
| `config/menu_meat_rules.php` | Sandwich Classique = 2 viandes incluses ; aucun prix ajouté dans la config | No | No |
| `config/menu_sauce_rules.php` + ressource catalogue serveur concernée | Source serveur du nombre de sauces incluses par type de produit : sandwich=2, frites=1 | No | No |
| `app/Services/Pricing/PricingService.php` (frozen) | Refuse « Sauce supplémentaire » sur un sandwich et valide la deuxième sauce frites à partir de la règle serveur | No | No |
| `public/js/pos-wizard.js` (frozen) | Limite le sandwich à deux sauces incluses ; sérialise l'extra seulement pour les frites au-delà de la première et les crudités réellement cochées | No | No |
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`, `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue` | Même matrice publiée : sandwich maximum deux incluses ; frites première incluse puis extra serveur | No | No |
| `resources/js/helpers/kdsSymbolic.js` + `app/Services/Hardware/KitchenTicketSymbolicFormatter.php` | Affichage explicite de « Façon Cayenne » et des viandes en plus si le chemin existant ne le restitue pas | No | No |
| `tests/Feature/Catalog/**`, `tests/Unit/Hardware/**`, `tests/js/**`, `tests/e2e/**` | Tests data sync, prix serveurs, POS/borne/KDS et non-régression | fixture par branche | vérification après commit |

## SUBSYSTEMS_OFF_LIMITS

- `OrderService` et `FrontendOrderService` : exclus sauf si les tests prouvent une divergence de sérialisation ; alors réplanification avec note de symétrie obligatoire.
- Migrations et schéma : exclus. Le changement est entièrement idempotent sur les tables catalogue existantes.
- Images produits générées non approuvées : exclues. Le produit 5 Tenders réutilise l'asset réel existant `public/images/menu/tenders.png` tant qu'une photo propriétaire plus récente n'est pas fournie.

## INVARIANTS

- Backend catalogue, règle serveur de sauces et `PricingService` restent l'unique source de vérité prix.
- Aucune chaîne libre `OrderStatus` ; aucun changement de statut.
- Aucune fuite `branch_id` : les lectures/événements catalogue gardent le contrat de branche existant.
- Tout événement catalogue est émis avec `CatalogChanged::dispatch(...)` hors de la fermeture transactionnelle ; le trait `DispatchableAfterCommit` garantit l'émission après commit et son absence sur rollback.
- Aucune suppression d'article : réactivation/idempotence pour préserver l'historique fiscal.
- Si `OrderService` ou `FrontendOrderService` deviennent nécessaires, implémenter les deux avec `SYMMETRY_NOTE` et tests miroir.

## EXECUTION STEPS

1. Lire le catalogue actif et les commandes de synchronisation existantes ; identifier le seul Sandwich Classique actif au lieu d'ajouter un doublon. Les lectures d'article sont globales ; toute disponibilité reste servie par le filtre `branch_id` existant, jamais écrasée par la synchronisation.
2. Créer une commande serveur idempotente, transactionnelle et réexécutable qui :
   - maintient « Viande supplémentaire » à 2,50 € sur les produits composables, y compris les bols ;
   - ajoute « Façon Cayenne — base fromage et crème fraîche » à 1,50 € comme extra visible de supplément sur les produits autorisés ;
   - transforme le Sandwich Classique actif en produit 9,00 € à deux viandes incluses, avec deux emplacements viande et sans doublon ;
   - crée/réactive « 5 Tenders » à 5,00 €, simple, sans variation sauce/accompagnement, avec le visuel catalogue existant ;
   - corrige les descriptions demandées (steak haché frais, galette croustillante, base fromage et crème fraîche) ;
   - publie une règle serveur `included_sauce_count` : sandwich=2 (maximum, incluses), frites=1. L'extra à 0,50 € est autorisé uniquement pour les frites au-delà de la première ; aucun prix n'est une constante client.
3. Adapter `PricingService` pour refuser « Sauce supplémentaire » sur un sandwich et valider/recalculer la quantité frites facturable depuis les variations scellées et `included_sauce_count`; le total scellé ne dépend jamais d'un calcul client.
4. Adapter les deux consommateurs wizard précis à la matrice publiée : sandwich maximum deux sauces incluses, frites première incluse puis extra serveur. Corriger les crudités afin que seule une sélection effective traverse le payload et le ticket cuisine.
5. Limiter les modifications frozen à ces fichiers, en citant le gate et en ajoutant des tests de vraie sélection UI.
6. Ajouter des tests de synchronisation : idempotence, prix, absence d'options sur 5 Tenders, une seule fiche Sandwich Classique active, branche A/B sans fuite d'état, `CatalogChanged` seulement après commit et jamais après rollback, et snapshot historique bit-à-bit inchangé.
7. Ajouter/adapter les tests ciblés `tests/Playwright/pos-two-sauces-edit-e2e.spec.js`, `tests/Playwright/goal-viande-nommee-2026-08-03.spec.js`, `tests/js/kioskFritesSauceBilling.spec.js`, `tests/js/posWizardFritesSauce.spec.js` et les tests KDS existants : sandwich deux sauces incluses, frites une incluse puis 0,50 €, crudités non fantômes, viande 2,50 €, Façon Cayenne 1,50 € et libellés cuisine.
8. Exécuter la synchronisation sur la base de développement uniquement après tests, puis vérifier les ressources catalogue des cinq surfaces sans prix frontend.

## TEST_STRATEGY

- `php artisan test --filter=OwnerCatalog20261111`
- tests unitaires des commandes catalogue/extras et tests KDS concernés
- tests JS ciblés POS/borne de sauce, crudités, viande et ticket cuisine
- `tests/Playwright/pos-two-sauces-edit-e2e.spec.js` : Sandwich Classique 2 viandes + exactement deux sauces incluses, sans extra sauce.
- `tests/Playwright/goal-viande-nommee-2026-08-03.spec.js` : bol avec viande extra à 2,50 € et nom lisible KDS.
- `tests/js/kioskFritesSauceBilling.spec.js` + `tests/js/posWizardFritesSauce.spec.js` : frites une incluse, deuxième 0,50 €.
- test feature dédié : 5 Tenders simple, ressources web/mobile/borne/POS cohérentes, total scellé côté serveur et ticket KDS.
- `git diff --check`, `bash scripts/post-execute-guard.sh CATALOG-POS-KDS-20261111`

## SYMMETRY_NOTE

N/A initialement — aucun service de commande n'est planifié. Toute découverte imposant leur changement suspend l'exécution et ouvre une révision de plan miroir.

## ESCALATION

Si la caisse frozen ou la borne n'acceptent pas deux sauces gratuites sans changer leur propre calcul, arrêter l'implémentation et étendre le plan explicitement : aucune compensation de prix côté client n'est autorisée.

## PLAN_REVIEW

PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
PLAN_REVIEW_MODEL: gpt-5.5-pro
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PASS
