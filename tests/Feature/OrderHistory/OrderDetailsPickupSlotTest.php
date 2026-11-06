<?php

namespace Tests\Feature\OrderHistory;

use App\Enums\Ask;
use App\Http\Resources\OrderDetailsResource;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * [C-003 · reports/test-e2e/supervisor-caisse-2026-08-24/round-1/wave-C-findings.json]
 *
 * « HEURE DE RETRAIT » NE POUVAIT STRUCTURELLEMENT JAMAIS AFFICHER D'HEURE.
 *
 * `OrderDetailsResource.php:66` calculait `delivery_date` INCONDITIONNELLEMENT :
 *
 *     'delivery_date' => $this->is_advance_order == Ask::YES
 *         ? AppLibrary::increaseDate($this->order_datetime, 1)
 *         : AppLibrary::date($this->order_datetime),
 *
 * Les DEUX branches du ternaire renvoient une date non vide, pour TOUTE
 * commande. La ressource FABRIQUAIT donc un créneau de retrait à partir de la
 * date de création — c'est-à-dire une donnée qui n'a jamais été saisie par
 * personne — et le garde `v-if="order.delivery_date || order.delivery_time"`
 * de `PosOrderShowComponent.vue:54` était par construction toujours vrai.
 * Résultat à l'écran : « Heure de retrait: 25-08-2026 » (une date, pas une
 * heure, déjà affichée trois lignes plus haut) sur 100 % des commandes.
 *
 * INVARIANT VERROUILLÉ : `delivery_date` ne porte une valeur QUE si un créneau
 * a réellement été posé sur la commande. Un champ ne doit jamais inventer sa
 * propre valeur pour justifier son libellé.
 *
 * NB — `delivery_time` (colonne « HH:MM - HH:MM ») est la SEULE marque d'un
 * créneau sur une commande du jour ; `is_advance_order = YES` est celle d'une
 * commande de la veille pour le lendemain. Hors de ces deux cas, il n'y a
 * aucun créneau : le champ doit être null, pas la date de saisie.
 */
class OrderDetailsPickupSlotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function project(array $attributes): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        $order = Order::factory()->create(array_merge([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'order_datetime' => '2026-08-25 02:18:00',
            'is_advance_order' => Ask::NO,
            'delivery_time' => null,
        ], $attributes));

        return (new OrderDetailsResource($order->fresh()))->toArray(Request::create('/'));
    }

    /** Commande borne / caisse immédiate : aucun créneau n'a jamais été saisi. */
    public function test_immediate_order_exposes_no_pickup_slot(): void
    {
        $projected = $this->project([]);

        $this->assertNull(
            $projected['delivery_date'],
            'Aucun créneau posé : la ressource ne doit PAS fabriquer une date de retrait à partir de order_datetime'
        );
        $this->assertSame('', $projected['delivery_time']);
    }

    /** La date de la commande, elle, reste exposée — on ne masque aucune donnée réelle. */
    public function test_order_date_itself_is_still_exposed(): void
    {
        $projected = $this->project([]);

        $this->assertNotEmpty($projected['order_date'], 'la DATE DE COMMANDE reste une donnée réelle');
        $this->assertNotEmpty($projected['order_datetime']);
    }

    /** Créneau explicite sur une commande du jour : la date de retrait accompagne l'heure. */
    public function test_explicit_time_slot_yields_a_pickup_date(): void
    {
        $projected = $this->project(['delivery_time' => '12:00 - 12:30']);

        $this->assertSame('25-08-2026', $projected['delivery_date']);
        $this->assertSame('12:00 - 12:30', $projected['delivery_time']);
    }

    /** Commande à l'avance : le créneau est le lendemain (comportement historique conservé). */
    public function test_advance_order_keeps_next_day_pickup_date(): void
    {
        $projected = $this->project(['is_advance_order' => Ask::YES]);

        $this->assertSame('26-08-2026', $projected['delivery_date']);
    }

    /** Une valeur de créneau ININTERPRÉTABLE ne doit pas ressusciter la date. */
    public function test_unparsable_time_slot_is_not_a_slot(): void
    {
        // AppLibrary::deliveryTime() rend '' dès que la valeur n'est pas « X - Y ».
        $projected = $this->project(['delivery_time' => 'bientot']);

        $this->assertSame('', $projected['delivery_time']);
        $this->assertNull(
            $projected['delivery_date'],
            'une heure illisible n\'est pas un créneau : le libellé ne doit pas survivre'
        );
    }
}
