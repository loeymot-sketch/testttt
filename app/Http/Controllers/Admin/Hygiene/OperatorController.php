<?php

namespace App\Http\Controllers\Admin\Hygiene;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** [CAYENNE HYGIÈNE 2026-10-17] « Qui est-ce ? » : le personnel du restaurant, pour attribuer un relevé sur une tablette partagée. */
class OperatorController extends HygieneController
{
    public function index(Request $request): JsonResponse
    {
        $me = (int) $request->user()?->id;
        $rows = $this->staffQuery($request)->orderBy('name')->limit(200)->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $this->cleanName($u->name), 'short' => $this->short($u->name), 'is_me' => $u->id === $me])
            ->filter(fn ($u) => $u['name'] !== null)->values();

        return response()->json(['data' => $rows, 'meta' => ['me' => $me] + $this->clock()]);
    }

    /** « Karim Benali » → « Karim B. » : lisible sur un bouton, sans exposer plus que nécessaire. */
    private function short(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $first = $parts[0] ?? '';

        return count($parts) > 1 ? $first.' '.mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)).'.' : $first;
    }
}
