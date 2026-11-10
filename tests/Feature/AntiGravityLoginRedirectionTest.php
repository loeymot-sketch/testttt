<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Item;
use App\Models\Branch;
use App\Enums\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AntiGravityLoginRedirectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    /**
     * Test AG-LOGIN-A: POS Operator
     */
    public function test_pos_operator_receives_pos_landing_url()
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create([
            'email' => 'posoperator@example.com',
            'password' => bcrypt('123456'),
            'status' => Status::ACTIVE,
            'branch_id' => $branch->id,
        ]);
        $user->assignRole('POS Operator');

        $response = $this->withHeader('x-api-key', config('app.api_key'))->postJson('/api/auth/login', [
            'email'    => 'posoperator@example.com',
            'password' => '123456',
        ]);

        $response->assertStatus(201);
        $data = $response->json();

        $this->assertArrayHasKey('defaultPermission', $data);
        $this->assertEquals('pos', $data['defaultPermission']['url'], "POS Operator should be redirected to /admin/pos");
    }

    /**
     * Test AG-LOGIN-B: Chef Kitchen
     */
    public function test_chef_receives_kds_landing_url()
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create([
            'email' => 'chef@example.com',
            'password' => bcrypt('123456'),
            'status' => Status::ACTIVE,
            'branch_id' => $branch->id,
        ]);
        $user->assignRole('Chef');

        $response = $this->withHeader('x-api-key', config('app.api_key'))->postJson('/api/auth/login', [
            'email'    => 'chef@example.com',
            'password' => '123456',
        ]);

        $response->assertStatus(201);
        $data = $response->json();

        $this->assertArrayHasKey('defaultPermission', $data);
        $this->assertEquals('kitchen-display-system', $data['defaultPermission']['url'], "Chef should be redirected to /admin/kitchen-display-system");
    }

    /**
     * Test AG-LOGIN-C: Customer (No defaultPermission url)
     */
    public function test_customer_receives_null_landing_url()
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'password' => bcrypt('123456'),
            'status' => Status::ACTIVE,
            'branch_id' => $branch->id,
        ]);
        $user->assignRole('Customer');

        $response = $this->withHeader('x-api-key', config('app.api_key'))->postJson('/api/auth/login', [
            'email'    => 'customer@example.com',
            'password' => '123456',
        ]);

        $response->assertStatus(201);
        $data = $response->json();

        if (!empty($data['defaultPermission'])) {
            $this->assertTrue(
                !isset($data['defaultPermission']['url']) || empty($data['defaultPermission']['url']) || $data['defaultPermission']['url'] === '#',
                "Customer should NOT have a specific landing URL overriding home"
            );
        } else {
            $this->assertEmpty($data['defaultPermission']);
        }
    }

    /**
     * [GOAL LIVRAISON-TICKETS 2026-11-10 · T-1.4.2] Un livreur atterrit sur SON écran (`livreur` → /admin/livreur →
     * sortie dure vers /livreur), plus sur `delivery-boys` (liste admin qu'il ne peut pas ouvrir : rôle sans permission).
     * Le seeder ET la migration de données donnent la même valeur.
     */
    public function test_delivery_boy_receives_livreur_landing_url()
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum'], ['landing_url' => 'delivery-boys']);
        $this->seed(\Database\Seeders\LeCayenneRoleLandingUrlSeeder::class);

        $branch = Branch::factory()->create();
        $user = User::factory()->create([
            'email' => 'livreur@example.com',
            'password' => bcrypt('123456'),
            'status' => Status::ACTIVE,
            'branch_id' => $branch->id,
        ]);
        $user->assignRole('Delivery Boy');

        $response = $this->withHeader('x-api-key', config('app.api_key'))->postJson('/api/auth/login', [
            'email'    => 'livreur@example.com',
            'password' => '123456',
        ]);

        $response->assertStatus(201);
        $this->assertEquals('livreur', $response->json('defaultPermission.url'), 'Le livreur doit être envoyé vers /livreur');
        // La réponse liste TOUTES les permissions avec un drapeau `access` : pour un livreur, aucun n'est vrai.
        $accordees = collect($response->json('permission') ?: [])->filter(fn ($p) => ($p['access'] ?? false) === true)->pluck('name')->all();
        $this->assertSame([], $accordees, 'Le rôle Livreur ne porte aucune permission admin');

        // La migration de données fait la même chose sur une base déjà installée (valeur historique seulement).
        \Illuminate\Support\Facades\DB::table('roles')->where('name', 'Delivery Boy')->update(['landing_url' => 'delivery-boys']);
        (require database_path('migrations/2026_11_10_100000_delivery_boy_lands_on_livreur.php'))->up();
        $this->assertSame('livreur', \Illuminate\Support\Facades\DB::table('roles')->where('name', 'Delivery Boy')->value('landing_url'));
        \Illuminate\Support\Facades\DB::table('roles')->where('name', 'Delivery Boy')->update(['landing_url' => 'tableau-perso']);
        (require database_path('migrations/2026_11_10_100000_delivery_boy_lands_on_livreur.php'))->up();
        $this->assertSame('tableau-perso', \Illuminate\Support\Facades\DB::table('roles')->where('name', 'Delivery Boy')->value('landing_url'), 'Un choix fait depuis l\'écran des rôles n\'est jamais écrasé');
    }

    /**
     * Test AG-MENU: POS Menu Items have Status::ACTIVE (5)
     */
    public function test_pos_menu_items_are_visible()
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['email' => 'admin@test.com', 'branch_id' => $branch->id, 'status' => Status::ACTIVE]);
        $admin->assignRole('Admin');
        Item::factory()->create([
            'status' => Status::ACTIVE,
            'price' => 9.99,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/item?status=' . Status::ACTIVE);

        $response->assertStatus(200);
        $data = $response->json();

        $itemsCount = count($data['data'] ?? []);
        $this->assertGreaterThan(0, $itemsCount, "POS Menu should return active items, but returned 0");
    }
}
