<?php

namespace App\Http\Controllers\Admin\Hygiene;

use App\Services\Hygiene\Overview;
use Illuminate\Http\JsonResponse;

/** [CAYENNE HYGIÈNE 2026-10-17] L'accueil du module : une tuile par domaine, avec le reste à faire et les alertes du jour. Lecture seule. */
class OverviewController extends HygieneController
{
    public function index(): JsonResponse
    {
        $tiles = (new Overview)->tiles();

        return response()->json([
            'data' => $tiles,
            'meta' => ['alerts' => collect($tiles)->where('status', 'alert')->count(), 'todo' => collect($tiles)->where('status', 'todo')->count()] + $this->clock(),
        ]);
    }
}
