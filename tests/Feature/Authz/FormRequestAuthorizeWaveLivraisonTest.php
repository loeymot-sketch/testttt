<?php

namespace Tests\Feature\Authz;

use App\Http\Requests\DeliveryBoyAddressRequest;
use App\Http\Requests\EmployeeAddressRequest;
use App\Http\Requests\ItemAttributeRequest;
use App\Http\Requests\ItemCategoryImportRequest;
use App\Http\Requests\ItemImportRequest;
use App\Http\Requests\ItemPhotoUploadRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [GOAL LIVRAISON-TICKETS 2026-11-10 · T-2.4.2] Six FormRequests des contrôleurs de ce GOAL (livreurs, employés, produits)
 * répondaient `return true;` à `authorize()` : l'autorisation reposait entièrement sur le middleware de route. Elles
 * vérifient désormais elles-mêmes la permission EXACTE que porte leur route (second verrou, même doctrine que
 * `AnalyticRequestAuthzTest`). Cliquet `FormRequestAuthzDriftSentinelTest::RETURN_TRUE_BASELINE` : 52 → 46.
 *
 * @group authz
 */
class FormRequestAuthorizeWaveLivraisonTest extends TestCase
{
    use RefreshDatabase;

    /**
     * classe → permissions dont CHACUNE suffit HORS ROUTE (création). Les deux requêtes d'adresse coupent comme leur
     * contrôleur : sans `{address}` dans l'URL = création (`_create`), avec = modification (`_edit`) —
     * `test_une_adresse_dans_l_url_exige_la_permission_de_modification` (supervision 2026-11-10 : le OU des deux était
     * plus lâche que la route).
     */
    private const MIROIR = [
        DeliveryBoyAddressRequest::class => ['delivery-boys_create'],
        EmployeeAddressRequest::class => ['employees_create'],
        ItemAttributeRequest::class => ['settings'],
        ItemCategoryImportRequest::class => ['settings'],
        ItemImportRequest::class => ['items_create'],
        ItemPhotoUploadRequest::class => ['items_edit'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        foreach (array_unique(array_merge(...array_values(self::MIROIR))) as $p) {
            Permission::findOrCreate($p, 'sanctum');
        }
        Permission::findOrCreate('pos', 'sanctum');
    }

    private function autorise(string $classe, ?User $u): bool
    {
        $r = new $classe();
        $r->setUserResolver(fn () => $u);

        return $r->authorize();
    }

    public function test_sans_utilisateur_ou_sans_la_permission_de_la_route_refuse(): void
    {
        $caissier = User::factory()->create(['branch_id' => 1]);
        $caissier->givePermissionTo('pos'); // un droit, mais pas CELUI de la route
        foreach (self::MIROIR as $classe => $perms) {
            $this->assertFalse($this->autorise($classe, null), "$classe : anonyme accepté");
            $this->assertFalse($this->autorise($classe, $caissier), "$classe : la permission `pos` ne doit pas suffire");
        }
    }

    public function test_chaque_permission_de_la_route_suffit_a_elle_seule(): void
    {
        foreach (self::MIROIR as $classe => $perms) {
            foreach ($perms as $perm) {
                $u = User::factory()->create(['branch_id' => 1]);
                $u->givePermissionTo($perm);
                $this->assertTrue($this->autorise($classe, $u), "$classe : `$perm` doit suffire");
            }
        }
    }

    /** Même requête sur la route PUT `…/{x}/{address}` : seule la permission de MODIFICATION passe, la création ne suffit plus. */
    public function test_une_adresse_dans_l_url_exige_la_permission_de_modification(): void
    {
        Permission::findOrCreate('delivery-boys_edit', 'sanctum');
        Permission::findOrCreate('employees_edit', 'sanctum');
        foreach ([DeliveryBoyAddressRequest::class => 'delivery-boys', EmployeeAddressRequest::class => 'employees'] as $classe => $prefixe) {
            $createur = User::factory()->create(['branch_id' => 1]);
            $createur->givePermissionTo("{$prefixe}_create");
            $editeur = User::factory()->create(['branch_id' => 1]);
            $editeur->givePermissionTo("{$prefixe}_edit");

            $route = new \Illuminate\Routing\Route(['PUT'], '/x/{owner}/{address}', fn () => null);
            $route->bind(\Illuminate\Http\Request::create('/x/7/5', 'PUT'));
            $surRoute = function (?User $u) use ($classe, $route): bool {
                $r = new $classe();
                $r->setUserResolver(fn () => $u);
                $r->setRouteResolver(fn () => $route);

                return $r->authorize();
            };

            $this->assertTrue($surRoute($editeur), "$classe : `{$prefixe}_edit` doit suffire pour modifier");
            $this->assertFalse($surRoute($createur), "$classe : `{$prefixe}_create` ne doit pas suffire pour modifier");
            $this->assertFalse($this->autorise($classe, $editeur), "$classe : `{$prefixe}_edit` ne doit pas suffire pour créer");
        }
    }
}
