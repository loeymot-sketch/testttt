# Queue Topology — FoodKing

**Statut** : SSOT opérationnel (Lot NEW-03, 2026-04-23).
**Primer technique préalable** : [`docs/QUEUE_WORKER_SETUP.md`](../QUEUE_WORKER_SETUP.md) — driver `database`, supervisor de base, monitoring de base.

Ce document est la **source de vérité unique** pour la topologie des files
d'attente FoodKing. Il décrit les 3 lanes, leur priorité et la commande
worker exacte à utiliser en Forge / Octane / Supervisor.

---

## 1. Les 3 files

| Lane | Jobs | SLO | Pourquoi isolée |
|---|---|---|---|
| `high` | `DispatchDomainEventsJob` | `outbox_dispatch_latency_p95 < 2s` | Le broadcast Pusher/Soketi alimente POS/KDS/Borne en temps réel ; tout retard > 2s = données stales en cuisine. |
| `notifications` | `SendFcmNotificationJob` | best effort, 30s OK | Les envois FCM sont lents (200–500 ms par hit, parfois 2–3 s en cas de throttling Google) ; isolés pour ne pas affamer `high`. |
| `default` | tâches sans contrainte de latence outbox | best effort | Lane fourre-tout : maintenance, exports, jobs ad hoc. |

---

## 2. Commande worker

**Le worker DOIT drainer dans cet ordre exact** :

```bash
php artisan queue:work database \
  --queue=high,notifications,default \
  --tries=0 \
  --sleep=1 \
  --timeout=60
```

- `--queue=high,notifications,default` : Laravel lit dans l'ordre déclaré, `high` est servi avant `notifications` qui est servi avant `default`.
- `--tries=0` : signifie « **déléguer** à la propriété `$tries` du job » — pas « infini ». Tous les jobs critiques de FoodKing (`DispatchDomainEventsJob = 6`, `SendFcmNotificationJob = 3`) déclarent leur propre `$tries`. Avant d'ajouter un nouveau Job sans `$tries` à la lane `default`, **vérifier qu'il définit bien sa borne** (sinon la combinaison `--tries=0` + job sans `$tries` = retries infinis = risque pager). Audit T (NEW-03 G10) — clarification opérationnelle.
- `--sleep=1` : pause 1 s entre deux polls quand la file est vide (économie CPU).
- `--timeout=60` : kill le job au-delà de 60 s (DispatchDomainEventsJob.handle() doit rester bien en-dessous).

---

## 3. Topologies recommandées (Supervisor)

### 3.1 SMB / volume normal (jusqu'à ~200 commandes/heure)

| Lane | numprocs |
|---|---|
| `high` | **2** |
| `notifications` | **2** |
| `default` | **1** |

```ini
[program:foodking-worker-high]
process_name=%(program_name)s_%(process_num)02d
command=php /home/forge/app/current/artisan queue:work database --queue=high --tries=0 --sleep=1 --timeout=60
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/home/forge/app/current/storage/logs/worker-high.log

[program:foodking-worker-notifications]
process_name=%(program_name)s_%(process_num)02d
command=php /home/forge/app/current/artisan queue:work database --queue=notifications --tries=0 --sleep=1 --timeout=60
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/home/forge/app/current/storage/logs/worker-notifications.log

[program:foodking-worker-default]
process_name=%(program_name)s_%(process_num)02d
command=php /home/forge/app/current/artisan queue:work database --queue=default --tries=0 --sleep=1 --timeout=60
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/home/forge/app/current/storage/logs/worker-default.log
```

Pourquoi des pools dédiés et pas un seul worker `--queue=high,notifications,default` ?
- En pool dédié, `high` ne peut PAS être pris en otage par un FCM qui freeze 30 s.
- Coût : +2 workers PHP vs un combiné. Acceptable.

### 3.2 Volume élevé (> 1000 commandes/heure)

| Lane | numprocs |
|---|---|
| `high` | **4+** |
| `notifications` | **4+** |
| `default` | **2+** |

Scaler `high` en premier si la fraîcheur POS/KDS dégrade. Scaler `notifications` séparément si FCM/push devient lent.

### 3.3 Mode mono-worker (dev / staging léger)

Si on n'a qu'un seul process, **garder l'ordre** :

```bash
php artisan queue:work database --queue=high,notifications,default --tries=0 --sleep=1 --timeout=60
```

---

## 4. Politique de retry par job

| Job | Queue | `$tries` | `$backoff` (s) | Total worst-case |
|---|---|---|---|---|
| `DispatchDomainEventsJob` | `high` | **6** | `[1, 5, 15, 60, 300]` | **~6.4 min** |
| `SendFcmNotificationJob` | `notifications` | 3 | `[10, 30]` | ~40 s |

Justifications :
- **DispatchDomainEventsJob** : la courbe 1/5/15/60/300 couvre un redémarrage Pusher typique (1–3 min). Comme Laravel utilise `$backoff[i]` **entre** les tentatives, il faut `$tries > count($backoff)` pour que l'entrée 300 soit effectivement consommée (Audit T G2, 2026-04-23). Avec `$tries = 6` Laravel applique 1, 5, 15, 60, 300, 300 → ~6.4 min de couverture totale. Au-delà, l'événement est en `failed_jobs` + `last_error` peuplé + `OutboxRetryFailedCommand` dispo.
- **SendFcmNotificationJob** : FCM a son propre retry serveur ; on n'amplifie pas un 503 storm avec 5 attempts client.

---

## 5. Failed jobs — observabilité

Le hook `failed()` de `DispatchDomainEventsJob` log un payload structuré (Lot NEW-03) :

```json
{
  "domain_event_id": 1234,
  "event_type": "OrderCreated",
  "queue": "high",
  "connection": "database",
  "attempts": 6,
  "delay_since_creation_seconds": 412,
  "error": "envelope missing required key",
  "error_class": "App\\Exceptions\\PayloadMismatchException",
  "last_error": "contract_violation: envelope missing required key",
  "error_category": "contract_violation"
}
```

- **Niveau** : `Log::error` (était `warning` avant NEW-03 — upgradé car terminal-failure = pager-worthy).
- **Sentry** : breadcrumb optionnel `category=queue.dispatch_domain_events.failed`, gardé par `class_exists(\Sentry\State\Scope::class)` — pas de hard-dep.
- **Préservation invariant** : `last_error` conserve le préfixe `contract_violation: ` pour `PayloadMismatchException` (NEW-01 audit G1) **dans le contexte log ET dans la colonne DB** — calculé une seule fois pour éviter le drift (Audit T G3, 2026-04-23).
- **`error_category`** : `contract_violation` ou `runtime_failure` — clé canonique pour les filtres pager (Audit T G3).

---

## 6. Monitoring

```bash
# Profondeur des files (live)
php artisan queue:monitor high,notifications,default --max=100

# Failed jobs (post-mortem)
php artisan queue:failed

# Profondeur SQL (driver=database)
SELECT queue, COUNT(*) AS pending FROM jobs GROUP BY queue ORDER BY queue;
```

Métriques prod à surveiller :

| Métrique | Seuil pager |
|---|---|
| `outbox_dispatch_latency_p95` (queue `high`) | > 2 s pendant 5 min |
| Pending count `high` | > 100 pendant 2 min |
| `failed_jobs` `DispatchDomainEventsJob` | tout incrément vers Sentry |
| Pending count `notifications` | > 500 pendant 10 min |

---

## 7. Troubleshooting

```bash
# Rejouer un failed job précis
php artisan queue:retry <failed-job-id>

# Rejouer toute la queue failed (post-incident)
php artisan queue:retry all

# Outbox-spécifique : rejoue les DomainEvents avec last_error renseigné
php artisan foodking:outbox:retry-failed --since=1h

# Outbox-spécifique : rescue les events orphelins (jamais dispatchés)
php artisan foodking:outbox:rescue

# Restart workers post-deploy
php artisan queue:restart

# Flush les failed jobs (uniquement après confirmation post-mortem)
php artisan queue:flush
```

---

## 8. Checklist déploiement NEW-03

- [ ] `QUEUE_CONNECTION=database` en `.env` prod.
- [ ] Migration `jobs` table appliquée (cf. primer).
- [ ] 3 programs Supervisor enregistrés (high, notifications, default) — voir §3.1.
- [ ] `supervisorctl status` : tous `RUNNING`.
- [ ] Smoke : créer une commande POS, vérifier que la ligne `jobs` `queue='high'` apparaît puis disparaît en < 2 s.
- [ ] Sentry (si configuré) : vérifier que la categ `queue.dispatch_domain_events.failed` apparaît bien lors d'un test de failure forcée (ex: désactiver Pusher temporairement).

---

## Références

- [`docs/QUEUE_WORKER_SETUP.md`](../QUEUE_WORKER_SETUP.md) — primer driver/Supervisor/monitoring de base.
- [`plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md`](../../plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md) §3.3 — décision NEW-03.
- [Laravel Queues](https://laravel.com/docs/10.x/queues).
