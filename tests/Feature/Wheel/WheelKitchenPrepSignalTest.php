<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\Item;
use App\Models\WheelSpin;
use App\Services\Wheel\WheelDeliveryService;
use App\Services\Wheel\WheelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [AUDIT-5SYS 2026-08-12 P1] Depuis le commit du jour (`39ee3eb76`, 16h57) 3 des 7 lots réels
 * sont des plats CUISINE (Cheese Burger, Cayenne, Terminator) — mais `WheelDeliveryService`
 * n'écrit qu'une sortie de stock, jamais aucun signal cuisine (0 occurrence Printer/KDS/OrderItem
 * dans app/Services/Wheel/*). Créer un faux Order pour un cadeau contredirait la décision de
 * conception documentée du fichier (« un produit offert ne peut pas être un coupon » / pas de
 * commande fantôme) et risquerait un double décrément de stock (déjà géré par recordCost()).
 *
 * Fix minimal et sûr : réutiliser le SEUL canal qui existe déjà entre le comptoir et l'équipe —
 * le message affiché à l'écran au moment du "remis" (`WheelPrizeController::deliver` → vue
 * `admin.wheel.lot`). Avant ce fix, l'équipe qui remet un burger gagné n'a AUCUN moyen de savoir
 * qu'elle doit prévenir la cuisine. Ce test verrouille l'instruction.
 */
class WheelKitchenPrepSignalTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branchId = Branch::factory()->create()->id;

        Config::set('wheel.enabled', true);
        Config::set('wheel.campaign_key', 'test-kitchen-signal');
        Config::set('wheel.daily_total_cap', 500);
        Config::set('wheel.record_cost_on_claim', false); // hors-sujet ici : pas de produit de référence à seeder
        Config::set('wheel.unlock_methods', ['staff' => true, 'order' => true, 'declaratif' => false]);
    }

    private function tour(string $key, string $tel, array $extra = []): WheelSpin
    {
        Config::set('wheel.segments', [array_merge([
            'key' => $key, 'label' => 'Lot test', 'type' => 'free_item', 'value' => 0,
            'weight' => 1, 'daily_cap' => 0,
        ], $extra)]);

        return app(WheelService::class)->spin(
            $this->branchId, $tel, 'Client', ['method' => 'staff'], null, null, $tel . '@exemple.fr'
        );
    }

    /** @test */
    public function un_lot_cuisine_affiche_une_instruction_de_relais_a_lequipe(): void
    {
        $spin = $this->tour('cheeseburger', '0611000901', ['kitchen_prep' => true, 'label' => 'Cheese Burger']);

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertStringContainsStringIgnoringCase(
            'cuisine',
            $r['message'],
            'Un lot CUISINE remis doit dire explicitement à l\'équipe de prévenir la cuisine — '
            . 'sinon rien ne le distingue d\'une boisson qu\'on tend soi-même.'
        );
    }

    /** @test */
    public function un_lot_non_cuisine_naffiche_aucune_instruction_de_relais(): void
    {
        $spin = $this->tour('boisson', '0611000902', ['label' => 'Boisson offerte']); // pas de kitchen_prep

        $r = app(WheelDeliveryService::class)->deliver($spin->id, null, $this->branchId);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertStringNotContainsStringIgnoringCase(
            'cuisine',
            $r['message'],
            'Un lot que l\'équipe tend elle-même (boisson/frites/dessert) ne doit pas déclencher '
            . 'une fausse alerte cuisine.'
        );
    }
}
