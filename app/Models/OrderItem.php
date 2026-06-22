<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function boot()
    {
        parent::boot();

        // [P0-FIX-2 NF525-V1 2026-05-09] OrderItem must be scoped to user's branch
        // like Order is. Without this, ItemService::destroy():365
        // (`OrderItem::query()->where('item_id', $itemId)->count()`) leaks
        // historical-order counts across all tenants → branch isolation breach.
        // BranchScope respects admin bypass (branch_id=0) and kiosk routing
        // (KioskMachine.branch_id), so no behavior change for legitimate paths.
        // Existing access via `$order->orderItems()` is unchanged because Order
        // already enforces the same scope on its parent query.
        static::addGlobalScope(new BranchScope());

        // [GOAL-J2-HEAL-06 2026-05-24] Phase J-ADV-2 FV-F5-1 P1 NF525.
        //
        // Application-layer guard preventing composition_snapshot mutation
        // post-insert. The DB BEFORE UPDATE trigger added by migration
        // 2026_05_24_040211_add_composition_snapshot_immutability_trigger is
        // the runtime defence (catches raw SQL UPDATE bypassing Eloquent);
        // this `updating()` hook is the tests-time + grep-time + IDE-time
        // visibility layer (catches at Eloquent layer with a stack trace
        // pointing at the offending caller, rather than a generic
        // QueryException from the trigger).
        //
        // Behaviour:
        //   - INSERT: untouched (this hook fires on UPDATE only).
        //   - UPDATE where composition_snapshot is NOT dirty: pass.
        //   - UPDATE where composition_snapshot is dirty AND original was
        //     null: pass (legacy backfill scenario).
        //   - UPDATE where composition_snapshot is dirty AND original was
        //     non-null: throw RuntimeException.
        //
        // This mirrors the StockMovement::booted() append-only guard
        // (cf. app/Models/StockMovement.php) and AuditLog immutability pattern.
        static::updating(function (OrderItem $orderItem) {
            if ($orderItem->isDirty('composition_snapshot')
                && $orderItem->getOriginal('composition_snapshot') !== null) {
                throw new \RuntimeException(
                    'NF525: composition_snapshot is immutable after creation. '
                    . 'Attempted mutation on OrderItem #' . ($orderItem->id ?? 'unsaved')
                );
            }
        });
    }

    protected $table = "order_items";
    protected $fillable = [
        'order_id',
        'branch_id',
        'item_id',
        'quantity',
        'discount',
        'tax_name',
        'tax_rate',
        'tax_type',
        'tax_amount',
        'price',
        'item_variations',
        'item_extras',
        'composition_snapshot',
        'item_variation_total',
        'item_extra_total',
        'total_price',
        'instruction',
        'allergens_snapshot',
        'creator_type',
        'creator_id',
        'editor_type',
        'editor_id',
        'created_at',
        'updated_at'
    ];
    protected $casts = [
        'id'                   => 'integer',
        'order_id'             => 'integer',
        'branch_id'            => 'integer',
        'item_id'              => 'integer',
        'quantity'             => 'integer',
        'discount'             => 'decimal:6',
        'tax_name'             => 'string',
        'tax_rate'             => 'string',
        'tax_type'             => 'integer',
        'tax_amount'           => 'decimal:6',
        'price'                => 'decimal:6',
        'item_variations'      => 'string',
        'item_extras'          => 'string',
        'composition_snapshot' => 'array',
        'item_variation_total' => 'decimal:6',
        'item_extra_total'     => 'decimal:6',
        'total_price'          => 'decimal:6',
        'instruction'          => 'string',
        'allergens_snapshot'   => 'array',
        'creator_type'         => 'string',
        'creator_id'           => 'integer',
        'editor_type'          => 'string',
        'editor_id'            => 'integer',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
    public function orderItem()
    {
        return $this->belongsTo(Item::class, 'item_id', 'id')->withTrashed();
    }
}
