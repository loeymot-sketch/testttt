# PROPOSAL — Refactor KioskMachine binding to a dedicated kiosk user (Layer 2)

**Status** : DRAFT — owner countersign required
**Origin** : Phase J-ADV-6 PATH-1 RED P0 (J2-HEAL-02 follow-up)
**Date** : 2026-05-24
**Risk class** : medium-high (production data + Sanctum identity surface)
**Linked heal** : commit `<J2-HEAL-02>` — `BlockKioskTokenFromAdminRoutes` middleware (Layer 1)

---

## 1. Context

Phase J adversarial trust-escalation audit (J-ADV-6) empirically verified
that a Sanctum token with the `['kiosk:order']` ability could reach
`/api/admin/pos-order` (200 with payload) because:

1. Spatie `permission:*` middleware checks `Auth::user()->can()` —
   token abilities (`tokenCan`) are NEVER consulted on `/api/admin/*`.
2. Default Le Cayenne config binds the `kiosk-lecayenne` machine to the
   `admin@lecayenne.fr` user (`branch_id=0`, role=Admin, all Spatie
   permissions) — see :
   - `database/seeders/KioskMachineTableSeeder.php:29-53`
   - `app/Console/Commands/EnsureKioskMachineCommand.php:50-55`
3. Therefore the kiosk-issued token silently inherits ALL admin Spatie
   permissions AND the `BranchScope` `userBranch===0` cross-branch read
   bypass — a publicly-exposed terminal with full admin reach.

**Layer 1 fix (J2-HEAL-02 — already shipped)** :
`BlockKioskTokenFromAdminRoutes` middleware applied to both
`Route::prefix('admin')` groups in `routes/api.php`. Any Sanctum token
carrying `kiosk:order` (and not `*`) is refused with 403 +
`token_ability_insufficient` before reaching `permission:*`.

**Layer 2 (THIS PROPOSAL)** : remove the underlying privilege grant by
binding the KioskMachine to a dedicated, no-role kiosk user. Even if
Layer 1 were ever removed or bypassed, the kiosk token would carry no
admin permissions to inherit.

---

## 2. Proposed change

### 2.1 New canonical kiosk user

Username pattern : `kiosk_kayenne_borne_<N>` (N = machine slot).

User row :
- `email` : `kiosk-borne-<N>@kiosks.lecayenne.local` (synthetic, never
  receives mail — flag via DB constraint or `email_verified_at=null`).
- `branch_id` : the borne's physical branch (NOT 0 — must be scoped so
  `BranchScope` activates).
- `status` : ACTIVE.
- `password` : random 32-byte secret (never used — kiosk auth goes
  through `KioskMachineLoginController` against `kiosk_machines.password`).
- **No Spatie role assignment.** No direct permissions.

### 2.2 Seeder refactor

`database/seeders/KioskMachineTableSeeder.php` :

```php
// Replace lines 28-41 (owner = admin lookup):
$kioskOwner = User::query()
    ->where('email', 'kiosk-borne-1@kiosks.lecayenne.local')
    ->where('status', Status::ACTIVE)
    ->first();

if (! $kioskOwner) {
    $kioskOwner = User::forceCreate([
        'name'      => 'Kiosk Borne 1 (Le Cayenne)',
        'email'     => 'kiosk-borne-1@kiosks.lecayenne.local',
        'username'  => 'kiosk_kayenne_borne_1',
        'password'  => bcrypt(Str::random(32)),
        'status'    => Status::ACTIVE,
        'branch_id' => 1, // Le Cayenne single-branch V1
        // NO assignRole — empty Spatie permission set by design.
    ]);
}

$ownerBranch = (int) $kioskOwner->branch_id;
$ownerId     = $kioskOwner->id;
```

Then the `KioskMachine::updateOrCreate` block uses `$ownerId` (not
`$admin->id`).

### 2.3 Console command refactor

`app/Console/Commands/EnsureKioskMachineCommand.php:49-55` :

Change default `--user-id` resolution from
`User::query()->find(1) ?? User::query()->orderBy('id')->first()` to:

```php
$user = User::query()
    ->where('username', 'kiosk_kayenne_borne_1')
    ->where('status', Status::ACTIVE)
    ->first();

if (! $user) {
    $this->error('Kiosk user "kiosk_kayenne_borne_1" not found. Run db:seed --class=KioskMachineTableSeeder first.');
    return 1;
}
```

Reject explicit `--user-id` if the targeted user has any Spatie roles
(defense in depth — operator cannot silently re-grant admin).

### 2.4 Production migration

One-shot Artisan command (NOT a schema migration — data-only):
`php artisan foodking:rebind-kiosk-machines-to-dedicated-user`

Steps :
1. List all rows in `kiosk_machines` where `user_id` resolves to a
   user with any Spatie role.
2. For each, ensure the dedicated kiosk user exists for that branch,
   create if missing.
3. Update `kiosk_machines.user_id` to the dedicated user.
4. Revoke all existing `personal_access_tokens` rows with
   `tokenable_id` = old admin user AND `abilities` containing
   `kiosk:order` (forces borne re-login under the new identity).
5. Log a structured `kiosk_machine.rebind` audit entry per row.

Idempotent — safe to re-run.

---

## 3. Blast radius / risk

| Risk | Mitigation |
|------|------------|
| Existing kiosk sessions invalidate at migration → 60-90s downtime per borne | Run during scheduled closure window; brief operator |
| `BranchScope` semantics change for kiosk-side reads (was admin bypass) | Audit kiosk controllers for `withoutGlobalScope(BranchScope::class)` calls — most are already explicit per CLAUDE.md §9 |
| `creator_id`/`audited_by` columns previously pointed at admin user for kiosk-originated rows | Backfill: keep historical data unchanged; new rows attribute to kiosk user (operationally cleaner) |
| Recovery story when dedicated user is deleted | `EnsureKioskMachineCommand` recreates it; refuse to bind to admin user |

---

## 4. Why owner countersign

- Touches **20 BranchScope models** indirectly (any model queried under
  kiosk identity now scopes to the borne's branch, where previously
  `branch_id=0` admin bypass returned global data).
- Production data migration on `kiosk_machines.user_id` is reversible
  but visible in `audit_logs` (NF525 — chain unaffected, but
  attribution differs).
- Layer 1 (already shipped) reduces urgency to zero — this is
  **defense in depth**, not a bug fix.

---

## 5. Acceptance criteria (post-apply)

1. `KioskMachine::query()->whereHas('user', fn($q) => $q->whereHas('roles'))->count()` returns 0.
2. `BlockKioskTokenFromAdminRoutes` sentinel still passes.
3. Kiosk happy-path E2E (kiosk:idle → cart → pay → KDS arrival) green.
4. New sentinel `KioskMachineUserHasNoSpatieRolesSentinelTest` baseline-locks.
5. NF525 chain bit-identical (`audit_logs` count + `last_hash` unchanged).

---

## 6. Decision log

- [ ] Owner reviewed and approves Layer 2 application
- [ ] Owner countersigned the data migration command
- [ ] Scheduled downtime window booked
- [ ] Rollback plan rehearsed (kiosk_machines.user_id restoration script)

---

*Generated by J2-HEAL-02. Layer 1 is complete and verified GREEN.
Layer 2 awaits owner go.*
