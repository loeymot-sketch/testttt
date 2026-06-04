# PR-01 — Supervision des daemons + scheduler (la « prise de courant » fiable)

**Gravité (mandat owner)** : P1 — touche directement la fiabilité du CŒUR (les chemins de récupération de la matrice §3 sont dormants).
**Risque d'exécution** : ⚠️ MOYEN — pas à cause du fix (additif) mais d'un **backlog pré-existant** que démarrer le scheduler va déclencher.

---

## §1 — Problématique + cause racine
La boîte doit faire tourner les daemons, mais en l'état (vérifié live 2026-06-04) seuls `php artisan serve`, `queue:work redis --queue=high` (PID 21046) et `redis` tournent. **`soketi :6001` est éteint** et surtout **le scheduler Laravel (`schedule:work`) ne tourne pas** (aucun process, pas de crontab).
→ Conséquence : **toute la couche planifiée (`app/Console/Kernel.php`) est dormante** :
- `foodking:outbox:rescue` (everyMinute) — rejoue les events bloqués → **chemin de récupération C-01 inactif**
- `foodking:fiscal:retry-alloc` (Kernel.php:248) — réessaie les allocations fiscales ratées → **C-09 inactif**
- `foodking:availability:reset-stale-quota` (Kernel.php:261) — reset des plafonds quotidiens
- `foodking:backup-daily` (Kernel.php:144, 03:00) — **sauvegarde NF525 6 ans**
- + outbox:monitor / retry-failed / webhook:retry / z-membership / prunes / OTP purge

## §2 — TOUS les fichiers concernés (vérifiés)
**À CRÉER (additif) :**
- `scripts/foodking-up.sh` *(n'existe pas — vérifié)* — démarre + healthcheck idempotent des 5 daemons + mode `--check`.

**Lus / dépendances (NE PAS modifier) :**
- `app/Console/Kernel.php` — définitions du schedule (485 lignes)
- `app/Jobs/CleanupStalePendingKioskOrders.php:34-87` — auto-reject everyFiveMinutes (Kernel.php:105)
- `app/Jobs/DispatchDomainEventsJob.php:46` — `->onQueue('high')` (le pipeline sync tourne sur la queue **high**)
- `app/Console/Commands/{PruneOutboxCommand,PruneWebhookEventsCommand,PosPurgeParkedOrders,ResetStaleDailyQuotaCommand}.php`
- `app/Services/PosParkedOrderService.php:204-209` — hard `->delete()` parked >24h
- `soketi.json` (host 127.0.0.1, port 6001, app-id/key/secret = `.env`)
- `.env` (BROADCAST_DRIVER=pusher, PUSHER_HOST/PORT 127.0.0.1:6001, CACHE_DRIVER=redis, QUEUE_CONNECTION=redis)
- `tests/Feature/Outbox/{OutboxConcurrentWorkerDedupeTest,OutboxConcurrentRetryLockTest}.php` — preuve double-worker safe

## §3 — Solution + raisonnement fort
Script **additif** `scripts/foodking-up.sh` (ne touche AUCUN fichier existant) qui :
1. vérifie redis (PONG) ; 2. démarre soketi si :6001 libre ; 3. démarre `queue:work redis **--queue=high,default**` si le worker **high** manque ; 4. vérifie `serve` (ne le redémarre JAMAIS) ; 5. démarre `schedule:work` **en dernier**.
Mode `--check` = statut 5/5 sans rien démarrer. Idempotent (double-run = pas de second soketi/worker).
**Raisonnement** : le cœur « ne perd jamais une commande » repose sur outbox:rescue + fiscal:retry-alloc, qui exigent le scheduler. Sans ce PR, la récupération automatique n'a jamais lieu. Le fix est 100% additif (un script), zéro frozen, zéro logique métier.

## §4 — Simulation d'impact (« si je lance le script → »)
- soketi monte → KDS/OSS/tracker repassent en **push temps-réel** (~6ms) au lieu de polling 30-60s. Amélioration stricte.
- `schedule:work` démarre → les lanes `everyMinute` (outbox:rescue/monitor) tournent dans la minute ; les lanes quotidiennes (backup 03:00, prunes 04:00+) tournent **à leur heure** (pas de rattrapage rétroactif — vérifié : le scheduler Laravel est un match cron ponctuel, `Event::isDue`, pas de replay des runs manqués).
- ⚠️ **Effet de bord majeur** : voir §5.

## §5 — ⚔️ Analyse adversariale (effets négatifs calculés)
| # | Effet | Preuve | Sévérité |
|---|---|---|---|
| **N1** | **81 commandes kiosk PENDING auto-REJETÉES dans ~5 min** : `CleanupStalePendingKioskOrders` (Kernel.php:105, everyFiveMinutes) passe les PENDING+UNPAID >15min à REJECTED via `OrderStateMachine::apply` + dispatch **~243 mail/SMS/push** + 81 `OrderCanceled` (release comptoir). Live : 81 lignes (plus vieille 2026-05-28). | Kernel.php:105 ; CleanupStalePendingKioskOrders.php:64,80-86 | **P1 (surprise état)** |
| **N2** | **8 parked POS supprimées à 03:15** (hard delete >24h). | Kernel.php:128 ; PosParkedOrderService.php:204-209 | P2 (delete) |
| **N3** | **`queue:work redis` simple rate la queue `high`** → le pipeline outbox→broadcast→KDS reste **noir** ⇒ le fix serait **INERTE**. | DispatchDomainEventsJob.php:46 `onQueue('high')` ; worker vivant `--queue=high` | **P1 (fix inopérant)** |
| **N4** | Garde idempotence naïf (`pgrep queue:work`) peut conclure « worker up » alors que c'est la lane **high** qui manque. | — | P3 |
| ✅ | **Pas de rattrapage** des runs manqués (scheduler ponctuel). Prunes = **0 ligne éligible** aujourd'hui (outbox 7j/attempts<6, webhook vide, OTP vide, sanctum expirés only). `reset-stale-quota` = **aucun item à plafond** (Le Cayenne n'utilise pas de cap quotidien) → no-op. soketi sans conflit (6001 libre, config = .env). Double-worker **dedupe-safe** (OutboxConcurrentWorkerDedupeTest). | — | — |

## §6 — Ajustements pour ZÉRO effet négatif
1. **Pré-étape owner (gate)** : AVANT le premier `schedule:work`, **trier les 81 ordres kiosk PENDING** (vrais paniers abandonnés → reject OK ; sinon ajuster état/timestamp). **Confirmer que mail/SMS/push sont no-op en local** avant de démarrer (sinon tempête de notifications + rejets client réels). Lister `pos_parked_orders` avant 03:15.
2. **Queue obligatoire** : `php artisan queue:work redis **--queue=high,default**` (sinon fix inerte).
3. **Garde idempotence spécifique à la queue `high`**, pas un substring `queue:work`.
4. **Ordre de démarrage** : redis → soketi → queue(high,default) → serve(check) → **schedule:work EN DERNIER** (pipeline vivant avant que le cleanup des 81 ne tire ses notifs).
5. `--check` mode peut exposer les comptes éligibles (`--dry-run` supporté sur outbox:prune/webhook:prune) sans rien supprimer.

## §7 — NE PAS toucher / RESPECTER
- ❌ Jamais `kill`/restart de `php artisan serve` (PID vivant, mono-process — crash sous charge) → healthcheck seul.
- ❌ Jamais tuer le worker `--queue=high` existant (PID 21046, lane sync vivante).
- ❌ Jamais modifier `Kernel.php`, `soketi.json`, `.env`, ni aucun fichier frozen/NF525 — PR-01 = additif pur.
- ❌ Jamais ajouter une crontab `schedule:run` ET `schedule:work` (double scheduler).
- ❌ Jamais `composer dump-autoload` sur la boîte live.
- ✅ Script **idempotent** : double-run = pas de second soketi, pas de worker redondant sur la même queue.

## §8 — Acceptation + rollback
- **Accept** : `scripts/foodking-up.sh --check` → 5/5 UP ; `:6001` répond ; worker `high` présent ; `php artisan schedule:list` montre les lanes ; après triage, 0 rejet surprise non voulu ; `tests/Feature/Outbox/*` verts. *(test à créer : `tests/Feature/Ops/DaemonHealthcheckTest.php` OU assertion du mode `--check`.)*
- **Rollback** : ne pas lancer le script ; arrêter `schedule:work`/soketi (Ctrl-C) → retour à l'état polling. Aucun fichier source modifié → `git` propre.
