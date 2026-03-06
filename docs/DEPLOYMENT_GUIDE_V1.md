# Guide de Déploiement Production (V1)

Ce document décrit comment déployer l'application FoodKing (Périmètre Backend Laravel + Frontend Admin Vue 3) sur un environnement de production SaaS (Ubuntu 20.04/22.04 LTS ou Debian 11/12).

---

## 1. Pré-requis Serveur

La machine virtuelle (VPS ou instance Cloud) doit posséder :

- **OS :** Ubuntu 22.04 LTS recommandé.
- **Web Server :** Nginx ou Apache2.
- **PHP :** Version `^8.1.0` (8.1 ou 8.2).
- **Extensions PHP requises :** `bcmath`, `ctype`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `cURL`, `GD/Imagick`, `zip`.
- **Database :** MySQL 8.0+ ou MariaDB 10.4+.
- **Node.js :** v16+ (Pour compiler Vue3 si ce n'est pas fait en CI/CD).
- **Composer :** v2+.

## 2. Déploiement Codebase

1. Cloner le repo dans `/var/www/foodking-web`.
2. Donner les droits stricts à l'utilisateur web (ex: `www-data` pour Nginx/Ubuntu) :
   ```bash
   sudo chown -R $USER:www-data /var/www/foodking-web
   sudo find /var/www/foodking-web -type f -exec chmod 664 {} \;    
   sudo find /var/www/foodking-web -type d -exec chmod 775 {} \;
   sudo chmod -R 775 /var/www/foodking-web/storage
   sudo chmod -R 775 /var/www/foodking-web/bootstrap/cache
   ```

## 3. Installation des Dépendances

Exécutez l'installation en mode production pur (sans les packages de dev comme Pest/PHPUnit qui gonflent le vendor) :

```bash
composer install --optimize-autoloader --no-dev
```

Si le code Front-End (Vue 3) n'a pas été compilé (`public/mix-manifest.json` manquant), compilez-le :
```bash
npm ci
npm run prod
```

## 4. Configuration Environnement (`.env`)

1. Copier `.env.example` en `.env`.
2. Générer la clé de cryptage : `php artisan key:generate`.
3. Paramètres Vitaux :
   - `APP_ENV=production`
   - `APP_DEBUG=false` ⚠️ *(VITAL POUR LA SÉCURITÉ)*
   - `APP_URL=https://votre-domaine-foodking.com`
   - Configurer les credentials de la Base de données locale/RDS.

## 5. Préparation Opérationnelle (Tuning Production)

Exécutez les optimisations de cache Laravel. Ces commandes mettent en RAM structurée la config, les routes et les views pour des performances maximales :

```bash
php artisan optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Lancez la migration DB (avec ou sans --seed selon installation vierge ou update) :
```bash
php artisan migrate --force
```

## 6. Queues & Workers (Firebase / Mails)

Laravel gère les tâches asynchrones (envoyer des notifications Firebase FCM à la cuisine ou aux clients) via des Queues. Sur un serveur Nginx, vous DEVEZ utiliser **Supervisor** pour faire tourner le worker H24.

1. `sudo apt install supervisor`
2. Créer `/etc/supervisor/conf.d/foodking-worker.conf` :
```ini
[program:foodking-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/foodking-web/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/foodking-web/worker.log
```
3. Lancer : `sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start foodking-worker:*`

## 7. Planification (Cron Jobs)
Le planificateur Laravel permet le nettoyage automatique des logs, le calcul de stats, etc. Ajoutez ceci au CRON de l'utilisateur qui fait tourner PHP (ex: www-data).

```bash
* * * * * cd /var/www/foodking-web && php artisan schedule:run >> /dev/null 2>&1
```

## 8. Reverse Proxy (Nginx)

Exemple minimaliste de VirtualHost sécurisé :
```nginx
server {
    listen 80;
    server_name www.votre-domaine.com;
    root /var/www/foodking-web/public;

    # Forcer la redirection SSL
    index index.php index.html index.htm;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    }
}
```
*Note : N'oubliez pas Certbot / Let's Encrypt pour certifier en HTTPS. Les tokens Sanctum exigent du TLS en prod pour ne pas être interceptés (MITM).*
