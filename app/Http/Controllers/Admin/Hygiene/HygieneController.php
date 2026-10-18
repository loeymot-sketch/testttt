<?php

namespace App\Http\Controllers\Admin\Hygiene;

use App\Http\Controllers\Admin\AdminController;
use App\Models\User;
use App\Enums\Status;
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
    /** Rôles (par NOM : les identifiants diffèrent d'une base à l'autre) de la liste « Qui est-ce ? » — jamais les clients ni les livreurs. */
    public const STAFF_ROLES = ['Admin', 'Waiter', 'Chef', 'Branch Manager', 'POS Operator', 'Stuff'];

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

    /** Le personnel de CE restaurant (admin multi-branche inclus). */
    protected function staffQuery(Request $request)
    {
        $branch = $this->branchId($request);

        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('roles.name', self::STAFF_ROLES))
            ->where('status', Status::ACTIVE) // un ancien salarié désactivé n'est plus proposé ni accepté comme opérateur
            ->where(fn ($q) => $q->where('branch_id', $branch)->orWhere('branch_id', 0));
    }

    /**
     * Qui a fait le geste ? Tablette partagée : `operator_user_id` désigne la personne, sinon l'utilisateur connecté.
     * Un identifiant hors du personnel du restaurant est REFUSÉ (jamais d'attribution à un inconnu).
     *
     * Si la personne désignée a un CODE PERSONNEL (hygiene_operator_pins), il faut le prouver : `operator_token` (remis après vérification du
     * code, valable quelques minutes, y compris pour un relevé gardé hors ligne : `$at` = l'heure du geste) ou, à défaut, `operator_pin`.
     * Choisir SOI-MÊME (le compte connecté) ne demande rien : la connexion est déjà la preuve.
     *
     * @return array{user_id:?int, name:?string}
     */
    protected function operator(Request $request, ?\Carbon\CarbonInterface $at = null): array
    {
        $id = $request->input('operator_user_id');
        if ($id !== null && $id !== '' && (int) $id !== (int) $request->user()?->id) {
            $person = $this->staffQuery($request)->whereKey((int) $id)->first(['id', 'name']);
            if (! $person) {
                abort(response()->json(['message' => 'Opérateur inconnu.', 'errors' => ['operator_user_id' => ['Cette personne ne fait pas partie du personnel.']]], 422));
            }
            $this->assertPinProven($request, $person, $at ?? now());

            return ['user_id' => $person->id, 'name' => $this->cleanName($person->name)];
        }
        $user = $request->user();

        return ['user_id' => $user?->id, 'name' => $this->cleanName($user?->name)];
    }

    private function assertPinProven(Request $request, $person, \Carbon\CarbonInterface $at): void
    {
        $pins = app(\App\Services\Hygiene\OperatorPins::class);
        $branch = $this->branchId($request);
        if (! $pins->has($branch, (int) $person->id)) return; // aucun code : libre, comme avant
        $name = $this->cleanName($person->name) ?? 'cette personne';
        $refuse = fn (string $field, string $message, int $status = 422) => abort(response()->json(['message' => $message, 'errors' => [$field => [$message]]], $status));

        $token = $request->input('operator_token');
        if (is_string($token) && $token !== '') {
            $r = $pins->checkToken($branch, (int) $person->id, $token, $at);
            if ($r === 'ok') return;
            $refuse('operator_token', $r === 'expired'
                ? "Le code de $name a expiré : choisissez de nouveau son nom et saisissez son code."
                : "Code de $name non valide : choisissez de nouveau son nom et saisissez son code.");
        }
        $pin = $request->input('operator_pin');
        if (is_string($pin) && $pin !== '') {
            $v = $pins->verify($branch, (int) $person->id, $pin);
            if ($v['result'] === 'ok' || $v['result'] === 'none') return;
            if ($v['result'] === 'locked') {
                $refuse('operator_pin', "Trop d'essais : le code de $name est bloqué pendant {$v['locked_minutes']} minutes.", 423);
            }
            $refuse('operator_pin', "Code de $name incorrect ({$v['remaining']} essai".($v['remaining'] > 1 ? 's' : '').' restant'.($v['remaining'] > 1 ? 's' : '').').');
        }
        $refuse('operator_pin', "Le code de $name est requis pour signer à son nom.");
    }

    protected function cleanName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : mb_substr($name, 0, 80);
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
