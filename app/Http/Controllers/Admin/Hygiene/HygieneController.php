<?php

namespace App\Http\Controllers\Admin\Hygiene;

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Concerns\ResolvesOperator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Socle des contrôleurs du module hygiène.
 *
 * Porte : la même que les étiquettes (permission:pos-orders|pos|kitchen-display-system) — le personnel qui tient la tablette.
 * Règles communes (voir docs/HYGIENE_FEUILLE_DE_ROUTE_KOMIA.md) : journal append-only, contexte copié, verdict serveur,
 * action corrective obligatoire en cas d'écart, envois rejouables (`client_ref`), opérateur identifié.
 */
abstract class HygieneController extends AdminController
{
    use ResolvesOperator;

    public function __construct()
    {
        parent::__construct();

        $this->middleware(['permission:pos-orders|pos|kitchen-display-system']);
    }

    protected function branchId(Request $request): int
    {
        return (int) ($request->user()?->branch_id ?: config('hygiene.branch_id', 1));
    }

    protected function clock(): array
    {
        return ['server_now' => now()->toIso8601String(), 'timezone' => config('app.timezone')];
    }

    protected function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

        return $sqlState === '23000' || $sqlState === '23505';
    }

    /** Bornes d'une période de journal (par défaut : 30 derniers jours), dans le fuseau de l'application. */
    protected function period(array $v, int $defaultDays = 30): array
    {
        $tz = config('app.timezone');
        $from = isset($v['from']) ? \Carbon\Carbon::createFromFormat('Y-m-d', $v['from'], $tz)->startOfDay() : now()->subDays($defaultDays)->startOfDay();
        $to = isset($v['to']) ? \Carbon\Carbon::createFromFormat('Y-m-d', $v['to'], $tz)->endOfDay() : now()->endOfDay();

        return [$from, $to];
    }
}
