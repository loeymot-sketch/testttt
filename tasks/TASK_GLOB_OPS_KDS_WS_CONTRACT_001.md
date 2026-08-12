# TASK_GLOB_OPS_KDS_WS_CONTRACT_001 — Contrat WebSocket réel et cadence KDS

## Meta

- **Priority:** P1 sync / P0 request-amplification contributor
- **EXECUTION_TIER:** `complex`
- **PRIMARY_EXECUTION_MODEL:** `gpt-5.5-pro`
- **REASONING_EFFORT:** `xhigh`
- **TEST_STRATEGY:** `local-validation` + `playwright-critical-flow`
- **SOURCE:** `reports/audit/AUDIT_GLOBAL_OPERATIONS_CAISSE_KDS_WEB_MOBILE_2026-08-11.md` F-08
- **STATUS:** `READY_FOR_RUN_CYCLE_AFTER_MASTERPLAY_FREEZE`
- **CURRENT_BLOCKER:** `plans/masterplay/MASTERPLAY_QUEUE.md` addendum `MASTERPLAY_FROZEN=1`

## Problème prouvé

Le producteur et le consommateur n'utilisent pas le même contrat :

| Contrat réel `WebSocketService` | Contrat attendu par `KdsSyncService` |
| --- | --- |
| `getState()` | `.state` |
| états minuscules (`connected`, `connecting`, `disconnected`, `unavailable`, `session_invalid`) | états majuscules (`CONNECTED`, `RECONNECTING`, `DEGRADED`, `DISCONNECTED`, `SESSION_INVALID`) |
| `state_change {previous,current}` | `state_change {from,to}` |

Le test `tests/js/kdsSyncCadence.spec.js` construit un faux service avec `.state`, états majuscules et `{from,to}`. Il rend donc vert le contrat inexistant au lieu de protéger l'intégration réelle.

**Baseline 2026-08-11 :** `npx vitest run tests/js/kdsSyncCadence.spec.js --reporter=verbose` retourne 3/3 PASS. Ce vert est conservé comme preuve du faux positif : les intitulés annoncent CONNECTED/DISCONNECTED alors que le service production n'émet jamais ce vocabulaire.

Conséquences :

- le polling delta reste au régime déconnecté même lorsque WebSocket est connecté ;
- le composant KDS conserve en parallèle son full polling 15 s/5 s ;
- les requêtes sont amplifiées et contribuent au 429 global ;
- les transitions réseau/reconnexion ne pilotent pas la cadence attendue.

## Goal

Faire de `WebSocketService` l'unique autorité du vocabulaire de connexion, aligner `KdsSyncService` dessus, et remplacer la preuve QA fictive par un contract test qui échoue si les deux services divergent à nouveau.

Cette mission **ne résout pas à elle seule** le coordinateur de polling global ni la suppression du full-poll du composant. Elle doit néanmoins empêcher le double régime provoqué par le contrat faux et produire les mesures nécessaires à la mission Inbox/poll coordinator.

## SUBSYSTEMS_TOUCHED

| Fichier | Action autorisée |
| --- | --- |
| `resources/js/services/KdsSyncService.js` | EDIT ciblé : lecture/normalisation de l'état et payload `state_change` |
| `resources/js/services/WebSocketService.js` | READ ; EDIT uniquement si export public du vocabulaire nécessaire, sans changer le comportement Pusher |
| `tests/js/kdsSyncCadence.spec.js` | EDIT : remplacer le mock fictif |
| `tests/js/kdsWebSocketContract.spec.js` | CREATE recommandé : contract test producteur/consommateur |

## SUBSYSTEMS_OFF_LIMITS

- `KitchenDisplaySystemComponent.vue` — propriétaire du full polling ; mission ultérieure dédiée pour éviter scope expansion.
- `resources/js/components/admin/pos/PosComponent.vue` — dirty et hors scope.
- `routes/api.php`, backend KDS, OrderService, FrontendOrderService.
- Toute logique prix, `OrderStatus`, paiement, impression ou stock.
- Aucun changement de throttle dans cette mission.

## INVARIANTS_AT_RISK

- **Synchronisation KDS:** une connexion WS ne doit pas désactiver la réconciliation de drift 60 s ; elle doit désactiver le poll rapide.
- **Auth:** `session_invalid` ne doit jamais être assimilé à connecté.
- **Lifecycle:** `stop()` doit toujours désabonner les listeners et annuler les timers.
- **No request storm:** un événement de changement d'état ne doit créer qu'un timer actif.
- **branch_id:** inchangé ; le poll conserve le filtre de branche existant.

## Contrat cible

1. `KdsSyncService` obtient l'état courant via une fonction interne unique :
   - `wsService.getState()` si disponible ;
   - fallback temporaire `.state` uniquement pour compatibilité de doubles anciens, explicitement testé et déprécié.
2. Les états réels sont interprétés sans duplication magique :
   - `connected` → cadence rapide désactivée, drift 60 s conservé ;
   - `connecting|unavailable` → cadence dégradée ;
   - `disconnected|failed|session_invalid|initialized` → cadence fallback.
3. Le listener accepte le payload réel `{previous,current}` et réémet un payload canonique nommé de manière cohérente. Aucun consommateur ne doit dépendre de `{from,to}` sans adaptateur explicite.
4. Les constantes partagées sont exportées par le producteur ou normalisées dans un adaptateur testé ; ne pas copier une seconde table divergente.
5. Les start/stop répétés ne laissent aucun listener ni timer résiduel.

## Tests obligatoires

### Contract tests Vitest

- Service réel à l'état `connected` → `currentIntervalMs === Infinity`, avec un seul drift timer 60 s.
- `state_change {previous:'connecting',current:'connected'}` → passage immédiat au mode connecté.
- `unavailable`, `disconnected`, `failed` et `session_invalid` → cadence fallback appropriée.
- `connecting` → cadence dégradée.
- `start → stop → start` → un seul callback par événement.
- Trois événements identiques rapides → un seul timer final.
- Le test doit utiliser `getState()` et `{previous,current}` ; la présence de `.state`/`{from,to}` comme unique preuve fait échouer la revue.

### Régression composant / navigateur

- Ouvrir KDS connecté : mesurer les requêtes `/api/admin/kds-order/sync` pendant 70 s ; attendu au plus le drift prévu, pas le régime 10 s.
- Couper WebSocket en gardant HTTP : le fallback démarre et les commandes restent visibles.
- Reconnecter : le poll rapide s'arrête sans perdre la réconciliation.
- Garder deux onglets KDS visibles pour mesurer le problème résiduel inter-onglets et l'inscrire dans le rapport, sans l'élargir silencieusement.

## Validation commands

```bash
npx vitest run tests/js/kdsSyncCadence.spec.js tests/js/kdsWebSocketContract.spec.js
npm run production
```

Puis `playwright-critical-flow` KDS avec journal des requêtes et état WebSocket. Le dépôt ne possède actuellement ni script lint JS ni dépendance ESLint déclarée ; ne pas inventer un faux gate lint et ne jamais lancer un formateur global sur le worktree dirty.

## Acceptance Criteria

- [ ] Aucun accès direct à `.state` comme source primaire dans `KdsSyncService`.
- [ ] Le payload réel `{previous,current}` pilote la cadence.
- [ ] Les états minuscules réels sont couverts.
- [ ] Le mode connecté n'exécute pas le poll delta 10 s.
- [ ] Le drift 60 s reste actif.
- [ ] Stop/restart ne duplique aucun listener/timer.
- [ ] Les tests échouent si `WebSocketService` change son contrat sans adapter KDS.
- [ ] La mesure navigateur contient nombre de requêtes et timestamps, pas seulement une capture visuelle.
- [ ] Aucun fichier hors scope n'est modifié.

## SYMMETRY_NOTE

N/A — OrderService et FrontendOrderService hors scope.

## Gate / reprise

Aucune migration/frozen zone nouvelle n'est nécessaire pour ce périmètre. L'exécution reste néanmoins interdite tant que le correction-freeze masterplay n'est pas explicitement levé et que le cycle `run-cycle TASK_GLOB_OPS_KDS_WS_CONTRACT_001` n'a pas obtenu `PLAN_REVIEW_VERDICT: PASS`.
