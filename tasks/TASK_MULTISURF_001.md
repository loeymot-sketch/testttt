# Task – MULTISURF_001

## Description
FoodKing est une SPA Vue.js unique (app.js) avec plusieurs surfaces métier distinctes,
chacune ayant son propre rôle utilisateur et son propre flux d'accès.
Actuellement, aucune surface n'est accessible directement et proprement —
il n'existe pas d'URL d'entrée claire par rôle, les redirections post-login
sont incohérentes, et certaines surfaces ne chargent pas correctement leur
contexte (branch_id, token abilities, guard de route).

Objectif : rendre chaque surface directement accessible, propre, et autonome.
L'accès à chaque surface doit fonctionner via une URL directe, avec
un login dédié si nécessaire, et une redirection post-login correcte.

## Surfaces à corriger

| Surface | URL cible | Rôle | Login dédié ? |
|---|---|---|---|
| Admin / Dashboard | /admin/dashboard | admin (branch_id=0) | Login générique |
| POS (Caissier) | /admin/pos | caissier / employee | Login générique |
| KDS (Cuisine / Chef) | /kds | chef | Login KDS dédié |
| Kiosk (Borne) | /kiosk | kiosk machine token | Login kiosk machine |
| OSS (Order Status Screen) | /order-status | public ou token | Accès direct ou PIN |
| Livreur (Delivery) | /delivery | delivery_boy | Login générique |
| Waiter | /admin/waiter | waiter | Login générique |
| Frontend client | / | client / guest | Login optionnel |

## Problèmes connus

1. **Redirection post-login non différenciée** : après login, tous les rôles
   tombent sur `frontend.home` au lieu d'être routés vers leur surface respective.
2. **KDS et Kiosk** : les routes existent dans le router Vue mais l'accès direct
   par URL (F5 / lien direct) peut échouer si le guard n'est pas correctement configuré
   ou si le token ability n'est pas vérifié côté frontend.
3. **OSS** : surface publique sans auth claire — il faut définir si c'est public,
   par PIN, ou par token branch.
4. **Branch_id isolation** : chaque surface non-admin doit être liée à un branch_id
   et refuser l'accès cross-branch.
5. **Livreur** : interface de livraison avec commandes, adresses, et détails clients —
   vérifier que les routes API livreur sont toutes protégées et que le frontend
   charge correctement les données de livraison.

## Périmètre de cette tâche

**In scope :**
- `resources/js/router/` — tous les modules de route des surfaces concernées
- `resources/js/store/` — auth store, guards, token management
- `resources/js/components/` — pages de login et landing par surface
- `routes/api.php` — auth endpoints et guards par rôle
- `app/Http/Controllers/Auth/LoginController.php` — redirection post-login
- `app/Http/Middleware/` — guards et vérification de role

**Explicitly out of scope :**
- `app/Services/OrderService.php` — frozen zone
- `app/Services/FrontendOrderService.php` — frozen zone
- Logique de pricing
- Logique de dispatch d'événements
- Migrations base de données

## branch_id Impact
[x] branch_id scoping affecté — toutes les surfaces non-admin doivent être
    liées à un branch_id et refuser l'accès cross-branch.

## Invariants at Risk
[x] branch_id data isolation — risque principal de cette tâche
[x] OrderStatus enum — vérifier que OSS n'affiche que des statuts via l'enum
[ ] Backend pricing SSOT — hors scope
[ ] Dispatch after DB commit — hors scope
[ ] OrderService / FrontendOrderService symmetry — hors scope
[x] Frozen zone — OrderService et FrontendOrderService exclus du scope

## Anticipated Gate Conditions
[x] Human gate requis avant tout changement sur les guards d'authentification
    qui affectent plusieurs surfaces simultanément
[x] Human gate requis si un changement sur LoginController touche le flow
    de tous les rôles (risque de régression globale)

## PRIMARY_MODEL
[x] GPT-5.4 — complex implementation
    (multi-surface, auth guards, routing, branch_id isolation — hors routine)
Planning et audit : Claude Opus toujours.

## Status
[x] Pending plan
[ ] Plan approved
[ ] In execution
[ ] Validation
[ ] Audit
[ ] Gate open
[ ] Closed
