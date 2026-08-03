# Round4 — Lane: Sync / outbox / idempotency (edges sous échec)

DB: foodking_e2e (READ-ONLY). Serveur :8766. 0 écriture.

## Verified-HOLD (pas de finding — couverture prouvée)

### Idempotency couvre les 4 routes critiques
`config/idempotency.php` `required_routes` + middleware `idempotency` au router :
- order-create : `api/admin/pos` (routes/api.php:799) + `api/frontend/order` (1317) ✓
- counter-collect : `counter-collect/*/confirm|cancel` (879/899) + `collect-kiosk-cash/*` (910) ✓
- kds-bump : `api/admin/kds-order/change-status/*` (1176) + `recall/*` (1195) ✓
- refund : `api/admin/pos-order/*/refund-with-counter-entry` (995) ✓
Sentinelle `IdempotencyRequiredRoutesCoverageTest` verrouille. `fail_open=false` + storage redis → en panne storage = 503 (fail-closed, pas double-exécution). Scope (branch_id,user_id,sha256(key)) + payloadHash → rejoue 2xx, 409 si payload divergent. Aucun gap trouvé.

### Broadcast échoué = rejoué proprement (pas perdu)
`DispatchDomainEventsJob` : claim sous `lockForUpdate`+`dispatched_at` (1 seul worker broadcast), backoff `[1,5,15,60,300]` tries=6, sur échec runtime `dispatched_at=NULL`+rethrow → requeue. `commit_before_dispatch` respecté. DB : aucun event runtime-failure non-terminal coincé.

### Double-broadcast (Echo + poll) ne double-chime PAS
`KitchenDisplaySystemComponent.vue:1437-1449` : le chime se déclenche sur diff **par id** de l'array `orders` reconciliée (pas sur le flux d'events). 1ʳᵉ livraison (WS ou poll) ajoute l'id → chime 1×; 2ᵉ livraison du même id → déjà présent → pas de 2ᵉ chime. Robuste. Version-gate poll (`KdsSyncService._versionMap`, version<=précédente → gated) + reconnect_storm jitté 0-500ms. Aucun double-affichage.

## P3 — Outbox : les events « contract_violation » (fail-once) restent coincés à vie + empoisonnent le moniteur de santé

**Fichiers**
- `app/Jobs/DispatchDomainEventsJob.php:184` — `$this->fail($e)` sur PayloadMismatchException AVANT que `attempts` n'atteigne 6 (fail-once volontaire pour ne pas saturer la lane). Résultat : la ligne reste `dispatched_at=NULL`, `attempts` bas (1-4).
- `app/Console/Commands/PruneOutboxCommand.php:55-63` — safe-set = (A) `dispatched_at IS NOT NULL` OU (B) `attempts >= 6`. Une ligne contract-violation a `dispatched_at=NULL` ET `attempts<6` → **n'entre dans AUCUNE clause → jamais purgée**.
- `app/Console/Commands/MonitorOutboxStaleness.php:48-51` — `staleCount = whereNull('dispatched_at') AND created_at < cutoff` → ces lignes sont comptées comme « stale » **à vie**. Le `crashClaimedCount` (l.72) exige `dispatched_at NOT NULL` → ne les couvre pas non plus. `retry-failed`/`rescue` ne font que les re-échouer instantanément.

**Repro (DB foodking_e2e, live)**
```
SELECT COUNT(*),MIN(attempts),MAX(attempts) FROM domain_events
 WHERE dispatched_at IS NULL AND last_error LIKE 'contract_violation%';
-- 17 lignes, attempts 2..4 (jamais >=6), plus vieille 2026-06-12
```
17 lignes déjà bloquées (résidus d'une feature loyalty `loyalty.balance_changed` retirée du `EventType` enum : type absent de `EventType::all()` → `assertEnvelopeValid` jette → fail-once). Le **mécanisme** est du code courant : tout futur producer émettant un payload non-conforme au contrat V1 produit la même fuite.

**Impact V1-LOCAL** : seuil défaut `MonitorOutboxStaleness --threshold=10`; 17 > 10 → le moniteur retourne **FAILURE en permanence** même worker sain, donc (a) page/alarme permanente, (b) **masque** une vraie panne de `queue:work` (le signal « worker down » devient indistinguible). Aucune perte de commande : les commandes passent par le fallback poll (KDS `kds-order/sync` lit la DB, pas le broadcast). C'est de la dette observabilité, pas du fiscal/argent → P3.

**Lentille** : classe systémique — un terminal-failure qui n'atteint pas `attempts>=6` échappe simultanément à prune (A et B), à crash-claimed, et reste dans staleCount. Le seuil de prune (6) et le marqueur de terminaison (`fail()` à attempts 1-2) sont désalignés.

**Reco** (non-frozen) : soit (1) sur PayloadMismatchException, marquer la ligne terminale d'une façon que prune ET staleCount excluent (ex. `dispatched_at=now()` + colonne `failed_terminal`/last_error préservé, OU `attempts=tries`), soit (2) ajouter une clause prune `last_error LIKE 'contract_violation%'` + exclure ces lignes du `staleCount` de MonitorOutboxStaleness. Sentinelle : un event contract-violation ne doit jamais rester compté « stale » indéfiniment.

**Frozen** : non (Job/Commands hors frozen-zone).
