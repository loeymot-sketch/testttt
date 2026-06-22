# RUN_C9_C10_AUDIT_CONVERGENCE_2026-04-20

**Cycle**: cleanup post-T20  
**Date**: 2026-04-20  
**Mode**: audit + prio (RUNNER_MODE single-session, auto-remediation active)  
**Status**: COMPLETED (audit) + HUMAN_GATE (exec)

---

## C9 — Migrations parity

### Diff brute

3 migrations uniques côté `testttt-kiosk-p93`, 0 unique côté `testttt`.

| Migration p93 | Type | Consommateur applicatif testttt |
|---|---|---|
| `2026_04_18_120000_add_role_to_item_attributes.php` | additif (string + index) | **0** (`grep ItemAttribute.*role` → uniquement docs/reports) |
| `2026_04_19_100000_add_theme_columns_to_branches_table.php` | additif (4 cols nullable) | **0** (`KioskContextResource` n'existe pas en testttt) |
| `2026_04_19_100001_add_capabilities_to_kiosk_machines_table.php` | additif (json nullable) | **0** (lecture future K-10 admin UI) |

### Décision : SKIP justifié

Porter ces migrations créerait des colonnes vides utilisées par personne → dette schema sans bénéfice. Quand le port applicatif arrivera (KioskContextResource, ItemAttribute roles, K-10 admin UI), les migrations viendront en convoi avec leur consommateur.

**Pas de gate humain requis** : pas d'exécution, pas de modification.

---

## C10 — Routes / seeders / providers / requests / enums

### Inventaire diff

| Path | Status | Évaluation |
|---|---|---|
| `routes/web.php` | identique | ✓ |
| `routes/api.php` | aligné précédemment (A5/C2/C4) | ✓ |
| `app/Mail`, `app/Logging`, `app/Events` | identiques | ✓ |
| `database/seeders` (4 differ + 1 unique p93 `ItemAttributeRoleSeeder` + 1 unique testttt `SpatieRoleLookup`) | divergent | **DATA critical** → gate (territoire B1) |
| `app/Providers/RouteServiceProvider.php` | divergent two-way | **AUTH critical** → voir détail ↓ |
| `app/Http/Requests/CouponCheckRequest.php` | testttt **AHEAD** (P8 `min:0`) | no-op (serait backport→p93) |
| `app/Http/Requests/CouponRequest.php` | testttt **AHEAD** (P9 `min:0` x4) | no-op (serait backport→p93) |
| `app/Http/Requests/{Order,PosOrder,TableOrder,OrderSetup,KioskMachine}Request.php` | divergent | order/payment/branch critical → gate |
| `app/Enums/PosPaymentMethod.php` | testttt **AHEAD** (`TICKET_RESTAURANT=5`) | no-op |

### Findings de valeur

#### Trouvaille #1 — `RouteServiceProvider` : merge bidirectionnel souhaitable

**testttt apporte** :
- `config('app.api_throttle_per_minute', 120)` (configurabilité)
- `config('auth.login_lockout.max_attempts'/`decay_minutes`)` (configurabilité)
- Lockout key fallback `email → username` (basique)

**p93 apporte (security hardening absent en testttt)** :
- **K-6.3** : `kiosk-orders` rate-limit keyed `kiosk:{user_id}|{ip}` au lieu de `ip()` seul.  
  → Empêche un kiosk compromis derrière un NAT (centre commercial / siège brand) de DoS ses voisins via le bucket de throttle partagé.
- **K-6.4** : login-lockout key utilise `email ?: username ?: 'anon'`.  
  → Évite le préfixe vide qui mappait toutes les tentatives kiosk anonymes sur un même bucket → bypass possible (cf. discovery §10.4).

Convergence idéale = **merge** : garder configurabilité testttt + ajouter K-6.3 et K-6.4 de p93. Patch ~10 lignes net.

**Zone critique** : `auth` + rate limiting → `auto-remediation.mdc` impose `HUMAN_GATE`.

#### Trouvaille #2 — Coupon* requests : convergence inverse

testttt a déjà reçu les hardening P8/P9 (`min:0` sur `total`, `discount`, `minimum_order`, `maximum_discount`, `limit_per_user`). p93 ne les a pas. Pas dans le scope (testttt-centric) mais à signaler si quelqu'un travaille sur p93.

#### Trouvaille #3 — `PosPaymentMethod::TICKET_RESTAURANT`

testttt a la const `TICKET_RESTAURANT = 5` alignée sur `PaymentGateway::TICKET_RESTAURANT`. Absent en p93. Convergence inverse également hors scope.

### Trouvaille #4 — Seeders divergents

`MenuSeeder`, `PermissionTableSeeder`, `PermissionTableSeederVersionTwo`, `RolePermissionTableSeeder` divergent. Touchent données auth + catalog. Lié à B1 (état BD pour E2E). À traiter dans un pass dédié seeders.

---

## Recommandation auto-remediation cycle

### Tâches utiles immédiates (sans gate)

Aucune, à ce stade : tous les findings restants touchent des zones critiques (auth, payment, branch_id, order, data) ou sont une convergence inverse (testttt → p93) qui n'est pas dans le scope.

### Gates humains à présenter

1. **C11** — Backport K-6.3 (kiosk-orders per-machine throttle) + K-6.4 (login-lockout anti-bypass anon fallback) dans `RouteServiceProvider.php`.
   - Risque: **bas** (additif, configurabilité testttt préservée)
   - Bénéfice: **haut** (2 vrais hardenings sécurité)
   - Test coverage à vérifier : `tests/Feature/Auth/LoginLockoutTest.php`, throttle tests
2. **B1** — Décision DB seed E2E (toujours en attente)
3. **Seeders convergence** — pass dédié quand B1 est tranché

### Skip justifié

- C9 migrations p93-uniques (zero consumer testttt)
- Coupon*/PosPaymentMethod (testttt ahead)

---

## Diff/commits

Aucun changement code. Audit-only.
