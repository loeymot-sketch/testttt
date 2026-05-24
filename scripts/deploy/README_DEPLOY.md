# README_DEPLOY — FoodKing Le Cayenne V1 Production Deploy (Hetzner CX22)

> **Audience** : Owner exécutant physiquement le premier déploiement production
> du Cayenne, salle Sainte-Geneviève. Lecture step-by-step. Pas de magie.
> **Durée totale estimée** : ~80 min (6 phases) — first-deploy clean install.
>
> **Plateforme cible** : Hetzner Cloud CX22 (2 vCPU, 4 GB RAM, 40 GB SSD, ~4€/mo)
> · Ubuntu 24.04 LTS · Single-box LOCAL run (pas multi-instance ALB en V1).
>
> **Domaine** : `lecayenne.fr` (+ `www.lecayenne.fr`)
>
> **Branche déployée** : `main` (post-merge final) — la branche
> `heal/cms-pr1-quickwins-2026-05-18` (HEAD `b8ce23851`) est la candidate
> Wave Polish Final 14/14 owner-decisions Q1-Q14 (cf. PROJECT_BRAIN §2 +
> `reports/test-e2e/wave-polish-final-2026-05-21/CONVERGENCE_FINAL.md`).
>
> **NF525 compliance** : ce deploy active les **5 production boot guards**
> codifiés dans `app/Providers/AppServiceProvider.php:78-220` —
> `POS_SIMULATION_HARDWARE=false` + `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` +
> `APP_DEBUG=false` + `APP_URL` non vide + `CACHE_DRIVER=redis`. Le boot
> **REFUSE** de démarrer si l'un est violé.

---

## Sommaire

1. [Pré-requis owner](#section-1--prerequis-owner)
2. [Phase 1 — Server setup (~30 min)](#section-2--phase-1-server-setup-30-min)
3. [Phase 2 — Deploy app (~15 min)](#section-3--phase-2-deploy-app-15-min)
4. [Phase 3 — Nginx + SSL (~15 min)](#section-4--phase-3-nginx--ssl-15-min)
5. [Phase 4 — Supervisor + Soketi (~10 min)](#section-5--phase-4-supervisor--soketi-10-min)
6. [Phase 5 — Firewall + monitoring (~5 min)](#section-6--phase-5-firewall--monitoring-5-min)
7. [Phase 6 — DNS test + backup drill (~10 min)](#section-7--phase-6-dns-test--backup-drill-10-min)
8. [Owner physical action list](#section-8--owner-physical-action-list)
9. [Rollback runbook](#section-9--rollback-runbook)
10. [NF525 production deployment compliance](#section-10--nf525-production-deployment-compliance)

---

## Section 1 — Pré-requis owner

Avant de commencer, owner doit avoir terminé les 4 préparatifs suivants. Ne
PAS sauter — la moindre absence bloque la Phase 1.

### 1.1 Hetzner CX22 instance provisioned

- Compte Hetzner Cloud créé (https://console.hetzner.cloud)
- Project **"foodking-prod"** créé
- Instance créée :
  - **Type** : CX22 (2 vCPU AMD, 4 GB RAM, 40 GB NVMe SSD)
  - **Image** : Ubuntu 24.04 LTS
  - **Datacenter** : Falkenstein (FSN1) ou Nuremberg (NBG1) — proche France
  - **Backups Hetzner** : ACTIVÉ (option payante ~0,80€/mo — snapshot quotidien,
    rétention 7 jours, **complémentaire** au backup applicatif `backup-foodking-daily.sh`)
  - **Coût total** : ~4,80€/mo (instance + backups)
- IP publique notée (sera référée comme `<server-ip>` ci-dessous)

### 1.2 SSH key pair (ED25519 recommended)

- Sur le poste owner (laptop) :
  ```bash
  ssh-keygen -t ed25519 -C "owner@lecayenne.fr" -f ~/.ssh/lecayenne_prod
  ```
- Clé publique `~/.ssh/lecayenne_prod.pub` chargée dans **Hetzner Cloud
  Console → Security → SSH Keys → Add SSH key**.
- Clé attachée à l'instance CX22 au moment de la création (sinon `root` n'aura
  pas d'accès SSH key-based).
- **Test d'accès AVANT toute autre chose** :
  ```bash
  ssh -i ~/.ssh/lecayenne_prod root@<server-ip>
  ```
  Si ça refuse, ne PAS continuer — réparer la clé d'abord.

### 1.3 DNS lecayenne.fr → Hetzner IP

- Console DNS du registrar (OVH, Gandi, Cloudflare, etc.) :
  - **Record A** `lecayenne.fr` → `<server-ip>`
  - **Record A** `www.lecayenne.fr` → `<server-ip>`
  - **TTL** : 300 secondes (5 min) pour permettre changement rapide
- Propagation vérifiable :
  ```bash
  dig +short lecayenne.fr A
  dig +short www.lecayenne.fr A
  ```
  Les deux doivent retourner `<server-ip>` avant Phase 3 (Certbot).

### 1.4 GitHub repo access (private deploy key recommended)

- Repo `Kossay20/foodking-web` privé sur GitHub.
- **Deploy key** dédié serveur (read-only) :
  - Sur Hetzner après Phase 1.1 : `ssh-keygen -t ed25519 -C "lecayenne-prod-deploy" -f /home/deploy/.ssh/github_deploy -N ""`
  - Clé publique `/home/deploy/.ssh/github_deploy.pub` ajoutée dans
    **GitHub → repo Settings → Deploy keys → Add deploy key** (cocher "Allow read access" UNIQUEMENT, **pas** "Allow write access").
- Alternative simple V1 : repo cloné en `https://github.com/Kossay20/foodking-web`
  avec Personal Access Token (PAT) scope `repo:read`. Pour V1 LOCAL c'est
  acceptable, deploy key conseillée à la migration ALB.

---

## Section 2 — Phase 1 server setup (~30 min)

### 2.1 SSH initial

Depuis le laptop owner :

```bash
ssh -i ~/.ssh/lecayenne_prod root@<server-ip>
```

Acceptez le fingerprint si demandé (notez-le, conservez-le — toute changement
ultérieur = soupçon MITM).

### 2.2 Clone deploy scripts

```bash
git clone https://github.com/Kossay20/foodking-web /tmp/foodking-deploy
cd /tmp/foodking-deploy
```

Si repo privé sans deploy key encore configurée : utiliser HTTPS + PAT
(`https://<USER>:<PAT>@github.com/Kossay20/foodking-web.git`) ou cloner sur le
laptop puis `scp -r foodking-web root@<server-ip>:/tmp/foodking-deploy`.

### 2.3 Run server-setup.sh

```bash
bash /tmp/foodking-deploy/scripts/deploy/server-setup.sh
```

Ce script (cf. **`scripts/deploy/server-setup.sh`** — fourni par D.1 agent)
installe en idempotent :

| Composant     | Version cible | Vérification post-install                      |
|---------------|---------------|-------------------------------------------------|
| PHP           | 8.4 + ext     | `php -v` → 8.4.x + `php -m \| grep -E 'mbstring\|bcmath\|redis\|mysql\|gd\|intl\|zip'` |
| Composer      | 2.x           | `composer --version` → 2.x.x                    |
| Node.js       | 18.x LTS      | `node -v` → v18.x + `npm -v` → 10.x             |
| MySQL         | 8.0           | `systemctl status mysql` → active (running)     |
| Redis         | 7.x           | `redis-cli ping` → `PONG`                       |
| Nginx         | 1.24+         | `nginx -v` + `systemctl status nginx` active    |
| Supervisor    | 4.x           | `supervisorctl version` → 4.x                   |
| Soketi        | 1.x (npm)     | `soketi --version` ou `which soketi`            |
| Certbot       | 2.x           | `certbot --version`                             |
| ufw           | installed     | `ufw status` → inactive (activé en Phase 5)     |
| fail2ban      | installed     | `systemctl status fail2ban` → active            |
| User `deploy` | UID 1000+     | `id deploy` → uid + groups (sudo, www-data)     |

**Vérification owner pas-à-pas** : à chaque ligne de sortie du script,
contrôle que le composant installé renvoie une version. Si l'un échoue,
STOP — ne PAS continuer Phase 2. Relire les logs `/tmp/foodking-deploy/server-setup.log`.

### 2.4 Smoke test post-setup

```bash
php -v && composer --version && node -v && mysql --version && redis-cli ping && nginx -v && supervisorctl version && certbot --version
```

Toutes les lignes doivent répondre. Si l'une vide → fix avant Phase 2.

---

## Section 3 — Phase 2 deploy app (~15 min)

### 3.1 Préparer l'arborescence

```bash
sudo mkdir -p /var/www/lecayenne
sudo chown -R deploy:www-data /var/www/lecayenne
sudo chmod 750 /var/www/lecayenne
```

### 3.2 Préparer le .env owner-edited

Avant de lancer `deploy.sh`, owner doit copier le template `.env.example` et
remplir les **valeurs production**. Sur le laptop ou en SSH :

```bash
sudo -u deploy cp /tmp/foodking-deploy/.env.example /var/www/lecayenne/.env
sudo -u deploy nano /var/www/lecayenne/.env
```

**Valeurs à éditer impérativement** (sinon les 5 production boot guards
refusent de démarrer — cf. `app/Providers/AppServiceProvider.php:78-220`) :

| Clé                                  | Valeur production V1 LOCAL Le Cayenne                 |
|--------------------------------------|-------------------------------------------------------|
| `APP_ENV`                            | `production`                                          |
| `APP_DEBUG`                          | **`false`** (guard refuse boot si `true`)             |
| `APP_URL`                            | **`https://lecayenne.fr`** (guard refuse boot vide)   |
| `APP_KEY`                            | généré : `php artisan key:generate --show` puis colle |
| `POS_SIMULATION_HARDWARE`            | **`false`** (NF525 guard — refuse boot si `true`)     |
| `IDEMPOTENCY_MIDDLEWARE_ENABLED`     | **`true`** (refuse boot si pas `true`)                |
| `CACHE_DRIVER`                       | **`redis`** (NF525 audit chain `Cache::lock` cross-worker — refuse boot sur `array`/`null`) |
| `QUEUE_CONNECTION`                   | `redis`                                               |
| `SESSION_DRIVER`                     | `redis`                                               |
| `BROADCAST_DRIVER`                   | `pusher` (Soketi reverse) ou `null` (no realtime V1)  |
| `DB_CONNECTION`                      | `mysql`                                               |
| `DB_HOST`                            | `127.0.0.1`                                           |
| `DB_PORT`                            | `3306`                                                |
| `DB_DATABASE`                        | `lecayenne_prod`                                      |
| `DB_USERNAME`                        | `lecayenne_app` (créé par server-setup.sh)            |
| `DB_PASSWORD`                        | owner-set strong password (notez-le hors serveur)     |
| `REDIS_HOST`                         | `127.0.0.1`                                           |
| `REDIS_PASSWORD`                     | owner-set strong (server-setup.sh propose)            |
| `REDIS_PORT`                         | `6379`                                                |
| `MAIL_*`                             | SMTP du registrar (ex. Gandi/OVH) — V1 best-effort    |
| `FISCAL_HMAC_SECRET`                 | **généré 64 hex chars** : `openssl rand -hex 32`      |
| `FISCAL_Z_HMAC_SECRET`               | **généré séparément** : `openssl rand -hex 32`        |
| `SANCTUM_STATEFUL_DOMAINS`           | `lecayenne.fr,www.lecayenne.fr`                       |
| `SESSION_DOMAIN`                     | `.lecayenne.fr`                                       |
| `PUSHER_APP_ID` / `PUSHER_APP_KEY` / `PUSHER_APP_SECRET` | générés par owner pour Soketi      |
| `PUSHER_HOST`                        | `127.0.0.1`                                           |
| `PUSHER_PORT`                        | `6001`                                                |
| `STRIPE_KEY` / `STRIPE_SECRET`       | clés **LIVE** Stripe (à NE PAS confondre avec test)   |
| `SENANGPAY_*`                        | différer V1.0.1 (TPE pas branché Phase 1)             |
| `LOG_CHANNEL`                        | `daily` (rotation auto)                               |
| `LOG_LEVEL`                          | `warning` (NF525 invariants logged automatiquement)   |

**Important secrets** : les `FISCAL_HMAC_SECRET` + `FISCAL_Z_HMAC_SECRET` une
fois posés ne doivent **JAMAIS** être régénérés — la chaîne NF525 entière
serait invalidée. Notez les hors serveur (gestionnaire de mots de passe owner).

### 3.3 Run deploy.sh

```bash
bash /tmp/foodking-deploy/scripts/deploy/deploy.sh
```

Ce script (cf. **`scripts/deploy/deploy.sh`** — fourni par D.2 agent) exécute :

1. `cd /var/www/lecayenne && git clone <repo> .` (premier deploy)
2. `composer install --no-dev --optimize-autoloader --no-interaction`
3. `npm ci && npm run production` (Mix bundle assets)
4. **Pré-migrate backup automatique** (Phase H.4 REC-1, 2026-05-24) : `bash scripts/db/backup.sh --env=production ...` écrit un dump horodaté + manifest dans `storage/app/migration-backups/` AVANT chaque migrate. Backup fail = deploy abort (`exit 1`). Aucune action owner.
5. `php artisan migrate --force` (NF525 triggers `BEFORE DELETE` créés)
6. `php artisan storage:link`
7. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
8. `php artisan event:cache`
9. `chown -R deploy:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache`
10. `php artisan migrate:status` (vérifie 0 pending)
11. **Boot dry-run** : `php artisan tinker --execute='echo "BOOT_OK"'` — si l'un
    des 5 guards échoue, l'exception RuntimeException sera levée ICI, et le
    script échoue **avant** d'enchaîner Phase 3. **C'est le comportement voulu.**

### 3.4 Vérification health endpoint

```bash
sudo -u deploy php /var/www/lecayenne/artisan serve --host=127.0.0.1 --port=8000 &
sleep 3
curl -i http://127.0.0.1:8000/api/health
# attendre 200 OK + JSON {"status":"ok",...}
kill %1
```

### 3.5 Vérification NF525 chain

```bash
cd /var/www/lecayenne
sudo -u deploy php artisan fiscal:verify-chain --all
```

**Sortie attendue** : `CHAIN OK | count=<N> | last_hash=<sha256>`.

Sur premier deploy (DB fraîche, 0 audit_log), `count=0 | CHAIN OK (empty)`.
Sur restauration depuis backup, doit matcher exactement le `last_hash`
historique snapshoté dans `reports/test-e2e/wave-polish-final-2026-05-21/CONVERGENCE_FINAL.md`.

---

## Section 4 — Phase 3 nginx + SSL (~15 min)

### 4.1 Copier le template

```bash
sudo cp /tmp/foodking-deploy/scripts/deploy/nginx.conf.template /etc/nginx/sites-available/lecayenne
sudo cp /etc/nginx/sites-available/lecayenne /etc/nginx/sites-available/lecayenne.bak
```

Le `.bak` est la copie de sécurité pour rollback (Section 9).

### 4.2 Éditer server_name + paths

```bash
sudo nano /etc/nginx/sites-available/lecayenne
```

Lignes à ajuster (le template fourni par D.3 contient des placeholders
`__SERVER_NAME__` / `__APP_ROOT__` / `__SSL_FULLCHAIN__` / `__SSL_PRIVKEY__`) :

- `server_name lecayenne.fr www.lecayenne.fr;`
- `root /var/www/lecayenne/public;`
- `ssl_certificate /etc/letsencrypt/live/lecayenne.fr/fullchain.pem;` (créé Phase 4.5)
- `ssl_certificate_key /etc/letsencrypt/live/lecayenne.fr/privkey.pem;` (créé Phase 4.5)
- `include /etc/nginx/snippets/deny-sensitive-files.conf;` — pointe vers
  `scripts/deploy/nginx-deny-sensitive-files.conf` (déjà présent dans le repo,
  cf. `scripts/deploy/nginx-deny-sensitive-files.conf`)

### 4.3 Activer le site + désactiver default

```bash
sudo ln -sf /etc/nginx/sites-available/lecayenne /etc/nginx/sites-enabled/lecayenne
sudo rm -f /etc/nginx/sites-enabled/default
```

### 4.4 Test config + reload

```bash
sudo nginx -t
# sortie attendue : "syntax is ok" + "test is successful"
sudo systemctl reload nginx
```

Si `nginx -t` rouge : **NE PAS reload**. Lire l'erreur, corriger la ligne
incriminée, retester. Le `.bak` permet de revenir : `sudo cp /etc/nginx/sites-available/lecayenne.bak /etc/nginx/sites-available/lecayenne`.

### 4.5 Run Certbot (interactif, owner répond)

```bash
sudo certbot --nginx -d lecayenne.fr -d www.lecayenne.fr
```

Questions Certbot :
- **Email** : email owner pour notifications expiry — ex. `owner@lecayenne.fr`
- **Terms of Service** : `A` (Agree)
- **Share email with EFF** : `N` (au choix owner)
- **Redirect HTTP → HTTPS** : **`2`** (Redirect — force HTTPS, recommandé)

Sortie attendue :
```
Successfully received certificate.
Certificate is saved at: /etc/letsencrypt/live/lecayenne.fr/fullchain.pem
Key is saved at:         /etc/letsencrypt/live/lecayenne.fr/privkey.pem
```

### 4.6 Auto-renewal dry-run

```bash
sudo certbot renew --dry-run
```

Sortie attendue : `Congratulations, all simulated renewals succeeded`.

Certbot installe automatiquement un timer systemd `certbot.timer` —
vérification : `systemctl status certbot.timer` doit retourner `active (waiting)`.

---

## Section 5 — Phase 4 supervisor + soketi (~10 min)

### 5.1 Copier les templates

```bash
sudo cp /tmp/foodking-deploy/scripts/deploy/supervisor.conf.template /etc/supervisor/conf.d/lecayenne.conf
sudo nano /etc/supervisor/conf.d/lecayenne.conf
```

Le template (fourni par D.5 agent) contient les programmes :

| Program                  | Commande                                     | Process|
|--------------------------|----------------------------------------------|--------|
| `lecayenne-php-fpm`      | (géré par systemd `php8.4-fpm` directement)  | n/a    |
| `lecayenne-worker`       | `php artisan queue:work redis --tries=3`     | 2      |
| `lecayenne-schedule`     | (en cron, cf. CRONTAB_PROD.md)               | n/a    |
| `lecayenne-soketi`       | `soketi start --config=/etc/soketi.json`     | 1      |
| `lecayenne-horizon` (V1.0.1 opt)| `php artisan horizon`                 | 1      |

À éditer dans le template :
- Path `/var/www/lecayenne` partout
- User `deploy` partout
- Logs `/var/log/supervisor/lecayenne-*.log`

### 5.2 Configurer Soketi

```bash
sudo nano /etc/soketi.json
```

Contenu minimum :
```json
{
  "host": "127.0.0.1",
  "port": 6001,
  "appManager.driver": "array",
  "appManager.array.apps": [
    {
      "id": "<PUSHER_APP_ID-from-.env>",
      "key": "<PUSHER_APP_KEY-from-.env>",
      "secret": "<PUSHER_APP_SECRET-from-.env>",
      "maxConnections": 200,
      "enableClientMessages": false,
      "enabled": true
    }
  ]
}
```

### 5.3 Recharger Supervisor

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

Sortie attendue :
```
lecayenne-worker:lecayenne-worker_00   RUNNING   pid 12345, uptime 0:00:05
lecayenne-worker:lecayenne-worker_01   RUNNING   pid 12346, uptime 0:00:05
lecayenne-soketi                       RUNNING   pid 12347, uptime 0:00:05
```

Si l'un `FATAL` : `tail -50 /var/log/supervisor/lecayenne-<program>.log` —
souvent c'est un path ou un permission. Fix puis `supervisorctl restart <program>`.

### 5.4 Crons (deploy par CRONTAB_PROD.md)

```bash
sudo crontab -u deploy -e
```

Coller les entrées listées dans **`scripts/deploy/CRONTAB_PROD.md`** (fourni
par D.6 agent). Contenu canonique attendu :

```cron
# Laravel scheduler (toutes les minutes) — clôtures Z, NF525 retries, etc.
* * * * * cd /var/www/lecayenne && php artisan schedule:run >> /dev/null 2>&1

# Backup quotidien NF525 (audit_logs + z_reports + .env chiffré) — 02:30
30 2 * * * /var/www/lecayenne/scripts/backup-foodking-daily.sh >> /var/log/lecayenne-backup.log 2>&1

# Certbot auto-renew (déjà géré par certbot.timer, ligne défensive opt)
0 3 * * * certbot renew --quiet
```

**Vérification** : `sudo crontab -u deploy -l` doit retourner les 3 lignes.

---

## Section 6 — Phase 5 firewall + monitoring (~5 min)

### 6.1 SSH safety net AVANT ufw enable

**Ne JAMAIS activer ufw sans avoir ouvert un SECOND terminal SSH déjà connecté.**
Sinon, mauvaise règle = perte d'accès = re-provision serveur depuis Hetzner
Console.

Sur le laptop owner, dans un **deuxième terminal** :
```bash
ssh -i ~/.ssh/lecayenne_prod root@<server-ip>
```
Laisser ce terminal ouvert et inactif comme filet de sécurité.

### 6.2 Configurer ufw

Dans le terminal principal (premier) :

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw status numbered
```

Vérifier que les 3 règles sont présentes avant d'activer.

### 6.3 Activer ufw

```bash
sudo ufw enable
# Confirmer "y" — un avertissement SSH apparaît, c'est normal.
sudo ufw status verbose
```

Test : depuis le deuxième terminal, faire `ls /var/www/lecayenne`. Si la
commande répond, l'accès SSH est intact. Si elle freeze, **ne fermer aucun
terminal** — depuis le premier terminal `sudo ufw disable` et investiguer.

### 6.4 Fail2ban SSH jail

Vérifier que le jail SSH est actif :

```bash
sudo fail2ban-client status
sudo fail2ban-client status sshd
```

Sortie attendue :
```
Status for the jail: sshd
|- Filter
|  |- Currently failed: 0
|  |- Total failed:     0
|- Actions
|  |- Currently banned: 0
|  |- Total banned:     0
```

Si jail absent : `sudo systemctl restart fail2ban` et re-check.

### 6.5 Crons confirmés (Section 5.4)

Re-vérifier `sudo crontab -u deploy -l` après-coup.

---

## Section 7 — Phase 6 DNS test + backup drill (~10 min)

### 7.1 Premier accès navigateur

Sur laptop owner :
- Ouvrir un onglet privé / incognito
- Aller à `https://lecayenne.fr`

**Attendus** :
- Cadenas vert dans la barre URL (certificat Let's Encrypt valide)
- Page d'accueil Le Cayenne charge (hero burger Cayenne, menu, footer)
- Pas d'écran d'erreur Laravel (`Whoops`, `500`, etc.)
- HTTP→HTTPS redirect (taper `http://lecayenne.fr` → redirigé sur HTTPS)

Tester les surfaces production listées dans CLAUDE.md §6 :
- `https://lecayenne.fr/kiosk/idle` → écran kiosk borne accueil
- `https://lecayenne.fr/admin/pos` → login admin (puis POS Caisse)
- `https://lecayenne.fr/login` → admin login form
- `https://lecayenne.fr/kds` → Kitchen Display (post-login)
- `https://lecayenne.fr/admin/order-status-screen` → OSS

### 7.2 Backup drill manuel

Premier test du backup avant prod transactionnelle :

```bash
ssh -i ~/.ssh/lecayenne_prod root@<server-ip>
sudo -u deploy /var/www/lecayenne/scripts/backup-foodking-daily.sh
```

Vérifier dans `/var/backups/lecayenne/` :
- Fichier `lecayenne-YYYYMMDD-HHMMSS.sql.gz` créé
- Fichier `audit_logs-YYYYMMDD-HHMMSS.tar.gz` créé
- Taille non-zéro

### 7.3 Restore drill (mandatory avant ouverture prod)

```bash
# Créer une DB de test
mysql -e "CREATE DATABASE lecayenne_restore_test;"

# Restaurer un backup dedans
gunzip -c /var/backups/lecayenne/lecayenne-<timestamp>.sql.gz | mysql lecayenne_restore_test

# Vérifier la chaîne NF525 restaurée
cd /var/www/lecayenne
DB_DATABASE=lecayenne_restore_test sudo -u deploy php artisan fiscal:verify-chain --all
# attendu : CHAIN OK | count=<N> | last_hash=<sha256>

# Nettoyer
mysql -e "DROP DATABASE lecayenne_restore_test;"
```

Si CHAIN KO ou count différent du backup source : **NE PAS ouvrir prod**.
Investiguer (script `scripts/restore-foodking-from-backup.sh` peut aider).

---

## Section 8 — Owner physical action list

Après deploy technique réussi (Sections 2-7), owner exécute physiquement :

### 8.1 Rotate AWS demo keys (per H-SEC-001 owner action #1)

Si le serveur ou l'application a référencé des credentials AWS de démo
(historique observé dans `/insights` reports — cf. feedback_insights_full_2026-05-18.md
"fun_finding AWS keys"), owner connecté AWS console :

- **AWS Console → IAM → Users → <demo user> → Security credentials → Deactivate**
- Créer nouvelle clé production (si nécessaire) avec scope minimal
- Mettre à jour `.env` en conséquence (`AWS_ACCESS_KEY_ID` + `AWS_SECRET_ACCESS_KEY`)
- `sudo -u deploy php /var/www/lecayenne/artisan config:cache`
- Redémarrer workers : `sudo supervisorctl restart lecayenne-worker:*`

### 8.2 Upload real production Firebase Admin JSON (per H-SEC-001 owner action #3)

Pour les push notifications (PushNotification model — kiosk pickup ready, etc.) :

- Firebase Console → Project Settings → Service accounts → **Generate new private key**
- Télécharge `<project>-firebase-adminsdk-XXXXX.json`
- Upload via Admin UI : **Admin → Settings → Notification → Firebase Admin JSON**
  (route admin protégée par `permission:settings`)
- Le fichier est stocké encrypted-at-rest via Laravel encrypter (cf.
  `app/Services/Notification/FirebaseService.php`)

### 8.3 Configure Senangpay TPE (D8 — V1.0.1, defer)

Senangpay (TPE physique paiement carte) n'est PAS branché V1 — ouvre prod
en `cash` + `chèque` only. La config TPE est planifiée V1.0.1 — cf.
PROJECT_BRAIN §1 "V1.0.1 roadmap".

Si owner veut activer V1 quand même (carte-blanche) :
- Demander à Senangpay un compte LIVE + clés `SENANGPAY_MERCHANT_ID` +
  `SENANGPAY_SECRET_KEY` + `SENANGPAY_WEBHOOK_SECRET`
- Éditer `.env` puis `config:cache` + worker restart
- Tester webhook : `curl -X POST https://lecayenne.fr/senangpay-webhook/...`
  doit retourner 200 (HMAC vérifié) — cf. `project_route_audit_2026-05-08.md`
  note "Senangpay missing-class bug" — confirmer corrigé sur la branche
  déployée avant prod.

### 8.4 Choose monitoring service

V1 baseline = logs locaux + cron alerting via mail. V1.0.X recommandation :

| Service          | Coût V1    | Recommandation                                |
|------------------|------------|-----------------------------------------------|
| **Sentry**       | Free tier  | Errors PHP + JS — branchement 10 min          |
| **UptimeRobot**  | Free       | Ping `https://lecayenne.fr/api/health` 5 min  |
| **Hetzner Backups** | 0,80€/mo | Snapshot serveur quotidien (Section 1.1)     |
| **Cloudflare**   | Free CDN   | DDoS protection (proxy DNS, V1.0.X opt)       |

Décision owner. Pour V1 minimum : UptimeRobot + Hetzner Backups suffisent.

### 8.5 Generate LOYALTY_QR_SECRET (per GOAL-I2-HEAL-03 — I.8 P1 I8-E-001)

Generate `LOYALTY_QR_SECRET` via `openssl rand -hex 32` + put in production
`.env` BEFORE first deploy. `AppServiceProvider:161` boot guard REFUSES to
start in production when this is empty — without this step the app crashes
immediately at boot with `RuntimeException: LOYALTY_QR_SECRET must be set
in production (LCS-S-001)`.

```bash
ssh root@<server-ip>
cd /var/www/lecayenne
NEW_SECRET=$(openssl rand -hex 32)
# Append to production .env (DO NOT commit to git):
echo "" | sudo tee -a .env
echo "# Loyalty QR HMAC signing secret (LCS-S-001 / GOAL-I2-HEAL-03)" | sudo tee -a .env
echo "LOYALTY_QR_SECRET=${NEW_SECRET}" | sudo tee -a .env
sudo -u deploy php artisan config:cache
sudo supervisorctl restart lecayenne-worker:*
```

Rules:
- MUST be ≥ 32 chars / 256 bits (the boot guard rejects shorter values).
- MUST be unique per environment (never reuse staging value in prod).
- MUST NEVER be committed to git — treat as a secret like
  `FISCAL_AUDIT_SECRET` / `FISCAL_Z_REPORT_SECRET`.
- Rotation: see `config/loyalty.php` header docblock. Old plaintext path
  remains accepted during the transition window (legacy `FK:<code>` mobile
  clients) — set `LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT=false` only when
  field roll-out confirms zero plaintext traffic.

---

## Section 9 — Rollback runbook

### 9.1 Si `deploy.sh` fail

```bash
cd /var/www/lecayenne
git log --oneline -5   # noter le SHA précédent stable
git reset --hard <previous-sha>
sudo -u deploy composer install --no-dev --optimize-autoloader
sudo -u deploy php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo -u deploy npm ci && npm run production
```

**Migrations** : NE PAS faire `migrate:rollback` à l'aveugle. NF525 invariants
exigent que les triggers `BEFORE DELETE` restent en place (cf. CLAUDE.md §8).
Faire `php artisan migrate:status` d'abord, identifier la migration cassante,
demander owner gate (§ Human gate CLAUDE.md §10) avant rollback.

Si rollback DB indispensable (corruption schema, pas de business data perdue) :
```bash
sudo -u deploy php artisan migrate:rollback --step=1
```
Et **immédiatement** : `php artisan fiscal:verify-chain --all` pour confirmer
la chain HMAC n'a pas été cassée.

### 9.2 Si nginx config break

```bash
sudo cp /etc/nginx/sites-available/lecayenne.bak /etc/nginx/sites-available/lecayenne
sudo nginx -t && sudo systemctl reload nginx
```

Le `.bak` créé Phase 4.1 est la source de vérité pour rollback.

### 9.3 Si SSL renewal fail

```bash
sudo certbot certificates                # voir l'état des certs
sudo certbot renew --force-renewal       # forcer renew immédiat
sudo systemctl reload nginx              # appliquer
```

Si Certbot ne peut joindre Let's Encrypt (firewall, DNS) : vérifier `ufw status`
(80/tcp ouvert), `dig lecayenne.fr A` (DNS pointe bien serveur), `curl -I http://lecayenne.fr/.well-known/acme-challenge/test`
(retourne 200/404, pas 502).

### 9.4 Si Soketi/worker crash repeating

```bash
sudo supervisorctl status
sudo supervisorctl restart lecayenne-soketi
sudo supervisorctl restart lecayenne-worker:*
tail -100 /var/log/supervisor/lecayenne-worker_00.log
```

Si crash persiste après 3 restarts : check `.env` (clés Pusher cohérentes
entre `/etc/soketi.json` et `.env` Laravel) et `redis-cli ping`.

### 9.5 Full restore depuis backup

```bash
sudo bash /var/www/lecayenne/scripts/restore-foodking-from-backup.sh \
  /var/backups/lecayenne/lecayenne-<timestamp>.sql.gz
```

Le script (déjà présent dans le repo — cf.
`scripts/restore-foodking-from-backup.sh`) gère DB drop+restore +
`fiscal:verify-chain` + cache flush. **Owner gate obligatoire** avant exécution
(c'est destructif).

---

## Section 10 — NF525 production deployment compliance

### 10.1 Owner manually verifies CHAIN OK before opening first transaction

**Pré-ouverture première caisse** (J0) :
```bash
ssh root@<server-ip>
cd /var/www/lecayenne
sudo -u deploy php artisan fiscal:verify-chain --all
```

Doit retourner `CHAIN OK | count=0 | last_hash=GENESIS` (DB fraîche) **OU**
`CHAIN OK | count=<N> | last_hash=<sha256>` matching le snapshot historique
(restauration depuis Wave Polish Final).

**Si CHAIN KO** : NE PAS encaisser. NE PAS ouvrir Z. Contacter le développeur
(Claude session reprise) pour investigation. Une chaîne cassée = délit NF525
si transaction encaissée par-dessus.

### 10.2 6-year retention policy documented

NF525 article 88 : tout audit_log + z_report doit être conservé **6 ans**
post-clôture. Cela couvre :
- `audit_logs` table (HMAC chain-signed, BEFORE DELETE trigger MySQL)
- `z_reports` table (HMAC chain-signed, BEFORE DELETE trigger MySQL)
- Backups quotidiens `scripts/backup-foodking-daily.sh` archivés off-site

**Off-site** : V1 LOCAL = backup local + Hetzner Backups (7j rétention).
**V1.0.X** : externaliser sur S3 ou Backblaze B2 (rétention 6 ans config).

Owner doit documenter dans son livre de procédures la rétention engagée
(7j Hetzner + N années local OU N années S3 selon choix V1.0.X).

### 10.3 Quarterly restore drill scheduled

Tous les **3 mois**, owner exécute manuellement la Section 7.3 (restore drill)
pour confirmer que :
- les backups sont restaurables
- la chain HMAC est restaurable bit-identical (`last_hash` égal entre dump source et restore)
- le `fiscal:verify-chain` retourne `CHAIN OK`

Documenter le drill (date + count + hash) dans un journal owner. En cas
d'inspection fiscale, ce journal prouve la conformité du processus de
conservation.

### 10.4 Production boot guards (rappel)

Les 5 guards `AppServiceProvider.php:78-220` sont la **dernière barrière**
NF525 active. Si owner essaie de :
- éditer `.env` pour `APP_DEBUG=true` → app refuse de booter à la prochaine
  requête (RuntimeException)
- forcer `POS_SIMULATION_HARDWARE=true` → idem
- passer `CACHE_DRIVER=array` → idem
- vider `APP_URL` → idem
- désactiver `IDEMPOTENCY_MIDDLEWARE_ENABLED` → idem

C'est intentionnel. **NE PAS contourner.** Si un guard doit changer
(ex. UNI-03 : élargir le forbidden cache-driver list à `file`/`database` pour
ALB multi-instance), cela nécessite un **LOCK plan** (cf. `skills/lock-plan`)
+ owner-countersign + sentinel update.

---

## Cross-références aux autres scripts D-series

| Fichier                                                  | Producer    | Rôle                                                     |
|----------------------------------------------------------|-------------|----------------------------------------------------------|
| `scripts/deploy/server-setup.sh`                         | **D.1**     | Install PHP 8.4, Composer, Node 18, MySQL 8, Redis, Nginx, Supervisor, Soketi, Certbot, ufw, fail2ban + create user `deploy` |
| `scripts/deploy/deploy.sh`                               | **D.2**     | Clone repo, composer install, npm production, migrate, cache, boot dry-run |
| `scripts/deploy/nginx.conf.template`                     | **D.3**     | Template Nginx HTTPS + Laravel + WS proxy (ports 80/443/6001) |
| `scripts/deploy/README_DEPLOY.md`                        | **D.4** (ce fichier) | Owner step-by-step (vous êtes ici)                |
| `scripts/deploy/supervisor.conf.template`                | **D.5**     | Template Supervisor pour worker + Soketi                 |
| `scripts/deploy/CRONTAB_PROD.md`                         | **D.6**     | Crons production (schedule:run, backup, certbot renew)   |
| `scripts/deploy/nginx-deny-sensitive-files.conf`         | (repo)      | Snippet Nginx — deny `.env`, `.git`, `composer.*`, etc.  |
| `scripts/backup-foodking-daily.sh`                       | (repo)      | Backup quotidien NF525 (déjà présent, 4318 bytes)        |
| `scripts/restore-foodking-from-backup.sh`                | (repo)      | Restore depuis backup (déjà présent, 3838 bytes)         |
| `app/Providers/AppServiceProvider.php:78-220`            | (code)      | 5 production boot guards NF525                           |

---

## Fin — checklist owner deploy GO

Cocher avant déclarer prod ouverte :

- [ ] Section 1.1 → Hetzner CX22 provisioned + IP notée
- [ ] Section 1.2 → SSH ED25519 testé (`ssh root@<ip>` réussi)
- [ ] Section 1.3 → DNS lecayenne.fr + www propagés (`dig` confirmé)
- [ ] Section 1.4 → GitHub deploy key OU PAT en place
- [ ] Section 2 → server-setup.sh tous composants OK (12/12)
- [ ] Section 3.2 → `.env` 19 clés production éditées + secrets HMAC générés
- [ ] Section 3.3 → `deploy.sh` exit 0 + boot dry-run OK
- [ ] Section 3.4 → `/api/health` retourne 200
- [ ] Section 3.5 → `fiscal:verify-chain` retourne CHAIN OK
- [ ] Section 4.4 → `nginx -t` OK + reload OK
- [ ] Section 4.5 → Certbot interactive done + 2 certs créés
- [ ] Section 4.6 → `certbot renew --dry-run` OK
- [ ] Section 5.3 → `supervisorctl status` tous RUNNING
- [ ] Section 5.4 → `crontab -u deploy -l` 3 lignes
- [ ] Section 6.3 → `ufw enable` + SSH intact (deuxième terminal confirme)
- [ ] Section 6.4 → fail2ban sshd jail active
- [ ] Section 7.1 → `https://lecayenne.fr` cadenas vert + 5 surfaces OK
- [ ] Section 7.2 → backup manual OK + fichiers présents
- [ ] Section 7.3 → restore drill OK + CHAIN OK identique
- [ ] Section 8.1 → AWS demo keys rotated (si applicable)
- [ ] Section 8.2 → Firebase Admin JSON uploaded via Settings
- [ ] Section 8.4 → monitoring choisi (UptimeRobot minimum)
- [ ] Section 8.5 → `LOYALTY_QR_SECRET` généré (`openssl rand -hex 32`) + écrit dans prod `.env` + `config:cache` (BLOQUE le boot sinon)
- [ ] Section 10.1 → `fiscal:verify-chain` final pré-ouverture caisse
- [ ] Section 10.3 → date prochain restore drill (J+90) calendarisée

**Si 25/25 cochés** → prod ouverte. Owner peut ouvrir Z première caisse.

**Si l'un échoue** → ne PAS ouvrir prod. Loop fix → re-vérif (CLAUDE.md §5
étape 7 self-correct discipline).

---

> **Document** : `scripts/deploy/README_DEPLOY.md`
> **Producer** : DEPLOY DOC AGENT D.4
> **Date** : 2026-05-23
> **Branche source** : `heal/cms-pr1-quickwins-2026-05-18` HEAD `b8ce23851`
> **Lié à** : `plans/GOAL_V1_PRODUCTION_PERFECT_PHASE2_2026-05-18.md` Phase D
> + `reports/test-e2e/wave-polish-final-2026-05-21/CONVERGENCE_FINAL.md`
