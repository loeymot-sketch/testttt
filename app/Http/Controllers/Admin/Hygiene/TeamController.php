<?php

namespace App\Http\Controllers\Admin\Hygiene;

use App\Services\Hygiene\OperatorPins;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P1 « Qui ? sans code »] Le responsable donne (ou retire) un code personnel à chaque employé.
 * Réservé à la permission `settings` EN PLUS de la porte du module : le personnel de la tablette ne se donne pas de code à la place d'un
 * collègue. Le code n'est jamais relu : on ne peut que le DÉFINIR (ce qui débloque aussi la personne) ou le RETIRER.
 */
class TeamController extends HygieneController
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(['permission:settings']);
    }

    public function index(Request $request): JsonResponse
    {
        $pins = app(OperatorPins::class)->states($this->branchId($request));
        $rows = $this->staffQuery($request)->orderBy('name')->limit(200)->get(['id', 'name'])
            ->map(fn ($u) => [
                'id' => $u->id, 'name' => $this->cleanName($u->name),
                'has_pin' => isset($pins[$u->id]), 'pin_set_at' => $pins[$u->id]['set_at'] ?? null, 'locked' => (bool) ($pins[$u->id]['locked'] ?? false),
            ])->filter(fn ($u) => $u['name'] !== null)->values();

        return response()->json([
            'data' => $rows,
            'meta' => ['without_pin' => $rows->where('has_pin', false)->count(), 'pin_digits' => [(int) config('hygiene.operator.pin_min_digits', 4), (int) config('hygiene.operator.pin_max_digits', 6)]] + $this->clock(),
        ]);
    }

    public function setPin(Request $request, string $user): JsonResponse
    {
        $v = $request->validate(['pin' => ['required', 'string', 'regex:/^\d{4,6}$/']], ['pin.regex' => 'Le code se compose de 4 à 6 chiffres.', 'pin.required' => 'Saisissez un code de 4 à 6 chiffres.']);
        $person = $this->staffQuery($request)->whereKey((int) $user)->first(['id', 'name']);
        if (! $person) {
            return response()->json(['message' => 'Cette personne ne fait pas partie du personnel.', 'errors' => ['user' => ['Cette personne ne fait pas partie du personnel.']]], 404);
        }
        app(OperatorPins::class)->set($this->branchId($request), (int) $person->id, $v['pin'], $request->user()?->id);

        return response()->json(['data' => ['id' => $person->id, 'name' => $this->cleanName($person->name), 'has_pin' => true]]);
    }

    public function removePin(Request $request, string $user): JsonResponse
    {
        $person = $this->staffQuery($request)->whereKey((int) $user)->first(['id', 'name']);
        if (! $person) {
            return response()->json(['message' => 'Cette personne ne fait pas partie du personnel.', 'errors' => ['user' => ['Cette personne ne fait pas partie du personnel.']]], 404);
        }
        app(OperatorPins::class)->remove($this->branchId($request), (int) $person->id);

        return response()->json(['data' => ['id' => $person->id, 'name' => $this->cleanName($person->name), 'has_pin' => false]]);
    }
}
