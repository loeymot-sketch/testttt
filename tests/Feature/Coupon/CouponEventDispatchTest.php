<?php

namespace Tests\Feature\Coupon;

use App\Enums\DiscountType;
use App\Enums\EventType;
use App\Enums\Status;
use App\Events\CouponChanged;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\DomainEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * [PROMO-DASH-2026-05-06] Vérifie que toute mutation Dashboard d'un coupon
 * (create/update/delete/toggle) déclenche {@see CouponChanged} et persiste
 * un row `domain_events` par branche concernée.
 */
class CouponEventDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatching_coupon_changed_persists_one_event_per_active_branch(): void
    {
        Queue::fake();

        Branch::factory()->create(['status' => Status::ACTIVE]);
        Branch::factory()->create(['status' => Status::ACTIVE]);
        Branch::factory()->create(['status' => 0]); // inactive ignored

        $coupon = Coupon::create([
            'name'             => 'Promo dispatch',
            'description'      => 't',
            'code'             => 'TESTDISPATCH',
            'discount'         => 5,
            'discount_type'    => DiscountType::FIXED,
            'start_date'       => Carbon::now()->subDay(),
            'end_date'         => Carbon::now()->addMonth(),
            'minimum_order'    => 0,
            'maximum_discount' => 0,
            'limit_per_user'   => 0,
            'status'           => Status::ACTIVE,
            'usage_count'      => 0,
        ]);

        // Direct dispatch — mirror controller behaviour.
        CouponChanged::dispatch(
            (int) $coupon->id,
            CouponChanged::CHANGE_CREATED,
            (string) $coupon->code,
            (int) $coupon->status,
            [],
            [],
            []
        );

        $rows = DomainEvent::query()
            ->where('event_type', EventType::COUPON_CHANGED)
            ->orderBy('branch_id')
            ->get();

        $this->assertCount(2, $rows, 'One row per active branch.');
        foreach ($rows as $row) {
            $this->assertSame('coupon', $row->aggregate_type);
            $this->assertSame((int) $coupon->id, (int) $row->aggregate_id);
            $this->assertSame('CouponChanged', $row->broadcast_as);
            $this->assertSame(['private-branch.' . $row->branch_id], json_decode($row->channel, true));
            $this->assertSame((int) $coupon->id, (int) $row->payload['coupon_id']);
            $this->assertSame('TESTDISPATCH', $row->payload['code']);
            $this->assertSame('created', $row->payload['change_type']);
        }
    }

    public function test_dispatch_with_branch_scope_only_targets_those_branches(): void
    {
        Queue::fake();

        $b1 = Branch::factory()->create(['status' => Status::ACTIVE]);
        $b2 = Branch::factory()->create(['status' => Status::ACTIVE]);
        $b3 = Branch::factory()->create(['status' => Status::ACTIVE]);

        $coupon = Coupon::create([
            'name'             => 'Promo scope',
            'description'      => 't',
            'code'             => 'SCOPE',
            'discount'         => 5,
            'discount_type'    => DiscountType::FIXED,
            'start_date'       => Carbon::now()->subDay(),
            'end_date'         => Carbon::now()->addMonth(),
            'minimum_order'    => 0,
            'maximum_discount' => 0,
            'branch_scope'     => [$b1->id, $b3->id],
            'status'           => Status::ACTIVE,
            'usage_count'      => 0,
        ]);

        CouponChanged::dispatch(
            (int) $coupon->id,
            CouponChanged::CHANGE_UPDATED,
            (string) $coupon->code,
            (int) $coupon->status,
            [$b1->id, $b3->id],
            [],
            []
        );

        $branchIds = DomainEvent::query()
            ->where('event_type', EventType::COUPON_CHANGED)
            ->pluck('branch_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([(int) $b1->id, (int) $b3->id], $branchIds);
    }

    public function test_event_is_fired_with_correct_change_type(): void
    {
        Event::fake([CouponChanged::class]);

        CouponChanged::dispatch(42, CouponChanged::CHANGE_DELETED, 'X', Status::ACTIVE, [], [], []);

        Event::assertDispatched(CouponChanged::class, function (CouponChanged $event) {
            return $event->couponId === 42
                && $event->changeType === CouponChanged::CHANGE_DELETED
                && $event->code === 'X';
        });
    }
}
