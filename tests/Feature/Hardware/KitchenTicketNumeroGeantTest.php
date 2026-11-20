<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\EscPosCommandBuilder;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [GOAL REMARQUES 2026-10-03 · T-1.4 R-052] Propriétaire, 07/09 : « le numéro de commande soit un peu
 * plus grand vraiment qui va prendre une grande partie, disons 4 cm ou 3 cm de la page ».
 *
 * Le numéro sortait en double taille (GS ! 0x11, ≈ 6 mm). Il passe à la HAUTEUR maximale de
 * l'ESC/POS (×8 ≈ 2,4 cm en police A) et à la plus grande LARGEUR qui tient sur une ligne
 * (largeur × nombre de caractères ≤ colonnes) — jamais de débordement. Au-delà de ×8, il faudrait
 * une image tramée (porte G4 du GOAL).
 */
class KitchenTicketNumeroGeantTest extends TestCase
{
    private function ticket(string $numero, int $largeur): string
    {
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1, 'total_price' => 8.9, 'tax_rate' => 10, 'tax_name' => 'TVA', 'tax_type' => 1, 'tax_amount' => 0.8,
            'instruction' => '', 'composition_snapshot' => ['lines' => [], 'extras' => [], 'addons' => []],
        ]);
        $oi->name = 'Tacos M';
        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-NUM', 'queue_number' => $numero, 'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 8.9, 'total' => 8.9, 'pos_payment_method' => 1, 'order_datetime' => '2026-10-03 12:00:00', 'fiscal_sequence_no' => 3003,
        ]);
        $order->setRelation('branch', (new Branch)->forceFill(['name' => 'Le Cayenne', 'address' => 'x', 'phone' => '+33600000000']));
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        return app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($order, ['width_chars' => $largeur]);
    }

    /** @return array<string, array{0:string,1:int,2:int}> */
    public static function cas(): array
    {
        return [
            '80 mm (48 col.)' => ['A0042', 48, 8],
            'SAGA (42 col.)' => ['A0042', 42, 8],
            '58 mm (32 col.)' => ['A0042', 32, 6],
            'numéro long' => ['CMD-20261003-42', 48, 3],
        ];
    }

    /** @dataProvider cas */
    public function test_le_numero_est_imprime_a_la_hauteur_maximale_et_tient_sur_une_ligne(string $numero, int $largeur, int $multiplicateur): void
    {
        $b = $this->ticket($numero, $largeur);

        $this->assertStringContainsString(
            EscPosCommandBuilder::textSize($multiplicateur, 8).EscPosCommandBuilder::bold(true).$numero."\n",
            $b,
            "le numéro « $numero » doit sortir en hauteur ×8 et largeur ×$multiplicateur sur $largeur colonnes"
        );
        $this->assertLessThanOrEqual($largeur, mb_strlen($numero) * $multiplicateur, 'jamais plus large que le papier');
        // Retour à la taille normale juste après : l'en-tête suivant ne doit pas hériter du ×8.
        $this->assertStringContainsString($numero."\n".EscPosCommandBuilder::textSize(1, 1).EscPosCommandBuilder::bold(false), $b);
    }

    public function test_un_numero_trop_long_pour_doubler_reste_en_repli_double_hauteur(): void
    {
        $long = str_repeat('9', 30);
        $b = $this->ticket($long, 48);

        $this->assertStringNotContainsString(EscPosCommandBuilder::textSize(8, 8), $b);
        $this->assertStringContainsString(EscPosCommandBuilder::doubleHeight(true).EscPosCommandBuilder::bold(true), $b);
    }
}
