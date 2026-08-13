<?php

namespace App\Services;


use Exception;
use App\Models\ItemAttribute;
use App\Models\ItemWizardStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Http\Requests\ItemAttributeRequest;

class ItemAttributeService
{
    public $itemAttribute;
    protected $itemAttributeFilter = [
        'name',
        'status'
    ];

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

            return ItemAttribute::where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->itemAttributeFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
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
    public function store(ItemAttributeRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $this->itemAttribute = ItemAttribute::create($request->validated());
            });
            return $this->itemAttribute;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            DB::rollBack();
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(ItemAttributeRequest $request, ItemAttribute $itemAttribute): ItemAttribute
    {
        try {
            DB::transaction(function () use ($request, $itemAttribute) {
                // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] adversarial-
                // audit finding: ComposerProfileProjection resolves an
                // item_wizard_steps row by matching source_ref against this
                // attribute's lowercased NAME (id-referenced steps are safe —
                // this only concerns the legacy name-referenced ones, 57 of
                // them found live in this DB). A bare rename silently
                // orphaned every one of them: choices() returns empty, the
                // size/sauce/meat dropdown goes blank for that composer step,
                // zero error surfaced to the admin or the customer. Cascade
                // the rename to every step still referencing the old name,
                // in the same transaction as the attribute update.
                $oldName = mb_strtolower((string) $itemAttribute->name);

                $itemAttribute->update($request->validated());

                $newName = mb_strtolower((string) $itemAttribute->name);
                if ($oldName !== '' && $oldName !== $newName) {
                    ItemWizardStep::query()
                        ->where('source_type', 'item_attribute')
                        ->where('source_ref', $oldName)
                        ->update(['source_ref' => $newName]);
                }
            });
            return $itemAttribute;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            DB::rollBack();
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(ItemAttribute $itemAttribute)
    {
        try {
            DB::transaction(function () use ($itemAttribute) {
                $itemAttribute->delete();
            });
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            DB::rollBack();
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(ItemAttribute $itemAttribute): ItemAttribute
    {
        try {
            return $itemAttribute;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
