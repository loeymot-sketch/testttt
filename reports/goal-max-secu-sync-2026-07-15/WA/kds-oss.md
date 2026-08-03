# Audit KDS + OSS — synchro + impression (W4)
Date : 2026-07-15 · Périmètre : KDS (KitchenDisplaySystemComponent.vue, KitchenDisplaySystemController/Service), OSS (OrderStatusScreen*, PreparingAndReadyComponent.vue), sync WS→poll (KdsSyncService.js / OssSyncService.js), pont impression cuisine (kitchenLocalPrinter.js + tools/kitchen-bridge).

## Verdict synthétique
Le cœur synchro/impression est globalement solide (dé-dup persistée, garde in-flight, retry auto, back-off, clamp cadence, garde release-board partagée KDS↔OSS↔sync). **1 défaut RÉEL P1** (RBAC : rôle Chef = opérateur KDS désigné ne peut PAS imprimer le ticket cuisine → auto-print + réimpression cassés à vie) + **1 P3** (perte de ticket au montage/reload). Aucun P0 fiscal/argent trouvé côté KDS/OSS.

---

## P1 — Le rôle Chef (opérateur KDS désigné) ne peut PAS imprimer le ticket cuisine → impression AUTO + réimpression cassées à 100 %, retry 403 illimité

**Fichiers**
- Gate : `app/Http/Controllers/Admin/Pos/PosTicketBytesController.php:28` — `abort_unless($request->user()?->can('pos'), 403);`
- Route : `routes/api.php:968` — `GET admin/pos/orders/{order}/escpos` (groupe POS, aucune perm KDS)
- Appelant KDS : `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:2009` (`ax.get('admin/pos/orders/${order.id}/escpos', {params:{ticket:'kitchen'}})`)
- Rôle : `database/seeders/RolePermissionTableSeeder.php:113-123` — Chef = `dashboard, kitchen-display-system, order-status-screen` (PAS `pos`)
- Landing : `database/seeders/LeCayenneRoleLandingUrlSeeder.php:28` — `'Chef' => 'kitchen-display-system'`

**Mécanique du défaut**
La fonctionnalité KITCHEN-BRIDGE (impression cuisine AUTO à chaque commande) récupère les octets ESC/POS via `GET admin/pos/orders/{id}/escpos?ticket=kitchen`. Cet endpoint est gardé `abort_unless(can('pos'), 403)`. Or l'écran cuisine `/kds` est accessible au rôle **Chef** (perm `kitchen-display-system`), et c'est même sa page d'atterrissage désignée. Chef n'a PAS `pos` → **403 sur chaque ticket**.
- `autoPrintKitchenTicket` (l.2011-2019) attrape le 403 en `{ok:false, retriable:true}` → `_addKitchenFailed` → **retry toutes les 20 s à vie** (`KITCHEN_PRINT_RETRY_MS=20000`, l.1667-1669) → re-403.
- Le badge « ⚠️ N tickets échec » (l.232-235) enfle sans borne et ne se vide jamais.
- Le bouton 🖨️ réimpression manuelle (`reprintKitchenTicket` → même `autoPrintKitchenTicket`) 403 aussi.
- Le POST alternatif `orders/{order}/print-kitchen` (api.php:965) est `permission:pos-orders|pos` → **exclut Chef également**. Aucune voie d'impression cuisine n'est ouverte à Chef.

Conséquence terrain : un PC cuisine loggé avec le compte « Chef » (choix sémantiquement correct, seedé, landing = KDS) → **aucun ticket cuisine papier ne sort jamais**, en silence, avec spam réseau 403 toutes les 20 s. Le POS Operator, lui, a `pos` + `kitchen-display-system` (GAP-19-5) donc marche — ce qui **masque** le bug dans la config actuelle mais ne le corrige pas.

**Repro (live, serveur :8000)**
```
# token du user id=4 (rôle Chef, branch=1) ; commande 5678 branch=1
curl -s -w '\nHTTP %{http_code}\n' \
  -H "Authorization: Bearer <CHEF_TOKEN>" -H "Accept: application/json" -H "x-api-key: <KEY>" \
  "http://127.0.0.1:8000/api/admin/pos/orders/5678/escpos?ticket=kitchen"
# → {"message":""}  HTTP 403
```
(403 = gate permission `can('pos')`, pas 404 branch — la commande est bien branch=1.)

**Couverture test manquante** : `tests/Feature/Pos/PosTicketBytesEndpointTest.php` n'exerce que `POS Operator` et un user sans permission ; **aucun test Chef** → le trou est passé sous le radar.

**Fix scope-minimal proposé (hors frozen)** : autoriser la lecture des octets **ticket cuisine** aux détenteurs de `kitchen-display-system`. Le ticket `kitchen` ne porte AUCUN incrément fiscal (lecture seule, pas d'audit), donc sûr. Ex. dans `PosTicketBytesController::show` :
```php
$ticket = $request->query('ticket') === 'kitchen' ? 'kitchen' : 'client';
$canPos = $request->user()?->can('pos');
$canKds = $request->user()?->can('kitchen-display-system');
abort_unless($canPos || ($ticket === 'kitchen' && $canKds), 403);
```
(+ test Chef `ticket=kitchen` → 200, `ticket=client` → 403). Alternative : route KDS dédiée `GET admin/kds-order/{order}/escpos-kitchen` gardée `permission:kitchen-display-system`, et pointer le helper dessus.

---

## P3 — Le seed de dé-dup au montage supprime l'impression cuisine des commandes présentes à la 1re hydratation (arrivées pendant le reload / KDS non monté)

**Fichiers** : `KitchenDisplaySystemComponent.vue:1950-1967` (`_seedKitchenPrintedBacklogOnce`) + watch `orders` l.1507-1521 (garde `!_kdsOrdersHydrated`).

**Mécanique** : la 1re hydratation n'imprime JAMAIS (garde du watch + seed backlog marquant toutes les commandes présentes comme « imprimées »). C'est voulu pour ne pas ré-imprimer tout le board au reload. Mais le seed ne distingue pas « déjà imprimée avant reload » de « arrivée pile pendant le reload / pendant que la route KDS était démontée, jamais imprimée ». Toute commande qui apparaît pour la première fois DANS ce premier lot est marquée imprimée sans sortir de ticket → **ticket cuisine perdu en silence**.

**Repro (fenêtre étroite)** : commande créée dans l'intervalle [début du F5 du PC cuisine → 1re réponse `admin/kds-order`] OU pendant que le chef a quitté la route `/kds` (navigation admin) ; au (re)montage `_seedKitchenPrintedBacklogOnce` la seede → aucun ticket. La commande reste visible sur le board (récupérable via 🖨️ manuel), d'où P3, mais c'est une perte réelle d'impression pour un poste 24/7 qui reboot.

**Atténuation existante** : bouton 🖨️ réimpression manuelle (mais lui-même cassé pour Chef, cf. P1).

**Piste** : ne seeder QUE les ids déjà présents dans le set persistant `hasKitchenPrinted` (les vraies « déjà imprimées »), et laisser les ids inconnus suivre le chemin d'impression normal au lieu de les marquer imprimés en masse.

---

## Points vérifiés SAINS (pas de finding)
- **Dé-dup impression** : `kds.printedKitchenIds` persistée localStorage + garde in-flight `_kitchenInFlight` + liste d'échec persistée `kds.failedKitchenIds` exclue du seed → 1 ticket/commande, pas de doublon au reload (chemins auto + retry + reprint tous protégés par la même garde in-flight).
- **Pont cuisine** (`tools/kitchen-bridge`) : /raw répond le résultat RÉEL (200/500), timeout worker 15 s < timeout client 20 s (anti-double), `client_aborted` saute le job en file (anti head-of-line duplicate), drop-oldest borné, worker unique. Cohérent.
- **Fallback WS→poll** : `KdsSyncService` re-`_schedule()` sur erreur réseau (pas de blocage), back-off 5xx, clamp cadence [250 ms, 60 s] anti-misconfig ; `OssSyncService` back-off sur 4xx/5xx/réseau, `_emit` avale les exceptions listener (boucle poll jamais figée), burst-poll visibilité. Pas de perte/dup.
- **Garde release-board partagée** (`KitchenReleaseRule::applyBoardReleaseFilter`) appliquée à l'identique sur KDS list/orderItems/sync + guard changeStatus + OSS list/listForBranch → « visible cuisine == visible client == bumpable », commande UNPAID non-cash exclue partout.
- **Isolation branche** : `changeStatus`/`recall` re-vérifient `branch_id` sous `lockForUpdate` (403 cross-branche) ; `KdsSyncController`/`OrderStatusScreenController` refusent l'override cross-branche ; `publicIndex` mur VIDE si branche non résolue (pas de fuite toutes-branches).
- **recall NF525** : append-only `order_status_transitions`, `orders.status` jamais muté, assertion invariant en fin de transaction, dé-dup fenêtre glissante. Sain.
- **Optimistic lock** `changeStatus` (`expected_status`) → 409 propre + refresh front. Notifications post-commit isolées en try/catch (un bump réussi ne redevient jamais 422).
