<?php

namespace App\Services;


use Exception;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\DiningTableAuditLog;
use App\Models\Order;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\DB;
use Dipokhalder\EnvEditor\EnvEditor;
use Illuminate\Support\Facades\File;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;
use App\Http\Requests\DiningTableRequest;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class DiningTableService
{
    protected array $diningTableFilter = [
        'name',
        'size',
        'branch_id',
        'status'
    ];


    public $envService;

    public function __construct(EnvEditor $envEditor)
    {
        $this->envService = $envEditor;
    }

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            return DiningTable::with('branch')->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->diningTableFilter)) {
                        if ($key == "except") {
                            $explodes = explode('|', $request);
                            if (count($explodes)) {
                                foreach ($explodes as $explode) {
                                    $query->where('id', '!=', $explode);
                                }
                            }
                        } else {
                            if ($key == "branch_id") {
                                $query->where($key, $request);
                            } else {
                                $query->where($key, 'like', '%' . $request . '%');
                            }
                        }
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function store(DiningTableRequest $request)
    {
        try {
            $branch      = Branch::find($request->branch_id);           
            $branch_name = $branch ? $branch->name : "";

            $filename = Str::random(10) . '.png';
            $slug     = Str::slug($branch_name.'-'.$request->name);
            $url      = URL::to('/') . "/menu/" . $slug;
            $qrCodePath = null;

            try {
                if (!File::exists(storage_path('app/public/qr_codes/'))) {
                    File::makeDirectory(storage_path('app/public/qr_codes/'));
                }
                QrCode::format('png')->size(200)->generate($url, storage_path('app/public/qr_codes/' . $filename));
                $qrCodePath = 'storage/qr_codes/' . $filename;
            } catch (\Throwable $qrException) {
                Log::warning('[DiningTableService] QR code generation failed on store: ' . $qrException->getMessage());
            }

            return DiningTable::create($request->validated() + ['qr_code' => $qrCodePath, 'slug' => $slug]);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(DiningTableRequest $request, DiningTable $diningTable)
    {
        try {
            $branch      = Branch::find($request->branch_id);           
            $branch_name = $branch ? $branch->name : "";

            $filename = Str::random(10) . '.png';
            $slug     = Str::slug($branch_name.'-'.$request->name);
            $url      = URL::to('/') . "/menu/" . $slug;
            $qrCodePath = $diningTable->qr_code;

            try {
                if (!File::exists(storage_path('app/public/qr_codes/'))) {
                    File::makeDirectory(storage_path('app/public/qr_codes/'));
                }

                if(File::exists($diningTable->qr_code) && !$this->envService->getValue('DEMO')){
                    File::delete($diningTable->qr_code);
                }

                QrCode::format('png')->size(200)->generate($url, storage_path('app/public/qr_codes/' . $filename));
                $qrCodePath = 'storage/qr_codes/' . $filename;
            } catch (\Throwable $qrException) {
                Log::warning('[DiningTableService] QR code generation failed on update: ' . $qrException->getMessage());
            }

            return tap($diningTable)->update($request->validated() + ['qr_code' => $qrCodePath, 'slug' => $slug]);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(DiningTable $diningTable): void
    {
        try {
            if(File::exists($diningTable->qr_code) && !$this->envService->getValue('DEMO')){
                File::delete($diningTable->qr_code);
            }
            $diningTable->delete();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(DiningTable $diningTable): DiningTable
    {
        try {
            return $diningTable;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function floorplanState(int $branchId): array
    {
        return DiningTable::query()
            ->where('branch_id', $branchId)
            ->orderBy('name')
            ->get(['id', 'name', 'size', 'occupancy_status', 'occupied_order_id', 'occupied_at'])
            ->toArray();
    }

    public function occupy(int $userId, int $branchId, int $tableId, int $orderId): DiningTable
    {
        // [V14 C-β / FINDING C-β-T19-2 P1] Validate the order BEFORE locking the
        // table : it must exist AND belong to the same branch. Without this check,
        // an operator could mark a table "occupied" by a phantom or cross-branch
        // order id, producing a lying floor-plan and a multi-tenant leak.
        $orderExists = Order::query()
            ->where('id', $orderId)
            ->where('branch_id', $branchId)
            ->exists();
        if (! $orderExists) {
            abort(422, 'order_not_found_for_branch');
        }

        return DB::transaction(function () use ($userId, $branchId, $tableId, $orderId) {
            $table = DiningTable::query()
                ->where('id', $tableId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($table->isOccupied() && (int) $table->occupied_order_id !== $orderId) {
                abort(409, 'table_already_occupied');
            }

            if ($table->isOccupied() && (int) $table->occupied_order_id === $orderId) {
                return $table->fresh();
            }

            $table->update([
                'occupancy_status' => 'occupied',
                'occupied_order_id' => $orderId,
                'occupied_at' => now(),
            ]);

            // [V14 C-β / FINDING C-β-T19-3 P1] Sync orders.dining_table_id at
            // assignation time so KDS / receipts / reporting stay coherent with
            // the floor-plan. Direct ciblé update — NEVER through OrderService
            // (LOCK_B). Multi-tenant guard via where('branch_id') is mandatory.
            Order::query()
                ->where('id', $orderId)
                ->where('branch_id', $branchId)
                ->update(['dining_table_id' => $tableId]);

            DiningTableAuditLog::create([
                'branch_id' => $branchId,
                'user_id' => $userId,
                'action' => 'occupy',
                'source_table_id' => null,
                'target_table_id' => $tableId,
                'order_id' => $orderId,
                'metadata' => ['table_id' => $tableId],
                'created_at' => now(),
            ]);

            return $table->fresh();
        });
    }

    public function release(int $userId, int $branchId, int $tableId): DiningTable
    {
        return DB::transaction(function () use ($userId, $branchId, $tableId) {
            $table = DiningTable::query()
                ->where('id', $tableId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($table->isFree()) {
                return $table->fresh();
            }

            $previousOrderId = (int) $table->occupied_order_id;

            $table->update([
                'occupancy_status' => 'free',
                'occupied_order_id' => null,
                'occupied_at' => null,
            ]);

            DiningTableAuditLog::create([
                'branch_id' => $branchId,
                'user_id' => $userId,
                'action' => 'release',
                'source_table_id' => $tableId,
                'target_table_id' => null,
                'order_id' => $previousOrderId ?: null,
                'metadata' => ['table_id' => $tableId],
                'created_at' => now(),
            ]);

            return $table->fresh();
        });
    }

    public function transfer(int $userId, int $branchId, int $sourceTableId, int $targetTableId): DiningTable
    {
        if ($sourceTableId === $targetTableId) {
            abort(422, 'same_table');
        }

        return DB::transaction(function () use ($userId, $branchId, $sourceTableId, $targetTableId) {
            // [V14 C-β / FINDING C-β-T19-1 P1] MySQL deadlock guard.
            // Two operators doing concurrent transfers (1→2 and 2→1) can
            // deadlock if locks are taken in business order. Always lock
            // by ASCENDING id, then resolve which is source / target.
            $firstId  = min($sourceTableId, $targetTableId);
            $secondId = max($sourceTableId, $targetTableId);

            $first = DiningTable::query()
                ->where('id', $firstId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->firstOrFail();

            $second = DiningTable::query()
                ->where('id', $secondId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->firstOrFail();

            $source = $first->id === $sourceTableId ? $first : $second;
            $target = $first->id === $targetTableId ? $first : $second;

            if (! $source->isOccupied()) {
                abort(422, 'source_not_occupied');
            }

            if ($target->isOccupied()) {
                abort(409, 'target_already_occupied');
            }

            $orderId = (int) $source->occupied_order_id;
            $occupiedAt = $source->occupied_at;

            $target->update([
                'occupancy_status' => 'occupied',
                'occupied_order_id' => $orderId,
                'occupied_at' => $occupiedAt,
            ]);

            $source->update([
                'occupancy_status' => 'free',
                'occupied_order_id' => null,
                'occupied_at' => null,
            ]);

            if ($orderId > 0) {
                Order::query()
                    ->where('id', $orderId)
                    ->where('branch_id', $branchId)
                    ->update(['dining_table_id' => $targetTableId]);
            }

            DiningTableAuditLog::create([
                'branch_id' => $branchId,
                'user_id' => $userId,
                'action' => 'transfer',
                'source_table_id' => $sourceTableId,
                'target_table_id' => $targetTableId,
                'order_id' => $orderId ?: null,
                'metadata' => [
                    'source_table_id' => $sourceTableId,
                    'target_table_id' => $targetTableId,
                ],
                'created_at' => now(),
            ]);

            return $target->fresh();
        });
    }
}
