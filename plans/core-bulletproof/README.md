# Plans CŒUR Bulletproof — un fichier par problématique (audité adversarialement)

> 2026-06-04 · Branche `heal/cms-pr1-quickwins-2026-05-18` · Parent : `plans/GOAL_V1_CORE_BULLETPROOF_2026-06-04.md`.
> **Méthode** : chaque PR a été passé à un **agent adversaire read-only** chargé de CASSER le fix et de calculer **tous** les effets négatifs (preuve `file:line`). Ces fichiers intègrent ces findings. **Plans à auditer puis exécuter — rien n'est encore appliqué.**

## Discipline commune (s'applique à TOUS les PR)
- **Additif d'abord** : ne jamais réécrire la logique commande/prix/fiscal/sync. Frozen-zone diff = 0. NF525 CHAIN OK avant/après.
- **Pas de `git add -A`** (secrets). Commits scope-minimal, jamais `--no-verify`, jamais push sans owner.
- **Ne JAMAIS sur la boîte live** : `config:cache`, `composer dump-autoload`, `kill`/restart de `php artisan serve` (PID vivant), `--force` sur un prune.
- **Vérification** : tests existants verts + visuel analysé (si frontend) + un cycle adversaire après fix.

## Index des problématiques
| PR | Titre | Gravité (mandat) | Risque d'exécution | Statut audit |
|---|---|---|---|---|
| [PR-01](PR-01_daemon_scheduler_supervision.md) | Supervision daemons + scheduler (5 daemons) | P1 (cœur : recovery dormant) | ⚠️ **moyen** (81 ordres auto-rejetés + queue `high`) | audité adversarial |
| [PR-02](PR-02_kds_degradation_visible.md) | Dégradation sync visible (KDS+POS+OSS) | **P0 (silencieux=grave)** | faible | audité adversarial |
| [PR-03](PR-03_serve_crash_safety.md) | Sûreté crash serveur mono-process | P1 | faible (additif) | audité adversarial |
| [PR-04](PR-04_outbox_alert_visible.md) | Alerte outbox visible | P2 | faible (additif) | audité adversarial |
| [PR-05](PR-05_menu_404.md) | `/menu` 404 (doublon d'assets) | P3 (cosmétique) | nul (verdict : laisser) | audité adversarial |
| [PR-06](PR-06_deferred_backlog.md) | Backlog durcissement différé | P2-P3 différé | n/a (doc only) | audité adversarial |
| [PR-07](PR-07_env_config_cache.md) | `env()` runtime → `config()` (config:cache) | P2 (cloud-prep) | ⚠️ **partiel** (1 fix sûr ; 35 env, 1 NF525-FROZEN) | audité adversarial |

## Cross-cutting (découvertes transverses majeures)
1. **Le scheduler Laravel ne tourne pas** → toute la couche auto-réparation/sauvegarde est dormante (PR-01). C'est le finding le plus impactant pour la fiabilité cœur.
2. **3 surfaces masquent la dégradation en local** (KDS+POS-tracker+OSS), pas juste KDS (PR-02).
3. **35 `env()` runtime hors `config/`** cassent sous `config:cache` ; 1 est NF525-FROZEN (`AuditLogService.php:273`) → cloud-blocker à part (PR-07).
4. **Ordre d'exécution conseillé** : PR-02 (P0 visibilité, sûr) → PR-04 (alerte) → PR-01 (daemons, après triage des 81 ordres) → PR-03 (knob serve) → PR-05/06/07 selon décision.
