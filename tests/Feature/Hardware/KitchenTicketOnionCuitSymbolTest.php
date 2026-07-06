<?php

namespace Tests\Feature\Hardware;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\EscPosCommandBuilder;
use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [OWNER8 2026-07-06] W3 §B — « Oignons cuits » sur le ticket CUISINE :
 *
 *  - symbole O̲ (O + U+0332) émis par le formatter PHP (jumeau STRICT du JS
 *    kdsSymbolic.js — même string, ordre canonique S T O O̲)
 *  - CP858 ne connaît pas U+0332 → EscPosCommandBuilder::encodeForPrinter
 *    traduit la séquence X+U+0332 en soulignement MATÉRIEL ESC - 1 X ESC - 0
 *    (\x1B\x2D\x01 O \x1B\x2D\x00) — jamais un « ? » ni un caractère perdu
 *  - U+0332 compte largeur 0 dans les calculs wrap/pad → width-safe 32 ET 48
 */
class KitchenTicketOnionCuitSymbolTest extends TestCase
{
    private const O_CUIT = "O\u{0332}";

    private function makeOrder(array $snapshot, string $itemName, string $instruction = ''): Order
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne (principal)',
            'address' => '437 Rue Élie Gruyelle, 62110 Hénin-Beaumont',
            'phone' => '+33365678291',
            'email' => 'contact@lecayenne.fr',
        ]);
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1,
            'total_price' => 8.90,
            'tax_rate' => 10,
            'tax_name' => 'TVA',
            'tax_type' => 1,
            'tax_amount' => 0.81,
            'composition_snapshot' => $snapshot,
            'instruction' => $instruction,
        ]);
        $oi->name = $itemName;
        $order = (new Order)->forceFill([
            'order_serial_no' => '0607265400',
            'queue_number' => 'A0041',
            'order_type' => OrderType::TAKEAWAY,
            'subtotal' => 8.90,
            'total' => 8.90,
            'total_tax' => 0.81,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'pos_received_amount' => 0,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'order_datetime' => '2026-07-06 12:00:00',
            'fiscal_sequence_no' => 2600,
        ]);
        $order->setRelation('orderItems', collect([$oi]));
        $order->setRelation('branch', $branch);

        return $order;
    }

    private function onionCuitSnapshot(bool $withRawOnion = false): array
    {
        $extras = [
            ['extra_name' => 'Salade', 'line_total' => 0],
            ['extra_name' => 'Tomate', 'line_total' => 0],
            ['extra_name' => 'Oignons cuits', 'line_total' => 0],
        ];
        if ($withRawOnion) {
            $extras[] = ['extra_name' => 'Oignon', 'line_total' => 0];
        }

        return [
            'lines' => [['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Algérienne']],
            'extras' => $extras,
            'addons' => [],
        ];
    }

    /**
     * Décodeur « papier physique » : suit GS ! (double largeur), saute les
     * séquences de contrôle Y COMPRIS ESC - n (soulignement — 3 octets, aucune
     * colonne consommée). Jumeau de TicketWidthSafeTest::decodeLines + ESC '-'.
     *
     * @return array<int, array{text:string, width:int}>
     */
    private function decodeLines(string $bytes): array
    {
        $lines = [];
        $cur = '';
        $wmul = 1;
        $i = 0;
        $len = strlen($bytes);
        while ($i < $len) {
            $c = $bytes[$i];
            if ($c === "\x1D" && $i + 2 < $len && $bytes[$i + 1] === '!') {
                $n = ord($bytes[$i + 2]);
                $wmul = (($n >> 4) & 0x07) + 1;
                $i += 3;

                continue;
            }
            if ($c === "\x1D" && $i + 1 < $len && $bytes[$i + 1] === 'V') {
                if ($cur !== '') {
                    $lines[] = [$cur, $wmul];
                    $cur = '';
                }
                $i += 2;
                if ($i < $len) {
                    $i++;
                }

                continue;
            }
            if ($c === "\x1B" && $i + 1 < $len && in_array($bytes[$i + 1], ['a', 'E', 't', 'd', '!', '-'], true)) {
                $i += 3;

                continue;
            }
            if ($c === "\x1B" && $i + 1 < $len && $bytes[$i + 1] === '@') {
                $i += 2;

                continue;
            }
            if ($c === "\x0A") {
                $lines[] = [$cur, $wmul];
                $cur = '';
                $i++;

                continue;
            }
            if (ord($c) < 0x20) {
                $i++;

                continue;
            }
            $cur .= $c;
            $i++;
        }
        if ($cur !== '') {
            $lines[] = [$cur, $wmul];
        }

        $out = [];
        foreach ($lines as [$ln, $wm]) {
            $txt = iconv('CP858', 'UTF-8//IGNORE', $ln);
            $out[] = ['text' => $txt, 'width' => mb_strlen($txt) * $wm];
        }

        return $out;
    }

    public function test_formatter_symbol_and_canonical_order_match_js_twin(): void
    {
        $f = new KitchenTicketSymbolicFormatter;

        $this->assertSame(self::O_CUIT, $f->cruditeSymbol('Oignons cuits'));
        $this->assertSame(self::O_CUIT, $f->cruditeSymbol('oignon cuit'));
        $this->assertSame(self::O_CUIT, $f->cruditeSymbol('Cuit oignon'));
        $this->assertSame('O', $f->cruditeSymbol('Oignon'), 'le cru reste O');
        $this->assertSame('O', $f->cruditeSymbol('Oignons'));

        // Ordre canonique STOO̲ (cru + cuit ensemble — cas limite)
        $line = $f->mainLine('Tacos M', $this->onionCuitSnapshot(withRawOnion: true));
        $this->assertStringContainsString('STO'.self::O_CUIT, $line);

        // Cas owner réel : cuit sans cru → STO̲
        $line2 = $f->mainLine('Tacos M', $this->onionCuitSnapshot());
        $this->assertStringContainsString('ST'.self::O_CUIT, $line2);
        $this->assertStringNotContainsString('STO'.self::O_CUIT, $line2);
    }

    public function test_free_oignons_cuits_never_leaks_as_supplement_line(): void
    {
        $f = new KitchenTicketSymbolicFormatter;
        $supps = $f->supplementLines($this->onionCuitSnapshot());
        $this->assertSame([], $supps, 'crudité gratuite → repliée dans la ligne 1, pas un supplément');
    }

    public function test_underline_builder_emits_esc_dash_n(): void
    {
        $this->assertSame("\x1B-\x01", EscPosCommandBuilder::underline(true));
        $this->assertSame("\x1B-\x00", EscPosCommandBuilder::underline(false));
    }

    public function test_encode_for_printer_translates_combining_low_line_to_hardware_underline(): void
    {
        $encoded = EscPosCommandBuilder::encodeForPrinter('STO'.self::O_CUIT.' | ALG');

        $this->assertStringContainsString("\x1B-\x01O\x1B-\x00", $encoded,
            'O̲ doit devenir ESC-1 O ESC-0 (soulignement matériel)');
        $this->assertStringNotContainsString('?', $encoded, 'U+0332 ne doit jamais dégénérer en « ? »');
        // Le O cru (STO) reste un O nu AVANT la séquence soulignée
        $this->assertStringContainsString('STO'."\x1B-\x01O\x1B-\x00", $encoded);
    }

    public function test_kitchen_ticket_bytes_carry_underlined_o_next_to_sto(): void
    {
        $order = $this->makeOrder($this->onionCuitSnapshot(withRawOnion: true), 'Tacos M');
        $bytes = (new OrderReceiptEscPosRenderer)->renderKitchenTicket($order, ['width_chars' => 42]);

        $this->assertStringContainsString("\x1B-\x01O\x1B-\x00", $bytes, 'octets soulignement présents');

        // Décodé « papier » : la ligne produit porte STOO (le O̲ redevient O une fois
        // les codes de contrôle retirés) — le soulignement est porté par ESC - n.
        $joined = implode("\n", array_map(static fn ($l) => $l['text'], $this->decodeLines($bytes)));
        $this->assertStringContainsString('STOO', $joined, 'les 2 oignons (cru + cuit) visibles côte à côte');
    }

    public function test_kitchen_and_client_tickets_width_safe_at_32_42_48_with_onion_cuit(): void
    {
        $order = $this->makeOrder(
            $this->onionCuitSnapshot(withRawOnion: true),
            'Tacos M',
            'oignons bien cuits svp'
        );
        $renderer = new OrderReceiptEscPosRenderer;

        foreach ([32, 42, 48] as $w) {
            foreach (['renderKitchenTicket' => 'CUISINE', 'renderClientTicket' => 'CLIENT'] as $method => $ctx) {
                $bytes = $renderer->{$method}($order, ['width_chars' => $w]);
                $bad = [];
                foreach ($this->decodeLines($bytes) as $ln) {
                    if ($ln['width'] > $w) {
                        $bad[] = "[{$ln['width']}] « {$ln['text']} »";
                    }
                }
                $this->assertSame([], $bad, "$ctx O̲ @$w : lignes trop larges :\n  ".implode("\n  ", $bad));
            }
        }
    }

    public function test_combining_low_line_counts_zero_width_in_wrap_and_pad(): void
    {
        // 4 colonnes visibles (STOO̲) mais 5 code points : le wrap NE doit PAS
        // casser plus tôt à cause du U+0332 fantôme.
        $seg = 'STO'.self::O_CUIT;
        $lines = EscPosCommandBuilder::wrapIndented($seg.' | ALG', 12, '  ');
        $this->assertSame(1, count($lines), 'tient sur une seule ligne de 12 col (4+3+3=10 visibles)');

        // lineItemKV : le padding doit alignér la valeur sur la largeur VISIBLE.
        $kv = EscPosCommandBuilder::lineItemKV('1 x '.$seg, '8,90 €', 32);
        $decoded = rtrim($kv, "\x0A");
        $visible = mb_strlen(str_replace("\u{0332}", '', $decoded));
        $this->assertSame(32, $visible, 'ligne paddée à exactement 32 colonnes visibles');
    }
}
