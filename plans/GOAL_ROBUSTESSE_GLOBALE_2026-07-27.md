# GOAL — ROBUSTESSE GLOBALE DU PROJET (2026-07-27)

> Mandat owner : « je te laisse liberté de mettre tout le projet plus robuste en
> fonctionnement et l'améliorer… toi qui prends les décisions et tu vérifies et
> test-e2e jusqu'à tout validé. » Claude décide, exécute, prouve.

## §0 Préambule
- **Working tree** : bundles périmés trackés supprimés par le build contenthash
  (à sortir de l'index, W0) ; outils canoniques untracked (deploy script !).
- **Pipeline par tâche** : exécution directe + adversaire ciblé quand risque ;
  test gate DB-safe (`safe-test.sh`), frozen diff 0, chaîne NF525, e2e visuel.
- **Convergence** : chaque wave se ferme sur preuve concrète (fichier produit,
  test vert, sortie commande) — pas de « ça devrait marcher ».
- **Interdits** : secret staging NF525 (TAMPER id=1 = état documenté, registre
  fin-de-projet — ne pas toucher) ; frozen §7 ; push forcé ; `git add -A`.

## §1 Ancres vérifiées (2026-07-27, sorties réelles en session)
| Fait | Preuve |
|---|---|
| `storage/backups/db-daily/` VIDE sur VPS | `ssh lecayenne ls` → `total 0` |
| Cron scheduler VPS actif | `crontab -l` → `schedule:run` chaque minute |
| `foodking:backup-daily` + `backup:verify-restore` planifiés | `Kernel.php:151,160` |
| `fiscal:verify-z-membership` planifié, `verify-chain` NON planifié | `Kernel.php:98` + grep |
| Queue worker vivant (www-data, redis, max-time 3600) | `ps aux` VPS |
| Supervisor présent mais interrogation refusée (non-root) | `supervisorctl status` → PermissionError |
| `tools/deploy-lecayenne.sh` / `docs/HANDOVER_SECRETS_REGISTRY.md` / `tests/Feature/Fiscal/PreflightChainGateTest.php` / `plans/GOAL_REMEDIATION_3_POINTS_STAGING_2026-07-15.md` untracked | `git status` session |
| Bundles à nom fixe trackés maintenant supprimés (`D public/js/*.js`) | `git status` post-contenthash |
| Livraison verrouillée serveur (DISABLE + offerte 0) | migrations 2026_07_27_09* déployées |
| P2 livraison connu : coords client non re-vérifiées serveur | `DeliveryQuoteService.php:45-46` (adversaire) |
| Web e2e smoke = scripts scratchpad SESSION (périssables) | `web-nav-smoke.js` hors repo |

## §2 Waves

### W0 — Hygiène git (fondation, sans risque)
- T0.1 Sortir de l'index les bundles périmés supprimés (`git rm --cached` déjà
  effectif via `D` → commit) + vérifier `.gitignore` couvre `public/js/*.js`.
  Acceptance : `git status` propre hors `.claude/`.
- T0.2 Tracker les canoniques APRÈS secret-scan chacun : `tools/deploy-lecayenne.sh`,
  `docs/HANDOVER_SECRETS_REGISTRY.md`, `plans/GOAL_REMEDIATION_3_POINTS_STAGING_2026-07-15.md`,
  `tests/Feature/Fiscal/PreflightChainGateTest.php`, `tools/kds-poll-tune-2026-07-14.sh`.
  Acceptance : `git ls-files` les liste ; scan `sk_live|AWS_SECRET|password=` vide.
- T0.3 PreflightChainGateTest exécuté → vert (sinon heal).
  Acceptance : PHPUnit filtre `PreflightChainGate` PASS.

### W1 — Backups VPS réels (P1 le plus grave)
- T1.1 Diagnostiquer : `php artisan foodking:backup-daily` manuel sur VPS +
  code retour + log. Anchor : `app/Console/Commands/` (commande à lire).
- T1.2 Réparer la cause (chemin/credentials/mysqldump) — scope minimal.
- T1.3 Preuve : fichier `.sql.gz` daté du jour dans `db-daily/` sur VPS +
  `backup:verify-restore` PASS + taille > 100 Ko.
  Acceptance : sortie ssh listant le backup du jour.

### W2 — Continuité NF525 en alarme (C-01)
- T2.1 Planifier `fiscal:verify-chain --all` quotidien dans `Kernel.php`,
  **gated env production** (staging = TAMPER connu → pas de bruit) avec
  `emailOutputOnFailure`/log ERROR. Anchor : Kernel.php pattern existant `:98`.
- T2.2 Test unitaire du câblage (schedule list contient la commande).
  Acceptance : `php artisan schedule:list | grep verify-chain` + test vert.

### W3 — Durcissement livraison pré-lancement (P2 adversaire)
- T3.1 `DeliveryQuoteService` : garde plausibilité serveur — distance(text-géocode
  serveur ↔ coords fournies) OU re-calcul haversine depuis coords + plafond
  d'écart ; à défaut simple : recalcul serveur du fee TOUJOURS depuis coords +
  **refus si coords hors zone branche** (`branches.zone` polygone existe).
  Décision : garde ZONE (donnée existante, zéro dépendance géocodeur serveur).
- T3.2 Tests : coords resto (fraude → refus/fee zone), coords hors zone → 422.
  Acceptance : nouveaux tests PASS + suite Delivery 159+ verte.

### W4 — E2E web durable (anti-périssable)
- T4.1 Déplacer smoke local + live-e2e dans le repo web `tests-e2e/` +
  `README-tests.md` (commande one-liner, prérequis, AVERTISSEMENT live =
  commande réelle). Acceptance : fichiers commités web repo.
- T4.2 Back mobile ferme les MODALES d'abord (wizard/panier) — pushState d'un
  état modal + popstate → close. Scope JSX index.html seul, garde simple.
  Acceptance : smoke étendu (ouvrir panier → back → panier fermé, route intacte).

### W5 — Vérif worker/supervision (lecture seule)
- T5.1 Prouver le respawn : uptime worker vs max-time 3600 (2 mesures espacées)
  ou supervisor via sudo si dispo ; sinon documenter la zone d'ombre + healthz
  queue-depth (lire `healthz:check` couverture).
  Acceptance : verdict écrit (OK prouvé / zone d'ombre déclarée + mitigation).

### W6 — Validation totale
- PHPUnit large (suites touchées + Fiscal + Delivery + Pos) + vitest full +
  frozen diff 0 + chaîne OK + smoke web 11/11 + captures lues.
- Deploy VPS + Vercel si diffs. BRAIN §2 + memory + rapport owner.

## §G Gates owner (aucune bloquante pour ce GOAL)
| Gate | Détail | Statut |
|---|---|---|
| Secrets fin-de-projet (TAMPER staging, clé API forte, Mollie) | registre HANDOVER | PENDING owner |
| Lancement livraison (ENABLE + offerte + tarifs confirmés) | décision owner | PENDING owner |

## §F Done
GOAL clos quand W0→W6 fermées avec preuves, git propre, deploys verts,
rapport rendu. Les gates owner restent listées, jamais contournées.
