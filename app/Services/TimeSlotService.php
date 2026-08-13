<?php

namespace App\Services;


use Exception;
use App\Models\TimeSlot;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\TimeSlotRequest;
use App\Libraries\QueryExceptionLibrary;

class TimeSlotService
{

    /**
     * @throws Exception
     */
    public $timeSlotFilter = ['opening_time', 'closing_time', 'day'];


    public function list(PaginateRequest $request)
    {
        try {
            $requests = $request->all();
            $method = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType = $request->get('order_type') ?? 'desc';

            return TimeSlot::where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->timeSlotFilter)) {
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
    public function store(TimeSlotRequest $request)
    {
        try {
            $status = true;
            $timeSlots = TimeSlot::where('day', $request->day)->get();
            foreach ($timeSlots as $timeSlot) {
                // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] Standard
                // half-open interval overlap check ([a,b) overlaps [c,d) iff
                // a < d && c < b). The previous 3-branch check only tested
                // the NEW slot's edges against the existing one and missed:
                // the new slot fully CONTAINING an existing one, and exact
                // duplicate boundaries. Strict `<` (not `<=`) still allows
                // legitimate back-to-back adjacent slots (10:00-11:00 then
                // 11:00-12:00).
                if ($request->opening_time < $timeSlot->closing_time && $timeSlot->opening_time < $request->closing_time) {
                    $status = false;
                }
            }

            if ($status) {
                return TimeSlot::create($request->validated());
            } else {
                throw new Exception(trans('all.message.time_slot_exist'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(TimeSlot $timeSlot): void
    {
        try {
            $timeSlot->delete();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
