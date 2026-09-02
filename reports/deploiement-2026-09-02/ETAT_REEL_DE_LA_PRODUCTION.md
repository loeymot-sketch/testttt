# État réel de la production — relevé du 2026-09-02

Ce document corrige trois affirmations fausses qui circulent dans les rapports du jour, et
consigne ce qui a été **mesuré** sur la machine, pas déduit.

---

## 1. L'accès SSH existe

`ssh lecayenne` ouvre `ubuntu@vps-418872ac` (51.210.111.124). Vérifié le 2026-09-02.

Deux rapports du jour affirment le contraire (« aucun chemin pour écrire en production »).
Ils cherchaient `~/.ssh/foodking_deploy_ed25519`, qui n'existe pas. Les clés réelles sont
`~/.ssh/lecayenne_prod` — référencée par l'entrée `Host lecayenne` de `~/.ssh/config` — et
`~/.ssh/lecayenne_ovh`. Chercher une clé par le nom qu'on imagine, puis conclure à l'absence
d'accès, a produit deux constats faux.

## 2. La production tourne sur PHP 8.1, pas 8.4

| Mesuré | Valeur |
| --- | --- |
| PHP CLI | 8.1.2 |
| PHP-FPM actif | `php8.1-fpm.service` |
| Autres PHP installés | aucun (`/usr/bin/php8.1` seul) |
| Exigence de l'application | `"php": "^8.1.0"` |
| Composer | 2.10.1 |
| Node / npm | v18.20.8 / 10.8.2 |
| MySQL | 8.0.46 |
| nginx | actif |
| Disque | 17 Go utilisés sur 73 (23 %) |

`scripts/deploy/deploy.sh` vérifie la présence de **php8.4** et refuse de continuer sans lui.
Sur cette machine, il s'arrêterait donc avant de rien faire. L'application, elle, est
compatible : c'est le script qui est en avance sur l'hôte.

## 3. La production suit exactement la pointe du distant

`/var/www/lecayenne` est sur `pos/category-first-caisse-2026-06-23`, dépôt
`loeymot-sketch/testttt`, à **0 commit de retard** sur son distant. Ses seules modifications
locales sont deux dossiers de sauvegarde non suivis — aucun code.

Conséquence : **rien n'atteint le restaurant tant que ce n'est pas poussé sur ce distant.**

## 4. Sauvegardes présentes

```
Sep 2 19:57  pre-deploy-20260902-195702.sql.gz   1 971 113 o
Sep 2 19:55  pre-deploy-20260902-195531.sql.gz          20 o   ← vide, échec silencieux
Sep 2 01:00  daily-2026-09-02.sql.gz             1 946 175 o
```

⚠ La sauvegarde de 19:55 fait **20 octets**. Une sauvegarde vide qui porte le nom d'une
sauvegarde est plus dangereuse que pas de sauvegarde du tout : elle rassure. À vérifier avant
de s'appuyer sur un fichier `pre-deploy` — comparer la taille à la quotidienne du jour.

## 5. Ce qu'un déploiement exige ici

Le `git pull` ne suffit pas : `public/.gitignore` ignore `/js/*.js`, les bundles se
construisent sur la machine cible. Séquence, sur une caisse NF525 **en service** :

```bash
php artisan foodking:backup-daily
php artisan fiscal:verify-chain --all        # doit dire CHAIN OK AVANT de commencer
git fetch origin && git checkout <révision retenue>
composer install --no-dev --optimize-autoloader
npm ci && npm run production                  # Node 18 sur cet hôte
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl reload php8.1-fpm nginx
php artisan fiscal:verify-chain --all        # et APRÈS
```

Contrôle de sortie : la page répond, la chaîne fiscale est intacte, et l'empreinte du bundle
servi correspond à celle construite — `curl -s https://<hôte>/js/app.js | shasum -a 256`
comparé au fichier sur disque. Sans cette dernière vérification, on ne sait pas ce qui est
réellement servi ; c'est ainsi qu'un correctif a été cru déployé pendant six sondages
le 2026-08-30.
