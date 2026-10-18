<?php

namespace App\Http\Controllers\Admin\Hygiene;

use App\Services\Hygiene\OperatorPins;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** [CAYENNE HYGIÈNE 2026-10-17] « Qui est-ce ? » : le personnel du restaurant, pour attribuer un relevé sur une tablette partagée. */
class OperatorController extends HygieneController
{
    public function index(Request $request): JsonResponse
    {
        $me = (int) $request->user()?->id;
        $pins = app(OperatorPins::class)->states($this->branchId($request));
        $rows = $this->staffQuery($request)->orderBy('name')->limit(200)->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $this->cleanName($u->name), 'short' => $this->short($u->name), 'is_me' => $u->id === $me, 'has_pin' => isset($pins[$u->id])])
            ->filter(fn ($u) => $u['name'] !== null)->values();

        return response()->json(['data' => $rows, 'meta' => ['me' => $me, 'pin_digits' => [(int) config('hygiene.operator.pin_min_digits', 4), (int) config('hygiene.operator.pin_max_digits', 6)], 'token_minutes' => OperatorPins::tokenMinutes()] + $this->clock()]);
    }

    /**
     * Vérifie le code d'une personne au moment où on choisit son nom. Bon code : un jeton de signature (le code n'est jamais gardé sur la
     * tablette). Mauvais code : le nombre d'essais restants ; trop d'essais : la personne est bloquée quelques minutes (423).
     */
    public function verify(Request $request): JsonResponse
    {
        $v = $request->validate(['user_id' => ['required', 'integer'], 'pin' => ['required', 'string', 'regex:/^\d{4,6}$/']], ['pin.regex' => 'Le code se compose de 4 à 6 chiffres.']);
        $person = $this->staffQuery($request)->whereKey((int) $v['user_id'])->first(['id', 'name']);
        if (! $person) {
            return response()->json(['message' => 'Cette personne ne fait pas partie du personnel.', 'errors' => ['user_id' => ['Cette personne ne fait pas partie du personnel.']]], 422);
        }
        $r = app(OperatorPins::class)->verify($this->branchId($request), (int) $person->id, $v['pin']);
        $name = $this->cleanName($person->name) ?? 'cette personne';
        switch ($r['result']) {
            case 'ok':
                return response()->json(['data' => ['token' => $r['token'], 'expires_at' => $r['expires_at'], 'name' => $name]]);
            case 'none':
                return response()->json(['data' => ['token' => null, 'expires_at' => null, 'name' => $name]]); // aucun code : libre
            case 'locked':
                return response()->json(['message' => "Trop d'essais : le code de $name est bloqué pendant {$r['locked_minutes']} minutes.", 'errors' => ['pin' => ["Trop d'essais : bloqué pendant {$r['locked_minutes']} minutes."]], 'data' => ['locked_minutes' => $r['locked_minutes']]], 423);
            default:
                $m = "Code incorrect ({$r['remaining']} essai".($r['remaining'] > 1 ? 's' : '').' restant'.($r['remaining'] > 1 ? 's' : '').').';

                return response()->json(['message' => $m, 'errors' => ['pin' => [$m]], 'data' => ['remaining' => $r['remaining']]], 422);
        }
    }

    /** « Karim Benali » → « Karim B. » : lisible sur un bouton, sans exposer plus que nécessaire. */
    private function short(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $first = $parts[0] ?? '';

        return count($parts) > 1 ? $first.' '.mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)).'.' : $first;
    }
}
