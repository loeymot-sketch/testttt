<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [UBER-PHOTO / FRITES-SAUCE 2026-08-10 · owner] Ce que le TICKET CUISINE dit réellement.
 *
 * Le test décode les octets ESC/POS envoyés à l'imprimante — pas une chaîne intermédiaire —
 * parce que c'est le papier qui arrive en cuisine, pas une variable.
 *
 * Deux exigences owner :
 *   1. « ça imprime directement en cuisine, les dessus Uber et le nom de client » ;
 *   2. les frites doivent être complètes — leur sauce comprise.
 */
class KitchenTicketUberEtFritesTest extends TestCase
{
    /** @param array<int,array<string,mixed>> $items */
    private function render(array $items, array $orderAttrs = []): string
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

        $order = (new Order)->forceFill(array_merge([
            'order_serial_no' => 'T-1',
            'queue_number' => 'A0042',
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 8.90,
            'total' => 8.90,
            'order_datetime' => '2026-08-10 12:00:00',
        ], $orderAttrs));
        $order->setRelation('orderItems', $orderItems);
        $order->setRelation('branch', (new Branch)->forceFill(['name' => 'Le Cayenne (principal)']));

        return app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($order, ['width_chars' => 48]);
    }

    /** @return array<int,string> Octets ESC/POS → lignes lisibles. */
    private function lines(string $bytes): array
    {
        $stripped = preg_replace('/\x1B[aEtd!@].|\x1D![\x00-\xFF]|\x1B-.|\x1DV.|\x1B\x40/s', '', $bytes);
        $txt = (string) iconv('CP858', 'UTF-8//IGNORE', (string) $stripped);
        $txt = preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $txt);

        return array_values(array_filter(array_map('trim', explode("\n", $txt)), static fn ($l) => $l !== ''));
    }

    /** @test */
    public function une_commande_uber_annonce_uber_et_le_nom_du_client_avant_tout_le_reste(): void
    {
        $lignes = $this->lines($this->render(
            [['name' => 'Cayenne', 'snapshot' => ['lines' => [['attribute_name' => 'Viande 1', 'variation_name' => 'Poulet mariné']]]]],
            ['source_surface' => 'uber_eats', 'pos_customer_name' => 'Karim B.', 'queue_number' => 'UF7A2']
        ));

        $iUber = $this->indexOf($lignes, '/UBER EATS/');
        $iClient = $this->indexOf($lignes, '/Client\s*:\s*Karim B\./');
        $iNumero = $this->indexOf($lignes, '/UF7A2/');

        $this->assertNotNull($iUber, 'Le ticket cuisine ne dit PAS que la commande vient d\'Uber : '.implode(' | ', $lignes));
        $this->assertNotNull($iClient, 'Le nom du client est absent du ticket cuisine : '.implode(' | ', $lignes));
        $this->assertNotNull($iNumero);
        // La bannière se lit AVANT le numéro d'appel : c'est l'information qui change la façon
        // d'emballer, elle ne doit pas être noyée plus bas.
        $this->assertLessThan($iNumero, $iUber, 'La bannière UBER doit précéder le numéro de commande.');
    }

    /** @test */
    public function une_commande_de_comptoir_n_affiche_aucune_banniere_uber(): void
    {
        $lignes = $this->lines($this->render(
            [['name' => 'Cayenne', 'snapshot' => ['lines' => [['attribute_name' => 'Viande 1', 'variation_name' => 'Poulet mariné']]]]],
            ['source_surface' => 'pos', 'pos_customer_name' => 'Karim B.']
        ));

        $this->assertNull($this->indexOf($lignes, '/UBER/'), 'Une commande de comptoir ne doit jamais porter la bannière Uber.');
    }

    /**
     * @test
     *
     * Cas relevé en base (commande réelle) : « Grande Frites » avec trois sauces choisies, dont
     * AUCUNE n'apparaissait en cuisine — le badge qui devait les porter n'existait que pour les
     * menus, et le nettoyeur d'instruction supprimait la ligne d'origine.
     */
    public function les_frites_vendues_comme_produit_affichent_leur_sauce(): void
    {
        $lignes = $this->lines($this->render([[
            'name' => 'Grande Frites',
            'snapshot' => [],
            'instruction' => 'Sauce frites : Mayonnaise, Ketchup, Samouraï',
        ]]));

        $this->assertNotNull(
            $this->indexOf($lignes, '/FRITES\s*:\s*MAY KTP SAM/'),
            'Les sauces des frites ont disparu du ticket : '.implode(' | ', $lignes)
        );
    }

    /** @test */
    public function un_menu_garde_son_badge_annote_de_la_sauce_frites(): void
    {
        $lignes = $this->lines($this->render([[
            'name' => 'Cayenne',
            'snapshot' => [
                'lines' => [['attribute_name' => 'Viande 1', 'variation_name' => 'Poulet mariné']],
                'addons' => [['role' => 'menu_full', 'addon_name' => 'Menu (Frites + Boisson)', 'quantity' => 1]],
            ],
            'instruction' => 'Sauce frites : Andalouse',
        ]]));

        $this->assertNotNull($this->indexOf($lignes, '/MENU\s*:\s*AND/'), implode(' | ', $lignes));
    }

    /** @test */
    public function un_menu_pris_a_la_caisse_compte_sa_frite_au_bandeau_de_cuisson(): void
    {
        // Forme RÉELLE d'une commande de caisse : le produit, puis le menu en LIGNE DÉDIÉE.
        $lignes = $this->lines($this->render([
            [
                'name' => 'Cayenne',
                'snapshot' => ['lines' => [['attribute_name' => 'Viande 1', 'variation_name' => 'Viande Hachée']]],
                'instruction' => "CAYENNE\n+ Menu (Frites + Boisson) (+2,50 €)",
            ],
            ['name' => 'Menu (Frites + Boisson)', 'snapshot' => [], 'instruction' => 'MENU'],
        ]));

        $i = $this->indexOf($lignes, '/^2K 1F$/');
        $this->assertNotNull($i, 'Le bandeau de cuisson doit compter la frite du menu (2K 1F) : '.implode(' | ', $lignes));
    }

    /** @param array<int,string> $lignes */
    private function indexOf(array $lignes, string $regex): ?int
    {
        foreach ($lignes as $i => $l) {
            if (preg_match($regex, $l)) {
                return $i;
            }
        }

        return null;
    }
}
