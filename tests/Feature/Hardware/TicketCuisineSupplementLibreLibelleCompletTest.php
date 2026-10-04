<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [AUDIT AVAL 2026-09-29 · P0] Le ticket CUISINE doit imprimer le libellé COMPLET d'un
 * supplément libre.
 *
 * Le moteur symbolique réduit un nom à ses 3 premières lettres significatives. Tout
 * supplément libre commence par « Supplément — … » (PricingService) : le papier
 * cuisine imprimait donc « SUP » pour « Supplément — Sauce blanche maison » comme
 * pour « Supplément — Viande hachée en plus » — indiscernables — et « Supplément —
 * Tacos en plus » devenait « Tacos », un taco entier à préparer. Le ticket CLIENT,
 * lui, imprimait déjà le libellé complet : c'est la cuisine qui le perdait.
 *
 * Ce banc part du TICKET RENDU (octets ESC/POS décodés), pas de la ligne en base —
 * le périmètre que les tests existants sur `manual_supplement` ne couvraient pas.
 * Jumeau écran : tests/js/kdsSupplementLibreLibelleComplet.spec.js.
 */
class TicketCuisineSupplementLibreLibelleCompletTest extends TestCase
{
    use RefreshDatabase;

    private function ligne(array $attrs, ?string $nomCatalogue = null): OrderItem
    {
        $oi = (new OrderItem)->forceFill(array_merge([
            'quantity' => 1, 'total_price' => 5.90, 'composition_snapshot' => [], 'instruction' => '',
        ], $attrs));
        if ($nomCatalogue !== null) {
            $oi->name = $nomCatalogue;
        }

        return $oi;
    }

    private function ticket(array $lignes): string
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne (principal)',
            'address' => '437 Rue Élie Gruyelle, 62110 Hénin-Beaumont',
            'phone' => '+33365678291',
        ]);
        $order = (new Order)->forceFill([
            'order_serial_no' => 'SUPP-TEST-1',
            'queue_number' => 'A0042',
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 13.30, 'total' => 13.30,
            'order_datetime' => '2026-09-29 12:00:00',
        ]);
        $order->setRelation('orderItems', collect($lignes));
        $order->setRelation('branch', $branch);

        $bytes = app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($order, ['width_chars' => 48]);
        $s = preg_replace('/\x1B[aEtd!@].|\x1D![\x00-\xFF]|\x1B-.|\x1DV.|\x1B\x40/s', '', $bytes);
        $t = (string) iconv('CP858', 'UTF-8//IGNORE', (string) $s);

        return (string) preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $t);
    }

    /**
     * La tête de ligne cuisine est en double taille et enroulée à la demi-largeur : un
     * libellé long passe à la ligne (« Supplément - Sauce / blanche maison »). On compare
     * donc sur le texte APLATI — ma première version comparait sur le brut et échouait
     * sur un ticket pourtant correct.
     */
    private function aplati(string $texte): string
    {
        return (string) preg_replace('/\s+/u', ' ', $texte);
    }

    public function test_le_supplement_libre_garde_son_libelle_complet_en_cuisine(): void
    {
        $texte = $this->ticket([
            $this->ligne(['total_price' => 7.40], 'Cayenne'),
            $this->ligne([
                'line_type' => OrderItem::LINE_TYPE_MANUAL_SUPPLEMENT,
                'item_id' => null,
                'manual_label' => 'Supplément — Sauce blanche maison',
            ]),
        ]);

        // L'encodage CP858 translittère le tiret cadratin en « - » : on tolère les deux.
        $this->assertMatchesRegularExpression(
            '/Suppl[ée]ment\s*[—-]\s*Sauce blanche maison/u',
            $this->aplati($texte),
            "Le libellé complet doit sortir en cuisine. Ticket :\n" . $texte
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(^|\n)\s*SUP\s*(\n|$)/',
            $texte,
            'Aucune ligne « SUP » (code à 3 lettres) ne doit remplacer le supplément.'
        );
    }

    public function test_deux_supplements_differents_restent_discernables(): void
    {
        $texte = $this->ticket([
            $this->ligne(['line_type' => OrderItem::LINE_TYPE_MANUAL_SUPPLEMENT, 'item_id' => null, 'manual_label' => 'Supplément — Sauce blanche maison']),
            $this->ligne(['line_type' => OrderItem::LINE_TYPE_MANUAL_SUPPLEMENT, 'item_id' => null, 'manual_label' => 'Supplément — Viande hachée en plus']),
        ]);

        $this->assertStringContainsString('Sauce blanche maison', $this->aplati($texte));
        $this->assertStringContainsString('Viande hach', $this->aplati($texte));
    }

    public function test_un_supplement_tacos_ne_devient_pas_un_taco_entier(): void
    {
        $texte = $this->ticket([
            $this->ligne(['line_type' => OrderItem::LINE_TYPE_MANUAL_SUPPLEMENT, 'item_id' => null, 'manual_label' => 'Supplément — Tacos en plus']),
        ]);

        $this->assertStringContainsString('Tacos en plus', $this->aplati($texte));
        $this->assertDoesNotMatchRegularExpression('/(^|\n)\s*Tacos\s*(\n|$)/', $texte, 'La tête de ligne ne doit pas être le seul mot « Tacos ».');
    }

    /** Le banc mord : une ligne CATALOGUE du même nom passe, elle, par le moteur symbolique. */
    public function test_le_controle_mord_sur_une_ligne_catalogue(): void
    {
        $texte = $this->ticket([
            $this->ligne(['line_type' => OrderItem::LINE_TYPE_CATALOG, 'item_id' => 42], 'Supplément — Sauce blanche maison'),
        ]);

        $this->assertDoesNotMatchRegularExpression(
            '/Suppl[ée]ment\s*[—-]\s*Sauce blanche maison/u',
            $this->aplati($texte),
            'Si ceci échoue, c\'est le moteur symbolique qui a changé, pas le correctif.'
        );
    }
}
