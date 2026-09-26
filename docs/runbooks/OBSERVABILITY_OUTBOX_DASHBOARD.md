# OBSERVABILITY — Outbox Pipeline Dashboard

Mission: **CV1-OBSERVABILITY-OUTBOX-001**
Trigger: RED-R3 + RED-R5 §3 — when `laravel-websockets` or `queue:work` crashes
in production, ops have NO visual signal. Stock rupture broadcasts vanish
silently, KDS misses updates, customer screens go stale. This dashboard makes
the outbox pipeline observable from the admin SPA.

---

## 1. Surface

| Element | Path | Auth |
| --- | --- | --- |
| SPA route | `/admin/observability/outbox` | logged-in admin (Spatie role `Admin` or `Tenant Admin`) |
| Read endpoint | `GET /api/admin/observability/outbox` | `auth:sanctum` + `role:Admin\|Tenant Admin` |
| Retry endpoint | `POST /api/admin/observability/outbox/retry-failed` | `auth:sanctum` + `role:Admin\|Tenant Admin` + `throttle:10,1` |
| Drain endpoint | `POST /api/admin/observability/outbox/drain-failed` | `auth:sanctum` + `role:Admin\|Tenant Admin` + `throttle:5,1` |

Implementation lives in `app/Http/Controllers/Admin/Observability/SyncOverviewController.php`
(methods `outboxOverview`, `outboxRetryFailed`, `outboxDrainFailed`). The
controller was **enriched** rather than duplicated — see mission note "ENRICHIR
plutôt que créer un nouveau" — but the new methods are isolated from the
pre-existing `index()` / `clientMetrics()` JSON contracts.

---

## 2. Response shape

```json
{
  "generated_at": "2026-09-03T10:42:18.000000Z",

  // ÉTATS — cinq populations disjointes (voir docs/OUTBOX_PATTERN.md)
  "pending":           { "count": 7, "rows": [ /* 50 dernières, jamais claimées */ ] },
  "in_flight":         { "count": 3, "stale_after_minutes": 10 },
  "stale_claimed":     { "count": 2149, "rows": [ /* 20 dernières, orphelines */ ] },
  "delivered_24h":     { "count": 4321, "latency_p50_ms": 18, "latency_p95_ms": 230,
                         "latency_p99_ms": 980, "samples": 4321 },
  "terminal_failures": { "count": 5, "contract_violations": 2, "attempts_threshold": 6 },

  // ACTIONS — ce qu'un clic ferait. À ne JAMAIS confondre avec les états.
  "replayable_events":     { "count": 3, "max_age_days": 7 },
  "purgeable_failed_jobs": { "count": 12, "older_than_hours": 24, "capped": false },

  // INFRASTRUCTURE
  "queue_high":  { "available": true, "count": 12, "oldest_age_seconds": 47 },
  "failed_jobs": { "available": true, "count": 3, "rows": [ /* 20 dernières */ ] },
  "health": {
    "queue_work": {
      "status": "up",
      "last_signal_age_seconds": 12,
      "method": "heuristic_jobs_reserved_on_queue_high_or_event_delivered_within_90s"
    },
    "websockets_serve": {
      "status": "up",
      "last_signal_age_seconds": 8,
      "method": "heuristic_cache_heartbeat_or_recent_delivery_within_60s"
    }
  }
}
```

> Le JSON réel ne porte évidemment pas de commentaires ; ils sont ici pour dire
> ce que chaque bloc EST.

**`dispatched_24h` n'existe plus.** Cette clé comptait un CLAIM comme une
livraison. Elle a été remplacée par `delivered_24h`, adossé à `broadcast_at`.

**`terminal_failures` ne gouverne aucun bouton.** C'est un état. Les deux
boutons suivent `replayable_events` et `purgeable_failed_jobs`, chacun calculé
par le MÊME code que l'action correspondante — sinon le bouton promet un nombre
que l'action ne tient pas (défaut V-04, corrigé le 2026-09-03 : la confirmation
annonçait un nombre de `domain_events` pendant que la purge vidait `failed_jobs`).

---

## 3. Health probe heuristics — known limitations

The dashboard does **not** shell out to `pgrep` or query the supervisor. Per-
request `shell_exec` is a security risk and brittle across environments
(Docker, k8s, bare metal). Instead, the V1 health card is a **derived signal**:

| Sonde | Heuristique | Ce qu'elle détecte |
| --- | --- | --- |
| `queue_work.status` | une ligne `jobs` réservée **sur la file du job outbox** (`DispatchDomainEventsJob::$queue`, aujourd'hui `high`) depuis moins de 90 s, OU un `domain_events.broadcast_at` dans la même fenêtre | un worker qui prend RÉELLEMENT des travaux de cette file, ou une livraison réellement partie |
| `websockets_serve.status` | `cache('ws:heartbeat')` de moins de 60 s, OU un `domain_events.broadcast_at` de moins de 60 s | battement du diffuseur, ou trafic de diffusion observable |

Deux corrections de fond, à ne pas défaire :

1. **`broadcast_at`, jamais `dispatched_at`** (2026-09-02). `dispatched_at` n'est
   qu'un claim : un worker tué juste après laissait la sonde verte 90 s alors
   qu'aucun client n'avait rien reçu.
2. **La file est bornée** (2026-09-03, défaut V-06). La sonde acceptait
   n'importe quelle ligne `jobs` réservée. Or `config('queue.monitored_queues')`
   liste `default`, `high` et `notifications`, et 1 490 travaux dormaient sur
   `notifications` le 2026-08-25 : un worker de notifications bien vivant
   suffisait à afficher le worker OUTBOX « en service » pendant que sa file
   était morte — exactement le cas où l'on ouvre cet écran. Le nom de la file
   est LU sur le job, pas recopié, et la valeur de `method` le NOMME pour qu'on
   puisse contredire la sonde.

**Failure modes the heuristic does NOT catch:**
- A `queue:work` worker that is alive but stuck on a single hanging job for >90s
  with the queue otherwise idle. Mitigation: this is what `failed_jobs.count`
  surfaces after the worker times out.
- A `websockets:serve` daemon that accepts TCP connections but stops
  broadcasting (rare). Mitigation: client-side reconnect-storm metric in
  `sync_metrics` is the secondary signal.

**Future hardening (out of scope for V1):**
- Add `Cache::set('ws:heartbeat', now())` in a periodic broadcaster pulse
  (e.g., a `WebSocketsHeartbeatJob` scheduled every 30s).
- Real process probe via a dedicated /healthz endpoint exposed by each
  daemon (requires deployment topology decisions).

---

## 4. Operational use

### Symptom: customers report orders not updating in real time

1. Open `/admin/observability/outbox`.
2. Check the **health row** at top:
   - `queue:work DOWN` → restart the worker (`supervisorctl restart laravel-worker:*`).
   - `websockets:serve DOWN` → restart the daemon (`supervisorctl restart laravel-websockets:*`).
3. If both UP but **pending count grows**, look at `pending.rows[].last_error`:
   - "broker unreachable" → broadcast driver misconfigured.
   - HTTP 5xx → upstream notification provider down.
4. Si la cause est en amont, cliquez **Rejouer les échecs** une fois l'amont
   rétabli. Le bouton n'est offert que s'il y a quelque chose à rejouer
   (`replayable_events > 0`) et le message de retour dit COMBIEN d'événements
   sont repartis — « 37 événement(s) remis en file », pas « Relance demandée. »
   (défaut V-02 : l'écran lisait une clé `retried` que le serveur n'a jamais
   envoyée, le compte était donc toujours nul).

   Ce que la relance web fait, exactement :
   - même verrou que le cron (`outbox.retry-failed.lock`) — conflit ⇒ **409**,
     pas de double rejeu silencieux ;
   - une ligne `audit_logs` NF525 `outbox.replay` PAR événement, signée par
     l'opérateur humain ;
   - `attempts` et `last_error` sont **conservés** (trace forensique) : le
     bouton ne les remet PAS à zéro. Il l'a fait jusqu'au 2026-05-19, et un
     événement chroniquement en échec ne franchissait alors jamais le seuil de
     `PruneOutboxCommand`.

   Périmètre : pendants, porteurs d'un `last_error`, **hors** violations de
   contrat (les rejouer n'écrit que des lignes d'audit inutiles), créés depuis
   moins de 7 jours. Pas de plafond sur `attempts` : rejouer un événement qui a
   épuisé ses relances après réparation de l'infrastructure est l'usage premier
   de ce bouton.

5. Si **« Orphelins »** grimpe, ce ne sont pas des échecs : ce sont des lignes
   qu'un worker a prises et jamais diffusées (worker tué entre les phases 1 et
   2). C'est `php artisan foodking:outbox:rescue` qui les traite, pas ce bouton.

### Symptom: failed_jobs table balloons after an outage

1. Le bouton **Purger** annonce le nombre de travaux `failed_jobs` OUTBOX de
   plus de 24 h — `purgeable_failed_jobs`, calculé par le même code que la
   purge. S'il est à zéro, le bouton est inerte et le dit.
2. L'appel refuse `older_than_hours < 1` (HTTP 422) : une purge « tout de
   suite » n'existe pas.
3. Seuls les `DispatchDomainEventsJob` sont candidats. Un job étranger (un
   listener stock, une notification) n'est JAMAIS purgé depuis cet écran. Le
   filtre s'applique **avant** la borne des 500 par lot : sinon 500 travaux
   étrangers plus anciens affamaient la purge, qui répondait « 0 supprimé »
   indéfiniment (défaut V-11).
4. Les lignes sont exportées en JSON (`storage/app/outbox/drained-*.json`)
   AVANT toute suppression.
5. Ordre d'écriture, à ne pas inverser : **suppression, PUIS audit du nombre
   réellement supprimé**, les deux dans la même transaction. Si l'audit ne peut
   pas s'écrire, la suppression est annulée (rien n'est supprimé sans trace).
   Jusqu'au 2026-09-03 l'audit était écrit AVANT le `DELETE`, avec le nombre de
   candidats : une suppression partielle ou ratée laissait une ligne
   `audit_logs` — immuable, signée en chaîne HMAC, donc incorrigeable —
   affirmant une suppression qui n'avait pas eu lieu (défaut V-05).
6. La purge ne touche JAMAIS `domain_events` ni les tables fiscales ; les
   remboursements, les tickets Z et le journal d'audit sont hors d'atteinte.
   Le message de retour dit « travail(aux) en échec », pas « événements » : ce
   ne sont pas les mêmes lignes.

---

## 5. Polling + UX

The Vue component (`OutboxOverviewComponent.vue`) polls every 10s by default
(`pollIntervalMs` prop). Polling is paused when `document.hidden === true` to
avoid burning quota during long staff sessions; an immediate refresh fires on
`visibilitychange` when the tab regains focus.

`beforeUnmount()` clears both the interval and the visibility listener so a
SPA route change doesn't leak a background poller.

---

## 6. Tests

| Couche | Fichier | Ce qu'il verrouille |
| --- | --- | --- |
| PHPUnit | `OutboxOverviewControllerTest.php` | 401, 403, forme JSON, sémantique relance/purge, purge refusée à 0 h |
| PHPUnit | `OutboxDeliverySemanticsTest.php` | les cinq populations disjointes ; une sonde ne prend pas un claim pour un signal |
| PHPUnit | `OutboxWebActionsAreAuditedTest.php` | verrou partagé, audit par événement, pas d'audit ⇒ pas de suppression |
| PHPUnit | `OutboxDrainAuditApresSuppressionTest.php` | l'audit n'atteste que le fait accompli (partiel, nul, en échec) — V-05 |
| PHPUnit | `SondeWorkerBorneeFileHighTest.php` | un worker vivant sur une autre file ne rend pas le worker outbox vivant — V-06 |
| PHPUnit | `TerminaliteExigeAttemptsEpuisesTest.php` | « terminal » exige des essais épuisés ; la violation de contrat reste terminale — V-10 |
| PHPUnit | `PurgeNeSeLaissePasAffamerTest.php` | filtrer avant de borner ; le compteur du bouton ne s'affame pas — V-11 |
| Vitest | `observabilityOutboxRoute.spec.js` | route, enregistrement routeur, sections, primitives de polling |
| Vitest | `outboxOverviewPanneReseau.spec.js` | une lecture en échec le dit au lieu de garder le vert |
| Vitest | `outboxRelanceCompteVrai.spec.js` | la relance annonce le nombre réel (`requeued`) — V-02 |
| Vitest | `outboxEtatsClaimsVisibles.spec.js` | en vol / orphelins / terminaux sont RENDUS, pas seulement chargés — V-03 |
| Vitest | `outboxPurgeAnnonceLaBonneTable.spec.js` | chaque bouton suit le compteur de sa propre table — V-04 |

---

## 7. Frozen-zone audit

Aucun fichier de zone gelée n'est MODIFIÉ :
- `app/Services/Fiscal/*` — inchangé ;
- `FiscalSequenceService` — inchangé.

Nuance à ne pas perdre : `AuditLogService` (zone gelée, CLAUDE.md §7) est
désormais **appelé** par la relance et par la purge — il n'est pas modifié.
Les corrections du 2026-09-03 déplacent l'APPEL (après le `DELETE`, dans la même
transaction), jamais le service. `audit_logs` reste en ajout seul : aucune ligne
n'est réécrite ni corrigée a posteriori.

The retry/drain actions:
- `outboxRetryFailed` re-queues `domain_events` rows that already failed and
  are pending — same flow as `php artisan foodking:outbox:retry-failed`. No
  fiscal data is touched.
- `outboxDrainFailed` deletes from `failed_jobs` only, with a strict cutoff
  (≥1h). `domain_events` is never deleted by this endpoint.

---

## 8. Historique des mensonges corrigés

| Date | Défaut | Ce que l'écran disait | Ce qui était vrai |
| --- | --- | --- | --- |
| 2026-09-02 | livraison | un CLAIM comptait comme une livraison | 2 149 lignes claimées jamais diffusées, invisibles |
| 2026-09-02 | lecture | une lecture en échec gardait le dernier vert | le tuyau pouvait être bouché depuis des heures |
| 2026-09-03 (V-02) | relance | « Relance demandée. » | 37 événements repartis — ou zéro, indiscernables |
| 2026-09-03 (V-03) | claims | rien | en vol et orphelins étaient chargés puis jetés |
| 2026-09-03 (V-04) | purge | « 5 événements seront supprimés » | l'action vide `failed_jobs`, une autre table |
| 2026-09-03 (V-05) | audit | « deleted: 5 » | 2 supprimés, ou zéro, dans une ligne immuable |
| 2026-09-03 (V-06) | sonde | worker outbox « en service » | c'était le worker de notifications |
| 2026-09-03 (V-10) | terminalité | « 40 échecs terminaux » | la plupart étaient en reprise automatique |
| 2026-09-03 (V-11) | purge | « 0 supprimé » | des candidats existaient, jamais atteints |
