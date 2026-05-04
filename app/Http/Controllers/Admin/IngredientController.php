<?php

namespace App\Http\Controllers\Admin;

use App\Http\Resources\IngredientResource;
use App\Services\Ingredients\IngredientAvailabilityService;
use App\Services\Ingredients\IngredientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngredientController extends AdminController
{
    public function __construct(
        private readonly IngredientService $ingredients,
        private readonly IngredientAvailabilityService $availability,
    ) {
        parent::__construct();
    }

    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $list = $type
            ? $this->ingredients->listByType((string) $type)
            : $this->ingredients->listAll();

        return response()->json([
            'success' => true,
            'data' => IngredientResource::collection($list),
        ]);
    }

    public function show(string $globalId): JsonResponse
    {
        $found = $this->ingredients->findByGlobalId($globalId);
        abort_if(! $found, 404);

        return response()->json([
            'success' => true,
            'data' => new IngredientResource($found),
        ]);
    }

    public function toggleAvailability(Request $request, string $globalId): JsonResponse
    {
        $data = $request->validate([
            'is_available' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:64'],
        ]);

        $parsed = IngredientService::parseGlobalId($globalId);
        abort_if(! $parsed, 422, 'Invalid ingredient global id.');

        $ok = $this->availability->toggle(
            $parsed['type'],
            $parsed['id'],
            (bool) $data['is_available'],
            $data['reason'] ?? null
        );

        abort_if(! $ok, 422, 'Toggle failed (addon ingredients are read-only or row not found).');

        return response()->json([
            'success' => true,
            'data' => new IngredientResource($this->ingredients->findByGlobalId($globalId)),
        ]);
    }
}
