# RUN W8.C-P2 — P-MEGA-22 Pilier 2 — Schedule fiscal:archive quotidien

**Date** : 2026-04-20
**Cycle** : `P_MEGA_W8_SECURITY_OBSERVABILITY_2026-04-20`
**Sub-cycle** : W8.C-P2
**Pilier** : 2/4 (NF525 archive scheduling)
**Executor** : Claude Opus 4.7 directe (task trivial routine, 1 schedule entry + 1 test)
**Outcome** : ✅ **PASSED**
**Commits** : à intégrer dans le commit aggregate W8.C-P2

## Décisions humaines APPROUVÉES (orchestrateur)
- D4=A : quotidien 02:00 (heure creuse)
- D5=A : toutes branches actives (`status=1` AND `deleted_at IS NULL`)
- D6=A : disque géré par `config('fiscal.archive_disk')` (local par défaut, S3 nightly via env)
- D7=A : ZIP+JSON géré par `FiscalArchiveCommand` existante

## Implémentation

### `app/Console/Kernel.php` (+44 lignes)

Ajout `use App\Models\Branch`, `Artisan`, `Log` + nouveau `$schedule->call(...)` :
- Itère sur `Branch::where('status', 1)->whereNull('deleted_at')->pluck('id')`
- Appelle `Artisan::call('foodking:fiscal:archive', ['branch_id' => X, '--from' => yesterday, '--to' => yesterday])`
- Si exit ≠ 0 → `Log::channel('fiscal')->warning('fiscal.archive.daily.partial_failure', ...)`
- Si throw → `Log::channel('fiscal')->error('fiscal.archive.daily.scheduler_error', ...)`
- Schedule constraints : `dailyAt('02:00')`, `name('foodking-fiscal-archive-daily')`, `withoutOverlapping()`, `onOneServer()`

### `tests/Feature/Fiscal/FiscalArchiveScheduledTest.php` (NEW, 2 cas)

- Cas 1 : assert event scheduled `0 2 * * *` cron + `withoutOverlapping` (mutex non vide) + `onOneServer` true
- Cas 2 : assert exactement 1 event nommé `foodking-fiscal-archive-daily`

## Résultats tests

```
php artisan test tests/Feature/Fiscal/FiscalArchiveScheduledTest.php
Tests: 2 passed (0.10s)

php artisan test tests/Feature/Fiscal/ tests/Unit/Fiscal/
Tests: 104 passed (12.91s)
```

## `php artisan schedule:list` confirmation

```
0    2 * * *  Closure at: app/Console/Kernel.php:50  Next Due: dans 10 heures
```

## Scope conformity

Files modified:
- `app/Console/Kernel.php` (+44/-0)
- `tests/Feature/Fiscal/FiscalArchiveScheduledTest.php` (NEW)

OFF-LIMITS : aucun touché. Aucune modification de `FiscalArchiveCommand` (réutilisée telle quelle), aucune migration, aucun service Order/Payment/Pricing.

## Findings

| ID | Sev | Description | Impact | Reco |
|---|---|---|---|---|
| F-P2-1 | INFO | `Branch::where('status', 1)` hardcoded magic number | Faible (config branch_status enum existe) | Refactor backlog |
| F-P2-2 | LOW | Pas de retry si `Artisan::call` exit ≠ 0 (juste log) | Si une branche échoue, archive J-1 manquante | Acceptable MVP, retry à scheduler J si besoin |
| F-P2-3 | INFO | `Log::channel('fiscal')` non wrapped try/catch dans la closure scheduler | Si channel manquant → throw remonte au scheduler Laravel (gérera) | Acceptable |

**0 HIGH/CRITICAL.** 3 INFO/LOW (dette acceptable).

## DoD

- [x] Schedule entry présente, cron `0 2 * * *`, withoutOverlapping + onOneServer
- [x] Itère sur toutes branches actives
- [x] Yesterday only (`--from` = `--to` = J-1)
- [x] Logging fiscal channel (warn partial / error scheduler crash)
- [x] FiscalArchiveCommand existante non modifiée
- [x] Test sentinel 2 cas PASSED
- [x] Aucune régression scope Fiscal (104/104)
- [x] Aucun OFF-LIMITS

## Verdict orchestrateur : ✅ CLOSED PASSED
