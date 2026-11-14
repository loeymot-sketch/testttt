<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M9-103] La file caisse « commandes web à accepter » est plafonnée à 200.
 *
 * Défaut : elle renvoyait les 200 PLUS ANCIENNES (tri croissant puis `limit(200)`). Les
 * commandes « payer au comptoir » dont le client ne vient jamais ne sont purgées par aucune
 * tâche : au-delà de 200, toute NOUVELLE commande web disparaissait de l'écran caisse, sans
 * aucun signal. La file doit garder les plus RÉCENTES et dire qu'elle est tronquée.
 *
 * Second défaut (performance) : l'empreinte d'audit était lue par une requête `audit_logs`
 * PAR commande (119 lectures sur la base locale).
 */
class WebOrdersPendingTruncationTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
    }

    private function webPending(array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'source_surface' => 'web',
            'order_type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
        ], $extra));
    }

    private function fetch()
    {
        return $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos/web-orders/pending');
    }

    /** @test */
    public function au_dela_de_200_la_nouvelle_commande_reste_visible_et_la_troncature_est_dite(): void
    {
        $base = Carbon::parse('2026-08-01 12:00:00');
        for ($i = 0; $i < 200; $i++) {
            // 200 clients « payer au comptoir » jamais venus, accumulés depuis des semaines.
            $this->webPending(['created_at' => $base->copy()->addMinutes($i)]);
        }
        $nouvelle = $this->webPending(['created_at' => Carbon::parse('2026-10-22 19:00:00')]);

        $res = $this->fetch();
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertCount(200, $ids, 'le plafond de 200 reste en place');
        $this->assertContains($nouvelle->id, $ids, 'la NOUVELLE commande web doit apparaître en caisse');
        $this->assertSame(201, $res->json('meta.total'), 'la réponse dit combien de commandes attendent');
        $this->assertSame(200, $res->json('meta.returned'));
        $this->assertSame(200, $res->json('meta.limit'));
        $this->assertTrue($res->json('meta.truncated'), 'la réponse dit qu\'elle est tronquée');
    }

    /** @test */
    public function sous_le_plafond_la_reponse_dit_qu_elle_est_complete(): void
    {
        $this->webPending();
        $this->webPending();

        $res = $this->fetch();
        $res->assertOk();
        $this->assertSame(2, $res->json('meta.total'));
        $this->assertSame(2, $res->json('meta.returned'));
        $this->assertFalse($res->json('meta.truncated'));
    }

    /** @test */
    public function l_empreinte_d_audit_est_lue_en_lot_et_reste_identique(): void
    {
        $orders = [];
        for ($i = 0; $i < 5; $i++) {
            $orders[] = $o = $this->webPending();
            // Deux maillons par commande : l'empreinte vient du DERNIER.
            foreach (['order.created', 'order.updated'] as $k => $action) {
                AuditLog::create([
                    'branch_id' => $this->branch->id, 'action' => $action, 'resource' => 'order',
                    'resource_id' => $o->id, 'payload' => [], 'prev_hash' => null,
                    'current_hash' => hash('sha256', "h{$o->id}-{$k}"),
                ]);
            }
        }
        // Une commande sans aucun maillon : empreinte nulle, comme avant.
        $sansAudit = $this->webPending();

        $auditReads = 0;
        DB::listen(function ($q) use (&$auditReads) {
            if (stripos($q->sql, 'audit_logs') !== false && stripos($q->sql, 'select') === 0) {
                $auditReads++;
            }
        });

        $res = $this->fetch();
        $res->assertOk();

        $this->assertLessThanOrEqual(2, $auditReads, "audit_logs lu {$auditReads} fois pour 6 commandes (N+1)");

        $cards = collect($res->json('data'))->keyBy('id');
        foreach ($orders as $o) {
            $last = AuditLog::where('resource', 'order')->where('resource_id', $o->id)->orderByDesc('id')->first();
            $attendu = substr(hash('sha256', $last->current_hash.'|'.$last->id), 0, 12);
            $this->assertSame($attendu, $cards[$o->id]['audit_chain_fingerprint'], 'même empreinte qu\'avant le lot');
        }
        $this->assertNull($cards[$sansAudit->id]['audit_chain_fingerprint']);
    }
}
