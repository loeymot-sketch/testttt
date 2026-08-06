<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [CUISSON 2026-08-06 owner] Le bandeau de cuisson SUR LE TICKET IMPRIMÉ.
 *
 * Ce test décode les octets ESC/POS réellement envoyés à l'imprimante — pas une chaîne
 * intermédiaire. Il vérifie les deux propriétés que l'owner a demandées nommément :
 *   1. les viandes de TOUTE la commande sont agrégées en UNE ligne ;
 *   2. cette ligne est imprimée AU-DESSUS du numéro de commande.
 */
class KitchenTicketCuissonBannerTest extends TestCase
{
    private function render(array $items): string
    {
        $orderItems = collect();
        foreach ($items as $it) {
            $oi = (new OrderItem)->forceFill([
                'quantity' => $it['quantity'] ?? 1,
                'total_price' => 8.90,
                'composition_snapshot' => $it['snapshot'] ?? [],
                'instruction' => $it['instruction'] ?? '',
            ]);
            $oi->name = $it['name'];
            $orderItems->push($oi);
        }
        $order = (new Order)->forceFill([
            'order_serial_no' => 'CUISSON-1',
            'queue_number' => 'A0042',
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 8.90,
            'total' => 8.90,
            'order_datetime' => '2026-08-06 12:00:00',
        ]);
        $order->setRelation('orderItems', $orderItems);
        $order->setRelation('branch', (new Branch)->forceFill(['name' => 'Le Cayenne (principal)']));

        return app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($order, ['width_chars' => 48]);
    }

    /** Décode les octets ESC/POS en lignes lisibles (CP858, commandes strippées). */
    private function lines(string $bytes): array
    {
        $stripped = preg_replace('/\x1B[aEtd!@].|\x1D![\x00-\xFF]|\x1B-.|\x1DV.|\x1B\x40/s', '', $bytes);
        $txt = (string) iconv('CP858', 'UTF-8//IGNORE', (string) $stripped);
        $txt = preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $txt);

        return array_values(array_filter(array_map('trim', explode("\n", $txt)), static fn ($l) => $l !== ''));
    }

    private function viandes(array $viandes): array
    {
        $lines = [];
        foreach (array_values($viandes) as $i => $nom) {
            $lines[] = ['attribute_name' => 'Viande '.($i + 1), 'variation_name' => $nom];
        }

        return ['lines' => $lines];
    }

    /** L'index de la première ligne satisfaisant le motif, ou -1. */
    private function indexOf(array $lines, string $motif): int
    {
        foreach ($lines as $i => $l) {
            if (preg_match($motif, $l)) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * LE CŒUR DE LA DEMANDE — « si on a dans toute la commande plusieurs, on va tous les
     * assembler et dire une seule fois qu'il y en a neuf ». Ici : 3 Tacos hachée (6K) +
     * 2 Méga hachée/poulet (2K 2P) + 1 Galette poulet (2P) = 8K 4P.
     */
    public function test_le_bandeau_agrege_toutes_les_viandes_de_la_commande(): void
    {
        $lines = $this->lines($this->render([
            ['name' => 'Tacos M', 'snapshot' => $this->viandes(['Viande Hachée']), 'quantity' => 3],
            ['name' => 'Méga', 'snapshot' => $this->viandes(['Viande Hachée', 'Poulet mariné']), 'quantity' => 2],
            ['name' => 'Galette Normale', 'snapshot' => $this->viandes(['Poulet mariné']), 'quantity' => 1],
        ]));

        $i = $this->indexOf($lines, '/^CUISSON$/');
        $this->assertGreaterThan(-1, $i, 'Le ticket cuisine doit porter un bandeau CUISSON. Lignes : '.implode(' | ', $lines));
        $this->assertSame('8K 4P', $lines[$i + 1] ?? '', 'Les viandes de toute la commande doivent être agrégées en une seule ligne.');
    }

    /** « je demande de mettre pour les cuisiner tout en haut », au-dessus du numéro. */
    public function test_le_bandeau_est_imprime_au_dessus_du_numero_de_commande(): void
    {
        $lines = $this->lines($this->render([
            ['name' => 'Tacos M', 'snapshot' => $this->viandes(['Viande Hachée']), 'quantity' => 1],
        ]));

        $cuisson = $this->indexOf($lines, '/^CUISSON$/');
        $numero = $this->indexOf($lines, '/A0042/');

        $this->assertGreaterThan(-1, $cuisson, 'Bandeau CUISSON absent.');
        $this->assertGreaterThan(-1, $numero, 'Numéro de commande absent du ticket.');
        $this->assertLessThan(
            $numero,
            $cuisson,
            "Le bandeau doit être la PREMIÈRE chose lue, au-dessus du numéro. Lignes : ".implode(' | ', $lines)
        );
    }

    /** Une commande qui ne demande aucune cuisson n'imprime pas de bandeau vide. */
    public function test_une_commande_sans_cuisson_nimprime_aucun_bandeau(): void
    {
        $lines = $this->lines($this->render([
            ['name' => 'Coca 33cl', 'snapshot' => ['lines' => []], 'quantity' => 2],
        ]));

        $this->assertSame(-1, $this->indexOf($lines, '/^CUISSON$/'), 'Un bandeau vide ne ferait qu\'encombrer le ticket.');
    }

    /** Les frites font partie de ce qu'il faut cuire : « le nombre de menu tu mets 5F ». */
    public function test_les_frites_apparaissent_dans_le_bandeau(): void
    {
        $lines = $this->lines($this->render([
            ['name' => 'Tacos M', 'snapshot' => array_merge($this->viandes(['Viande Hachée']), [
                'addons' => [['role' => 'menu_frites', 'quantity' => 1, 'addon_name' => 'Frites']],
            ]), 'quantity' => 5],
        ]));

        $i = $this->indexOf($lines, '/^CUISSON$/');
        $this->assertSame('10K 5F', $lines[$i + 1] ?? '', '5 menus donnent 5 portions de frites.');
    }

    /** Une recette FIXE documentée s'imprime avec ses vraies quantités, jamais « ? ». */
    public function test_une_recette_fixe_simprime_avec_ses_vraies_quantites(): void
    {
        $lines = $this->lines($this->render([
            ['name' => 'Big Burger', 'snapshot' => ['lines' => []], 'quantity' => 2],
        ]));

        $i = $this->indexOf($lines, '/^CUISSON$/');
        $this->assertSame('6K', $lines[$i + 1] ?? '', 'Big Burger = 3 steaks ; deux exemplaires = 6K.');
    }

    /** Un burger SANS recette documentée reste annoncé « ? », jamais tu. */
    public function test_une_recette_non_documentee_est_annoncee_sur_le_ticket(): void
    {
        $lines = $this->lines($this->render([
            ['name' => 'Mystery Burger', 'snapshot' => ['lines' => []], 'quantity' => 2],
        ]));

        $i = $this->indexOf($lines, '/^CUISSON$/');
        $this->assertGreaterThan(-1, $i, 'Une recette inconnue doit quand même alerter le cuisinier.');
        $this->assertStringContainsString('?', $lines[$i + 1] ?? '');
    }
}
