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
 * [TICKET-WIDTH-BORNE 2026-07-05] Largeur DÉCOUPLÉE caisse ↔ borne.
 *
 * La caisse (SAGA) imprime ~42 col ; la borne (SK1-31) est plus large (48). Appliquer la
 * largeur caisse (42) à la borne laissait une MARGE BLANCHE à droite. Ce test prouve, via le
 * VRAI chemin (EscPosTicketBytesService, celui que fetchent la caisse ET la borne), que :
 *   - la CAISSE rend à RECEIPT_WIDTH_CHARS (42) ;
 *   - la BORNE rend à RECEIPT_BORNE_WIDTH_CHARS (48) — remplit la largeur, aucune marge ;
 *   - borne non configurée → défaut 48 (jamais 42).
 */
class BorneCaisseWidthDecoupledTest extends TestCase
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

    /** Largeur EFFECTIVE max d'une ligne (tient compte de la double-largeur GS ! n). */
    private function maxLineWidth(string $bytes): int
    {
        $max = 0; $cur = ''; $wmul = 1; $i = 0; $len = strlen($bytes);
        $flush = function () use (&$max, &$cur, &$wmul) {
            $txt = iconv('CP858', 'UTF-8//IGNORE', $cur);
            $w = mb_strlen($txt) * $wmul;
            if ($w > $max) { $max = $w; }
            $cur = '';
        };
        while ($i < $len) {
            $c = $bytes[$i];
            if ($c === "\x1D" && $i + 2 < $len && $bytes[$i + 1] === '!') { $wmul = ((ord($bytes[$i + 2]) >> 4) & 0x07) + 1; $i += 3; continue; }
            if ($c === "\x1D" && $i + 1 < $len && $bytes[$i + 1] === 'V') { $flush(); $i += 2; if ($i < $len) { $i++; } continue; }
            if ($c === "\x1B" && $i + 1 < $len && in_array($bytes[$i + 1], ['a', 'E', 't', 'd', '!'], true)) { $i += 3; continue; }
            if ($c === "\x1B" && $i + 1 < $len && $bytes[$i + 1] === '@') { $i += 2; continue; }
            if ($c === "\x0A") { $flush(); $i++; continue; }
            if (ord($c) < 0x20) { $i++; continue; }
            $cur .= $c; $i++;
        }
        $flush();

        return $max;
    }

    /** @test */
    public function caisse_rend_a_42_quand_configure(): void
    {
        config()->set('printing.receipt.width_chars', 42);
        config()->set('printing.receipt.borne_width_chars', 48);
        $order = $this->makeOrder();

        $caisse = app(EscPosTicketBytesService::class)->render((int) $order->branch_id, (int) $order->id, 'client', false, false);
        $this->assertNotNull($caisse);
        $this->assertSame(42, $this->maxLineWidth($caisse), 'la caisse doit rendre à 42 col (RECEIPT_WIDTH_CHARS)');
    }

    /** @test */
    public function borne_rend_a_48_et_ignore_la_largeur_caisse(): void
    {
        config()->set('printing.receipt.width_chars', 42);       // caisse
        config()->set('printing.receipt.borne_width_chars', 48); // borne
        $order = $this->makeOrder();

        $borne = app(EscPosTicketBytesService::class)->render((int) $order->branch_id, (int) $order->id, 'client', false, true);
        $this->assertNotNull($borne);
        // > 42 prouve que la borne N'utilise PAS la largeur caisse (42) → plus de marge blanche.
        $this->assertSame(48, $this->maxLineWidth($borne), 'la borne doit rendre à 48 col (RECEIPT_BORNE_WIDTH_CHARS), pas 42');
    }

    /** @test */
    public function borne_non_configuree_retombe_sur_48(): void
    {
        config()->set('printing.receipt.width_chars', 42);      // caisse configurée
        config()->set('printing.receipt.borne_width_chars', 0); // borne NON configurée
        $order = $this->makeOrder();

        $borne = app(EscPosTicketBytesService::class)->render((int) $order->branch_id, (int) $order->id, 'client', false, true);
        $this->assertNotNull($borne);
        $this->assertSame(48, $this->maxLineWidth($borne), 'borne non configurée → défaut 48 (jamais la largeur caisse 42)');
    }
}
