# PR-04 — Alerte outbox VISIBLE (pipeline dégradé non silencieux)

**Gravité (mandat owner)** : P2.
**Risque d'exécution** : FAIBLE (additif ; sonde déjà existante).

---

## §1 — Problématique + cause racine
`MonitorOutboxStaleness.php:129` lève `Log::error` (+ exit non-zéro) quand le pipeline outbox est dégradé — mais (commentaire ligne 20) « also goes nowhere → no alert is ever surfaced ». Planifié `Kernel.php:50` (everyMinute) **mais le scheduler ne tourne pas** (réglé par PR-01) → et même planifié, l'alerte n'est qu'un log.

## §2 — TOUS les fichiers concernés (vérifiés)
**Réutiliser (NE PAS modifier) :** `app/Http/Controllers/HealthController.php:143-159` `checkQueueWorker()` — **la sonde existe déjà** (même requête : `domain_events created_at<now-30s AND dispatched_at IS NULL`, seuil >10), exposée `GET /api/health/ready` (`routes/api.php:143`).
**À CRÉER (chemin zéro-risque préféré) :**
- méthode `DashboardService::outboxHealth()` (1 COUNT indexé, miroir HealthController:146-149)
- endpoint dans `app/Http/Controllers/Admin/DashboardController.php` (garde `permission:dashboard:38`)
- widget `resources/js/components/admin/dashboard/OutboxHealthWidget.vue` (miroir `SlaAlertsComponent.vue`), enregistré dans `DashboardComponent.vue:52/61` (ErrorBoundary)
**Index relié :** `database/migrations/2026_04_15_200000_create_domain_events_table.php:27` (`idx_pending` → COUNT bon marché).
**Contexte cron (no change) :** `MonitorOutboxStaleness.php`, `Kernel.php:50`.

## §3 — Solution + raisonnement fort
**Ajouter un petit read authentifié toujours-200** : `DashboardService::outboxHealth()` (COUNT indexé) + widget dashboard (visible seulement si count>seuil). **Ne PAS** faire poller `/api/health/ready` par la SPA (il renvoie **503** en dégradé → un widget naïf qui `.catch` le 503 = échec silencieux re-créé). Raisonnement : surface minimale, pattern store-authed existant (SlaAlerts), requête indexée triviale (`idx_pending`).

## §4 — Simulation d'impact
- Dashboard authed → widget vert/collapsé en nominal ; rouge « pipeline dégradé » si count>seuil. L'owner VOIT enfin l'état.
- Dépend de PR-01 (worker + scheduler vivants) pour que « dégradé » soit signifiant.

## §5 — ⚔️ Analyse adversariale (effets négatifs)
| # | Effet | Preuve | Sévérité |
|---|---|---|---|
| N1 | **Piège 503** : un widget qui poll `/ready` met le 503 dégradé dans `.catch` → n'affiche RIEN quand le worker est down (silence re-créé). | HealthController.php:59 ; SlaAlertsComponent.vue:54 | HIGH si on réutilise `/ready` |
| N2 | Signal plus faible que le cron : `checkQueueWorker` couvre « worker down » mais PAS la dimension orphelins crash-claimed (MonitorOutboxStaleness.php:72-77). | — | MEDIUM (cadrage) |
| N3 | Confusion endpoint : `/healthz` `queue_pending` = table `jobs` (profondeur queue), PAS la staleness outbox. Pointer `/ready`, jamais `/healthz`. | HealthzController.php:182 | MEDIUM |
| N4 | Fausse alarme : import masse-86 peut pousser >10 lignes non-dispatchées en 30s avec worker sain → bandeau qui flappe. | MonitorOutboxStaleness.php:52 | LOW-MED |
| ✅ | Coût requête = non-sujet (`idx_pending` mène sur `dispatched_at`). | migration 2026_04_15:27 | — |

## §6 — Ajustements pour ZÉRO effet négatif
1. **Read authed toujours-200** (pas `/ready` 503).
2. **Debounce** : 2-3 polls dégradés consécutifs avant rouge, OU libellé « lag possible ».
3. **Honnêteté de scope** : le widget couvre « worker down », pas les orphelins (eux restent au cron `Log::error` + PR-01).

## §7 — NE PAS toucher / RESPECTER
- Ne pas changer le seuil/forme de `/api/health/ready` (sonde de rotation LB ; le `--threshold` est volontairement côté cron).
- Ne pas toucher `MonitorOutboxStaleness.php` ni le schedule (SSOT orphelins).
- Frozen NF525 : aucun impact.

## §8 — Acceptation + rollback
- **Accept** : worker tué → widget passe rouge (debouncé) sur le dashboard ; worker relancé → vert ; `tests/Feature/Outbox/OutboxPipelineHealthSentinelTest.php` vert.
- **Rollback** : retirer le widget + l'endpoint (additif) ; le cron reste.
