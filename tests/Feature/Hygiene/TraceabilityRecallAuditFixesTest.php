<?php

namespace Tests\Feature\Hygiene;

use App\Models\Branch;
use App\Models\HygieneReception;
use App\Models\LabelPrintLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAYENNE HYGIÈNE · audit E2E 2026-11-18] Fiche de rappel et traçabilité :
 *  - P0 : une réception ANNULÉE après coup reste sur la fiche (toute l'histoire), mais DITE annulée (motif, quand, qui) ;
 *  - P1 : une étiquette en échec sans aucune page sortie n'est pas une « préparation » ; « non confirmée » reste listée avec son statut ;
 *  - P1 : la fiche porte les pièces jointes des livraisons (photo du BL consultable après le jour même) ;
 *  - P2 : une livraison refusée n'est plus proposée au rattachement d'un lot.
 */
class TraceabilityRecallAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/hygiene/trace';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(): User
    {
        $u = User::factory()->create(['branch_id' => 1, 'name' => 'Karim Benali']);
        $u->givePermissionTo('pos-orders');
        $u->assignRole('POS Operator');
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function reception(array $over = []): HygieneReception
    {
        $r = new HygieneReception($over + [
            'supplier_name' => 'Metro', 'delivery_note_no' => 'BL-1', 'temperatures' => [], 'checks' => [], 'status' => 'alert', 'decision' => 'reserve',
            'received_at' => now(), 'timezone' => 'Europe/Paris',
        ]);
        $r->branch_id = 1;
        $r->save();

        return $r;
    }

    private function label(array $attrs): LabelPrintLog
    {
        $l = new LabelPrintLog($attrs + ['kind' => 'dlc', 'status' => 'printed', 'copies' => 1, 'printed_pages' => 1, 'opened_at' => now()->subHours(2), 'expires_at' => now()->addDay()]);
        $l->branch_id = 1;
        $l->save();

        return $l;
    }

    public function test_a_reception_voided_after_tracing_stays_on_the_recall_sheet_marked_as_voided(): void
    {
        $user = $this->staff();
        $rc = $this->reception(['attachment_id' => 41, 'attachment_ids' => [42]]);
        $this->postJson(self::BASE, ['product_name' => 'Steak haché', 'lot' => 'LOT-9', 'reception_id' => $rc->id])->assertCreated();

        $before = $this->getJson(self::BASE.'/recall?lot=LOT-9')->assertOk();
        $this->assertFalse($before->json('data.receptions.0.voided'));
        $this->assertNull($before->json('data.receptions.0.void_reason'));
        $this->assertSame(41, $before->json('data.receptions.0.attachment_id'), 'la photo du BL est consultable depuis la fiche');
        $this->assertSame([42], $before->json('data.receptions.0.attachment_ids'));

        $this->postJson('/api/admin/hygiene/receptions/'.$rc->id.'/void', ['reason' => 'Mauvais fournisseur saisi'])->assertOk();

        $after = $this->getJson(self::BASE.'/recall?lot=LOT-9')->assertOk();
        $this->assertCount(1, $after->json('data.receptions'), 'un rappel montre toute l’histoire : la livraison annulée n’est pas cachée');
        $this->assertTrue($after->json('data.receptions.0.voided'));
        $this->assertSame('Mauvais fournisseur saisi', $after->json('data.receptions.0.void_reason'));
        $this->assertSame(now()->toIso8601String(), $after->json('data.receptions.0.voided_at'));
        $this->assertSame($user->name, $after->json('data.receptions.0.voided_by_name'));
        $this->assertSame('reserve', $after->json('data.receptions.0.decision'));
    }

    public function test_a_failed_label_with_no_page_out_is_not_a_preparation_but_an_unconfirmed_one_is_listed_with_its_status(): void
    {
        $this->staff();
        $ok = $this->label(['product_name' => 'Imprimée', 'supplier_lot' => 'LOT-5', 'lot' => 'L1']);
        $this->label(['product_name' => 'Jamais sortie', 'supplier_lot' => 'LOT-5', 'lot' => 'L2', 'status' => 'failed', 'printed_pages' => 0]);
        $this->label(['product_name' => 'Non confirmée', 'supplier_lot' => 'LOT-5', 'lot' => 'L3', 'status' => 'unconfirmed']);
        $this->label(['product_name' => 'Partielle', 'supplier_lot' => 'LOT-5', 'lot' => 'L4', 'status' => 'failed', 'printed_pages' => 1, 'copies' => 3]);
        // une descendante jamais sortie n'est pas une préparation non plus
        $this->label(['product_name' => 'Fille jamais sortie', 'lot' => 'L5', 'parent_log_id' => $ok->id, 'status' => 'failed', 'printed_pages' => 0]);
        $this->label(['product_name' => 'Fille imprimée', 'lot' => 'L6', 'parent_log_id' => $ok->id]);

        $labels = collect($this->getJson(self::BASE.'/recall?lot=lot-5')->assertOk()->json('data.labels'));
        $this->assertSame(['Imprimée', 'Non confirmée', 'Partielle', 'Fille imprimée'], $labels->pluck('product_name')->all());
        $this->assertSame('unconfirmed', $labels->firstWhere('product_name', 'Non confirmée')['status']);
        $this->assertSame('printed', $labels->firstWhere('product_name', 'Imprimée')['status']);
        $this->assertSame(1, $labels->firstWhere('product_name', 'Partielle')['printed_pages']);
    }

    public function test_a_refused_delivery_is_not_offered_for_linking_a_lot(): void
    {
        $this->staff();
        $refused = $this->reception(['delivery_note_no' => 'BL-REF', 'decision' => 'refused']);
        $kept = $this->reception(['delivery_note_no' => 'BL-OK', 'decision' => 'accepted', 'status' => 'ok']);
        $reserve = $this->reception(['delivery_note_no' => 'BL-RES', 'decision' => 'reserve']);

        $ids = collect($this->getJson(self::BASE.'/today')->assertOk()->json('meta.receptions'))->pluck('id')->all();
        $this->assertNotContains($refused->id, $ids);
        $this->assertContains($kept->id, $ids);
        $this->assertContains($reserve->id, $ids);
    }
}
