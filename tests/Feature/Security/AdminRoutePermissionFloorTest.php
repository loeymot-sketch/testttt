<?php

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [C09 heal — reports/audit-externe-triage-2026-07-06/VERDICT.md — 2026-07-06]
 *
 * Pré-heal (repro confirmée) :
 *   GET /api/admin/users (SimpleUserController::index → SimpleUserResource
 *   {id, name, name_email:"Nom (email)"}) était atteignable par TOUT token
 *   authentifié (x-api-key SPA + auth:sanctum) SANS aucune garde permission
 *   (ni middleware `permission:` sur la route, ni $this->authorize/can() dans
 *   la méthode). Un staff faible-privilège (rôle Chef, sans pos/settings/…)
 *   pouvait énumérer nom + email de TOUS les utilisateurs — User n'est pas
 *   branch-scopé, donc fuite PII cross-branche staff + clients.
 *
 * Heal (scope-minimal, deny-by-default) :
 *   SimpleUserController::__construct ajoute
 *     permission:pos|pos-orders|table-orders|online-orders|push-notifications|settings
 *   ->only('index'). L'OR-gate Spatie couvre EXACTEMENT les 6 surfaces SPA
 *   qui consomment la liste comme sélecteur client/staff (POS caisse,
 *   pos-order, table-order, online-order, push-notification, kiosk-machine).
 *   Le rôle admin bypass via Gate::before (AuthServiceProvider). Le flux
 *   caisse (permission `pos`) reste intact.
 *
 * Ce fichier = double protection :
 *   (A) Tests comportementaux TDD prouvant 401 / 403 / 200 sur /admin/users.
 *   (B) Sentinelle baseline-lock par réflexion : aucune NOUVELLE route
 *       `api/admin/*` en GET @index ne doit apparaître sans garde middleware
 *       permission hors de l'allowlist gelée ci-dessous. Un futur endpoint
 *       PII non gardé fait donc échouer la CI.
 */
class AdminRoutePermissionFloorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Allowlist gelée des routes `api/admin/*` GET @index qui n'ont PAS de
     * middleware `permission:`/`role:` — snapshot vérifié 2026-07-06 APRÈS le
     * heal C09. Chacune est SOIT non-PII (config/catalogue/lookup) SOIT gardée
     * INLINE (`$user->can()` dans index — non détectable par réflexion de
     * middleware). Toute nouvelle entrée doit être ajoutée ICI consciemment,
     * avec la garde adéquate, sinon la sentinelle (B) échoue.
     */
    private const BASELINE_OPEN_ADMIN_INDEX = [
        // — Gardées INLINE via $user->can() dans index() (pas un trou) —
        'api/admin/cash-overview',            // can('cash-sessions-report')
        'api/admin/cash-sessions-report',     // can('cash-sessions-report')
        'api/admin/fiscal/z-report',          // can('pos-manage-fiscal')
        // [fusion 2026-09-02] Gardé INLINE dans index() : `can('settings') || rôle Admin`.
        // La garde globale `role:Admin|Tenant Admin` a été retirée du constructeur parce
        // qu'elle fermait aussi la porte à un gérant porteur de `settings` — le compte qui
        // doit précisément pouvoir lire le plan de panne (InterrupteurLectureGardeeTest).
        // Un middleware ne sait pas exprimer ce OU ; le contrôle par méthode, si.
        'api/admin/observability/interrupteurs', // can('settings') || rôle Admin, inline

        // — Non-PII : catalogue / config / lookups —
        'api/admin/country-code',
        'api/admin/default-access',           // POS Operator bootstrap (cf. RouteCoverage_AdminPermissionGateSentinelTest)
        'api/admin/dining-table',             // n° table + contact BRANCHE (pas de PII perso)
        'api/admin/item',
        // [AUDIT-E E2/E3 2026-08-06] `payment-terminals` et `setting/kiosk-machine`
        // RETIRÉS de l'allowlist : mal classés « non-PII » alors que leur index
        // expose le serial_number + grille de commissions (TPE) et le username de
        // login borne (machine). Les deux sont désormais gatés en middleware
        // (PaymentTerminalController:28 `settings|pos`, KioskMachineController:22
        // `settings`) — la sentinelle doit les voir gardés, plus tolérés.
        'api/admin/pos-category',
        'api/admin/setting/analytic-section/{analytic}',
        'api/admin/setting/branch',
        'api/admin/setting/company',
        'api/admin/setting/cookies',
        'api/admin/setting/currency',
        'api/admin/setting/language',
        'api/admin/setting/menu-section',
        'api/admin/setting/menu-template',
        'api/admin/setting/notification-alert',
        'api/admin/setting/order-setup',
        'api/admin/setting/otp',
        'api/admin/setting/page',
        'api/admin/setting/site',
        'api/admin/setting/slider',
        'api/admin/setting/social-media',
        'api/admin/setting/tax',
        'api/admin/setting/theme',
        'api/admin/setting/time-slot',
        'api/admin/timezone',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Les permissions référencées par l'OR-gate qui ne sont pas déjà
        // seedées par seedSpatieRoles() (elles EXISTENT en prod via
        // RolePermissionTableSeeder ; on les matérialise ici pour éviter tout
        // PermissionDoesNotExist et pour pouvoir tester une surface non-POS).
        foreach (['table-orders', 'push-notifications', 'online-orders'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }

        $this->branch = Branch::factory()->create();
    }

    private Branch $branch;

    private function makeUserWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeUserWithPermission(?string $permission): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        if ($permission !== null) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    // ------------------------------------------------------------------ //
    // (A) Comportemental — la preuve C09
    // ------------------------------------------------------------------ //

    public function test_anonymous_request_to_admin_users_is_unauthenticated_401(): void
    {
        $response = $this->getJson('/api/admin/users');

        $this->assertSame(
            401,
            $response->getStatusCode(),
            'Requête anonyme sur /admin/users doit être rejetée par auth:sanctum (401).'
        );
    }

    public function test_low_privilege_chef_cannot_list_users_403(): void
    {
        // Chef = dashboard + kitchen-display-system + order-status-screen —
        // AUCUNE des permissions de l'OR-gate. Pré-heal : renvoyait 200 + PII.
        $chef = $this->makeUserWithRole('Chef');

        $response = $this->actingAs($chef, 'sanctum')->getJson('/api/admin/users');

        $this->assertSame(
            403,
            $response->getStatusCode(),
            '[C09] Un staff faible-privilège (Chef) NE DOIT PAS énumérer nom+email '
            . 'de tous les utilisateurs. Pré-heal : 200 (la fuite). Corps : '
            . $response->getContent()
        );
    }

    public function test_pos_operator_can_list_users_200(): void
    {
        // POS Operator possède `pos` — le flux caisse (sélecteur client walk-in)
        // NE DOIT PAS casser.
        $operator = $this->makeUserWithRole('POS Operator');

        $response = $this->actingAs($operator, 'sanctum')->getJson('/api/admin/users');

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'L\'opérateur POS (permission `pos`) DOIT pouvoir lister les users '
            . '(sélecteur client caisse). Corps : ' . $response->getContent()
        );
    }

    public function test_non_pos_consumer_with_table_orders_permission_can_list_users_200(): void
    {
        // Surface table-order (Waiter) : consomme aussi user/lists. Garde contre
        // un futur rétrécissement de l'OR-gate à `pos` seul qui casserait Waiter.
        $waiter = $this->makeUserWithPermission('table-orders');

        $response = $this->actingAs($waiter, 'sanctum')->getJson('/api/admin/users');

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'Un consommateur légitime non-POS (permission `table-orders`) DOIT '
            . 'pouvoir lister les users. Corps : ' . $response->getContent()
        );
    }

    // ------------------------------------------------------------------ //
    // (B) Sentinelle baseline-lock par réflexion
    // ------------------------------------------------------------------ //

    public function test_admin_users_index_is_guarded_by_permission_middleware(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/admin/users'
                && in_array('GET', $r->methods(), true)
        );

        $this->assertNotNull($route, 'Route GET api/admin/users introuvable.');

        $permissionMw = collect($route->gatherMiddleware())
            ->first(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));

        $this->assertNotNull(
            $permissionMw,
            '[C09] GET api/admin/users DOIT porter un middleware permission: (deny-by-default).'
        );

        $this->assertStringContainsString(
            'pos',
            $permissionMw,
            'L\'OR-gate de /admin/users doit inclure `pos` pour ne pas casser le flux caisse.'
        );
    }

    public function test_no_new_unguarded_admin_index_route_appears(): void
    {
        $open = [];

        foreach (Route::getRoutes() as $r) {
            $uri = $r->uri();
            if (strpos($uri, 'api/admin') !== 0) {
                continue;
            }
            if (! in_array('GET', $r->methods(), true)) {
                continue;
            }
            if (! preg_match('/@index$/', $r->getActionName())) {
                continue;
            }

            try {
                $middleware = $r->gatherMiddleware();
            } catch (\Throwable $e) {
                // Contrôleur non instanciable dans l'env de test : on le
                // considère comme "à revoir" plutôt que de masquer un trou.
                $open[] = $uri . ' (uninstantiable: ' . $e->getMessage() . ')';
                continue;
            }

            $hasPermissionGate = collect($middleware)->contains(
                fn ($m) => is_string($m) && (
                    str_starts_with($m, 'permission:')
                    || str_starts_with($m, 'role:')
                    || str_starts_with($m, 'role_or_permission:')
                )
            );

            if (! $hasPermissionGate) {
                $open[] = $uri;
            }
        }

        $newlyOpen = array_values(array_diff($open, self::BASELINE_OPEN_ADMIN_INDEX));

        $this->assertSame(
            [],
            $newlyOpen,
            "Nouvelle(s) route(s) api/admin GET @index SANS garde middleware permission détectée(s) :\n"
            . implode("\n", $newlyOpen)
            . "\n\nSi l'endpoint expose des PII (users/clients/staff), AJOUTE une garde "
            . "permission (middleware ou inline \$user->can()). S'il est non-PII ou gardé "
            . "inline, ajoute-le consciemment à BASELINE_OPEN_ADMIN_INDEX avec justification."
        );
    }
}
