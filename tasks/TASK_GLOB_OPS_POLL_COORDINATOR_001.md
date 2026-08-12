# TASK_GLOB_OPS_POLL_COORDINATOR_001 — Coordinateur temps réel/polling et budgets 429

## Meta

- **Priority:** P0 exploitation au repos
- **EXECUTION_TIER:** `complex`
- **PRIMARY_EXECUTION_MODEL:** `gpt-5.5-pro`
- **REASONING_EFFORT:** `xhigh`
- **TEST_STRATEGY:** `local-validation` + `playwright-critical-flow` + `load/chaos`
- **SOURCE:** `reports/audit/RATE_LIMIT_FORENSICS_2026-08-12.md`, audit global F-02, contre-débat sync
- **STATUS:** `PENDING_CONTRACT_REVIEW_AND_GATE`

## Problème prouvé

Le navigateur a mesuré +50 hits du bucket API authentifié à l'ouverture de cinq écrans supplémentaires, puis 37 hits sur environ 58 secondes au repos avec six écrans connectés. Le local utilise un plafond 1000 et masque le défaut ; la cible/default code est 120/min. En mode déconnecté, POS, KDS et listeners peuvent dépasser ce budget avant toute action opérateur.

Le KDS ajoute un double-poll parce que `KdsSyncService` consomme une interface WebSocket fictive. Les composants POS, tracker, KDS, dashboard et impression possèdent chacun leurs timers. Tous les onglets d'un utilisateur partagent le même bucket et certaines erreurs remplacent les données par zéro/vide.

Un second amplificateur a été prouvé : un reload POS a généré 37 rapports CSP, tous loggés malformed. Le route-level `throttle:1000,1` reste précédé par `throttle:api`; le plafond effectif production reste donc le plus bas. CSP anonyme par IP peut épuiser le NAT restaurant indépendamment de l'utilisateur authentifié.

## Décision d'architecture

Créer un coordinateur partagé, branch-scoped et cross-tab :

- au maximum une requête en vol par `{branch_id, feed, cursor}` ;
- leader élu par `BroadcastChannel`/heartbeat avec expiration/fencing ;
- WebSocket séquencé ; trou de séquence → rattrapage REST cursorisé ;
- backoff exponentiel, jitter et respect exact de `Retry-After` ;
- cache/cursor/version strictement indexés par branche et identité de projection ;
- budgets séparés par classe d'endpoint, sans augmenter la limite globale.

Classes :

1. **critique** — Operator Inbox, mutations commande, attention, print jobs ;
2. **opérationnel** — KDS, stock, disponibilité ;
3. **analytique** — dashboard, SLA agrégé, audit ;
4. **observabilité sécurité** — CSP dans un bucket dédié et sanitisé.

Un onglet caché peut ralentir/arrêter analytics. Il ne peut pas être l'unique propriétaire durable de l'alerte ou de l'impression ; leader expiry/failover doit respecter un SLO.

## Scope

1. Corriger d'abord le contrat `KdsSyncService`/`WebSocketService` via la mission dédiée et supprimer le faux test.
2. Inventorier chaque timer, endpoint, cadence, classe, visibilité et owner.
3. Implémenter le coordinator sur une surface pilote avec API stable ; aucune réécriture globale big-bang.
4. Déplacer les feeds vers le coordinator par lots, avec métriques avant/après et feature flag de cutover.
5. Introduire snapshot/delta/ETag/cursor là où nécessaire ; jamais multiplier les full refresh.
6. Conserver la dernière projection connue sur panne, avec `generated_at`, `last_success_at`, état `DEGRADED` et actions revalidées côté serveur.
7. Réserver un budget de mutations : analytics/observability ne peut jamais affamer accepter/rejeter/encaisser.
8. Isoler CSP selon la mission dédiée : parser formats natifs, taille bornée, fingerprint/dédup et limiter séparément derrière NAT.

## SUBSYSTEMS_OFF_LIMITS

- Aucune hausse brute de `api_throttle_per_minute` comme correction principale.
- Aucun retry automatique de mutation POST ambiguë sans clé d'idempotence/contrat.
- Aucun cache/cursor global sans `branch_id`.
- Aucun poll critique suspendu simplement parce que `document.hidden`; il doit être transféré/failover.
- Aucun message « aucune commande perdue » sans preuve de freshness/rattrapage.
- Aucune désactivation CSP ou ajout `unsafe-*`.

## INVARIANTS_AT_RISK

- `branch_id` dans leader key, cache, cursor et messages cross-tab.
- Mutations idempotentes et non rejouées par le coordinator.
- Ordre monotone des événements/deltas.
- Outbox/dispatch after commit.
- Priorité des commandes critiques.
- Worktree POS/KDS/listeners dirty selon fichiers ; réservation obligatoire.

## Tests falsifiables

1. Trois onglets POS + KDS + dashboard, même user, deux minutes au repos : zéro 429 et budget mesuré sous seuil avec marge pour mutations.
2. Deux branches ouvertes par admin global : leaders, caches et cursors séparés ; aucun delta A dans B.
3. Deux leaders concurrents au démarrage : fencing élit un owner, une seule requête par feed/cursor.
4. Tuer leader, suspendre onglet, fermer navigateur : takeover dans SLO, aucune alerte/print critique invisible.
5. WebSocket séquence 10 puis 12 : détection gap et catch-up cursorisé ; aucun full-refresh storm.
6. WebSocket reconnect storm 20–50 clients : jitter, backoff et charge bornée.
7. `Retry-After: 17` : aucune requête du feed avant délai, mutations non ambiguës restent disponibles selon budget.
8. Poll 500/timeout : dernière donnée conservée avec fraîcheur dégradée ; jamais `[]`, `0` ou vert.
9. Action POST en timeout : pas de replay générique ; contrat idempotent spécifique seulement.
10. Analytics très actif : ne peut pas consommer le budget critique réservé.
11. 121 CSP natifs derrière même IP : aucune requête métier/publique affamée ; logs sanitisés/dédupliqués, zéro malformed normal.
12. Mesure comparative avant/après avec HAR, bucket Redis, P50/P95 et correlation IDs.

## Acceptance Criteria

- [ ] Une seule requête en vol par branche/feed/cursor dans tous les onglets.
- [ ] KDS utilise le vrai contrat WebSocket et un seul propriétaire de cadence.
- [ ] Les budgets critiques ne sont pas affamés par dashboard/CSP.
- [ ] Zéro 429 au repos sur la matrice multi-onglets testée.
- [ ] Les trous/reconnects convergent sans tempête de full refresh.
- [ ] Toute donnée dégradée reste visible et horodatée, jamais remplacée par faux vide/vert.
- [ ] Les caches/cursors/leaders sont strictement branch-scoped.

## Gate et séquençage

- Exécuter après `TASK_GLOB_OPS_KDS_WS_CONTRACT_001` et en coordination avec `TASK_GLOB_OPS_CSP_RATE_LIMIT_001`.
- Tout nouvel endpoint Inbox/delta suit le gate frozen/branch correspondant.
- Le changement des limites par classe est une décision sécurité/opérations ; aucune baisse de protection mutation/login/PIN.
- Les fichiers dirty sont réservés et réconciliés avant chaque lot.

