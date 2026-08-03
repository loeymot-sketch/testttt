<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [MULTIVIANDE 2026-07-24] Le supplément de viande est envoyé par les wizards (frozen) comme un
 * ItemExtra GÉNÉRIQUE « Viande supplémentaire » (@2,50, aucun nom) → le cuisinier lit
 * « ⭐ Viande supplémentaire ×N » sans savoir QUELLE viande préparer. Le nom réel ne survit que
 * dans le texte libre `instruction`, sur une ligne DÉDIÉE écrite par les wizards en MIROIR de la
 * ligne sauce (« Sauces en plus : … ») : « Viandes en plus : <noms> ».
 *
 * Jumeau STRICT du mécanisme sauce (extraSauceNames / extraDisplayName / supplementLines,
 * cf. MultiSauceTicketNamesTest). Affichage seul : le composition_snapshot + le prix scellé
 * (NF525) sont INCHANGÉS. Rétro-compatible : sans instruction parsable, l'ancien libellé
 * générique est conservé (on ne perd jamais l'info que le client a payé une viande en plus).
 */
class KitchenTicketViandeSupplNameTest extends TestCase
{
    private KitchenTicketSymbolicFormatter $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new KitchenTicketSymbolicFormatter;
    }

    /**
     * Tacos : 1 ou 2 viande(s) de base (variations, déjà en ligne 1) + N viande(s) EN PLUS
     * véhiculée(s) par l'extra générique « Viande supplémentaire » @2,50. `$instruction`
     * reproduit le texte libre réel écrit par le wizard concerné (caisse / borne).
     */
    private function makeOrder(string $instruction, int $extraQty = 1): Order
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne',
            'address' => '437 Rue Élie Gruyelle',
            'phone' => '+33600000000',
        ]);

        $oi = (new OrderItem)->forceFill([
            'quantity' => 1,
            'total_price' => 8.90 + 2.50 * $extraQty,
            'tax_rate' => 10,
            'tax_name' => 'TVA',
            'tax_type' => 1,
            'tax_amount' => 1.04,
            'instruction' => $instruction,
            'composition_snapshot' => [
                'lines' => [
                    ['attribute_name' => 'Viande 1', 'variation_name' => 'Poulet mariné'],
                    ['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Algérienne'],
                ],
                'extras' => [
                    ['extra_name' => 'Salade', 'line_total' => 0, 'quantity' => 1],
                    ['extra_name' => 'Viande supplémentaire', 'line_total' => 2.50 * $extraQty, 'unit_price' => 2.50, 'quantity' => $extraQty],
                ],
                'addons' => [],
            ],
        ]);
        $oi->name = 'Tacos M';

        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-MV',
            'queue_number' => 'A0050',
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 8.90 + 2.50 * $extraQty,
            'total' => 8.90 + 2.50 * $extraQty,
            'pos_payment_method' => 1,
            'pos_received_amount' => 8.90 + 2.50 * $extraQty,
            'order_datetime' => '2026-07-24 12:00:00',
            'fiscal_sequence_no' => 3010,
        ]);
        $order->setRelation('branch', $branch);
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        return $order;
    }

    // ── Formatter : récupération du/des nom(s) depuis l'instruction ────────────

    /** @test */
    public function formatter_recovers_extra_viande_names_dedicated_line(): void
    {
        // Format canonique (caisse + borne/web), miroir de « Sauces en plus : … ».
        $this->assertSame(
            ['Poulet', 'Merguez'],
            $this->f->extraViandeNames("TACOS M\nViandes en plus : Poulet, Merguez")
        );
        // Borne mono-ligne jointe par « . » : la capture s'arrête au point suivant.
        $this->assertSame(
            ['Merguez'],
            $this->f->extraViandeNames('Pain : Pain. Viandes : Poulet. Viandes en plus : Merguez. Menu : complet')
        );
        // Cas 1 viande en plus.
        $this->assertSame(['Merguez'], $this->f->extraViandeNames('Viandes en plus : Merguez'));
    }

    /** @test */
    public function formatter_tolerates_supplementaire_wording_accents_and_case(): void
    {
        $this->assertSame(['Poulet'], $this->f->extraViandeNames('Viande supplémentaire : Poulet'));
        $this->assertSame(['Poulet'], $this->f->extraViandeNames('viandes supplementaires : Poulet')); // sans accent
        $this->assertSame(['Merguez'], $this->f->extraViandeNames('VIANDES EN PLUS : Merguez'));         // majuscules
        // Légère variante d'écriture caisse (« +Merguez ») : le « + » est retiré du nom.
        $this->assertSame(['Merguez'], $this->f->extraViandeNames('Viandes en plus : +Merguez'));
    }

    /** @test */
    public function formatter_dedupes_and_ignores_base_line_and_unparsable(): void
    {
        // Déduplication, ordre préservé.
        $this->assertSame(['Poulet', 'Merguez'], $this->f->extraViandeNames('Viandes en plus : Poulet, Merguez, Poulet'));
        // La ligne de composition de BASE (« Viandes : … ») n'est JAMAIS captée (base = déjà ligne 1).
        $this->assertSame([], $this->f->extraViandeNames("TACOS M\nViandes : Poulet, Merguez"));
        // Non-parsable / vide.
        $this->assertSame([], $this->f->extraViandeNames('Bien cuit svp'));
        $this->assertSame([], $this->f->extraViandeNames(''));
        $this->assertSame([], $this->f->extraViandeNames(null));
    }

    /** @test */
    public function extra_display_name_names_the_meat_and_leaves_others_intact(): void
    {
        $this->assertSame(
            'Viande supplémentaire : Poulet, Merguez',
            $this->f->extraDisplayName('Viande supplémentaire', 'Viandes en plus : Poulet, Merguez')
        );
        // Rétro-compat : sans nom parsable → générique inchangé (pas de crash).
        $this->assertSame('Viande supplémentaire', $this->f->extraDisplayName('Viande supplémentaire', 'TACOS M'));
        $this->assertSame('Viande supplémentaire', $this->f->extraDisplayName('Viande supplémentaire', null));
        // Un extra déjà nommé reste intact ; une instruction SAUCE ne renomme pas une viande.
        $this->assertSame('Cheddar', $this->f->extraDisplayName('Cheddar', 'Viandes en plus : Poulet'));
        $this->assertSame('Viande supplémentaire', $this->f->extraDisplayName('Viande supplémentaire', 'Sauce : Algérienne, Andalouse'));
    }

    /** @test */
    public function supplement_lines_name_the_meat_extra(): void
    {
        $snap = ['extras' => [
            ['extra_name' => 'Viande supplémentaire', 'unit_price' => 2.50, 'quantity' => 2],
        ]];
        // [OWNER 2026-08-03] Noms résolus = chaque unité énumérée → le ×N est SUPPRIMÉ
        // (il se lisait « 2× chaque » — plainte « Viande Hachée, Poulet mariné puis ×2 »).
        $this->assertSame(
            ['+ Viande supplémentaire : Poulet, Merguez'],
            $this->f->supplementLines($snap, 'Viandes en plus : Poulet, Merguez')
        );
        // Rétro-compat : sans instruction → générique (info « viande payée » conservée).
        $this->assertSame(['+ Viande supplémentaire ×2'], $this->f->supplementLines($snap, null));
    }

    // ── Ticket CUISINE (ESC/POS) bout-en-bout ─────────────────────────────────

    /** @test */
    public function kitchen_ticket_shows_the_two_meat_names(): void
    {
        $order = $this->makeOrder("TACOS M\nViandes en plus : Poulet, Merguez", 2);
        $kitchen = (new OrderReceiptEscPosRenderer)->renderKitchenTicket($order);

        // Le cuisinier voit désormais QUELLES viandes ajouter (noms complets, ASCII → survivent à l'encodage).
        $this->assertStringContainsString('Poulet', $kitchen, 'La 1ère viande en plus doit être nommée sur le ticket cuisine.');
        $this->assertStringContainsString('Merguez', $kitchen, 'La 2e viande en plus doit être nommée sur le ticket cuisine.');
        $this->assertStringContainsString('Viande suppl', $kitchen, 'La ligne supplément viande reste présente.');
    }

    /** @test */
    public function kitchen_ticket_single_meat_name(): void
    {
        $order = $this->makeOrder('TACOS M. Viandes en plus : Merguez.', 1);
        $kitchen = (new OrderReceiptEscPosRenderer)->renderKitchenTicket($order);

        $this->assertStringContainsString('Merguez', $kitchen, 'La viande en plus (1 seule) doit être nommée.');
    }

    /** @test */
    public function kitchen_ticket_retro_compatible_generic_when_no_names(): void
    {
        // Ancien snapshot : extra générique mais instruction sans portion viande parsable.
        $order = $this->makeOrder('TACOS M', 1);
        $kitchen = (new OrderReceiptEscPosRenderer)->renderKitchenTicket($order);

        // Pas de crash, libellé générique conservé (rétro-compat).
        $this->assertStringContainsString('Viande suppl', $kitchen, 'Libellé générique conservé sans nom parsable.');
    }

    // ── Ticket CLIENT (ESC/POS) : parité avec la sauce, prix inchangé ──────────

    /** @test */
    public function client_ticket_names_the_meat_and_keeps_the_price(): void
    {
        $order = $this->makeOrder("TACOS M\nViandes en plus : Poulet, Merguez", 1);
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);

        $this->assertStringContainsString('Poulet', $bytes, 'La viande en plus doit être nommée sur le ticket client.');
        $this->assertStringContainsString('Merguez', $bytes);
        $this->assertStringContainsString('2,50', $bytes, 'Le prix de la viande en plus est inchangé (NF525).');
    }

    // ── Non-régression sauce : le mécanisme viande est ADDITIF ─────────────────

    /** @test */
    public function does_not_break_sauce_recovery(): void
    {
        // Une instruction SAUCE continue de nourrir extraSauceNames (viande = additif, disjoint).
        $this->assertSame(['Andalouse'], $this->f->extraSauceNames('Sauce : Algérienne, Andalouse'));
        $this->assertSame([], $this->f->extraViandeNames('Sauce : Algérienne, Andalouse'));
        $this->assertSame(
            'Sauce supplémentaire : Andalouse',
            $this->f->extraDisplayName('Sauce supplémentaire', 'Sauce : Algérienne, Andalouse')
        );
    }
    /**
     * [OWNER 2026-08-03 « puis ×2 »] Quand le nom des viandes payées est RÉSOLU, la liste
     * énumère déjà CHAQUE unité (« Viande Hachée, Poulet mariné », « 2× Poulet ») : le
     * suffixe « ×N » devient redondant et se lit comme « 2× chaque » → supprimé.
     * Sans résolution (legacy), le générique GARDE son ×N (l'info quantité ne se perd jamais).
     */
    public function test_resolved_extra_names_drop_redundant_qty_suffix(): void
    {
        $snap = ['extras' => [['extra_name' => 'Viande supplémentaire', 'quantity' => 2, 'unit_price' => 2.5]]];
        $lines = $this->f->supplementLines($snap, "TACOS L\nViandes en plus : Viande Hachée, Poulet mariné");
        $this->assertSame(['+ Viande supplémentaire : Viande Hachée, Poulet mariné'], $lines, 'noms résolus → pas de ×2');

        // 2× la même viande : le « 2× » vit DANS le nom, pas en suffixe.
        $lines2 = $this->f->supplementLines($snap, "TACOS L\nViandes en plus : 2× Poulet mariné");
        $this->assertStringNotContainsString('×2', $lines2[0]);

        // Legacy sans instruction résolvable : le générique garde ×2.
        $lines3 = $this->f->supplementLines($snap, null);
        $this->assertSame(['+ Viande supplémentaire ×2'], $lines3);
    }
}
