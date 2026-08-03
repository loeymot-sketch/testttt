<?php

namespace Tests\Feature\Mobile;

use App\Enums\Status;
use App\Http\Middleware\EnsureMobileStockPin;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL MEGA W-MOBILE 2026-07-22] Accès Stock mobile (/m) par code PIN.
 *
 * Miroir du Carnet ({@see \Tests\Feature\DailyBook\DailyBookPinAuthTest}) avec la
 * divergence DURE fail-closed : PIN vide en config => TOUT refusé. Le toggle
 * délègue au SSOT {@see \App\Services\Menu\AvailabilityService::toggle} et écrit
 * item_branch_availability (raison 'stock_rupture') — aucun chemin parallèle.
 */
class MobileStockPinAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config(['mobile_stock.pin' => '2580']);
    }

    /** (1) La page /m se rend sans session et affiche l'écran PIN. */
    public function test_page_renders_pin_screen_without_session(): void
    {
        $this->get('/m')
            ->assertOk()
            ->assertSee('pin-screen', false)
            ->assertSee('Entrez le code', false);
    }

    /** (2) Mauvais PIN => 401, puis throttle 429 après 5 essais. */
    public function test_wrong_pin_rejected_then_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/m/api/pin', ['pin' => '0000'])->assertStatus(401);
        }

        $this->postJson('/m/api/pin', ['pin' => '0000'])->assertStatus(429);
    }

    /** (3) Bon PIN 2580 => session déverrouillée + accès à la page stock (catalog). */
    public function test_correct_pin_unlocks_and_opens_stock(): void
    {
        $this->postJson('/m/api/pin', ['pin' => '2580'])
            ->assertOk()
            ->assertJson(['unlocked' => true]);

        $this->getJson('/m/api/catalog')
            ->assertOk()
            ->assertJsonStructure(['branch_id', 'shopping', 'categories', 'fetched_at']);
    }

    /** (4) PIN vide en config => fail-closed : déverrouillage 403 + endpoints verrouillés. */
    public function test_empty_config_pin_is_fully_closed(): void
    {
        config(['mobile_stock.pin' => '']);

        // Même en présentant l'ancien PIN, aucune session ne peut être établie.
        $this->postJson('/m/api/pin', ['pin' => '2580'])->assertStatus(403);
        $this->postJson('/m/api/pin', ['pin' => ''])->assertStatus(403);

        // status signale non-configuré ; les endpoints protégés répondent 403
        // « non configuré » (et non 401 « PIN requis ») depuis que le middleware
        // coupe aussi les sessions déjà ouvertes — [S2 auto-RED cycle 2 2026-07-29].
        $this->getJson('/m/api/status')->assertOk()->assertJson(['unlocked' => false, 'configured' => false]);
        $this->getJson('/m/api/catalog')->assertStatus(403);
    }

    /** (5) Toggle PIN-gated => item marqué en rupture via AvailabilityService (SSOT). */
    public function test_pin_gated_toggle_marks_item_out_of_stock(): void
    {
        $branch = Branch::factory()->create();
        // Production menu data is Status::ACTIVE (=5) — the catalog read filters
        // on it (mirrors StockRuptureDashboardController). The factories default to
        // literal status=1 (dual-convention, see BranchFactory note), so pin ACTIVE.
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'status' => Status::ACTIVE,
            'is_available' => true,
        ]);

        // Déverrouillage PIN.
        $this->postJson('/m/api/pin', ['pin' => '2580'])->assertOk();

        // Bascule en RUPTURE.
        $this->postJson('/m/api/toggle', [
            'item_id' => $item->id,
            'is_available' => false,
        ])->assertOk()->assertJson([
            'ok' => true,
            'item_id' => $item->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
        ]);

        // SSOT a écrit l'override branche (raison 'stock_rupture').
        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => 0,
            'unavailable_reason' => 'stock_rupture',
        ]);

        // Le produit rupturé remonte dans « À acheter ».
        $this->getJson('/m/api/catalog')
            ->assertOk()
            ->assertJsonFragment(['id' => (int) $item->id, 'name' => (string) $item->name]);

        // Remise en stock => l'override repasse disponible.
        $this->postJson('/m/api/toggle', [
            'item_id' => $item->id,
            'is_available' => true,
        ])->assertOk()->assertJson(['ok' => true, 'is_available' => true]);

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => 1,
        ]);
    }

    /** (6) Endpoints /m/* sans session PIN => 401 (verrouillés). */
    public function test_protected_endpoints_locked_without_pin(): void
    {
        $this->getJson('/m/api/catalog')->assertStatus(401);
        $this->postJson('/m/api/toggle', ['item_id' => 1, 'is_available' => false])->assertStatus(401);
        $this->postJson('/m/api/lock', [])->assertStatus(401);
    }

    /** (bonus) Session expirée => re-verrouillage (glissante bornée à session_minutes). */
    public function test_expired_session_relocks(): void
    {
        $this->postJson('/m/api/pin', ['pin' => '2580'])->assertOk();

        $expired = time() - (((int) config('mobile_stock.session_minutes', 720)) * 60 + 61);
        $this->withSession([EnsureMobileStockPin::SESSION_KEY => $expired])
            ->getJson('/m/api/catalog')
            ->assertStatus(401);
    }
}
