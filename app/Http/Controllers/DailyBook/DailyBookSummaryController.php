<?php

namespace App\Http\Controllers\DailyBook;

use App\Http\Controllers\Controller;
use App\Services\DailyBook\DailyBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [GOAL RUPTURE-CARNET 2026-07-15 / W4] Résumés du Carnet — mois : totaux
 * dépenses/acomptes, détail par travailleur et par jour.
 */
class DailyBookSummaryController extends Controller
{
    public function __construct(private DailyBookService $service)
    {
    }

    public function month(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        return response()->json(['data' => $this->service->monthSummary($validated['month'])]);
    }
}
