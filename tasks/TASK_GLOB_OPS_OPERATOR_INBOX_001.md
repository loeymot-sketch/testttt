# TASK_GLOB_OPS_OPERATOR_INBOX_001 — Inbox opérateur unique et actions serveur

## Meta

- **Priority:** P0 contrôle opératoire
- **EXECUTION_TIER:** `complex`
- **PRIMARY_EXECUTION_MODEL:** `gpt-5.5-pro`
- **REASONING_EFFORT:** `xhigh`
- **TEST_STRATEGY:** `local-validation` + `playwright-critical-flow`
- **SOURCE:** audit global F-01/F-07/F-08/F-09 et contre-audit UX
- **STATUS:** `PENDING_CONTRACT_REVIEW_AND_FROZEN_GATE`

## Problème prouvé

Le POS, le tracker, le dashboard SLA, la santé et l'historique interrogent des populations, fenêtres et horloges différentes. Le test navigateur a simultanément observé 77 commandes web dans le POS, 0 dans le tracker, 484 « vieillissantes », 331 alertes SLA et 3 encaissements comptoir. Une sidebar ajoutée sur une de ces listes conserverait le split-brain.

Le détail POS ne propose pas toutes les actions attendues, avale certaines erreurs en page vide et l'historique y dirige des sources/statuts incompatibles. Le frontend reconstitue aussi des actions que la state machine refuse, notamment Annuler sur `PREPARED`.

## Décision de conception

Créer une projection backend branch-scoped `OperatorInbox` comme vérité de lecture unique. Les surfaces POS, tracker, dashboard opérationnel et historique récent consomment les mêmes lignes, versions et `actions[]`; elles peuvent filtrer ou présenter différemment, mais pas redéfinir l'éligibilité.

Catégories stables :

- `ACTION_REQUIRED` — web à accepter/rejeter, paiement comptoir, erreur matérielle/attention non ACKée ;
- `IN_PRODUCTION` — `ACCEPT`/`PREPARING` réellement libérées ;
- `READY_HANDOFF` — `PREPARED`/remise à effectuer ;
- `UPCOMING` — planifiées avant `release_at` ;
- `RECENT_TERMINAL` — livrées, rejetées, annulées, retournées dans une fenêtre explicite.

Les catégories ne remplacent jamais `OrderStatus` et ne sont pas persistées comme second statut métier.

## Contrat de ressource minimal

Chaque ligne expose :

- `id`, `correlation_id`, `version`, `branch_id` et branche lisible ;
- `source_surface` canonique (`pos`, `phone`, `web`, `app`, `kiosk`, `delivery`, intégration explicitement nommée) ;
- `order_status` entier issu de `OrderStatus` + label serveur ;
- `payment_status` issu de `PaymentStatus`, méthode, montant **fourni par le backend** ;
- `created_at`, `scheduled_at`, `release_at`, `promised_ready_at` si disponible, âge opérationnel serveur ;
- `attention` (`delivery/seen/claim lease/resolution`, actor/device/responsibility) lorsqu'autorisé par le gate dédié ;
- `production_groups[]` avec station, produits, quantités et modificateurs ; boissons jamais cachées dans un simple `+N` ;
- `print_summary`, `hardware_failures` et `freshness` quand ces ledgers existent ;
- `actions[]` calculées par state machine, policies, rôle, paiement et branche ;
- curseur stable et version/delta pour réconciliation.

## Matrice d'actions minimale

| État réel | Paiement/rôle | Actions possibles |
| --- | --- | --- |
| `PENDING` | autorisé, non payé ou déjà payé web | `ACCEPT`, `REJECT_WITH_REASON`, `OPEN_DETAIL`, reprint seulement si document existant |
| `PENDING` | paiement comptoir | `COLLECT_PAYMENT`, `REJECT_WITH_REASON` selon policy, `OPEN_DETAIL` |
| `ACCEPT` / `PREPARING` | non payé | `CANCEL_WITH_REASON` si state machine/policy l'autorise |
| `ACCEPT` / `PREPARING` | payé | remboursement/retour seulement avec permission dédiée ; jamais simple impayé |
| `PREPARED` | retrait | `MARK_DELIVERED`, `REPRINT`; jamais `CANCEL` générique |
| `PREPARED` | livraison | `MARK_OUT_FOR_DELIVERY` ou action policy correspondante |
| terminal | autorisé | lecture, reprint audité, remboursement/return selon état/policy ; aucune transition improvisée |

L'API renvoie seulement un code d'action typé, un identifiant opaque, `order_version` attendu et les contraintes utiles. Le frontend ne reçoit pas d'URL arbitraire et ne poste pas une chaîne de statut inventée ; le serveur revalide au moment de la mutation.

## Scope phase 1 — contrat et projection lecture seule

1. Définir le resource contract, les catégories et la matrice d'actions avec tests de table.
2. Implémenter un résumé léger par buckets puis des pages branch-scoped, cursorisées et sans limite top-100 implicite. Le tri priorise action/urgence puis ancienneté afin qu'une commande urgente ne soit pas affamée par le curseur.
3. Séparer `CURRENT_ACTIONABLE`, `UPCOMING`, `JANITOR_CANDIDATE` et `HISTORICAL_ORPHAN`; les historiques payés/fiscalisés ne sont jamais auto-cancelés.
4. Exposer freshness, snapshot/version et erreur explicite ; une panne ne devient jamais `[]` ou zéro vert.
5. Ne muter aucune commande durant cette phase.

## Scope phase 2 — UI et actions

1. Sidebar/drawer accessible depuis la caisse, constamment visible avec compteur par catégorie.
2. Carte expandable avec Cuisine/Boissons, paiement, heure, origine et alerte.
3. Accepter/rejeter/annuler/rembourser/livrer/reprint uniquement via `actions[]`.
4. État dégradé conservant la dernière donnée, horodatage, retry et correlation ID.
5. Pagination/infinite scroll stable sans faire disparaître une action récente.
6. Après action, optimistic UI seulement si réconciliable par version ; conflit 409 déclenche refetch ciblé, jamais mutation forcée. Chaque mutation passe par service métier canonique avec branche/rôle/paiement/state machine, CAS et clé d'idempotence.
7. Cache, summary, pages et cursors sont indexés par branche, utilisateur/rôle et filtres ; aucune clé globale partageable entre établissements.

## SUBSYSTEMS_OFF_LIMITS

- Aucun nouvel enum/order status.
- Aucune logique de prix ou permission dans Vue.
- Aucun admin global autorisé à agir sans branche sélectionnée explicitement.
- Aucune purge/réparation des 568 lignes historiques dans cette mission.
- Aucun changement de state machine sans gate dédié.
- Aucun `updated_at` utilisé comme chronomètre SLA universel.

## INVARIANTS_AT_RISK

- `OrderStatus` SSOT et policies.
- `branch_id` strict sur query, curseur, action et cache.
- Pricing backend SSOT.
- Symétrie OrderService/FrontendOrderService pour toute action métier modifiée.
- Dispatch après commit.
- Worktree POS dirty ; composant dédié recommandé pour minimiser collision.

## Tests falsifiables

1. 201 commandes actionnables : toutes sont cursorisables sans disparition top-100 ; même total/IDs sur POS, tracker et health projection.
2. Une active vieille de 62 jours, une future demain, une candidate janitor, une payée/fiscalisée : quatre classifications explicites et aucune auto-mutation.
3. Deux branches + admin global : absence de sélection = aucune action ; branche A ne lit/ne mute jamais B.
4. Matrice complète `OrderStatus × PaymentStatus × rôle × source` : `actions[]` correspond à `OrderStateMachine` et policies.
5. `PREPARED` n'expose pas `CANCEL`; PENDING web expose accepter/rejeter ; payé exige permission refund/return.
6. Boissons présentes et identifiables dans le détail, y compris plus de N lignes.
7. Endpoint 429/500/offline : dernière donnée conservée, bannière dégradée, jamais liste vide trompeuse.
8. Action concurrente depuis deux postes : un succès, un conflit/version ; aucune double transition.
9. Reprint crée une intention auditée, pas un changement de statut.
10. A11y : clavier seul, focus trap/retour, `aria-live`, labels et reduced motion.
11. Deux caisses acceptent/annulent simultanément : une transition canonique, l'autre reçoit conflit/version sans second effet.

## Acceptance Criteria

- [ ] Une seule définition serveur de « commande opérable » alimente les surfaces.
- [ ] Aucun plafond temporel/quantitatif silencieux.
- [ ] Les actions sont calculées et revalidées côté backend.
- [ ] Annulation/rejet/remboursement respectent statut, paiement et permission.
- [ ] La sidebar rend les commandes, boissons et reprints accessibles sans navigation fragile.
- [ ] Une erreur réseau ne se transforme jamais en faux vide/faux vert.
- [ ] Les branches restent isolées dans snapshot, delta, curseur et action.

## Gate et séquençage

- Phase 1 lecture seule peut être planifiée après validation du contrat et levée de correction freeze.
- Toute route/service frozen et toute action métier restent soumises au gate consolidé et au cycle officiel.
- Phase 2 doit précéder le ledger d'attention visuel, mais ne le remplace pas.
- Les modifications `PosComponent.vue` dirty exigent réservation/réconciliation ; privilégier un nouveau composant borné.
