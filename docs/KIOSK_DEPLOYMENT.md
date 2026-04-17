# Déploiement Borne (Kiosk) autonome — Le Cayenne

> **Cible** : une borne tactile (iPad / Android tablet / PC) qui boot directement sur la page de commande client, sans écran de login ni intervention opérateur.

## 1. Modèle mental

- La borne est un **client public autonome** : pas de login visible côté caisse, pas de mot de passe à taper devant le client.
- L'auto-login se fait **côté serveur** : `config/kiosk.php` injecte des credentials machine dans `window.foodkingConfig.kioskAutoLogin`, et le router dispatche silencieusement `kioskCart/kioskLogin` via `auth/kiosk-login` dès qu'on arrive sur `/kiosk*`.
- La borne est **synchronisée en temps réel** avec POS/KDS/Admin via Pusher/Soketi (stock, disponibilités, nouvelles commandes).
- **Staff-only mode** (`STAFF_ONLY_MODE=true`) ne touche pas la borne : `/kiosk/*` reste public, c'est la seule URL publique du système.

## 2. Variables d'environnement — `.env`

```ini
# Flags globaux
STAFF_ONLY_MODE=true            # masque la vitrine client (Home/Menu/Offers), / = login
KIOSK_USE_POS_WIZARD=true       # borne réutilise le wizard POS (SSOT pricing/UX)

# Credentials machine borne (obligatoires pour l'auto-login hors APP_ENV=local)
KIOSK_REQUIRE_MACHINE_LOGIN=false   # false = auto-login silencieux ; true = écran saisie (tests/audit)
KIOSK_MACHINE_USERNAME=kiosk-lecayenne
KIOSK_MACHINE_PASSWORD=kiosk123      # renouveler via php artisan kiosk:rotate en prod
# En APP_ENV=local uniquement : si KIOSK_MACHINE_* sont absents, config/kiosk.php retombe sur
# kiosk-lecayenne / kiosk123 (aligné sur KioskMachineTableSeeder). Production : toujours définir explicitement.

# Locale borne
KIOSK_DEFAULT_LOCALE=fr

# Limites opérationnelles
KIOSK_MAX_ITEM_QTY=20
KIOSK_ORDER_RATE_LIMIT=5

# Temps réel (déjà actif)
BROADCAST_DRIVER=pusher
PUSHER_HOST=127.0.0.1
PUSHER_PORT=6001
PUSHER_SCHEME=http
```

## 3. Boot chain — de la mise sous tension à la commande

| Étape | Où | Action |
|-------|-----|--------|
| 1. Boot OS | Tablette | L'OS ouvre le navigateur en mode **kiosque plein écran** (Chrome `--kiosk`, Safari Guided Access, etc.) sur `https://<app>/kiosk` |
| 2. HTTP 200 | Laravel | `RootController@index` sert `master.blade.php` avec `window.foodkingConfig.kioskAutoLogin = { username, password }` (config/kiosk.php:55) |
| 3. Vue bootstrap | SPA | Router matche `/kiosk` → `KioskAppComponent` → guard `requireKioskAuth` (kioskRoutes.js:37) |
| 4. Auto-login | API | Guard dispatche `kioskCart/kioskLogin` → POST `/api/auth/kiosk-login` → token Sanctum machine |
| 5. Idle screen | SPA | Redirect vers `kiosk.idle` → vidéo promo / slogan "Touchez pour commander" |
| 6. Commande | SPA | Tap utilisateur → `kiosk.categories` → `kiosk.wizard/:itemId` (wizard POS-style) → `kiosk.cart` → `kiosk.payment` → `kiosk.waiting` → `kiosk.confirmation` |
| 7. Sync | Pusher | `branch.{branchId}` push `order.created` vers KDS/Admin en temps réel |

## 4. Synchronisation temps réel

La borne écoute ces canaux :

- `branch.{branchId}` : mises à jour stock / disponibilités item (`item.availability.updated`)
- `kiosk.{kioskMachineId}` (à venir V5) : notifications directes (maintenance, message admin)

La borne **émet** ces événements (outbox → Pusher) :

- `order.created` avec `channel = order-status-screen.{branchId}` → affichage OSS
- `kiosk.session.started` (analytics)

## 5. Mode maintenance

Un admin sur place peut basculer la borne en maintenance sans physiquement la rebooter :

1. Naviguer vers `/kiosk/admin` (depuis la borne ou à distance)
2. Saisir les credentials admin → active le flag `sessionStorage.kiosk_maintenance_mode = 1`
3. L'auto-login est suspendu jusqu'au reload — `getKioskAutoCredentials()` retourne `null` (kioskRoutes.js:24)
4. Fin de maintenance : reload = reprise auto-login normal

## 6. Sécurité

- Les credentials machine sont **uniquement** dans `.env` serveur, jamais hardcodés.
- `auth/kiosk-login` est rate-limited (`kiosk-login` limiter) — en cas de 429, vider Redis : `redis-cli FLUSHDB && php artisan cache:clear`.
- Le token machine est **scopé** : `kiosk_order` ability uniquement (pas d'accès admin, pas de refund, etc.).
- Rotation des credentials : `php artisan kiosk:rotate` (à activer en production).
- Mode kiosque navigateur **obligatoire** en production pour empêcher navigation vers d'autres URLs.

## 7. Rollback

Pour repasser temporairement en mode "kiosk avec écran login machine" (audit, tests) :

```bash
# .env
KIOSK_REQUIRE_MACHINE_LOGIN=true

php artisan config:clear
```

La borne affichera alors `KioskLoginComponent` avec saisie username/password.

## 8. Checklist pré-production

- [ ] `KIOSK_MACHINE_PASSWORD` rotaté depuis la valeur par défaut `kiosk123`
- [ ] Branch ID correct pour l'établissement : `KioskMachine.branch_id`
- [ ] Pusher/Soketi up (`http://127.0.0.1:6001` accessible depuis la borne)
- [ ] Certificat SSL valide côté serveur (les kiosques modernes bloquent HTTP non-chiffré)
- [ ] Tablet en mode kiosque fullscreen + pas d'accès physique aux boutons home
- [ ] Vidéo idle optimisée (< 10 Mo, boucle silencieuse, résolution tablette)
- [ ] Test auto-login : couper réseau 30 sec → rétablir → la borne doit reprendre seule sans intervention

---

**Référence code** : `config/kiosk.php`, `resources/js/router/modules/kioskRoutes.js`, `resources/views/master.blade.php`, `app/Http/Controllers/Api/KioskAuthController.php`.
