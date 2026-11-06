<?php

namespace Tests\Feature\Docs;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sentinelle — le « Total » du PDF « commandes en ligne » est le chiffre d'affaires NET.
 *
 * Le défaut, relevé par lecture du code puis confirmé le 2026-10-04 : `online_orders.blade.php`
 * additionnait `$order->total` sans aucun prédicat. Annulées, impayées, canal Uber (non
 * fiscalisé par conception) et contre-écritures de remboursement entraient dans un « Total »
 * qui ne concordait ni avec la carte à l'écran ni avec le Z signé. Le document frère,
 * `sales_report.blade.php`, applique déjà `Order::isRealizedRevenueRow` (SALES-NET-01) : toutes
 * les commandes restent listées, seul l'agrégat est net. C'était le seul document de
 * chiffre d'affaires du dépôt à n'avoir aucun prédicat de réalisation.
 *
 * Le banc RENDU le gabarit — il ne lit pas le source — et cherche le montant dans la ligne
 * « Total » du HTML produit : c'est ce que le lecteur du PDF a sous les yeux.
 */
class PdfCommandesEnLigneTotalNetTest extends TestCase
{
    use RefreshDatabase;

    private function commande(int $statut, int $paiement, float $montant, ?string $surface = null): Order
    {
        return Order::factory()->create([
            'status'          => $statut,
            'payment_status'  => $paiement,
            'total'           => $montant,
            'source_surface'  => $surface,
            'parent_order_id' => null,
        ]);
    }

    private function rendre($commandes): string
    {
        return view('pdf.online_orders', [
            'company'    => ['company_name' => 'Le Cayenne', 'company_address' => 'Paris'],
            'theme_logo' => null,
            'orders'     => $commandes,
            'copyright'  => '',
        ])->render();
    }

    /** Le montant affiché dans la ligne Total, sans symbole ni espace. */
    private function totalAffiche(string $html): float
    {
        $this->assertSame(1, preg_match('/class="total"[^>]*>(.*?)<\/tr>/s', $html, $m),
            'Ligne « Total » introuvable dans le document rendu.');
        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $m[1], $cellules);
        $derniere = trim(strip_tags(end($cellules[1])));
        $chiffres = preg_replace('/[^\d,.\-]/u', '', $derniere);

        return (float) str_replace(',', '.', $chiffres);
    }

    /** @test */
    public function le_total_ne_somme_que_le_chiffre_d_affaires_realise(): void
    {
        $commandes = collect([
            $this->commande(OrderStatus::DELIVERED, PaymentStatus::PAID, 20.00),       // compte
            $this->commande(OrderStatus::DELIVERED, PaymentStatus::PAID, 10.00),       // compte
            $this->commande(OrderStatus::CANCELED, PaymentStatus::PAID, 50.00),        // annulée : non
            $this->commande(OrderStatus::PENDING, PaymentStatus::UNPAID, 40.00),       // impayée : non
            $this->commande(OrderStatus::DELIVERED, PaymentStatus::PAID, 30.00, 'uber_eats'), // Uber : non
        ]);

        $total = $this->totalAffiche($this->rendre($commandes));

        $this->assertEqualsWithDelta(
            30.00,
            $total,
            0.001,
            "Le Total du PDF vaut {$total} € ; seules les deux ventes livrées et payées (20 + 10) "
            . 'relèvent du chiffre d\'affaires réalisé. Une annulée, une impayée et une commande '
            . 'Uber ne doivent pas y entrer.',
        );
    }

    /** @test */
    public function toutes_les_commandes_restent_listees_meme_celles_exclues_du_total(): void
    {
        $annulee = $this->commande(OrderStatus::CANCELED, PaymentStatus::PAID, 50.00);
        $livree = $this->commande(OrderStatus::DELIVERED, PaymentStatus::PAID, 20.00);

        $html = $this->rendre(collect([$annulee, $livree]));

        // Règle SALES-NET-01 : les lignes sont listées pour toutes les commandes, seul
        // l'agrégat est net. Un lecteur doit pouvoir retrouver l'annulée dans le détail.
        $this->assertStringContainsString((string) $annulee->order_serial_no, $html);
        $this->assertStringContainsString((string) $livree->order_serial_no, $html);
    }
}
