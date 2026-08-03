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
 *   - la BORNE explicitement configurée rend à RECEIPT_BORNE_WIDTH_CHARS (ex. 48) ;
 *   - [HEAL 2026-07-09] borne NON configurée → largeur CAISSE (42), plus 48 en dur : la SK1-31
 *     58 mm ré-enroulait « 15,\n00 € » à 48 (photo owner IMG_1729). RECEIPT_BORNE_CODE_PAGE
 *     permet de caler la page de code € propre à la SK1-31 sans toucher la caisse.
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
    public function borne_non_configuree_retombe_sur_la_largeur_caisse(): void
    {
        config()->set('printing.receipt.width_chars', 42);      // caisse configurée
        config()->set('printing.receipt.borne_width_chars', 0); // borne NON configurée
        $order = $this->makeOrder();

        $borne = app(EscPosTicketBytesService::class)->render((int) $order->branch_id, (int) $order->id, 'client', false, true);
        $this->assertNotNull($borne);
        // [HEAL 2026-07-09] borne non configurée → largeur CAISSE (42), plus 48 en dur : la SK1-31
        // 58 mm ré-enroulait « 15,\n00 € » à 48 (photo owner IMG_1729).
        $this->assertSame(42, $this->maxLineWidth($borne), 'borne non configurée → largeur caisse (42), jamais 48');
    }

    /** @test */
    public function borne_code_page_configurable_sans_toucher_la_caisse(): void
    {
        config()->set('printing.receipt.width_chars', 42);
        config()->set('printing.receipt.borne_code_page', 16); // WPC1252 (€=0x80) propre à la SK1-31
        $order = $this->makeOrder();

        $borne = app(EscPosTicketBytesService::class)->render((int) $order->branch_id, (int) $order->id, 'client', false, true);
        $caisse = app(EscPosTicketBytesService::class)->render((int) $order->branch_id, (int) $order->id, 'client', false, false);
        $this->assertNotNull($borne);
        $this->assertNotNull($caisse);
        // ESC t n = 0x1B 0x74 n. La borne sélectionne la page 16 (0x10) ; la caisse garde 19 (0x13, CP858).
        $this->assertStringContainsString("\x1B\x74\x10", $borne, 'borne : ESC t 16 (page de code SK1-31)');
        $this->assertStringContainsString("\x1B\x74\x13", $caisse, 'caisse : ESC t 19 (CP858) inchangé');
    }

    /** @test */
    public function borne_sans_page_code_utilise_EUR_texte_jamais_le_symbole(): void
    {
        config()->set('printing.receipt.width_chars', 42);
        config()->set('printing.receipt.borne_code_page', 0); // aucune page € fiable calée
        $order = $this->makeOrder();

        $borne = app(EscPosTicketBytesService::class)->render((int) $order->branch_id, (int) $order->id, 'client', false, true);
        $this->assertNotNull($borne);
        // Repli SÛR : « EUR » en toutes lettres, JAMAIS l'octet € CP858 (0xD5 = « ⌐ » sur la SK1-31).
        $this->assertStringContainsString(' EUR', $borne, 'borne sans page € → montant en « EUR » texte');
        $this->assertStringNotContainsString("\xD5", $borne, 'borne sans page € → aucun octet € (0xD5) → aucun « ⌐ »');
    }

    /** @test */
    public function borne_avec_page_code_garde_le_vrai_symbole_euro(): void
    {
        config()->set('printing.receipt.width_chars', 42);
        config()->set('printing.receipt.borne_code_page', 19); // CP858 (€=0xD5) vérifiée sur la borne
        $order = $this->makeOrder();

        $borne = app(EscPosTicketBytesService::class)->render((int) $order->branch_id, (int) $order->id, 'client', false, true);
        $this->assertNotNull($borne);
        // Page € calée → vrai symbole (octet CP858 0xD5), pas de repli « EUR ».
        $this->assertStringContainsString("\xD5", $borne, 'borne avec page € → vrai symbole € (0xD5)');
        $this->assertStringNotContainsString(' EUR', $borne, 'borne avec page € → pas de repli EUR');
    }

    /** @test */
    public function caisse_garde_toujours_le_vrai_symbole_euro(): void
    {
        config()->set('printing.receipt.width_chars', 42);
        config()->set('printing.receipt.borne_code_page', 0); // n'affecte QUE la borne
        $order = $this->makeOrder();

        $caisse = app(EscPosTicketBytesService::class)->render((int) $order->branch_id, (int) $order->id, 'client', false, false);
        $this->assertNotNull($caisse);
        $this->assertStringContainsString("\xD5", $caisse, 'caisse → vrai symbole € (CP858 0xD5) inchangé');
        $this->assertStringNotContainsString(' EUR', $caisse, 'caisse → pas de repli EUR');
    }
}
