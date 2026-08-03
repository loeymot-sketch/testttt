# VERIFY-19 — Nouvelle route `menu/availability/toggle` + throttle `kiosk-menu`

**Date :** 2026-04-20  **Origine :** modif locale `routes/api.php` (ajout `AvailabilityController::toggle` + middleware `throttle:kiosk-menu` sur `GET /api/frontend/menu`)  **Priorité :** P0  **Mode :** AUDIT-ONLY

## 1. Contexte
Ajout récent (non commit ?) :
```
Route::post('/menu/availability/toggle', [AvailabilityController::class, 'toggle'])->name('menu.availability.toggle');
... GET /api/frontend/menu sous middleware throttle:kiosk-menu
```
Vérifier : existence du contrôleur, autorisations, validation, idempotence, broadcast event, configuration du throttle, test.

## 2. Sources OBLIGATOIRES
- `routes/api.php`
- `app/Http/Controllers/Admin/AvailabilityController.php` (existence)
- `app/Services/AvailabilityService.php`
- `app/Providers/RouteServiceProvider.php` (limit `kiosk-menu`)
- Tests : recherche `AvailabilityController` / `availability/toggle`
- Front : `resources/js/components/admin/menu/**` (UI toggle)

## 3. Hypothèses à challenger
- H1 : `AvailabilityController` n'existe pas (route 500 en prod).
- H2 : Endpoint sans permission Spatie → n'importe quel staff coupe la dispo.
- H3 : Toggle ne broadcast pas l'event de prune kiosk (P1).
- H4 : Throttle `kiosk-menu` non défini dans `RouteServiceProvider::configureRateLimiting`.
- H5 : Pas de test unitaire / feature.

## 4. Plan multi-agent
1. **Explore A** : controllers + services + provider rate-limit.
2. **Explore B** : tests + UI front menu admin.
3. **GeneralPurpose** : matrice route × auth × validation × event × test.

## 5. Vérifications obligatoires
- [ ] V1 : Contrôleur existe + méthode `toggle` typée.
- [ ] V2 : Middleware `permission:menu-manage` (ou équivalent) appliqué.
- [ ] V3 : Validation FormRequest (item_id, branch_id, available bool).
- [ ] V4 : Toggle déclenche `ItemAvailabilityChanged` event broadcasté sur `private-branch.{id}`.
- [ ] V5 : Throttle `kiosk-menu` défini avec valeur cohérente (ex: 60/min/user).
- [ ] V6 : Test feature couvre toggle ON→OFF + broadcast assertion.
- [ ] V7 : UI admin envoie bien sur cette route et reflète l'état immédiatement.

## 6. Critères d'acceptation
- ALL_GREEN si V1–V7 OK.
- WARN si V7 manquant côté UI.
- FAIL si V1, V2, V4 ou V5 absents (route morte ou faille).

## 7. Livrables
- `reports/review/VERIFY_19_AVAILABILITY_TOGGLE_ROUTE_2026-04-20.md`

## 8. Suite
- FAIL → `P11_AVAILABILITY_TOGGLE_HARDENING` (controller + permission + event + test).

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/19_VERIFY_AVAILABILITY_TOGGLE_ROUTE.md, applique §4-§7.

OBLIGATIONS: 2 explore parallèles + 1 generalPurpose matrice. 0 code modifié.
Livrable: reports/review/VERIFY_19_AVAILABILITY_TOGGLE_ROUTE_2026-04-20.md
Plan 5 lignes. Conclusion "GLOBAL: ..." + cycles P.
```
