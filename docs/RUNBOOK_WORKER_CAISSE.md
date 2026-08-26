# Runbook — le worker de file (comptoir)

> Une page. Pas un fil de discussion.
> Écrit le 2026-08-25 (GOAL `CONSOLIDATION_V1_PRODUCTION_20260825`, T-3.3.1) après une panne réelle :
> worker absent, **436 travaux en attente**, cuisine qui ne recevait plus.

---

## 1. Le symptôme, vu du comptoir

La pastille de santé de la caisse affiche **« Traitement en retard »** (orange), et la cuisine ne
voit plus arriver les commandes, alors que la caisse les enregistre normalement.

C'est le bon symptôme : la pastille dit la vérité. Elle est repassée au vert toute seule après
redémarrage lors de l'incident du 2026-08-23 — aucun vidage de cache n'est nécessaire
(verrouillé par `tests/Feature/Pos/PosSystemHealthQueueRecoveryTest.php`).

---

## 2. Vérifier en 10 secondes

```bash
# Le worker tourne-t-il ?
pgrep -af "queue:work"

# Combien de travaux attendent ?
php artisan tinker --execute='foreach ((array) config("queue.monitored_queues") as $f) {
    echo $f." = ".Illuminate\Support\Facades\Queue::size($f)."\n"; }'
```

Lecture :
- **worker absent** + file qui monte → c'est la panne décrite ici, allez au §3.
- **worker présent** + file qui monte → il traite plus lentement qu'on ne produit, ou il bloque sur
  un travail. Regardez les journaux avant de redémarrer.
- **worker présent** + files à 0 → le retard vient d'ailleurs (websocket, réseau cuisine).

---

## 3. Redémarrer

### En développement (poste local)
```bash
php artisan queue:work --queue=high,default --tries=3 --timeout=60
```

### En production
Le worker est géré par **supervisor** (`scripts/deploy/supervisor.conf.template`) :

```bash
sudo supervisorctl status                        # lecayenne-queue-worker_00, _01, lecayenne-soketi
sudo supervisorctl restart lecayenne-queue-worker:*
sudo tail -n 100 /var/log/supervisor/lecayenne-queue-worker.log
```

⚠️ Le service s'appelle **`lecayenne-queue-worker`**. `scripts/deploy/deploy.sh` cible
`lecayenne-queue-worker:*` mais **ne peut pas tourner sur le VPS actuel** (il exige PHP ≥ 8.4, le
serveur a 8.1, et lance tout en `sudo -u www-data` qui n'a pas les droits ici). Voir `PROJECT_BRAIN §2`.

---

## 4. 🔴 La file `notifications` n'est PAS traitée — et c'est voulu, pour l'instant

**À lire avant tout redémarrage.**

Le worker écoute `--queue=high,default`. La file **`notifications`** — alimentée par
`App\Jobs\SendFcmNotificationJob` (notifications push clients) — **n'est écoutée par personne**.

Au 2026-08-25 : **1 490 travaux en attente**, `attempts=0`, jamais tentés.

⛔ **N'ajoutez pas `notifications` au `--queue` sans avoir traité le retard.** Le worker enverrait
d'un coup 1 490 notifications push portant sur des commandes vieilles de plusieurs semaines. Des
clients recevraient « votre commande est prête » pour un repas consommé le mois dernier.

Décision propriétaire en attente → `reports/audit/P0_FILE_NOTIFICATIONS_ORPHELINE_2026-08-25.md`.

---

## 5. Vérifier que c'est reparti

```bash
curl -s http://127.0.0.1:8766/api/healthz | python3 -m json.tool
```

- `checks.queue_pending` doit **redescendre** au fil des secondes.
- ⚠️ Il inclut désormais `notifications` (1 490) : tant que cette file n'est pas tranchée, ce
  nombre **ne descendra pas à zéro**. Comparez la tendance, pas la valeur absolue.
- La pastille caisse (`/admin/pos`) doit repasser au vert **sans intervention sur le cache**.

---

## 6. Ce qu'il ne faut pas faire

| ⛔ | Pourquoi |
|---|---|
| `php artisan queue:flush` / `queue:clear` | Détruit des travaux non traités — des commandes cuisine peuvent y être. Destructif, accord propriétaire requis. |
| `composer dump-autoload` sur un serveur qui tourne | Casse l'autoload en état intermédiaire (CLAUDE.md §3quater). |
| Ajouter `notifications` au worker sans purge | 1 490 notifications d'un coup sur des commandes périmées (§4). |
| Conclure « la pastille ment » | Elle a dit vrai lors de l'incident réel du 2026-08-23. Vérifiez le worker avant de douter de la sonde. |

---

## 7. Où regarder ensuite

- Sonde caisse : `app/Http/Controllers/Admin/PosSystemHealthController.php` (seuil `STALE_OUTBOX_THRESHOLD = 10`)
- Sonde exploitation : `app/Http/Controllers/HealthzController.php` → `probeQueuePending()`
- Files surveillées : `config/queue.php` → `monitored_queues`
- Sentinelle : `tests/Feature/Health/FilesSurveilleesTest.php` — échoue si une file publiée par le
  code n'est pas surveillée
- Contrat de synchro : `SYNC_CONTRACT.md`
