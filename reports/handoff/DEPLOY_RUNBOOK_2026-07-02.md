# DEPLOY RUNBOOK — 2026-07-02
**Git : POUSSÉ.** `origin/pos/category-first-caisse-2026-06-23` = `2957ee1ff` (set certifié V1-V4 `47f3ad545`
+ durcissement `2957ee1ff` + tickets cowork). Suite **3059/0**, NF525 CHAIN OK, frozen-diff 0.
**Reste = étapes SERVEUR (à lancer sur le VPS, SSH owner/cowork — je n'y ai pas accès).**

## 0. Pré-requis (à vérifier UNE fois sur le VPS)
Le boot refuse de démarrer en prod si ces flags `.env` sont faux (garde `AppServiceProvider`) — donc à poser AVANT :
```
POS_SIMULATION_HARDWARE=false
APP_DEBUG=false
IDEMPOTENCY_MIDDLEWARE_ENABLED=true
BROADCAST_DRIVER=pusher          # PAS 'log' (sinon aucun temps-réel KDS/OSS)
APP_URL=https://<le vrai domaine>
CACHE_DRIVER=file                # (ou redis) — pas array/null
```
+ au BUILD des bundles : `MIX_PUSHER_APP_KEY` / `MIX_PUSHER_*` doivent être présents (sinon Echo=undefined côté KDS/borne → repli polling).

## 1. Déploiement du code (le script existant convient — 0 migration dans ce lot)
```bash
cd /chemin/vers/app
bash tools/deploy-vps.sh          # git reset --hard origin/BRANCH + npm ci + npm run production + caches
```
Le script auto-vérifie que le nouveau bundle est en ligne (rollback auto sinon). **Mes commits n'ont AUCUNE
migration, AUCUN schéma, AUCUN nouvel env** → ce script suffit tel quel.

## 2. Compléments que le script N'A PAS (à faire à la main — sinon défauts connus)
```bash
php artisan config:cache          # le script fait config:clear mais PAS config:cache (recommandé prod)
# WORKER SYNCHRO — impératif : le temps-réel KDS/OSS en dépend (file 'high')
sudo supervisorctl restart foodking-worker    # doit lancer : php artisan queue:work --queue=high,default
#   → si pas de service supervisor, en créer un (voir §4). SANS worker = KDS en poll 5s (dégradé, pas cassé).
```

## 3. PURGE cache / service-workers (cause n°1 des « tickets doublés / anciens écrans »)
Le code de rendu est PROPRE (prouvé) — les défauts visuels = cache navigateur / service-workers / anciens
fichiers Startup. À chaque déploiement de la borne/caisse :
```bash
php artisan cache:clear && php artisan view:clear
```
+ côté POSTE borne/caisse (Chrome) : vider le cache + **désinscrire les service-workers** + supprimer les
anciens fichiers de démarrage (dossier Startup) qui relançaient une VIEILLE version (source du 2e ticket cuisine).

## 4. Supervisor worker (si absent) — gabarit
```ini
[program:foodking-worker]
command=php /chemin/vers/app/artisan queue:work redis --queue=high,default --tries=1 --timeout=120
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/chemin/vers/app/storage/logs/worker.log
```
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start foodking-worker
```

## 5. Vérifications post-déploiement (smoke)
- [ ] L'app boote (pas de RuntimeException → sinon un flag §0 est faux).
- [ ] `php artisan fiscal:verify-chain --all` → CHAIN OK.
- [ ] Worker vivant : `sudo supervisorctl status foodking-worker` = RUNNING.
- [ ] 1 vraie commande caisse → apparaît sur le KDS **instantané** (worker OK) ou ≤5s (poll).
- [ ] 1 vraie commande borne → sur le KDS badge « à encaisser » → encaissement → badge « réglé » + séquence fiscale.
- [ ] Ticket cuisine = 1 seul, format symbolique correct (pas de doublon = cache purgé).

## 6. Rollback
`tools/deploy-vps.sh` sauvegarde le commit courant + rollback auto si le build échoue. Manuel :
`git reset --hard <PREV_SHA> && npm run production && php artisan config:cache`.

---
**Résumé** : code poussé + certifié (3059/0, NF525 OK, frozen 0). Le déploiement serveur = §0→§5 sur le VPS.
Point unique de vigilance = le **worker `--queue=high,default`** (temps-réel) + la **purge cache/SW** (tickets).
