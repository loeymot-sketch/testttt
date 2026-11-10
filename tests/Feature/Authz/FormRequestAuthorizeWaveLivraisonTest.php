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

    /** classe → permissions dont CHACUNE suffit (routes POST / PUT de la même requête). */
    private const MIROIR = [
        DeliveryBoyAddressRequest::class => ['delivery-boys_create', 'delivery-boys_edit'],
        EmployeeAddressRequest::class => ['employees_create', 'employees_edit'],
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
}
