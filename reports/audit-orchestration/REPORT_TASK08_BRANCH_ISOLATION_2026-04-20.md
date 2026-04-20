# T08 — Branch isolation kiosk (audit)

**Date.** 2026-04-20  
**Racine.** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`  
**Verdict.** **PARTIAL**

## Constats

1. **Fichiers attendus introuvables** dans ce clone :
   - `app/Http/Middleware/KioskAuth.php`, `KioskLocale.php` — absents.
   - `KioskContextController.php`, route `/kiosk/context` — absents.
   - `KioskMachine::current()` n'existe pas ; le code utilise `KioskMachine::where('user_id', $user->id)->first()` (ex. `MenuController`, `KioskEventController`, `FrontendOrderService`).

2. **`branch_id` côté requête vs autorité serveur**
   - **Commandes** : `FrontendOrderService::myOrderStore` **force** `branch_id` depuis la borne (`$validatedRequest['branch_id'] = $kiosk->branch_id`).
   - **Menu unifié** : `GET /api/frontend/menu` résout la branche via `KioskMachine` uniquement (`MenuController::kiosk`).
   - **Promo / pricing preview / upsell** : même motif (commentaires « jamais payload »).
   - **Kiosk events** : `branch_id` validé dans le body mais surtout utilisé pour le **texte de log** (`$request->input('branch_id', $machine?->branch_id)`). `ActionLog` reste lié au `user_id` du token.
   - `OrderRequest` : `branch_id` **nullable** pour kiosk (serveur écrase à la persistance).

3. **Tests** — Absents de ce dépôt : `KioskEventAbilityTest.php`, `MultiBranchIsolationTest.php`, suite `K8/*`. Présents et pertinents : `tests/Feature/KioskPhase7/KioskEventBranchIsolationTest.php`, `KioskScopeIsolationTest.php`, `KioskPhase1/KioskEndpointsTest.php`.

4. **Rapports / ADR K-8** — `AUDIT_KIOSK_110_ISOLATION_STATE_2026-04-19.md`, `VERIFY_K8_MULTIBRANCH_DEPLOYMENT_2026-04-18.md`, `PLAN_K8_…`, `ADR_K8_…` : **non présents** ici (référencés dans `testttt-kiosk-p93` / handoff).

5. **Capabilities / thème / locale** — pas d'endpoint « context » dédié ; `KioskMenuService::projectBranch` expose `available_locales` + flags `is_rush`/`is_night` via `GET /api/frontend/menu`. Pas de validation hex serveur pour un thème injecté.

6. **Pusher / Echo** — `routes/channels.php` : canal `branch.{branchId}` ; pour tokens `kiosk:order`, abonnement **restreint** au `branch_id` de `KioskMachine`.

7. **Charge utile menu legacy** — `kioskMenu.js` appelle encore `frontend/item` et `frontend/item-category` avec `branch_id` en query, mais **`ItemService::$itemFilter` ne contient pas `branch_id`** → paramètre **ignoré** pour le filtrage. Le scoping branche réel passe par `KioskMenuService` + `ItemBranchAvailability`. **Risque** : écart catalogue / disponibilité si le front s'appuie trop sur ces listes legacy.

8. **Routes événements kiosk** (`routes/api.php`) : middleware `auth:sanctum` + throttle uniquement — **pas** `abilities:kiosk:order`, contrairement aux commentaires et tests Phase 7.

## Top 3 actions de remédiation

1. **Aligner les routes** `POST /api/frontend/kiosk-event` et `POST /api/frontend/kiosk/event` sur **`abilities:kiosk:order`** (comme documenté), ajouter/renommer les tests manquants (`KioskEventAbilityTest`, etc.).
2. **Clôturer le périmètre K-8** : ajouter l'endpoint `/kiosk/context` (ou documenter officiellement que `GET /api/frontend/menu` + settings est le contrat) ; stratégie claire pour thème/capabilities (validation hex côté serveur).
3. **Unifier le chargement menu kiosk** : faire converger `kioskMenu/fetchMenu` vers la SSOT branche (menu unifié ou filtrage serveur explicite par `KioskMachine`) ; réimporter dans ce dépôt les preuves multi-branches + suites `K8/*`.

## Décision

**T08 PARTIAL** — invariant principal (branche serveur écrase client) tenu pour la commande/menu. Trous : ability route kiosk-event, doc/contrat thème, listes legacy non scopées. Backlog dédié recommandé.
