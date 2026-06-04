# PR-03 — Sûreté du serveur mono-process (crash sous charge)

**Gravité (mandat owner)** : P1 (un crash serveur = arrêt service).
**Risque d'exécution** : FAIBLE (knob env + supervisor template ; aucune logique métier).

---

## §1 — Problématique + cause racine
`php artisan serve` est **mono-process** (1 requête à la fois). Un soak de 4.92h propre s'est terminé par un **crash du serveur sous charge concurrente** (workflow agents + E2E browser + phpunit frappant l'unique worker en parallèle — mémoire 2026-06-01). Données/chaîne intactes. Le vrai fix prod (php-fpm/nginx multi-worker) = **jalon cloud**, pas V1-local.

## §2 — TOUS les fichiers concernés (vérifiés)
**À MODIFIER / ÉTENDRE :**
- `scripts/deploy/supervisor.conf.template` — ajouter un programme serve local à **redémarrage capé** + env `PHP_CLI_SERVER_WORKERS`
- `scripts/foodking-up.sh` (PR-01) — lancer `serve` avec `PHP_CLI_SERVER_WORKERS=4`
- 1 doc opérationnel (garde « pas de charge lourde pendant le service »)
**Lus (preuve no-gap — NE PAS modifier, frozen NF525) :**
- `app/Services/Fiscal/FiscalSequenceService.php:57-114` (`lockForUpdate` dans `DB::transaction`)
- `app/Services/OrderService.php:1179,1224` (alloc dans la même transaction que l'order)
**Précédent empirique :** `reports/stress-q13-direct-2026-05-21.md:71` (`PHP_CLI_SERVER_WORKERS=8` déjà prouvé), `reports/test-e2e/ultraudit-visual-2026-05-30/round-1/web-visual-findings.md`.

## §3 — Solution + raisonnement fort
Deux mesures **sans nouvelle dépendance** :
1. **`PHP_CLI_SERVER_WORKERS=4` sur `artisan serve`** : le serveur PHP intégré (PHP 8.2.30) supporte le multi-worker natif. **Déjà prouvé dans CE repo** (stress-q13 : single-process droppait les connexions, `=8` rendait proprement, même code). Mitigation locale gratuite. Pas de Octane/Valet/Swoole (absents de composer).
2. **Auto-restart capé** (supervisor `startretries=3` + backoff + **log bruyant** par restart). Le danger n'est PAS la corruption (voir §5) mais le **restart-loop qui masque un crash récurrent**.
3. **Garde opérationnel** : pas de batch lourd (E2E/phpunit/armée d'agents) pendant que la boîte sert en live.
php-fpm/nginx reste le vrai fix **prod** → **correctement différé au cloud** (PR Wave 5).

## §4 — Simulation d'impact
- `PHP_CLI_SERVER_WORKERS=4` → la boîte encaisse 1 caissier + quelques bornes (concurrence modeste réelle) sans drop. Le crash observé était sous charge **artificielle** d'agents, pas du trafic Le Cayenne.
- Auto-restart capé → un serve mort redémarre (≤3×) en loggant fort ; un flapping devient visible au lieu d'être caché.

## §5 — ⚔️ Analyse adversariale (effets négatifs)
| # | Effet | Preuve | Sévérité |
|---|---|---|---|
| ✅ | **Un kill mid-transaction ne peut PAS créer de trou fiscal** : alloc = `MAX(seq)+1` dans `lockForUpdate` + `DB::transaction` ; kill → rollback InnoDB atomique → next order recompute MAX+1. Pas de gap/doublon/écriture partielle. | FiscalSequenceService.php:76-104 ; OrderService.php:1179,1224 | — (rassurant) |
| N1 | **Restart-loop masquant un crash récurrent** (vrai danger). | — | MEDIUM → capé+loggé (§3.2) |
| N2 | `PHP_CLI_SERVER_WORKERS` n'est PAS prod-grade (pas de supervision opcode/process) → ne PAS le vendre comme substitut à php-fpm. | composer (pas d'Octane) | LOW (cadrage) |

## §6 — Ajustements pour ZÉRO effet négatif
1. Auto-restart **capé** (`startretries=3`) + backoff + **log bruyant** (jamais de résurrection silencieuse infinie).
2. `PHP_CLI_SERVER_WORKERS=4+` dans le lancement serve (script PR-01).
3. Doc garde : « pas de charge concurrente lourde pendant le service live ».
4. Ne PAS prétendre que c'est le fix prod → php-fpm reste explicitement différé cloud.

## §7 — NE PAS toucher / RESPECTER
- Frozen NF525 (`FiscalSequenceService`, `ZReportService`, `AuditLogService`, `OrderStateMachine`) : **lecture seule** ici, aucun changement requis.
- Ne pas redémarrer le `serve` vivant manuellement (le supervisor s'en charge proprement).
- Pas de `git add -A`.

## §8 — Acceptation + rollback
- **Accept** : serve lancé avec `PHP_CLI_SERVER_WORKERS=4` ; un soak **SOLO** (rien d'autre ne frappe la boîte) sans crash = preuve no-crash ; restart capé loggé visible.
- **Rollback** : retirer le knob / le programme supervisor (additif) ; retour `artisan serve` simple.
