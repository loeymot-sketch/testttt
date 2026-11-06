<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\DeliveryHandoverToken;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\UberDirectDelivery;
use App\Services\Delivery\DeliveryHandoverService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Delivery\Concerns\HandoverFixtures;
use Tests\TestCase;

/**
 * [LIVRAISON QR 2026-11-01] Le jeton de remise : dérivé, haché en base, idempotent par arrêt, révocable,
 * brûlé de façon atomique, évalué EN DIRECT.
 *
 * @group delivery
 */
class DeliveryHandoverTokenTest extends TestCase
{
    use RefreshDatabase;
    use HandoverFixtures;

    private DeliveryHandoverService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHandover();
        $this->svc = app(DeliveryHandoverService::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownHandover();
        parent::tearDown();
    }

    public function test_token_is_256_bits_base64url_and_only_its_hash_is_stored(): void
    {
        $stop = $this->delivery();
        $token = $this->svc->issueForStop($stop);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        $row = DeliveryHandoverToken::query()->withoutGlobalScopes()->sole();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertSame($stop->id, (int) $row->delivery_stop_id);
        $this->assertSame($stop->order_id, (int) $row->order_id);
        $this->assertSame((int) $this->branch->id, (int) $row->branch_id);
        // Le clair n'apparaît dans AUCUNE colonne de AUCUNE ligne.
        foreach (DB::table('delivery_handover_tokens')->get() as $r) {
            foreach ((array) $r as $col => $val) {
                $this->assertStringNotContainsString($token, (string) $val, "le jeton en clair ne doit pas être en base ($col)");
            }
        }
    }

    public function test_issue_is_idempotent_per_stop_and_reprint_gives_the_same_qr(): void
    {
        $stop = $this->delivery();
        $a = $this->svc->issueForStop($stop);
        $b = $this->svc->issueForStop($stop->fresh());

        $this->assertSame($a, $b, 'réimpression = même QR');
        $this->assertSame(1, DeliveryHandoverToken::query()->withoutGlobalScopes()->count());

        $other = $this->delivery();
        $this->assertNotSame($a, $this->svc->issueForStop($other));
    }

    public function test_url_lives_on_the_platform_host_in_the_fragment_and_fits_the_printer_limit(): void
    {
        $token = $this->svc->issueForStop($this->delivery());
        $url = $this->svc->urlFor($token);

        $this->assertSame('https://plateforme.test/livreur/remise#'.$token, $url);
        $this->assertLessThanOrEqual(124, strlen($url), 'longueur d\'un QR ESC/POS : 1 octet de longueur, CP858');
        $this->assertStringNotContainsString('?', $url);
        $this->assertStringNotContainsString('www.lecayenne.fr', $url);
    }

    public function test_token_depends_on_the_application_key(): void
    {
        $stop = $this->delivery();
        $a = $this->svc->issueForStop($stop);
        DeliveryHandoverToken::query()->withoutGlobalScopes()->delete();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
        $b = app(DeliveryHandoverService::class)->issueForStop($stop->fresh());

        $this->assertNotSame($a, $b, 'une autre clé d\'application donne un autre jeton');
    }

    public function test_expiry_is_tour_time_plus_six_hours_and_follows_the_tour(): void
    {
        $stop = $this->delivery();
        $this->svc->issueForStop($stop);
        $row = DeliveryHandoverToken::query()->withoutGlobalScopes()->sole();
        $this->assertTrue($row->expires_at->equalTo($stop->wave_at->copy()->addHours(6)));

        // La tournée est décalée : le prochain passage rafraîchit l'échéance.
        DeliveryStop::query()->withoutGlobalScopes()->whereKey($stop->id)->update(['wave_at' => $stop->wave_at->copy()->addMinutes(30)]);
        $this->svc->issueForStop($stop->fresh());
        $this->assertTrue($row->fresh()->expires_at->equalTo($stop->wave_at->copy()->addMinutes(30)->addHours(6)));
    }

    public function test_regenerate_revokes_the_old_token_and_issues_a_new_one(): void
    {
        $stop = $this->delivery();
        $old = $this->svc->issueForStop($stop);
        $new = $this->svc->regenerate($stop->fresh());

        $this->assertNotNull($new);
        $this->assertNotSame($old, $new);
        $this->assertSame(2, DeliveryHandoverToken::query()->withoutGlobalScopes()->count());
        $oldRow = $this->svc->find($old);
        $this->assertNotNull($oldRow->revoked_at);
        $this->assertSame(DeliveryHandoverService::REASON_REVOKED, $this->svc->inspect($oldRow, $this->courier)['reason']);
        // Le nouveau est vivant : seul le départ manque (arrêt PRÊT, pas encore parti).
        $this->assertSame(DeliveryHandoverService::REASON_NOT_DEPARTED, $this->svc->inspect($this->svc->find($new), $this->courier)['reason']);
    }

    public function test_a_revoked_stop_is_not_reissued_by_a_plain_reprint(): void
    {
        $stop = $this->delivery();
        $this->svc->issueForStop($stop);
        $this->svc->revoke($stop->fresh(), 'test');

        $this->assertNull($this->svc->issueForStop($stop->fresh()), 'un jeton révoqué ne ressuscite pas à la réimpression');
    }

    public function test_burn_is_atomic_the_second_attempt_loses(): void
    {
        $stop = $this->delivery();
        $row = $this->svc->find($this->svc->issueForStop($stop));

        $this->assertTrue($this->svc->burn($row, $this->courier->id));
        $this->assertFalse($this->svc->burn($row->fresh(), $this->courier->id), 'usage unique');
        $this->assertNotNull($row->fresh()->used_at);
        $this->assertSame($this->courier->id, (int) $row->fresh()->used_by_user_id);
        $this->assertNull($this->svc->issueForStop($stop->fresh()), 'un jeton brûlé n\'est plus réimprimé');
    }

    public function test_live_inspection_covers_every_way_a_qr_can_die(): void
    {
        $stop = $this->departed($this->delivery());
        $row = $this->svc->find($this->svc->issueForStop($stop));

        $this->assertTrue($this->svc->inspect($row, $this->courier)['ok'], 'livreur attribué, parti : valide');
        $this->assertSame(DeliveryHandoverService::REASON_NOT_ASSIGNED, $this->svc->inspect($row, $this->otherCourier)['reason']);
        $this->assertSame(DeliveryHandoverService::REASON_NOT_ASSIGNED, $this->svc->inspect($row, $this->staffAccount)['reason']);

        // Annulée après impression.
        Order::query()->withoutGlobalScopes()->whereKey($stop->order_id)->update(['status' => OrderStatus::CANCELED]);
        $this->assertSame(DeliveryHandoverService::REASON_CANCELLED, $this->svc->inspect($row, $this->courier)['reason']);
        Order::query()->withoutGlobalScopes()->whereKey($stop->order_id)->update(['status' => OrderStatus::REJECTED]);
        $this->assertSame(DeliveryHandoverService::REASON_CANCELLED, $this->svc->inspect($row, $this->courier)['reason']);
        Order::query()->withoutGlobalScopes()->whereKey($stop->order_id)->update(['status' => OrderStatus::OUT_FOR_DELIVERY]);

        // Réattribué à un autre livreur.
        DeliveryStop::query()->withoutGlobalScopes()->whereKey($stop->id)->update(['delivery_boy_id' => $this->otherCourier->id]);
        $this->assertSame(DeliveryHandoverService::REASON_NOT_ASSIGNED, $this->svc->inspect($row, $this->courier)['reason']);
        // Attribution à moitié faite (arrêt seul) : personne n'est valide tant que commande et arrêt divergent.
        $this->assertSame(DeliveryHandoverService::REASON_NOT_ASSIGNED, $this->svc->inspect($row, $this->otherCourier)['reason']);
        Order::query()->withoutGlobalScopes()->whereKey($stop->order_id)->update(['delivery_boy_id' => $this->otherCourier->id]);
        $this->assertTrue($this->svc->inspect($row, $this->otherCourier)['ok'], 'réattribué en entier : le nouveau livreur est valide');
        $this->assertSame(DeliveryHandoverService::REASON_NOT_ASSIGNED, $this->svc->inspect($row, $this->courier)['reason']);
        DeliveryStop::query()->withoutGlobalScopes()->whereKey($stop->id)->update(['delivery_boy_id' => $this->courier->id]);
        Order::query()->withoutGlobalScopes()->whereKey($stop->order_id)->update(['delivery_boy_id' => $this->courier->id]);

        // Déjà livrée.
        DeliveryStop::query()->withoutGlobalScopes()->whereKey($stop->id)->update(['delivered_at' => now()]);
        $this->assertSame(DeliveryHandoverService::REASON_DELIVERED, $this->svc->inspect($row, $this->courier)['reason']);
    }

    public function test_uber_direct_takeover_kills_the_qr(): void
    {
        $stop = $this->departed($this->delivery());
        $row = $this->svc->find($this->svc->issueForStop($stop));
        $this->assertTrue($this->svc->inspect($row, $this->courier)['ok']);

        // Docs LIVRAISON §6 : confié à Uber = plus de livreur à nous sur l'arrêt ni sur la commande.
        DeliveryStop::query()->withoutGlobalScopes()->whereKey($stop->id)->update(['delivery_boy_id' => null]);
        Order::query()->withoutGlobalScopes()->whereKey($stop->order_id)->update(['delivery_boy_id' => null]);

        $this->assertSame(DeliveryHandoverService::REASON_NOT_ASSIGNED, $this->svc->inspect($row, $this->courier)['reason']);
    }

    public function test_an_active_uber_course_kills_the_qr_even_if_the_assignment_was_left_in_place(): void
    {
        $stop = $this->departed($this->delivery());
        $row = $this->svc->find($this->svc->issueForStop($stop));
        UberDirectDelivery::query()->withoutGlobalScopes()->create([
            'branch_id' => $this->branch->id, 'order_id' => $stop->order_id, 'delivery_id' => 'del_123', 'status' => UberDirectDelivery::STATUS_PICKUP,
        ]);

        $this->assertSame(DeliveryHandoverService::REASON_NOT_ASSIGNED, $this->svc->inspect($row, $this->courier)['reason']);
        $this->assertNull($this->svc->issueForStop($stop->fresh()->fresh()), 'pas de nouveau QR pour une course Uber');
    }

    public function test_expired_token_is_refused(): void
    {
        $stop = $this->departed($this->delivery());
        $row = $this->svc->find($this->svc->issueForStop($stop));

        Carbon::setTestNow($stop->wave_at->copy()->addHours(6)->subMinute());
        $this->assertTrue($this->svc->inspect($row->fresh(), $this->courier)['ok']);
        Carbon::setTestNow($stop->wave_at->copy()->addHours(6)->addMinute());
        $this->assertSame(DeliveryHandoverService::REASON_EXPIRED, $this->svc->inspect($row->fresh(), $this->courier)['reason']);
    }

    public function test_not_departed_yet_is_reported_to_the_assigned_courier_only(): void
    {
        $stop = $this->delivery(); // PRÊTE, pas encore partie
        $row = $this->svc->find($this->svc->issueForStop($stop));

        $this->assertSame(DeliveryHandoverService::REASON_NOT_DEPARTED, $this->svc->inspect($row, $this->courier)['reason']);
        $this->assertSame(DeliveryHandoverService::REASON_NOT_ASSIGNED, $this->svc->inspect($row, $this->otherCourier)['reason']);
    }

    public function test_no_token_for_non_delivery_cancelled_or_uber_eats_orders(): void
    {
        $takeaway = $this->delivery();
        Order::query()->withoutGlobalScopes()->whereKey($takeaway->order_id)->update(['order_type' => OrderType::TAKEAWAY]);
        $this->assertNull($this->svc->issueForStop($takeaway->fresh()));

        $cancelled = $this->delivery();
        Order::query()->withoutGlobalScopes()->whereKey($cancelled->order_id)->update(['status' => OrderStatus::CANCELED]);
        $this->assertNull($this->svc->issueForStop($cancelled->fresh()));

        $uber = $this->delivery();
        Order::query()->withoutGlobalScopes()->whereKey($uber->order_id)->update(['source_surface' => 'uber_eats']);
        $this->assertNull($this->svc->issueForStop($uber->fresh()));

        $this->assertSame(0, DeliveryHandoverToken::query()->withoutGlobalScopes()->count());
    }

    public function test_unknown_or_malformed_tokens_find_nothing(): void
    {
        $this->assertNull($this->svc->find(str_repeat('A', 43)));
        $this->assertNull($this->svc->find('../../etc/passwd'));
        $this->assertNull($this->svc->find(''));
        $this->assertNull($this->svc->find(str_repeat('A', 44)));
    }

    public function test_model_is_branch_scoped_for_authenticated_staff_of_another_branch(): void
    {
        $stop = $this->delivery();
        $this->svc->issueForStop($stop);
        $branchB = Branch::factory()->create();
        $strangerB = $this->userWithRole('Delivery Boy', 'livreur.c@test.fr', $branchB->id);

        $this->actingAs($strangerB, 'sanctum');
        $this->assertSame(0, DeliveryHandoverToken::query()->count(), 'BranchScope : un livreur d\'une autre branche ne voit pas le jeton');
        $this->assertSame(1, DeliveryHandoverToken::query()->withoutGlobalScopes()->count());

        // Recherche avant connexion : ignore le scope, mais l'inspection refuse la branche étrangère.
        $row = $this->svc->find($this->svc->issueForStop($stop));
        $this->assertNotNull($row);
        $this->assertSame(DeliveryHandoverService::REASON_NOT_ASSIGNED, $this->svc->inspect($row, $strangerB)['reason']);
    }
}
