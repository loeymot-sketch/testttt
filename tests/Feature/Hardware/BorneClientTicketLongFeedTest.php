<?php

namespace Tests\Feature\Hardware;

use App\Enums\OrderType;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\EscPosTicketBytesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [TICKET-BORNE-LONG 2026-07-02] Le ticket CLIENT imprimé par la BORNE doit avoir une
 * queue de papier suffisante (il ressort, ne tombe pas) + coupe PARTIELLE (il reste
 * accroché). La CAISSE garde son ticket court (le caissier le tend).
 *
 * [TICKET-BORNE-WHITE 2026-07-05 / c70b1e518] Nouveau contrat EscPosTicketBytesService:81 :
 * la queue borne est CLAMPÉE `max(1, min(12, config))`, défaut 8 — fini l'ère « queue 30 »
 * (30 lignes ≈ 10 cm de BLANC si config orpheline). Ce test verrouille le clamp (30 → 12)
 * et le défaut (8), plus les modes de coupe borne=PARTIELLE / caisse=TOTALE.
 */
class BorneClientTicketLongFeedTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(): Order
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['price' => 7.90]);
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::TAKEAWAY,
            'source' => Source::WEB,
            'source_surface' => 'kiosk',
            'total' => 7.90,
            'subtotal' => 7.90,
            'queue_number' => 'A0001',
        ]);
        (new OrderItem)->forceFill([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 7.90,
            'total_price' => 7.90,
            'discount' => 0,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'tax_amount' => 0,
        ])->save();

        return $order;
    }

    private function tailFeedLines(string $bytes): int
    {
        $cut = strpos($bytes, "\x1DV");
        if ($cut === false) {
            return -1;
        }
        $n = 0;
        for ($i = $cut - 1; $i >= 0 && ord($bytes[$i]) === 0x0A; $i--) {
            $n++;
        }

        return $n;
    }

    /** @test */
    public function borne_client_config_excessive_est_clampee_a_12_et_coupe_partielle(): void
    {
        // Config héritée de l'ère « queue 30 » → le service doit la CLAMPER à 12 (cap).
        config()->set('printing.cut.kiosk_client_feed_lines', 30);
        config()->set('printing.cut.kiosk_client_mode', 'partial');

        $order = $this->makeOrder();
        $svc = app(EscPosTicketBytesService::class);

        $borne = $svc->render((int) $order->branch_id, (int) $order->id, 'client', false, true);
        $this->assertNotNull($borne);
        // Clamp max(1, min(12, 30)) → exactement 12 lignes de queue, jamais 30.
        $this->assertSame(12, $this->tailFeedLines($borne), 'config 30 doit être clampée à 12 lignes de queue');
        // Coupe PARTIELLE (GS V 1) → ne tombe pas.
        $this->assertStringContainsString("\x1DV\x01", $borne, 'la borne doit couper en PARTIEL (ticket reste accroché)');
    }

    /** @test */
    public function borne_client_sans_override_utilise_le_defaut_8(): void
    {
        // Pas d'override : le défaut livré (config/printing.php:134 + repli service :81)
        // = 8 lignes compactes (≈27 mm : dégage la barre de coupe, zéro blanc).
        $order = $this->makeOrder();
        $svc = app(EscPosTicketBytesService::class);

        $borne = $svc->render((int) $order->branch_id, (int) $order->id, 'client', false, true);
        $this->assertNotNull($borne);
        $this->assertSame(8, $this->tailFeedLines($borne), 'défaut borne = 8 lignes de queue');
        // Défaut de mode = partial.
        $this->assertStringContainsString("\x1DV\x01", $borne, 'défaut borne = coupe PARTIELLE');
    }

    /** @test */
    public function caisse_client_ticket_reste_court_et_coupe_totale(): void
    {
        config()->set('printing.cut.feed_lines_before_cut', 8);
        config()->set('printing.cut.mode', 'full');

        $order = $this->makeOrder();
        $svc = app(EscPosTicketBytesService::class);

        $caisse = $svc->render((int) $order->branch_id, (int) $order->id, 'client', false, false);
        $this->assertNotNull($caisse);
        // La caisse n'est PAS rallongée (queue courte).
        $this->assertLessThanOrEqual(12, $this->tailFeedLines($caisse), 'la caisse ne doit pas être rallongée');
        $this->assertStringContainsString("\x1DV\x00", $caisse, 'la caisse coupe en TOTAL');
    }
}
