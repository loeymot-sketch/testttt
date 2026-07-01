# Procédures de Déploiement FoodKing

> **Document:** Guide de déploiement et maintenance en production  
> **Version:** 1.0  
> **Date:** 11 Mars 2026  
> **Public:** DevOps, Administrateurs Système, Tech Leads

---

## Table des Matières

1. [Checklist Pré-Déploiement](#1-checklist-pré-déploiement)
2. [Procédure de Déploiement](#2-procédure-de-déploiement)
3. [Migrations de Base de Données](#3-migrations-de-base-de-données)
4. [Vérification Post-Déploiement](#4-vérification-post-déploiement)
5. [Procédures de Rollback](#5-procédures-de-rollback)
6. [Checklist de Sécurité](#6-checklist-de-sécurité)

---

## 1. Checklist Pré-Déploiement

### 1.1 Vérifications Code

| Élément | Action | Statut |
|---------|--------|--------|
| **Tests** | Tous les tests passent (`php artisan test`) | [ ] OK |
| **Lint** | Pas d'erreurs de style (`phpcs`, `eslint`) | [ ] OK |
| **Review** | Code review validée par 1+ développeur | [ ] OK |
| **Conflits** | Aucun conflit avec la branche principale | [ ] OK |
| **Documentation** | Documentation mise à jour si nécessaire | [ ] OK |

### 1.2 Vérifications Environnement

| Élément | Action | Statut |
|---------|--------|--------|
| **PHP Version** | Serveur en PHP 8.1+ | [ ] OK |
| **MySQL** | MySQL 8.0+ disponible | [ ] OK |
| **Node** | Node 18+ pour build | [ ] OK |
| **Extensions** | Extensions PHP installées (voir ci-dessous) | [ ] OK |
| **Permissions** | Droits `storage/` et `bootstrap/cache` OK | [ ] OK |
| **Backup** | Backup base de données récent | [ ] OK |

### 1.3 Extensions PHP Requises (Vérifier)

```bash
# Sur le serveur de production
php -m | grep -E "pdo|mbstring|xml|ctype|json|tokenizer|openssl|gd|zip|curl|fileinfo|bcmath"

# Liste complète requise:
# - pdo, pdo_mysql
# - mbstring
# - xml, dom
# - ctype
# - json
# - tokenizer
# - openssl
# - gd
# - zip
# - curl
# - fileinfo
# - bcmath
```

### 1.4 Variables d'Environnement (Vérifier)

| Variable | Production | À vérifier |
|----------|------------|------------|
| `APP_ENV` | `production` | [ ] |
| `APP_DEBUG` | `false` | [ ] |
| `APP_URL` | URL HTTPS | [ ] |
| `DB_HOST` | Host MySQL | [ ] |
| `DB_DATABASE` | Nom base prod | [ ] |
| `DB_USERNAME` | User dédié | [ ] |
| `DB_PASSWORD` | Mot de passe fort | [ ] |
| `SANCTUM_STATEFUL_DOMAINS` | Domaine(s) | [ ] |
| `FIREBASE_*` | Clés Firebase | [ ] |
| `PUSHER_*` | Config WebSocket | [ ] |

---

## 2. Procédure de Déploiement

### 2.1 Méthode Recommandée: Déploiement avec Git

```bash
# 1. Se connecter au serveur
ssh user@votre-serveur.com
cd /var/www/foodking

# 2. Passer en mode maintenance (optionnel mais recommandé)
php artisan down

# 3. Sauvegarder la base de données
mysqldump -u root -p foodking_prod > backup-$(date +%Y%m%d_%H%M%S).sql

# 4. Récupérer les derniers changements
git fetch origin
git reset --hard origin/main

# 5. Installer les dépendances
composer install --no-dev --optimize-autoloader
npm ci  # Plus rapide et fiable que npm install

# 6. Compilation des assets
npm run prod

# 7. Optimiser Laravel
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 8. Migrations (voir section 3)
php artisan migrate --force

# 9. Permissions
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache  # Adapter selon le serveur web

# 10. Redémarrer le mode production
php artisan up

# 11. Vérification (voir section 4)
php artisan about
curl -s http://localhost/api/frontend/setting | head -20
```

### 2.2 Déploiement Zero-Downtime (Avancé)

```bash
#!/bin/bash
# deploy.sh - Déploiement sans interruption

APP_DIR="/var/www/foodking"
RELEASE_DIR="$APP_DIR/releases/$(date +%Y%m%d%H%M%S)"
CURRENT_LINK="$APP_DIR/current"

# 1. Créer le répertoire de release
mkdir -p "$RELEASE_DIR"

# 2. Cloner le code
git clone --depth=1 git@github.com:org/foodking.git "$RELEASE_DIR"

# 3. Installer et compiler
cd "$RELEASE_DIR"
composer install --no-dev --optimize-autoloader
npm ci
npm run prod

# 4. Copier le .env
cp "$CURRENT_LINK/.env" "$RELEASE_DIR/.env"

# 5. Lien symbolique pour storage
ln -s "$APP_DIR/storage" "$RELEASE_DIR/storage"

# 6. Cache et migrations
php artisan config:cache
php artisan migrate --force

# 7. Atomically switch
ln -sfn "$RELEASE_DIR" "$CURRENT_LINK"

# 8. Redémarrer PHP-FPM/Nginx
sudo systemctl reload php8.1-fpm
sudo systemctl reload nginx
```

### 2.3 Déploiement Docker (Option)

```yaml
# docker-compose.prod.yml
version: '3.8'

services:
  app:
    build:
      context: .
      dockerfile: Dockerfile.prod
    environment:
      APP_ENV: production
      APP_DEBUG: false
    volumes:
      - ./storage:/var/www/storage
    networks:
      - foodking

  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - app
    networks:
      - foodking

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: foodking
      MYSQL_ROOT_PASSWORD_FILE: /run/secrets/db_root_password
    volumes:
      - mysql_data:/var/lib/mysql
    networks:
      - foodking

volumes:
  mysql_data:

networks:
  foodking:
    driver: bridge
```

---

## 3. Migrations de Base de Données

### 3.1 Avant la Migration

```bash
# 1. Backup OBLIGATOIRE
mysqldump -h localhost -u root -p --single-transaction \
  --routines --triggers \
  foodking_prod > backup-$(date +%Y%m%d_%H%M%S).sql

# 2. Vérifier l'état actuel
php artisan migrate:status

# 3. Tester en staging (si disponible)
# Copier le backup sur staging et tester la migration
```

### 3.2 Exécution des Migrations

```bash
# Méthode 1: Migration standard (avec confirmation)
php artisan migrate

# Méthode 2: Forcer sans confirmation (production)
php artisan migrate --force

# Méthode 3: Uniquement une migration spécifique
php artisan migrate --path=database/migrations/2024_03_11_xxxxxx_nom_migration.php
```

### 3.3 En Cas de Problème de Migration

```bash
# Option 1: Rollback de la dernière migration
php artisan migrate:rollback

# Option 2: Rollback de N migrations
php artisan migrate:rollback --step=3

# Option 3: Reset complet (toutes les migrations)
php artisan migrate:reset

# Option 4: Fresh + Seed (⚠️ PERD TOUTES LES DONNÉES)
php artisan migrate:fresh --seed
```

### 3.4 Migration avec Données Sensibles

```bash
# Pour les migrations modifiant des données critiques:

# 1. Mode maintenance
php artisan down

# 2. Backup
mysqldump -u root -p foodking_prod > backup-critical.sql

# 3. Migration
php artisan migrate --force

# 4. Vérifier les données
php artisan tinker -e "Order::count(); Item::count();"

# 5. Mode normal
php artisan up
```

### 3.5 Seeders en Production (Prudence)

```bash
# ⚠️ NE JAMAIS exécuter db:seed en production sans vérifier
# Les seeders peuvent écraser des données existantes

# Si nécessaire, exécuter un seeder spécifique:
php artisan db:seed --class=MenuSeeder --force

# Vérifier AVANT ce que fait le seeder:
cat database/seeders/MenuSeeder.php
```

---

## 4. Vérification Post-Déploiement

### 4.1 Checklist de Vérification

| Élément | Commande/Vérification | Résultat Attendu |
|---------|----------------------|------------------|
| **Application Up** | `php artisan about` | Pas d'erreurs |
| **Mode Prod** | `grep APP_ENV .env` | `production` |
| **Debug Off** | `grep APP_DEBUG .env` | `false` |
| **Routes** | `php artisan route:list` | Routes chargées |
| **Migration** | `php artisan migrate:status` | Up to date |
| **Config** | `php artisan config:show app` | Config OK |

### 4.2 Vérifications Fonctionnelles

```bash
# 1. Health Check API
curl -s https://votre-site.com/api/frontend/setting | python3 -m json.tool

# 2. Authentification (obtenir un token)
curl -X POST https://votre-site.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password","branch_id":1}'

# 3. Créer une commande test
curl -X POST https://votre-site.com/api/frontend/order \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "branch_id": 1,
    "order_type": 5,
    "items": [{"item_id": 1, "quantity": 1}]
  }'

# 4. Vérifier les logs (pas d'erreurs récentes)
tail -50 storage/logs/laravel.log
```

### 4.3 Vérifications Interface Web

| Page | URL | Vérifier |
|------|-----|----------|
| Login | `/admin` | Formulaire affiché |
| Dashboard | `/admin/dashboard` | Stats chargées |
| POS | `/admin/pos` | Menu affiché, wizard fonctionne |
| KDS | `/admin/kds` | Écran cuisine OK |
| OSS | `/admin/oss` | Écran file d'attente OK |

### 4.4 Scripts de Vérification Automatisée

```bash
#!/bin/bash
# health-check.sh

BASE_URL="https://votre-site.com"
LOG_FILE="/var/log/foodking-health.log"

echo "$(date): Starting health check" >> $LOG_FILE

# Check 1: API reachable
if curl -sf "$BASE_URL/api/frontend/setting" > /dev/null; then
    echo "$(date): API OK" >> $LOG_FILE
else
    echo "$(date): API FAIL" >> $LOG_FILE
    echo "ALERT: API not responding" | mail -s "FoodKing Alert" admin@votre-site.com
fi

# Check 2: No recent errors in logs
RECENT_ERRORS=$(grep -c "$(date +%Y-%m-%d)" /var/www/foodking/storage/logs/laravel.log | grep -i error || true)
if [ -z "$RECENT_ERRORS" ]; then
    echo "$(date): No recent errors" >> $LOG_FILE
else
    echo "$(date): Found errors: $RECENT_ERRORS" >> $LOG_FILE
fi

echo "$(date): Health check completed" >> $LOG_FILE
```

---

## 5. Procédures de Rollback

### 5.1 Rollback Rapide (Code)

```bash
# 1. Mode maintenance
php artisan down

# 2. Revenir à la version précédente
git log --oneline -5  # Voir les commits
git reset --hard HEAD~1  # ou git reset --hard COMMIT_HASH

# 3. Réinstaller les dépendances
composer install --no-dev --optimize-autoloader
npm ci
npm run prod

# 4. Re-cacher les configs
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. Relancer
php artisan up

# 6. Vérifier
curl -s https://votre-site.com/api/frontend/setting | head
```

### 5.2 Rollback Base de Données

```bash
# 1. Mode maintenance
php artisan down

# 2. Lister les backups disponibles
ls -la /var/backups/foodking/

# 3. Restaurer le backup
mysql -u root -p foodking_prod < /var/backups/foodking/backup-20240311_120000.sql

# 4. Vérifier les tables
mysql -u root -p -e "SHOW TABLES;" foodking_prod

# 5. Relancer
php artisan up
```

### 5.3 Rollback Complet (Urgence)

```bash
#!/bin/bash
# emergency-rollback.sh

echo "🚨 EMERGENCY ROLLBACK INITIATED 🚨"
echo "$(date)"

APP_DIR="/var/www/foodking"
BACKUP_DIR="/var/backups/foodking"
LATEST_BACKUP=$(ls -t $BACKUP_DIR/*.sql | head -1)

cd $APP_DIR

# 1. Stop application
php artisan down
echo "Application in maintenance mode"

# 2. Restore database
echo "Restoring database from: $LATEST_BACKUP"
mysql -u root -p foodking_prod < $LATEST_BACKUP

# 3. Revert code
git log --oneline -5
echo "Enter commit hash to revert to:"
read COMMIT
git reset --hard $COMMIT

# 4. Rebuild
composer install --no-dev --optimize-autoloader
npm ci
npm run prod

# 5. Cache
clear-all-cache

# 6. Restart
php artisan up

echo "✅ ROLLBACK COMPLETED"
echo "$(date)"
```

### 5.4 Points de Restauration (Snapshots)

Créer des snapshots réguliers pour rollback rapide:

```bash
# Snapshot complet (code + DB)
#!/bin/bash
# create-snapshot.sh

DATE=$(date +%Y%m%d_%H%M%S)
SNAPSHOT_DIR="/var/snapshots/foodking-$DATE"

mkdir -p $SNAPSHOT_DIR

# Backup code
cp -r /var/www/foodking $SNAPSHOT_DIR/code

# Backup DB
mysqldump -u root -p foodking_prod > $SNAPSHOT_DIR/database.sql

# Backup .env
cp /var/www/foodking/.env $SNAPSHOT_DIR/.env

echo "Snapshot created: $SNAPSHOT_DIR"
```

---

## 6. Checklist de Sécurité

### 6.1 Avant Déploiement

| Vérification | Action | Statut |
|--------------|--------|--------|
| **APP_DEBUG** | Vérifier `false` en prod | [ ] |
| **APP_KEY** | Unique et généré (`php artisan key:generate`) | [ ] |
| **DB_PASSWORD** | Mot de passe fort, pas dans le repo | [ ] |
| **HTTPS** | Forcer HTTPS (middleware/config) | [ ] |
| **Firewall** | Ports 80/443 uniquement ouverts | [ ] |
| **Sauvegardes** | Automatisées et testées | [ ] |

### 6.2 Fichiers Sensibles à Protéger

```bash
# Permissions correctes
chmod 600 .env
chmod -R 755 storage bootstrap/cache
chmod -R 644 storage/logs/*.log

# Fichiers à ne JAMAIS versionner
cat > .gitignore << 'EOF'
/.env
/.env.backup
/.env.production
/storage/*.key
/vendor
/node_modules
.phpunit.result.cache
Homestead.json
Homestead.yaml
npm-debug.log
yarn-error.log
EOF
```

### 6.3 Headers de Sécurité (Nginx)

```nginx
# /etc/nginx/sites-available/foodking
server {
    listen 443 ssl http2;
    server_name votre-site.com;

    # SSL
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Content-Security-Policy "default-src 'self'" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        # ... config PHP
    }
}
```

### 6.4 Monitoring Post-Déploiement

```bash
# Créer un cron pour surveillance
# crontab -e

# Health check toutes les 5 minutes
*/5 * * * * /var/www/foodking/scripts/health-check.sh

# Backup quotidien à 2h du matin
0 2 * * * /var/www/foodking/scripts/backup.sh

# Rotation des logs hebdomadaire
0 3 * * 0 /usr/sbin/logrotate -f /etc/logrotate.d/foodking
```

---

## Annexes

### A. Commandes de Secours

```bash
# Réparer les permissions
sudo chown -R www-data:www-data /var/www/foodking
sudo chmod -R 755 /var/www/foodking/storage
sudo chmod -R 755 /var/www/foodking/bootstrap/cache

# Vider tous les caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan clear-compiled

# Optimiser pour production
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Mode maintenance personnalisé
php artisan down --message="Mise à jour en cours" --retry=60

# Voir les tâches en queue
php artisan queue:work --queue=high,default --daemon --sleep=3 --tries=3
```

### B. Contact d'Urgence

| Rôle | Contact | Cas d'usage |
|------|---------|-------------|
| **Tech Lead** | tech-lead@company.com | Problèmes de déploiement |
| **DevOps** | devops@company.com | Infrastructure, serveurs |
| **Support** | support@company.com | Problèmes utilisateurs |

---

**Procédures de déploiement FoodKing.**

*⚠️ Suivre ces procédures à la lettre. Toute modification doit être documentée.*
