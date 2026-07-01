# Queue Worker Setup — Phase 36 (P0)

Ce document explique comment passer de `QUEUE_CONNECTION=sync` (développement) à `QUEUE_CONNECTION=database` (production) pour garantir la fiabilité du système en charge.

---

## Pourquoi un Queue Worker ?

**Problème actuel (`sync`) :**
- Les jobs (notifications FCM, emails) s'exécutent dans le thread HTTP
- Un envoi FCM lent (200-500ms) bloque la réponse au client
- Sous charge, les timeouts HTTP peuvent survenir
- Pas de retry automatique en cas d'échec

**Solution (`database` + worker) :**
- Les jobs sont stockés dans la table `jobs` instantanément
- Le worker les traite en arrière-plan, hors du cycle HTTP
- Retry automatique avec backoff (10s, 30s)
- Le client reçoit une réponse HTTP rapide (< 100ms)

---

## 1. Migration

La migration a déjà été créée :

```bash
php artisan migrate --path=database/migrations/2026_03_26_070719_create_jobs_table.php
```

Ou simplement :

```bash
php artisan migrate
```

---

## 2. Configuration `.env`

**Développement local (gardez `sync`) :**
```env
QUEUE_CONNECTION=sync
```

**Production (changer vers `database`) :**
```env
QUEUE_CONNECTION=database
QUEUE_DRIVER=database  # alias
DB_QUEUE_CONNECTION=mysql
DB_QUEUE_TABLE=jobs
```

---

## 3. Démarrage manuel du Worker

Test local (terminal) :

```bash
php artisan queue:work --queue=high,default --sleep=3 --tries=3 --timeout=30
```

Options :
- `--sleep=3` : pause 3s si pas de jobs
- `--tries=3` : 3 tentatives avant échec définitif
- `--timeout=30` : kill le job après 30s
- `--queue=default` : nom de la queue (peut être `fcm,emails,default`)

---

## 4. Configuration Supervisor (Production)

Supervisor garde le worker actif 24/7 et redémarre automatiquement en cas de crash.

### Installation

```bash
# Ubuntu/Debian
sudo apt-get install supervisor

# CentOS/RHEL
sudo yum install supervisor
```

### Configuration

Créer `/etc/supervisor/conf.d/foodking-worker.conf` :

```ini
[program:foodking-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/foodking/artisan queue:work --queue=high,default --sleep=3 --tries=3 --timeout=30 --max-jobs=1000
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2              ; 2 workers en parallèle
redirect_stderr=true
stdout_logfile=/var/www/foodking/storage/logs/worker.log
```

Options importantes :
- `numprocs=2` : 2 workers parallèles (ajuster selon CPU)
- `--max-jobs=1000` : restart après 1000 jobs (prévient memory leaks)

### Démarrage Supervisor

```bash
# Recharger la config
sudo supervisorctl reread
sudo supervisorctl update

# Démarrer les workers
sudo supervisorctl start foodking-worker:*

# Vérifier le statut
sudo supervisorctl status

# Voir les logs
sudo tail -f /var/www/foodking/storage/logs/worker.log
```

### Commandes utiles

```bash
# Redémarrer tous les workers
sudo supervisorctl restart foodking-worker:*

# Arrêter temporairement
sudo supervisorctl stop foodking-worker:*

# Voir les processus
sudo supervisorctl status foodking-worker:*
```

---

## 5. Monitoring

### Worker actif ?

```bash
ps aux | grep "queue:work"
```

### Jobs en attente ?

```sql
SELECT COUNT(*) FROM jobs WHERE queue = 'default';
```

Ou via Artisan :

```bash
php artisan queue:monitor
```

### Jobs échoués ?

```bash
php artisan queue:failed
```

Retry manuel :

```bash
php artisan queue:retry all    # retry tous
php artisan queue:retry 123    # retry job ID 123
```

---

## 6. Scaling (High Volume)

Si > 1000 jobs/heure :

1. **Augmenter les workers** :
   ```ini
   numprocs=4  ; dans supervisor config
   ```

2. **Queue dédiée FCM** (optionnel) :
   ```php
   SendFcmNotificationJob::dispatch(...)->onQueue('fcm');
   ```
   
   Config supervisor supplémentaire :
   ```ini
   [program:foodking-fcm-worker]
   command=php /var/www/foodking/artisan queue:work --queue=fcm --sleep=1 --tries=5
   numprocs=2
   ```

3. **Redis queue driver** (optionnel, plus rapide que database) :
   ```env
   QUEUE_CONNECTION=redis
   ```

---

## 7. Déploiement Checklist

- [ ] Migration jobs table exécutée
- [ ] `.env` : `QUEUE_CONNECTION=database`
- [ ] Supervisor installé et configuré
- [ ] Workers démarrés (`supervisorctl status` = RUNNING)
- [ ] Logs accessibles (`storage/logs/worker.log`)
- [ ] Test : créer une commande → vérifier qu'elle disparaît de `jobs` en < 5s

---

## Références

- [Laravel Queues Documentation](https://laravel.com/docs/9.x/queues)
- [Supervisor Documentation](http://supervisord.org/)
