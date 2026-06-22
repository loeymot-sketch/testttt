# RUN — A5 Convergence testttt ↔ testttt-kiosk-p93 (T08b / T09b)

**Date** : 2026-04-20
**Task** : A5 — Vérifier et aligner 3 fichiers touchés par T08b (abilities kiosk:order) et T09b (broadcast refactor) entre les deux worktrees.
**Scope** : `routes/api.php`, `app/Http/Kernel.php`, `app/Jobs/DispatchDomainEventsJob.php`
**Runner mode** : single-session (auto-remediation active)

---

## 1. Diff résumé

| Fichier | Statut | Détail |
|---|---|---|
| `app/Http/Kernel.php` (lignes 83-84 — alias `abilities` / `ability`) | ✅ **IDENTIQUE** | Deux worktrees exposent `\Laravel\Sanctum\Http\Middleware\CheckAbilities::class` et `CheckForAnyAbility::class`. |
| `routes/api.php` — `POST /kiosk-event` et `POST /kiosk/event` | ✅ **IDENTIQUE fonctionnel** | Middleware stack des deux côtés : `['auth:sanctum', 'abilities:kiosk:order', 'throttle:30,1']`. Seule différence cosmétique : testttt conserve un tag `[T08b]` dans le commentaire inline (pas de risque). |
| `app/Jobs/DispatchDomainEventsJob.php` | ⚠️ **DIVERGENT → ALIGNÉ** | Voir §2. |

---

## 2. Divergence corrigée — DispatchDomainEventsJob.php

### Avant (testttt T09b — version défensive)

```php
$connection = app(BroadcastManager::class)->connection('pusher');
$pusherKey = (string) config('broadcasting.connections.pusher.key', '');
$isRealManager = get_class(app(BroadcastManager::class)) === BroadcastManager::class;
if ($pusherKey === '' && $isRealManager) {
    Log::info('...skipping broadcast', ...);
} else {
    $connection->broadcast($channels, ...);
}
```

### Après (aligné sur p93 — idiomatique)

```php
$broadcaster = app(BroadcastManager::class)->connection();
$broadcaster->broadcast($channels, $domainEvent->broadcast_as, $envelope);
```

### Justification

- **p93 = référence** : le pattern idiomatique Laravel est `->connection()` sans argument → respect de `config('broadcasting.default')`. En PHPUnit `BROADCAST_DRIVER=log` route vers `LogBroadcaster` (aucun appel réseau). En prod, `BROADCAST_DRIVER=pusher` route vers `PusherBroadcaster`. Aucune garde manuelle nécessaire.
- **Version testttt originale** : hardcodait `pusher` puis ajoutait une garde `pusherKey === ''` pour simuler le comportement LogBroadcaster. Masquait un misconfig prod plutôt que de l'exposer. Plus de code à maintenir.
- **Zone critique** : NON touchée. Le Job reste `dispatch(... afterCommit)` via `CommitDispatcher` (inchangé). Seul le `broadcast()` interne change.

---

## 3. Adaptations tests

Trois mocks `$manager->shouldReceive('connection')->with('pusher')` → `->withNoArgs()` :

- `tests/Feature/OutboxTest.php:92` — test `test_domain_event_broadcast_envelope_has_correct_shape`
- `tests/Feature/EventContractTest.php:114` — test `test_dispatch_job_broadcasts_envelope_matching_contract`
- `tests/Feature/EventContractTest.php:159` — test `test_dispatch_job_rejects_envelope_that_violates_contract`

---

## 4. Validation

```
php vendor/bin/phpunit --testsuite Feature --filter 'OutboxTest|EventContractTest'
→ OK (11 tests, 34 assertions)
```

Aucune régression. Zone critique non touchée.

---

## 5. Autres divergences relevées (hors scope A5 — backlog P5)

Lors du diff `routes/api.php` complet, divergences observées à documenter pour une future tâche convergence worktrees :

| Élément | testttt | p93 | Note |
|---|---|---|---|
| Middleware `kiosk.locale` | ❌ absent | ✅ présent sur menu / kiosk-upsell / kiosk-event / kiosk-allergens | Convergence i18n |
| Route `GET /kiosk/context` + `KioskContextResource` | ❌ absent | ⚠️ route présente mais `KioskContextController.php` supprimé par l'utilisateur → lien mort dans p93 | À trancher avec l'utilisateur |
| Route `POST /csp-report` | ❌ absent | ✅ présent (observabilité K-9 ADR-5) | À porter dans testttt pour P6 CSP enforce |
| Bloc `loyalty/splash` (`/add-points`, `/redeem`, `/balance`) | ✅ présent | ❌ absent | Divergence feature loyalty |

Ces items **ne bloquent pas** le canary T08b/T09b ; ils sont tracés pour la task T08/P5 convergence worktrees.

---

## 6. Verdict

**A5 = CLOSED**
- Kernel.php : identique ✅
- routes/api.php (kiosk-event stack) : identique fonctionnel ✅
- DispatchDomainEventsJob.php : divergent → aligné sur p93 ✅
- Tests T09b : 11/11 PASS ✅

Remediation attempts : 0 (alignement direct, aucun bug)
Critical zones touched : NONE
Human gate : NONE

Cycle : **CLOSED** after 0 remediation round(s)
