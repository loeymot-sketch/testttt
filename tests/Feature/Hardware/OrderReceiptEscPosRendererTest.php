<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use App\Services\Hardware\PrinterTransport\WindowsRawPrinterTransport;
use Tests\TestCase;

/**
 * [PRINT-SAGA 2026-06-24] Pure (no-DB) coverage for the ESC/POS ticket renderer
 * and the Windows raw transport command builder.
 */
class OrderReceiptEscPosRendererTest extends TestCase
{
    private function makeOrder(): Order
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne',
            'address' => '437 Rue Élie Gruyelle',
            'phone' => '+33600000000',
        ]);

        // 2 distinct meats + an ACCENTED paid extra ("Viande supplémentaire") — the
        // exact shape that regressed (accented label dropped by double-encoding).
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1,
            'total_price' => 13.80,
            'tax_rate' => 10,
            'tax_name' => 'TVA',
            'tax_type' => 1,
            'tax_amount' => 1.25,
            'composition_snapshot' => [
                'lines' => [
                    ['attribute_name' => 'Viande 1', 'variation_name' => 'Cordon Bleu'],
                    ['attribute_name' => 'Viande 2', 'variation_name' => 'Fricadelle'],
                    ['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Samouraï'],
                ],
                'extras' => [
                    ['extra_name' => 'Cheddar', 'line_total' => 0.90, 'quantity' => 1],
                    ['extra_name' => 'Viande supplémentaire', 'line_total' => 2.50, 'quantity' => 1],
                ],
                'addons' => [],
            ],
        ]);
        $oi->name = 'Tacos L';

        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-1',
            'queue_number' => 'A0010',
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 13.80,
            'total' => 13.80,
            'total_tax' => 1.25,
            'pos_payment_method' => 1,
            'pos_received_amount' => 13.80,
            'order_datetime' => '2026-06-24 17:03:00',
            'fiscal_sequence_no' => 2550,
        ]);
        $order->setRelation('branch', $branch);
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        return $order;
    }

    public function test_client_ticket_keeps_accented_extra_label_and_price(): void
    {
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeOrder());

        // The accented label MUST survive (regression: double-encoding dropped it).
        $this->assertStringContainsString('Viande suppl', $bytes, 'accented extra label dropped');
        $this->assertStringContainsString('Cheddar', $bytes);
        $this->assertStringContainsString('2,50', $bytes, 'paid supplement price missing');
        $this->assertStringContainsString('0,90', $bytes);
        // 2 distinct meats present.
        $this->assertStringContainsString('Cordon Bleu', $bytes);
        $this->assertStringContainsString('Fricadelle', $bytes);
        // Totals + ESC/POS framing.
        $this->assertStringContainsString('TOTAL', $bytes);
        $this->assertStringContainsString('13,80', $bytes);
        $this->assertStringContainsString("\x1B\x40", $bytes, 'missing ESC @ init');
        $this->assertStringContainsString("\x1D\x56", $bytes, 'missing GS V cut');
        // Fiscal footer.
        $this->assertStringContainsString('2550', $bytes);
    }

    public function test_client_ticket_shows_order_type_queue_and_tva_breakdown(): void
    {
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeOrder());
        // Queue number prominent (customer pickup number).
        $this->assertStringContainsString('A0010', $bytes);
        // Order type banner (sur place / à emporter / livraison).
        $this->assertStringContainsString('EMPORTER', $bytes);
        // Reference layout (VAZY GOOD style).
        $this->assertStringContainsString('ARTICLES', $bytes, 'column header missing');
        $this->assertStringContainsString('MONTANT TOTAL', $bytes);
        $this->assertStringContainsString('SOUS', $bytes);
        $this->assertStringContainsString('BON APP', $bytes, 'BON APPÉTIT footer missing');
        // TVA ventilated by rate.
        $this->assertStringContainsString('TVA 10', $bytes, 'TVA rate line missing');
    }

    public function test_client_ticket_shows_phone_email_and_website(): void
    {
        config(['printing.receipt.website' => 'lecayenne.fr']);
        $order = $this->makeOrder();
        $order->branch->phone = '0365678291';
        $order->branch->email = 'contact@lecayenne.fr';
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);
        // French 10-digit number grouped in pairs.
        $this->assertStringContainsString('03 65 67 82 91', $bytes, 'phone not formatted');
        $this->assertStringContainsString('contact@lecayenne.fr', $bytes, 'email missing');
        $this->assertStringContainsString('lecayenne.fr', $bytes, 'website missing');
    }

    /**
     * [TICKET-PHONE 2026-07-03] Owner : « le n° n'est pas affiché ». La branche V1
     * peut ne pas avoir de téléphone en base → le ticket doit alors retomber sur le
     * défaut config `printing.receipt.phone` (03 65 67 82 91) pour que le n° apparaisse
     * TOUJOURS. Si la branche EN a un, il prime (test précédent).
     */
    public function test_client_ticket_falls_back_to_config_phone_when_branch_has_none(): void
    {
        config(['printing.receipt.phone' => '03 65 67 82 91']);
        $order = $this->makeOrder();
        $order->branch->phone = null; // branche sans téléphone renseigné
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);
        $this->assertStringContainsString('03 65 67 82 91', $bytes, 'config phone fallback missing (n° absent du ticket)');
    }

    /**
     * [TICKET-ADRESSE 2026-07-03] L'adresse doit apparaître via le défaut config
     * `printing.receipt.address` quand la branche n'en a pas (design pro nom/adresse/tél).
     */
    public function test_client_ticket_falls_back_to_config_address_when_branch_has_none(): void
    {
        config(['printing.receipt.address' => '12 Rue Exemple, 59000 Lille']);
        $order = $this->makeOrder();
        $order->branch->address = null; // branche sans adresse
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);
        $this->assertStringContainsString('12 Rue Exemple', $bytes, 'config address fallback missing');
    }

    public function test_client_ticket_shows_unit_price_when_qty_above_one(): void
    {
        $order = $this->makeOrder();
        $oi = $order->orderItems->first();
        $oi->quantity = 2;
        $oi->total_price = 15.80;
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);
        // 15,80 / 2 = 7,90 unit price shown.
        $this->assertStringContainsString('7,90', $bytes, 'unit price for qty>1 missing');
    }

    public function test_client_ticket_tva_is_prorated_when_discounted(): void
    {
        // Gross goods 15,90 (HT 14,45 / TVA 1,45 @10%), discount 5,00 → net 10,90.
        // The printed VAT must reflect the NET (≈0,99), not the gross 1,45.
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1, 'total_price' => 15.90,
            'tax_rate' => 10, 'tax_name' => 'TVA', 'tax_type' => 1, 'tax_amount' => 1.45,
            'composition_snapshot' => ['lines' => [['attribute_name' => 'Sauce', 'variation_name' => 'Blanche']]],
        ]);
        $oi->name = 'Cayenne';
        $order = (new Order)->forceFill([
            'order_serial_no' => 'D-1', 'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 15.90, 'discount' => 5.00, 'delivery_charge' => 0,
            'total' => 10.90, 'total_tax' => 1.45, 'pos_payment_method' => 1, 'pos_received_amount' => 10.90,
            'fiscal_sequence_no' => 12,
        ]);
        $order->setRelation('branch', (new Branch)->forceFill(['name' => 'Le Cayenne']));
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);
        $this->assertStringContainsString('0,99', $bytes, 'TVA should be prorated to the net');
        $this->assertStringNotContainsString('1,45', $bytes, 'gross TVA must not be printed when discounted');
    }

    public function test_paid_order_without_pos_method_is_not_marked_to_pay(): void
    {
        $order = $this->makeOrder();
        $order->pos_payment_method = null;
        $order->payment_status = \App\Enums\PaymentStatus::PAID;
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);
        $this->assertStringNotContainsString('REGLER EN CAISSE', $bytes, 'paid order must not say "à régler"');
        $this->assertStringContainsString('PAY', $bytes, 'paid order should be marked paid');
    }

    public function test_unpaid_order_is_marked_to_pay_at_counter(): void
    {
        $order = $this->makeOrder();
        $order->pos_payment_method = null;
        $order->payment_status = \App\Enums\PaymentStatus::UNPAID;
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);
        $this->assertStringContainsString('REGLER EN CAISSE', $bytes);
    }

    /**
     * [SYNC-BORNE 2026-06-30] La borne Plan B crée la commande avec
     * pos_payment_method=COUNTER_DEFERRED(6) + payment_status=PENDING_COUNTER(15)
     * + pos_received_amount=0. AVANT encaissement, le ticket client doit dire
     * « À RÉGLER EN CAISSE » — JAMAIS « PAIEMENT 6 : 0,00 € » (méthode 6 = différé,
     * pas un vrai règlement). L'encaissement caisse (confirmCounterPayment) écrase
     * ensuite la méthode par la vraie (1/2…) → le ticket final montre le vrai paiement.
     */
    public function test_counter_deferred_kiosk_order_is_marked_to_pay_not_payment6(): void
    {
        $order = $this->makeOrder();
        $order->pos_payment_method = \App\Enums\PosPaymentMethod::COUNTER_DEFERRED; // 6
        $order->pos_received_amount = '0.000000';
        $order->payment_status = \App\Enums\PaymentStatus::PENDING_COUNTER; // 15
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);
        $this->assertStringContainsString('REGLER EN CAISSE', $bytes, 'commande borne différée doit afficher "à régler en caisse"');
        $this->assertStringNotContainsString('PAIEMENT 6', $bytes, 'méthode différée 6 ne doit pas apparaître comme un règlement');
        $this->assertStringNotContainsString('Paiement 6', $bytes);
    }

    public function test_tickets_use_enlarged_readable_body(): void
    {
        // [TICKET-BIG 2026-06-30] Owner: écriture grande & lisible (~+30%). On agrandit
        // la HAUTEUR du corps (GS ! 0x01) → présent dans les 2 tickets, et la largeur
        // double (0x11) reste pour l'en-tête/total. 48 colonnes préservées (pas de débordement).
        $client = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeOrder());
        $kitchen = (new OrderReceiptEscPosRenderer)->renderKitchenTicket($this->makeOrder());
        $this->assertStringContainsString("\x1D!\x01", $client, 'client body not enlarged (double-height)');
        $this->assertStringContainsString("\x1D!\x01", $kitchen, 'kitchen body not enlarged (double-height)');
    }

    public function test_client_ticket_marks_duplicata(): void
    {
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeOrder(), ['is_duplicata' => true]);
        $this->assertStringContainsString('DUPLICATA', $bytes);
    }

    public function test_kitchen_ticket_uses_symbolic_format_no_prices(): void
    {
        $bytes = (new OrderReceiptEscPosRenderer)->renderKitchenTicket($this->makeOrder());
        $this->assertStringContainsString('CUISINE', $bytes);
        // [KITCHEN-SYMBOLS 2026-06-28 / T2+T3-CUISINE 2026-07-05] Tacos L → code 3 lettres « TAC ».
        // La ligne produit est en DOUBLE TAILLE : une compo longue (2 viandes) s'enroule
        // proprement sur 2 lignes (jamais coupée au milieu d'un symbole) → on vérifie les
        // SEGMENTS (robuste à l'enroulement) plutôt que la chaîne contiguë.
        $this->assertStringContainsString('TAC', $bytes);
        // [KITCHEN-QTY 2026-07-15] Segments vérifiés séparément (robuste à l'enroulement) : sans
        // le préfixe « 1 x » (retiré à qty=1), la ligne double-taille s'enroule à un autre endroit
        // et « Cordon » / « Frec » peuvent tomber sur 2 lignes — les deux viandes restent présentes.
        $this->assertStringContainsString('Cordon', $bytes);
        $this->assertStringContainsString('Frec', $bytes);
        $this->assertStringContainsString('SAM', $bytes);
        // [T3-CUISINE] Suppléments payants en GRAS + étoile « * » (accent doit survivre CP858).
        $this->assertStringContainsString('* Cheddar', $bytes);
        $this->assertStringContainsString('* Viande suppl', $bytes);
        // No prices on the kitchen ticket.
        $this->assertStringNotContainsString('EUR', $bytes, 'kitchen ticket must not show prices');
        // [AUDIT F1] Same call number as the client ticket (queue, not the long serial).
        $this->assertStringContainsString('A0010', $bytes, 'kitchen must show the call number');
        $this->assertStringNotContainsString('TEST-1', $bytes, 'kitchen must not show the long serial');
        // [AUDIT F2] Order type so the cook knows the packaging.
        $this->assertStringContainsString('EMPORTER', $bytes, 'kitchen must show order type');
    }

    public function test_windows_raw_transport_builds_winspool_command(): void
    {
        $t = new WindowsRawPrinterTransport;
        $cmd = $t->buildSpoolCommand('SAGA-80mm', 'C:\\tmp\\fk.bin');

        $this->assertStringContainsString('powershell', $cmd);
        $this->assertStringContainsString('-EncodedCommand', $cmd);

        $b64 = trim(str_replace([
            'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ',
            ' 2>&1',
        ], '', $cmd));
        $decoded = mb_convert_encoding(base64_decode($b64), 'UTF-8', 'UTF-16LE');

        $this->assertStringContainsString('SAGA-80mm', $decoded, 'printer name not in spool command');
        $this->assertStringContainsString('WritePrinter', $decoded);
        $this->assertStringContainsString('RAW', $decoded);
        $this->assertStringContainsString('C:\\tmp\\fk.bin', $decoded);
    }

    public function test_windows_raw_transport_refuses_non_windows_host(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('host is Windows');
        }
        $t = new WindowsRawPrinterTransport;
        $this->assertFalse($t->send('bytes', ['host' => 'SAGA']));
        $this->assertStringContainsString('requires_windows_host', (string) $t->lastError());
    }

    /* ───────── [TICKET-FEED 2026-06-30] le ticket tombait par terre ─────────
     * Cause : feed(3) puis coupe immédiate (GS V 0) → la queue (~10 mm) ne dégage
     * pas la barre de coupe (~15-20 mm au-dessus de la tête) → stub trop court qui
     * tombe. Fix : queue allongée (config, défaut 8 lignes ≈ 28 mm) à hauteur NORMALE
     * avant la coupe, + mode coupe PARTIELLE optionnel (ticket reste attaché). */
    public function test_client_ticket_feeds_long_tail_before_cut(): void
    {
        config(['printing.cut.feed_lines_before_cut' => 8, 'printing.cut.mode' => 'full']);
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeOrder());
        // 8 sauts de ligne consécutifs JUSTE avant la coupe GS V → queue à attraper.
        $this->assertStringContainsString(str_repeat("\x0A", 8) . "\x1D\x56", $bytes, 'queue client avant coupe trop courte (ticket tombe)');
    }

    public function test_kitchen_ticket_feeds_long_tail_before_cut(): void
    {
        config(['printing.cut.feed_lines_before_cut' => 8, 'printing.cut.mode' => 'full']);
        $bytes = (new OrderReceiptEscPosRenderer)->renderKitchenTicket($this->makeOrder());
        $this->assertStringContainsString(str_repeat("\x0A", 8) . "\x1D\x56", $bytes, 'queue cuisine avant coupe trop courte');
    }

    public function test_feed_lines_before_cut_is_configurable(): void
    {
        config(['printing.cut.feed_lines_before_cut' => 12, 'printing.cut.mode' => 'full']);
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeOrder());
        $this->assertStringContainsString(str_repeat("\x0A", 12) . "\x1D\x56", $bytes);
    }

    public function test_partial_cut_mode_keeps_ticket_attached(): void
    {
        config(['printing.cut.mode' => 'partial']);
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeOrder());
        // GS V 1 = coupe PARTIELLE (ticket reste accroché au rouleau → ne tombe jamais).
        $this->assertStringContainsString("\x1D\x56\x01", $bytes, 'coupe partielle (GS V 1) absente');
    }
}
