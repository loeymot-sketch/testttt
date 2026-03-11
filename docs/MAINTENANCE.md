# Plan de Maintenance - FoodKing SaaS

> **Version:** 1.0  
> **Date:** 11 Mars 2026  
> **Public:** Équipe technique et opérationnelle

---

## Table des Matières

1. [Vérifications Quotidiennes](#1-vérifications-quotidiennes)
2. [Vérifications Hebdomadaires](#2-vérifications-hebdomadaires)
3. [Vérifications Mensuelles](#3-vérifications-mensuelles)
4. [Surveillance en Temps Réel](#4-surveillance-en-temps-réel)
5. [Vérification de la Santé du Système](#5-vérification-de-la-santé-du-système)
6. [Procédures d'Urgence](#6-procédures-durgence)

---

## 1. Vérifications Quotidiennes

### 1.1 Matin (Avant Service)

**Temps estimé:** 10 minutes

#### Checklist Serveur

- [ ] **Espace disque** disponible (> 20%)
```bash
df -h | grep -E "Filesystem|/dev/"
```

- [ ] **Mémoire** disponible (> 20%)
```bash
free -h
```

- [ ] **Processus** critiques actifs
```bash
systemctl status mysql
systemctl status nginx    # ou apache2
systemctl status php-fpm  # si applicable
```

- [ ] **Logs** erreurs critique (dernières 24h)
```bash
grep -i "error\|critical\|emergency" /var/www/foodking/storage/logs/laravel.log | tail -20
```

#### Checklist Application

- [ ] **POS Web** accessible
```bash
curl -s -o /dev/null -w "%{http_code}" https://[domaine]/admin/pos
# Doit retourner: 200
```

- [ ] **API** répond correctement
```bash
curl -s https://[domaine]/api/frontend/item | grep -o '"status":true' | head -1
# Doit retourner: "status":true
```

- [ ] **File d'attente** (queue) jobs
```bash
cd /var/www/foodking && php artisan queue:status
# Ou vérifier jobs failed
php artisan queue:failed
```

#### Checklist Utilisateurs

- [ ] Aucun **caissier** ne reporte de problème de connexion
- [ ] Les **bornes Kiosk** sont opérationnelles
- [ ] L'**écran cuisine (KDS)** affiche les commandes
- [ ] L'**écran file d'attente (OSS)** est visible

### 1.2 Soir (Après Service)

**Temps estimé:** 15 minutes

#### Backup Quotidien

```bash
#!/bin/bash
# backup_daily.sh

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/backups/daily"
DB_NAME="foodking_prod"

# Backup base de données
mysqldump -u root -p[password] $DB_NAME > $BACKUP_DIR/db_$DATE.sql
gzip $BACKUP_DIR/db_$DATE.sql

# Supprimer backups de plus de 7 jours
find $BACKUP_DIR -name "db_*.sql.gz" -mtime +7 -delete

echo "Backup quotidien terminé: $DATE"
```

#### Vérification des Métriques Journalières

| Métrique | Commande/Outil | Seuil Alerte |
|----------|----------------|--------------|
| Commandes du jour | `SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()` | < 10 (vérifier) |
| Erreurs 500 | Logs | > 10/h |
| Temps réponse API | `curl -w "@curl-format.txt"` | > 2s |
| Taux de conversion Kiosk | Dashboard | < 60% |

---

## 2. Vérifications Hebdomadaires

### 2.1 Tous les Lundi Matin

**Temps estimé:** 30 minutes

#### Maintenance Base de Données

```sql
-- Optimiser les tables fréquemment accédées
OPTIMIZE TABLE orders;
OPTIMIZE TABLE order_items;
OPTIMIZE TABLE items;
```

```bash
# Via commande
mysqlcheck -o foodking_prod orders order_items items -u root -p
```

#### Analyse des Logs

```bash
# Extraire les erreurs récurrentes de la semaine
grep -E "ERROR|CRITICAL" /var/www/foodking/storage/logs/laravel.log.1 | \
  awk '{print $6}' | sort | uniq -c | sort -rn | head -20
```

#### Vérification Sécurité

- [ ] **Tentatives de login échouées**
```bash
grep "Failed login" /var/www/foodking/storage/logs/laravel.log | wc -l
```

- [ ] **IPs bloquées** (si système de blocage)
```bash
# Vérifier fail2ban ou équivalent
fail2ban-client status
```

- [ ] **Mises à jour de sécurité disponibles**
```bash
# Ubuntu/Debian
apt list --upgradable | grep -E "php|mysql|nginx"

# Composer
cd /var/www/foodking && composer audit
```

### 2.2 Analyse Hebdomadaire des Performances

```bash
#!/bin/bash
# weekly_report.sh

echo "=== RAPPORT HEBDOMADAIRE FOODKING ==="
echo "Semaine: $(date +%V)"
echo ""

# 1. Stats commandes
echo "--- Commandes ---"
cd /var/www/foodking
php artisan tinker --execute="
  \$week = now()->subWeek();
  \$orders = Order::where('created_at', '>=', \$week)->count();
  echo \"Commandes cette semaine: \$orders\n\";
  \$avg = Order::where('created_at', '>=', \$week)->avg('total');
  echo \"Panier moyen: \" . number_format(\$avg, 2) . \"€\n\";
"

# 2. Erreurs
echo ""
echo "--- Erreurs ---"
grep -c "ERROR" storage/logs/laravel.log.1 2>/dev/null || echo "0"

# 3. Performance
echo ""
echo "--- Performance DB ---"
mysql -u root -p -e "
  SELECT 
    table_name,
    round(((data_length + index_length) / 1024 / 1024), 2) as 'Size MB'
  FROM information_schema.TABLES
  WHERE table_schema = 'foodking_prod'
  ORDER BY (data_length + index_length) DESC
  LIMIT 10;
"
```

### 2.3 Maintenance Fichiers

- [ ] **Nettoyer les vieux logs**
```bash
find /var/www/foodking/storage/logs -name "laravel-*.log" -mtime +30 -delete
```

- [ ] **Vérifier taille des uploads**
```bash
du -sh /var/www/foodking/storage/app/public/
```

- [ ] **Backup hebdomadaire** (conservé 4 semaines)
```bash
# Exécuter le backup
mysqldump -u root -p foodking_prod | gzip > /backups/weekly/foodking_$(date +%Y%m%d).sql.gz
```

---

## 3. Vérifications Mensuelles

### 3.1 Maintenance Mensuelle

**Temps estimé:** 2-3 heures

#### Mise à Jour Système

```bash
# Mise à jour sécurité (Ubuntu/Debian)
sudo apt update
sudo apt upgrade -y

# Redémarrer services si nécessaire
sudo systemctl restart mysql
sudo systemctl restart nginx
```

#### Maintenance Approfondie DB

```sql
-- Vérifier intégrité
CHECK TABLE orders, order_items, items, users;

-- Analyser pour optimiser requêtes
ANALYZE TABLE orders, order_items, items;

-- Vérifier fragmentation
SELECT 
  table_name,
  engine,
  round(data_free/1024/1024, 2) AS data_free_mb
FROM information_schema.tables
WHERE table_schema = 'foodking_prod'
AND data_free > 0;
```

#### Revue de la Sécurité

- [ ] **Changer les mots de passe** des comptes critiques (tous les 3 mois)
- [ ] **Vérifier les accès** comptes inactifs
```sql
SELECT username, last_login_at 
FROM users 
WHERE last_login_at < DATE_SUB(NOW(), INTERVAL 3 MONTH);
```
- [ ] **Audit des permissions** admin
- [ ] **Revérifier** les clés API exposées

### 3.2 Rapport Mensuel

| Indicateur | Objectif | Mesure |
|------------|----------|--------|
| **Disponibilité** | > 99.5% | Uptime monitoring |
| **Temps réponse moyen** | < 500ms | API monitoring |
| **Erreurs 5xx** | < 0.1% | Logs |
| **Commandes/mois** | Croissance 5% | DB stats |
| **Tickets support** | Résolution < 24h | Support system |

---

## 4. Surveillance en Temps Réel

### 4.1 Métriques à Surveiller

```
┌─────────────────────────────────────────────────────────────┐
│                    DASHBOARD DE SURVEILLANCE                │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  🔴 CRITIQUE (Alerte immédiate)                              │
│  • Serveur DOWN                                             │
│  • Base de données DOWN                                      │
│  • Erreurs 500 > 10/min                                      │
│  • Espace disque < 10%                                       │
│                                                             │
│  🟡 ATTENTION (Investigation requise)                        │
│  • Temps réponse API > 2s                                    │
│  • File de jobs > 100                                        │
│  • Erreurs 404 inhabituelles                                 │
│  • Mémoire > 85%                                             │
│                                                             │
│  🟢 INFO (Monitoring standard)                               │
│  • Commandes/heure                                           │
│  • Connexions actives                                        │
│  • Taille base de données                                    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 4.2 Outils de Monitoring Recommandés

| Outil | Usage | Installation |
|-------|-------|--------------|
| **Laravel Telescope** | Debug requêtes | `composer require laravel/telescope` |
| **Laravel Horizon** | Queue Redis | `composer require laravel/horizon` |
| **New Relic** | APM complet | Agent serveur |
| **Uptime Robot** | Monitoring uptime | Service externe |
| **Loggly/Papertrail** | Agrégation logs | Service externe |

### 4.3 Configuration Alertes

```php
// config/monitoring.php (exemple)
return [
    'alerts' => [
        'disk_space' => [
            'threshold' => 20, // %
            'channels' => ['email', 'slack'],
        ],
        'error_rate' => [
            'threshold' => 10, // errors per minute
            'channels' => ['slack'],
        ],
        'response_time' => [
            'threshold' => 2000, // ms
            'channels' => ['email'],
        ],
    ],
];
```

---

## 5. Vérification de la Santé du Système

### 5.1 Endpoint de Health Check

**Endpoint:** `GET /api/health`

**Réponse attendue (200):**
```json
{
  "status": "healthy",
  "timestamp": "2026-03-11T14:32:00Z",
  "checks": {
    "database": "ok",
    "cache": "ok",
    "storage": "ok",
    "queue": "ok"
  }
}
```

### 5.2 Script de Vérification Complète

```bash
#!/bin/bash
# health_check_full.sh

echo "=== VÉRIFICATION SANTÉ SYSTÈME ==="
echo "Date: $(date)"
echo ""

FAILED=0

# 1. Vérifier espace disque
echo -n "[1/6] Espace disque... "
DISK_USAGE=$(df / | tail -1 | awk '{print $5}' | sed 's/%//')
if [ "$DISK_USAGE" -lt 90 ]; then
    echo "✓ OK ($DISK_USAGE%)"
else
    echo "✗ ALERTE ($DISK_USAGE%)"
    FAILED=1
fi

# 2. Vérifier mémoire
echo -n "[2/6] Mémoire... "
MEMORY_USAGE=$(free | grep Mem | awk '{printf("%.0f", $3/$2 * 100.0)}')
if [ "$MEMORY_USAGE" -lt 90 ]; then
    echo "✓ OK ($MEMORY_USAGE%)"
else
    echo "✗ ALERTE ($MEMORY_USAGE%)"
    FAILED=1
fi

# 3. Vérifier MySQL
echo -n "[3/6] MySQL... "
if systemctl is-active --quiet mysql; then
    echo "✓ OK"
else
    echo "✗ ARRÊTÉ"
    FAILED=1
fi

# 4. Vérifier Web Server
echo -n "[4/6] Web Server... "
if systemctl is-active --quiet nginx || systemctl is-active --quiet apache2; then
    echo "✓ OK"
else
    echo "✗ ARRÊTÉ"
    FAILED=1
fi

# 5. Vérifier API
echo -n "[5/6] API Response... "
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/api/frontend/item)
if [ "$HTTP_CODE" = "200" ]; then
    echo "✓ OK ($HTTP_CODE)"
else
    echo "✗ ERREUR ($HTTP_CODE)"
    FAILED=1
fi

# 6. Vérifier logs erreurs
echo -n "[6/6] Logs erreurs (1h)... "
ERROR_COUNT=$(grep -c "ERROR\|CRITICAL" /var/www/foodking/storage/logs/laravel.log 2>/dev/null || echo "0")
if [ "$ERROR_COUNT" -lt 50 ]; then
    echo "✓ OK ($ERROR_COUNT erreurs)"
else
    echo "⚠ ATTENTION ($ERROR_COUNT erreurs)"
fi

echo ""
if [ "$FAILED" -eq 0 ]; then
    echo "=== SYSTÈME SAIN ==="
    exit 0
else
    echo "=== PROBLÈMES DÉTECTÉS ==="
    exit 1
fi
```

### 5.3 Automatisation (Cron)

```bash
# Ajouter à crontab (crontab -e)

# Vérification quotidienne à 8h et 18h
0 8,18 * * * /var/www/foodking/scripts/health_check_full.sh >> /var/log/foodking_health.log 2>&1

# Backup quotidien à 2h du matin
0 2 * * * /var/www/foodking/scripts/backup_daily.sh

# Nettoyage logs hebdomadaire (dimanche 3h)
0 3 * * 0 /var/www/foodking/scripts/cleanup_logs.sh
```

---

## 6. Procédures d'Urgence

### 6.1 Système Lent ou Non Réactif

1. **Identifier le goulot d'étranglement**
```bash
# Processus consommant CPU
htop

# Requêtes MySQL lentes
mysql -u root -p -e "SHOW FULL PROCESSLIST;"

# Logs Laravel en temps réel
tail -f /var/www/foodking/storage/logs/laravel.log
```

2. **Redémarrage contrôlé (si nécessaire)**
```bash
# Redémarrer services (dans l'ordre)
sudo systemctl restart php-fpm  # si applicable
sudo systemctl restart nginx
sudo systemctl restart mysql
```

### 6.2 Base de Données Inaccessible

1. **Vérifier le service**
```bash
sudo systemctl status mysql
sudo systemctl restart mysql
```

2. **Vérifier l'espace disque**
```bash
df -h /var/lib/mysql
```

3. **Vérifier les erreurs MySQL**
```bash
sudo tail -50 /var/log/mysql/error.log
```

### 6.3 Restauration d'Urgence

```bash
#!/bin/bash
# emergency_restore.sh

# ATTENTION: Script de dernière recours

echo "=== RESTAURATION D'URGENCE ==="

# 1. Arrêter l'applicationsudo touch /var/www/foodking/storage/framework/down

# 2. Restaurer dernière backup stable
LATEST_BACKUP=$(ls -t /backups/daily/*.sql.gz | head -1)
echo "Restauration depuis: $LATEST_BACKUP"

gunzip < "$LATEST_BACKUP" | mysql -u root -p foodking_prod

# 3. Nettoyer caches
php /var/www/foodking/artisan cache:clear
php /var/www/foodking/artisan config:clear

# 4. Redémarrer
rm /var/www/foodking/storage/framework/down

echo "=== RESTAURATION TERMINÉE ==="
```

### 6.4 Contacts d'Urgence

| Situation | Contact | Délai |
|-----------|---------|-------|
| Serveur DOWN | Hébergeur / DevOps | Immédiat |
| Base de données corrompue | DBA / Administrateur | < 30 min |
| Paiements non fonctionnels | Support FoodKing | < 15 min |
| Sécurité compromise | Security team | Immédiat |

---

## 7. Fiche Référence Maintenance

```
┌─────────────────────────────────────────────────────────────────┐
│                  RÉFÉRENCE MAINTENANCE RAPIDE                   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  QUOTIDIEN (10 min)                                            │
│  □ Espace disque: df -h                                         │
│  □ Mémoire: free -h                                             │
│  □ Services actifs: systemctl status mysql nginx               │
│  □ Logs erreurs: grep ERROR storage/logs/laravel.log           │
│  □ API répond: curl /api/frontend/item                         │
│                                                                 │
│  HEBDOMADAIRE (30 min)                                         │
│  □ Optimiser DB: mysqlcheck -o                                 │
│  □ Analyser logs: grep ERROR laravel.log.1 | sort | uniq -c    │
│  □ Vérifier sécurité: composer audit                          │
│  □ Backup hebdo: mysqldump | gzip                              │
│  □ Nettoyer vieux logs: find -mtime +30 -delete                │
│                                                                 │
│  MENSUEL (2-3h)                                                │
│  □ Mise à jour sécurité: apt upgrade                           │
│  □ Analyse performances DB                                     │
│  □ Revue accès et permissions                                  │
│  □ Rapport métriques mensuelles                                │
│                                                                 │
│  URGENCE (Immédiat)                                            │
│  1. systemctl status mysql nginx                               │
│  2. df -h (espace disque)                                      │
│  3. tail -f storage/logs/laravel.log                           │
│  4. Si nécessaire: systemctl restart mysql                     │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

*Ce plan de maintenance doit être suivi rigoureusement. Toute anomalie doit être documentée dans le journal de maintenance.*
