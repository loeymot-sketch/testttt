<?php

namespace App\Http\Controllers\Admin\Hygiene;

use App\Services\Hygiene\Overview;
use App\Services\Hygiene\Reminders;
use App\Services\Hygiene\SetupProgress;
use Illuminate\Http\JsonResponse;

/** [CAYENNE HYGIÈNE 2026-10-17] L'accueil du module : une tuile par domaine, avec le reste à faire et les alertes du jour. Lecture seule. */
class OverviewController extends HygieneController
{
    public function index(): JsonResponse
    {
        $reminders = (new Reminders)->all();
        $tiles = (new Overview)->tiles($reminders);

        return response()->json([
            'data' => $tiles,
            // `reminders` : ce qui est en retard ou à traiter MAINTENANT, une phrase chacun, relié à son écran
            'meta' => ['alerts' => collect($tiles)->where('status', 'alert')->count(), 'todo' => collect($tiles)->where('status', 'todo')->count(), 'reminders' => $reminders, 'setup' => (new SetupProgress)->all()] + $this->clock(),
        ]);
    }
}
