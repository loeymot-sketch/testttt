<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\EscPosCommandBuilder;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [NOTES-EN-GRAND 2026-10-19 · owner] « Quand on met une note (ex. : pas d'œuf dans la formule),
 * c'est toujours écrit très fin sur la cuisine, et on oublie de la lire. »
 *
 * La note du client sort sur le ticket cuisine en GRAS et en DOUBLE TAILLE (comme la ligne
 * produit), jamais plus petite que le reste, et jamais coupée en largeur.
 */
class KitchenTicketNoteEnGrandTest extends TestCase
{
    private function order(string $instruction): Order
    {
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1, 'total_price' => 8.9, 'tax_rate' => 10, 'tax_name' => 'TVA', 'tax_type' => 1, 'tax_amount' => 0.8,
            'instruction' => $instruction,
            'composition_snapshot' => ['lines' => [['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Algérienne']], 'extras' => [], 'addons' => []],
        ]);
        $oi->name = 'Tacos M';
        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-NOTE', 'queue_number' => 'A0042', 'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 8.9, 'total' => 8.9, 'pos_payment_method' => 1, 'order_datetime' => '2026-10-19 12:00:00', 'fiscal_sequence_no' => 3002,
        ]);
        $order->setRelation('branch', (new Branch)->forceFill(['name' => 'Le Cayenne', 'address' => 'x', 'phone' => '+33600000000']));
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        return $order;
    }

    private function bytes(Order $o, int $width = 48): string
    {
        return app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($o, ['width_chars' => $width]);
    }

    public function test_la_note_du_client_sort_en_gras_et_en_double_taille(): void
    {
        $b = $this->bytes($this->order('[sans oeuf]'));

        $this->assertStringContainsString(
            EscPosCommandBuilder::doubleSize(true).EscPosCommandBuilder::bold(true).'  ** [sans oeuf]',
            $b,
            'la note doit être ouverte en double taille + gras, juste avant son texte'
        );
    }

    public function test_le_style_est_referme_apres_la_note(): void
    {
        $b = $this->bytes($this->order('[sans oeuf]'));
        $apres = substr($b, (int) strpos($b, 'sans oeuf'));

        $this->assertStringContainsString(EscPosCommandBuilder::bold(false).EscPosCommandBuilder::doubleSize(false), $apres, 'le gras et la double taille doivent être refermés');
    }

    public function test_une_note_longue_est_enroulee_jamais_coupee_en_double_largeur(): void
    {
        $note = 'sans oeuf dans la formule il y en a deja dans le sandwich merci beaucoup';
        $b = $this->bytes($this->order("[$note]"), 42);

        // En double largeur, une ligne tient 21 caractères sur 42 colonnes : aucune ligne imprimée
        // de la note ne doit les dépasser, et tous les mots doivent être là.
        foreach (explode(' ', $note) as $mot) {
            $this->assertStringContainsString($mot, $b, "le mot « $mot » de la note doit être imprimé");
        }
        $debut = (int) strpos($b, '** [sans');
        $fin = (int) strpos($b, "\n", (int) strpos($b, 'merci'));
        foreach (explode("\n", substr($b, $debut, $fin - $debut + 1)) as $ligne) {
            $visible = preg_replace('/[\x00-\x1f]|[!B]\x01|[!]\x11/', '', $ligne);
            $this->assertLessThanOrEqual(24, mb_strlen(trim($visible)), "ligne trop large en double taille : « $visible »");
        }
    }

    public function test_sans_note_aucun_gras_double_taille_en_plus(): void
    {
        $avec = $this->bytes($this->order('[sans oeuf]'));
        $sans = $this->bytes($this->order(''));

        $this->assertStringNotContainsString('  ** ', $sans);
        $this->assertGreaterThan(substr_count($sans, EscPosCommandBuilder::doubleSize(true)), substr_count($avec, EscPosCommandBuilder::doubleSize(true)));
    }
}
