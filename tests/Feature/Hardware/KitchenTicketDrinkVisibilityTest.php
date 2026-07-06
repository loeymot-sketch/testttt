<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [W3-FIX-C 2026-07-06] Boissons VISIBLES sur le ticket cuisine (owner : le cuisinier
 * prépare aussi les boissons). 3 chemins réels prouvés en DB :
 *  1. item boisson standalone (#5456 « Coca-Cola 33cl ») → nom COMPLET (plus « 1 x COC »)
 *  2. addon role=drink (#5171 « Boisson Seule » sur Bol Riz) → ligne « 1 Boisson Seule »
 *  3. addon role=menu_boisson (formule borne) → boisson listée sous MENU
 * + width-safe 32 col (58mm). Détection isDrinkItem = jumeau EXACT du JS
 * categorize()==='drink' (kdsCustomization.js, garde dessert-avant-drink).
 */
class KitchenTicketDrinkVisibilityTest extends TestCase
{
    private function renderer(): OrderReceiptEscPosRenderer
    {
        return app(OrderReceiptEscPosRenderer::class);
    }

    /** @return array<int,array{text:string,width:int}> décode ESC/POS avec la double-largeur (GS ! n) */
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
            if ($c === "\x1B" && $i + 1 < $len && in_array($bytes[$i + 1], ['a', 'E', 't', 'd', '!'], true)) {
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
            $txt = (string) iconv('CP858', 'UTF-8//IGNORE', $ln);
            $out[] = ['text' => $txt, 'width' => mb_strlen($txt) * $wm];
        }

        return $out;
    }

    private function decodedText(string $bytes): string
    {
        return implode("\n", array_map(fn ($l) => $l['text'], $this->decodeLines($bytes)));
    }

    /** @param array<int,array{name:string,snapshot:array,instruction?:string}> $items */
    private function makeOrder(array $items): Order
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne (principal)',
            'address' => '437 Rue Élie Gruyelle, 62110 Hénin-Beaumont',
            'phone' => '+33365678291',
        ]);
        $orderItems = collect();
        foreach ($items as $it) {
            $oi = (new OrderItem)->forceFill([
                'quantity' => $it['quantity'] ?? 1,
                'total_price' => 8.90,
                'composition_snapshot' => $it['snapshot'],
                'instruction' => $it['instruction'] ?? '',
            ]);
            $oi->name = $it['name'];
            $orderItems->push($oi);
        }
        $order = (new Order)->forceFill([
            'order_serial_no' => 'E2E-173832',
            'queue_number' => 'A0055',
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 8.90,
            'total' => 8.90,
            'order_datetime' => '2026-07-05 23:29:00',
        ]);
        $order->setRelation('orderItems', $orderItems);
        $order->setRelation('branch', $branch);

        return $order;
    }

    private const EMPTY_SNAP = ['lines' => [], 'extras' => [], 'addons' => []];

    public function test_standalone_drink_item_prints_full_name_not_code(): void
    {
        // shape réel #5456 : « Coca-Cola 33cl » standalone (snapshot vide)
        $order = $this->makeOrder([['name' => 'Coca-Cola 33cl', 'snapshot' => self::EMPTY_SNAP]]);
        $txt = $this->decodedText($this->renderer()->renderKitchenTicket($order));

        $this->assertStringContainsString('Coca-Cola 33cl', $txt, 'la boisson doit sortir en NOM COMPLET');
        $this->assertDoesNotMatchRegularExpression('/1 x COC\b/', $txt, 'plus de code 3 lettres cryptique');
    }

    public function test_drink_addon_role_drink_is_printed(): void
    {
        // shape réel #5171 : Bol Riz + addon « Boisson Seule » role=drink
        $order = $this->makeOrder([[
            'name' => 'Bol Riz',
            'snapshot' => [
                'lines' => [],
                'extras' => [],
                'addons' => [['role' => 'drink', 'addon_id' => 100, 'quantity' => 1, 'addon_name' => 'Boisson Seule', 'line_total' => 2, 'unit_price' => 2]],
            ],
        ]]);
        $txt = $this->decodedText($this->renderer()->renderKitchenTicket($order));

        $this->assertStringContainsString('1 Boisson Seule', $txt, 'addon role=drink DOIT apparaître en cuisine');
        $this->assertStringContainsString('BOL', $txt, 'le produit principal garde sa ligne symbolique');
    }

    public function test_menu_boisson_addon_is_printed_with_menu_line(): void
    {
        $order = $this->makeOrder([[
            'name' => 'Tacos M',
            'snapshot' => [
                'lines' => [['attribute_name' => 'Viande 1', 'variation_name' => 'Poulet mariné']],
                'extras' => [],
                'addons' => [
                    ['role' => 'menu_frites', 'quantity' => 1, 'addon_name' => 'Frites'],
                    ['role' => 'menu_boisson', 'quantity' => 1, 'addon_name' => 'Coca-Cola 33cl'],
                ],
            ],
        ]]);
        $txt = $this->decodedText($this->renderer()->renderKitchenTicket($order));

        $this->assertStringContainsString('MENU', $txt);
        $this->assertStringContainsString('1 Coca-Cola 33cl', $txt, 'la boisson du MENU doit être listée (owner : préparée en cuisine)');
    }

    public function test_menu_item_branch_prints_its_drink_addon(): void
    {
        // item addon « Menu (Frites + Boisson) » séparé (branche isMenuItem)
        $order = $this->makeOrder([[
            'name' => 'Menu (Frites + Boisson)',
            'snapshot' => [
                'lines' => [],
                'extras' => [],
                'addons' => [['role' => 'menu_boisson', 'quantity' => 1, 'addon_name' => 'Fanta 33cl']],
            ],
        ]]);
        $txt = $this->decodedText($this->renderer()->renderKitchenTicket($order));

        $this->assertStringContainsString('MENU', $txt);
        $this->assertStringContainsString('1 Fanta 33cl', $txt);
    }

    public function test_order_5501_shape_note_and_drink_both_visible(): void
    {
        // réplique EXACTE de la commande 5501 E2E-173832 (Tacos M note « oignons cuits » + Coca item)
        $order = $this->makeOrder([
            [
                'name' => 'Tacos M',
                'instruction' => "Viandes : Poulet mariné ×1\noignons cuits",
                'snapshot' => [
                    'lines' => [
                        ['attribute_name' => 'Viande 1', 'variation_name' => 'Poulet mariné'],
                        ['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Algérienne'],
                    ],
                    'extras' => [],
                    'addons' => [],
                ],
            ],
            ['name' => 'Coca-Cola 33cl', 'snapshot' => self::EMPTY_SNAP],
        ]);
        $txt = $this->decodedText($this->renderer()->renderKitchenTicket($order));

        $this->assertStringContainsString('** oignons cuits', $txt, 'note client sur le ticket cuisine');
        $this->assertStringContainsString('Coca-Cola 33cl', $txt, 'boisson en nom complet');
        $this->assertStringNotContainsString('Viandes : Poulet', $txt, 'écho compo du wizard strippé');
    }

    public function test_width_safe_32_and_48_columns(): void
    {
        $order = $this->makeOrder([
            ['name' => 'Coca-Cola Cherry Zero 33cl', 'snapshot' => self::EMPTY_SNAP],
            [
                'name' => 'Bol Riz',
                'snapshot' => [
                    'lines' => [],
                    'extras' => [],
                    'addons' => [['role' => 'drink', 'quantity' => 2, 'addon_name' => 'Boisson Seule Grand Format 50cl']],
                ],
            ],
        ]);
        foreach ([32, 48] as $w) {
            $bytes = $this->renderer()->renderKitchenTicket($order, ['width_chars' => $w]);
            $bad = [];
            foreach ($this->decodeLines($bytes) as $ln) {
                if ($ln['width'] > $w) {
                    $bad[] = "[{$ln['width']}] « {$ln['text']} »";
                }
            }
            $this->assertSame([], $bad, "lignes > $w col (seraient coupées) :\n  ".implode("\n  ", $bad));
        }
    }

    public function test_is_drink_item_twin_of_js_categorize(): void
    {
        $f = app(KitchenTicketSymbolicFormatter::class);
        // attendus = categorize() JS (kdsCustomization.js) — parité verrouillée à la main
        $cases = [
            'Coca-Cola 33cl' => true,
            'Fanta Hawai 33cl' => true,
            'Eau 50cl' => true,
            'Jus de pomme' => true,
            'Café' => true,
            'Gâteau' => false,          // garde dessert-avant-drink (« eau » non ancré)
            'Tiramisu' => false,
            'Tacos M' => false,
            'Menu Enfant Burger' => false, // menu_formule avant drink
            'Bol Riz' => false,
            'Frites' => false,
        ];
        foreach ($cases as $name => $expected) {
            $this->assertSame($expected, $f->isDrinkItem($name), "isDrinkItem('$name')");
        }
    }
}
