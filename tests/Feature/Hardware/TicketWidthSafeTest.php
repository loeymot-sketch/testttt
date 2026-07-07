<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [TICKET-WIDTHSAFE 2026-07-01] GARDE ANTI-COUPURE.
 *
 * Plainte owner : « il met sept euros, il revient, 40 € » — l'imprimante 58mm (32 col)
 * ré-enroulait les lignes de 48 col. Ce test simule le papier physique : il décode les
 * octets ESC/POS EN TENANT COMPTE de la double-largeur (GS ! n) et vérifie qu'AUCUNE ligne
 * ne dépasse la largeur → aucun « 7,40 € » coupé en deux. Verrouille client + cuisine à 32 ET 48.
 */
class TicketWidthSafeTest extends TestCase
{
    /** @return array<int,array{text:string,width:int}> lignes avec leur largeur EFFECTIVE (×2 si double-largeur) */
    private function decodeLines(string $bytes): array
    {
        $lines = [];
        $cur = '';
        $wmul = 1;
        $i = 0;
        $len = strlen($bytes);
        while ($i < $len) {
            $c = $bytes[$i];
            if ($c === "\x1D" && $i + 2 < $len && $bytes[$i + 1] === '!') { // GS ! n → taille
                $n = ord($bytes[$i + 2]);
                $wmul = (($n >> 4) & 0x07) + 1;
                $i += 3;
                continue;
            }
            if ($c === "\x1D" && $i + 1 < $len && $bytes[$i + 1] === 'V') { // coupe
                if ($cur !== '') { $lines[] = [$cur, $wmul]; $cur = ''; }
                $i += 2; if ($i < $len) $i++;
                continue;
            }
            // [OWNER8 2026-07-06] '-' = ESC - n (soulignement O̲ oignons cuits) : 3 octets, 0 colonne.
            if ($c === "\x1B" && $i + 1 < $len && in_array($bytes[$i + 1], ['a', 'E', 't', 'd', '!', '-'], true)) { $i += 3; continue; }
            if ($c === "\x1B" && $i + 1 < $len && $bytes[$i + 1] === '@') { $i += 2; continue; }
            if ($c === "\x0A") { $lines[] = [$cur, $wmul]; $cur = ''; $i++; continue; }
            if (ord($c) < 0x20) { $i++; continue; }
            $cur .= $c;
            $i++;
        }
        if ($cur !== '') { $lines[] = [$cur, $wmul]; }

        $out = [];
        foreach ($lines as [$ln, $wm]) {
            $txt = iconv('CP858', 'UTF-8//IGNORE', $ln);
            $out[] = ['text' => $txt, 'width' => mb_strlen($txt) * $wm];
        }

        return $out;
    }

    private function assertNoLineExceeds(string $bytes, int $width, string $ctx): void
    {
        $bad = [];
        foreach ($this->decodeLines($bytes) as $ln) {
            if ($ln['width'] > $width) {
                $bad[] = "[{$ln['width']}] « {$ln['text']} »";
            }
        }
        $this->assertSame([], $bad, "$ctx : lignes qui dépassent $width col (seraient coupées) :\n  " . implode("\n  ", $bad));
    }

    private function makeOrder(array $snapshot, string $itemName, float $total = 8.90, string $instruction = ''): Order
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne (principal)',
            'address' => '437 Rue Élie Gruyelle, 62110 Hénin-Beaumont',
            'phone' => '+33365678291',
            'email' => 'contact@lecayenne.fr',
        ]);
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1,
            'total_price' => $total,
            'tax_rate' => 10,
            'tax_name' => 'TVA',
            'tax_type' => 1,
            'tax_amount' => round($total - $total / 1.1, 2),
            'composition_snapshot' => $snapshot,
            'instruction' => $instruction,
        ]);
        $oi->name = $itemName;
        $order = (new Order)->forceFill([
            'order_serial_no' => '0107265333',
            'queue_number' => 'A0040',
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => $total,
            'total' => $total,
            'total_tax' => round($total - $total / 1.1, 2),
            'pos_payment_method' => \App\Enums\PosPaymentMethod::COUNTER_DEFERRED,
            'pos_received_amount' => 0,
            'payment_status' => \App\Enums\PaymentStatus::PENDING_COUNTER,
            'order_datetime' => '2026-07-01 01:35:00',
            'fiscal_sequence_no' => 2550,
        ]);
        $order->setRelation('orderItems', collect([$oi]));
        $order->setRelation('branch', $branch);

        return $order;
    }

    public static function orderProvider(): array
    {
        return [
            'cayenne + menu' => [[
                'lines' => [
                    ['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Algérienne'],
                    ['attribute_name' => 'Type de Pain', 'variation_name' => 'Pain'],
                ],
                'extras' => [['extra_name' => 'Salade', 'line_total' => 0], ['extra_name' => 'Tomate', 'line_total' => 0], ['extra_name' => 'Oignon', 'line_total' => 0]],
                'addons' => [['addon_name' => 'Menu (Frites + Boisson)', 'line_total' => 1.50, 'role' => 'menu_frites']],
            ], 'Cayenne'],
            'terminator 2 viandes + suppléments' => [[
                'lines' => [
                    ['attribute_name' => 'Viande 1', 'variation_name' => 'Mexicanos'],
                    ['attribute_name' => 'Viande 2', 'variation_name' => 'Cordon Bleu'],
                    ['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Andalouse'],
                    ['attribute_name' => 'Type de Pain', 'variation_name' => 'Pain'],
                ],
                'extras' => [['extra_name' => 'Salade', 'line_total' => 0], ['extra_name' => 'Tomate', 'line_total' => 0], ['extra_name' => 'Oignon', 'line_total' => 0], ['extra_name' => 'Champignons', 'line_total' => 0.90], ['extra_name' => 'Jambon', 'line_total' => 0.90]],
                'addons' => [],
            ], 'Terminator'],
            'nom très long' => [[
                'lines' => [], 'extras' => [], 'addons' => [],
            ], 'Grande Frites Cheddar + Oignons frits'],
        ];
    }

    /** @dataProvider orderProvider */
    public function test_client_ticket_never_exceeds_width(array $snapshot, string $name): void
    {
        $order = $this->makeOrder($snapshot, $name);
        $renderer = new OrderReceiptEscPosRenderer;
        foreach ([32, 42, 48] as $w) {
            $bytes = $renderer->renderClientTicket($order, ['width_chars' => $w]);
            $this->assertNoLineExceeds($bytes, $w, "CLIENT ".$name." @".$w);
        }
    }

    /** @dataProvider orderProvider */
    public function test_kitchen_ticket_never_exceeds_width(array $snapshot, string $name): void
    {
        $order = $this->makeOrder($snapshot, $name);
        $renderer = new OrderReceiptEscPosRenderer;
        foreach ([32, 42, 48] as $w) {
            $bytes = $renderer->renderKitchenTicket($order, ['width_chars' => $w]);
            $this->assertNoLineExceeds($bytes, $w, "CUISINE ".$name." @".$w);
        }
    }

    public function test_long_client_note_wraps_within_width(): void
    {
        // [P1 audit] la note client libre (longue) débordait — doit s'enrouler comme la cuisine.
        $order = $this->makeOrder(
            ['lines' => [['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Algérienne']], 'extras' => [], 'addons' => []],
            'Tacos',
            8.90,
            'Sans oignon, bien cuit, sauce à part et surtout pas de sel du tout merci beaucoup'
        );
        foreach ([32, 42, 48] as $w) {
            $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order, ['width_chars' => $w]);
            $this->assertNoLineExceeds($bytes, $w, 'CLIENT note longue @' . $w);
        }
    }

    public function test_ligature_oeuf_keeps_width_and_reads_oeuf(): void
    {
        // [P2 audit] « Œuf » : mb_strlen=3 mais imprime « Oeuf »=4 → largeur faussée + « UF » bizarre.
        // Normalisation Œ→Oe en amont : largeur juste ET « + Oeuf » lisible.
        $order = $this->makeOrder(
            ['lines' => [], 'extras' => [['extra_name' => 'Œuf', 'line_total' => 0.90]], 'addons' => []],
            'Tacos'
        );
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order, ['width_chars' => 32]);
        $this->assertNoLineExceeds($bytes, 32, 'CLIENT Œuf @32');
        $decoded = iconv('CP858', 'UTF-8//IGNORE', preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $bytes));
        $this->assertStringContainsString('Oeuf', $decoded, '« Œuf » doit s\'imprimer « Oeuf »');
    }

    public function test_config_width_chars_is_honored_when_no_opts(): void
    {
        // [TICKET-WIDTH 2026-07-04] Régression du vrai bug photo IMG_1709 : la largeur DOIT
        // suivre config('printing.receipt.width_chars') (RECEIPT_WIDTH_CHARS) quand aucune
        // largeur n'est passée en opts — sinon la SAGA 42 col ré-enroule des lignes de 48.
        config(['printing.receipt.width_chars' => 42]);
        $order = $this->makeOrder(self::orderProvider()['terminator 2 viandes + suppléments'][0], 'Terminator');
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order, []); // pas de width_chars
        $this->assertNoLineExceeds($bytes, 42, 'CLIENT largeur-config @42 (sans opts)');
    }

    public function test_kitchen_supplement_starred_and_product_line_double_size(): void
    {
        // [T2+T3 2026-07-05] Cuisine : ligne produit en DOUBLE TAILLE (grande) + suppléments
        // en GRAS avec étoile « * » (owner). Le tout SANS jamais dépasser la largeur physique.
        $order = $this->makeOrder(
            ['lines' => [], 'extras' => [['extra_name' => 'Cheddar', 'line_total' => 1.00]], 'addons' => []],
            'Tacos'
        );
        $bytes = (new OrderReceiptEscPosRenderer)->renderKitchenTicket($order, ['width_chars' => 42]);
        $this->assertNoLineExceeds($bytes, 42, 'CUISINE grande @42');

        $lines = $this->decodeLines($bytes);
        $joined = implode("\n", array_map(static fn ($l) => $l['text'], $lines));
        $this->assertStringContainsString('* Cheddar', $joined, 'supplément doit porter une étoile « * »');
        $this->assertStringNotContainsString('+ Cheddar', $joined, 'plus de « + » : c\'est « * » maintenant');

        $headDoubleSize = false;
        foreach ($lines as $l) {
            if (str_contains($l['text'], 'TAC') && $l['width'] === mb_strlen($l['text']) * 2) {
                $headDoubleSize = true;
            }
        }
        $this->assertTrue($headDoubleSize, 'la ligne produit cuisine (TAC) doit être en double taille (2×)');
    }

    public function test_client_name_printed_on_client_and_kitchen_tickets_when_set(): void
    {
        // [C2-CAISSE 2026-07-05] Nom du client (optionnel) imprimé sur le ticket client ET cuisine.
        $order = $this->makeOrder(['lines' => [], 'extras' => [], 'addons' => []], 'Tacos');
        $order->pos_customer_name = 'Marc';

        foreach (['renderClientTicket', 'renderKitchenTicket'] as $method) {
            $bytes = (new OrderReceiptEscPosRenderer)->{$method}($order, ['width_chars' => 42]);
            $this->assertNoLineExceeds($bytes, 42, "C2 {$method} @42");
            $decoded = iconv('CP858', 'UTF-8//IGNORE', preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $bytes));
            $this->assertStringContainsString('Client : Marc', $decoded, "{$method} doit afficher le nom client");
        }

        // Sans nom → aucune ligne « Client : ».
        $order2 = $this->makeOrder(['lines' => [], 'extras' => [], 'addons' => []], 'Tacos');
        $bytes2 = (new OrderReceiptEscPosRenderer)->renderClientTicket($order2, ['width_chars' => 42]);
        $decoded2 = iconv('CP858', 'UTF-8//IGNORE', preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $bytes2));
        $this->assertStringNotContainsString('Client :', $decoded2, 'pas de ligne Client si nom vide');
    }

    public function test_client_phone_printed_on_client_and_kitchen_tickets_when_set(): void
    {
        // [C4-CAISSE-TELEPHONE FIX-3 2026-07-07] Téléphone client (commande téléphone
        // différée) imprimé sur le ticket client ET cuisine, à côté du nom, pour rappeler
        // le client au comptoir. Width-safe à 32/42/48 col, et 0 régression quand absent.
        $order = $this->makeOrder(['lines' => [], 'extras' => [], 'addons' => []], 'Tacos');
        $order->pos_customer_name = 'Madame Durand';
        $order->pos_customer_phone = '06 12 34 56 78';

        foreach (['renderClientTicket', 'renderKitchenTicket'] as $method) {
            foreach ([32, 42, 48] as $w) {
                $bytes = (new OrderReceiptEscPosRenderer)->{$method}($order, ['width_chars' => $w]);
                $this->assertNoLineExceeds($bytes, $w, "C4 {$method} @{$w}");
                $decoded = iconv('CP858', 'UTF-8//IGNORE', preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $bytes));
                $this->assertStringContainsString('Client : Madame Durand', $decoded, "{$method} doit afficher le nom client");
                $this->assertStringContainsString('Tel : 06 12 34 56 78', $decoded, "{$method} doit afficher le téléphone client");
            }
        }

        // Téléphone seul (sans nom) → la ligne « Tel : » s'imprime quand même.
        $orderPhoneOnly = $this->makeOrder(['lines' => [], 'extras' => [], 'addons' => []], 'Tacos');
        $orderPhoneOnly->pos_customer_phone = '0102030405';
        $bytesPhoneOnly = (new OrderReceiptEscPosRenderer)->renderClientTicket($orderPhoneOnly, ['width_chars' => 32]);
        $decodedPhoneOnly = iconv('CP858', 'UTF-8//IGNORE', preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $bytesPhoneOnly));
        $this->assertStringContainsString('Tel : 0102030405', $decodedPhoneOnly, 'téléphone seul doit s\'imprimer même sans nom');

        // Sans téléphone → aucune ligne « Tel : » (0 régression ticket normal).
        $orderNoPhone = $this->makeOrder(['lines' => [], 'extras' => [], 'addons' => []], 'Tacos');
        $bytesNoPhone = (new OrderReceiptEscPosRenderer)->renderClientTicket($orderNoPhone, ['width_chars' => 42]);
        $decodedNoPhone = iconv('CP858', 'UTF-8//IGNORE', preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $bytesNoPhone));
        $this->assertStringNotContainsString('Tel :', $decodedNoPhone, 'pas de ligne Tel si téléphone vide');
    }

    public function test_price_stays_atomic_on_one_line(): void
    {
        $order = $this->makeOrder(self::orderProvider()['cayenne + menu'][0], 'Cayenne');
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order, ['width_chars' => 32]);
        $decoded = iconv('CP858', 'UTF-8//IGNORE', preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $bytes));
        // Le montant total « 8,90 € » ne doit JAMAIS être scindé (« 8,\n90 » ou « 8,90\n€ »).
        $this->assertStringContainsString('8,90 €', $decoded, 'le prix doit rester atomique');
        $this->assertDoesNotMatchRegularExpression('/\d,\s*\n\s*\d{2}/', $decoded, 'aucun prix coupé après la virgule');
    }
}
