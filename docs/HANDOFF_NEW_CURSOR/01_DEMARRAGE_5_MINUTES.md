# Démarrage 5 minutes — nouvelle session

## 1. Contexte en une phrase

**FoodKing** = monolithe **Laravel 9** + **SPA Vue 3** (admin, POS, KDS, OSS, **kiosk web** dans `resources/js`). MySQL. Commandes : **`Order`** et **`FrontendOrder`** (même table `orders`). Temps réel : **Laravel Broadcasting** (Pusher/Soketi) + **FCM** en complément.

## 2. À lire avant de coder quoi que ce soit de sensible

1. [`../PROJECT_CONTINUITY_AND_VISION.md`](../PROJECT_CONTINUITY_AND_VISION.md)  
2. [`../../AGENTS.md`](../../AGENTS.md)  
3. [`../ARCHITECTURE.md`](../ARCHITECTURE.md) (section « Zones gelées »)

## 3. Checklist environnement

- [ ] `.env` : `APP_URL`, DB, **`MIX_API_KEY`** (ou équivalent dans `config/app.php`)
- [ ] `BROADCAST_DRIVER=pusher` + variables Soketi/Pusher si vous voulez le temps réel (sinon polling ~30s)
- [ ] `QUEUE_CONNECTION` : `sync` en local possible ; prod → `database`/`redis` + worker
- [ ] Comptes test : [`../LOCAL_TEST_ACCOUNTS.md`](../LOCAL_TEST_ACCOUNTS.md)

## 4. Où est le code kiosk ?

- **Dans ce dépôt** : `resources/js/components/frontend/kiosk/`, routes `kioskRoutes.js`, store `kioskCart.js` / `kioskMenu.js`.
- **Shell Electron** : souvent dépôt ou dossier séparé (`borne-windows/`, etc.) — pas toujours dans le même workspace.

## 5. Commandes utiles

```bash
composer install && npm install
cp .env.example .env   # puis éditer
php artisan key:generate
php artisan migrate --seed
npm run dev            # ou npm run production
```

Tests (éviter OOM sur suite complète) :

```bash
php -d memory_limit=512M scripts/run_php_feature_batches.sh auth-security
npm test
```

## 6. Suite

→ [`02_ARCHITECTURE_MONOLITHE.md`](./02_ARCHITECTURE_MONOLITHE.md)  
→ [`04_FICHIERS_PIVOTS_PAR_FLUX.md`](./04_FICHIERS_PIVOTS_PAR_FLUX.md)
