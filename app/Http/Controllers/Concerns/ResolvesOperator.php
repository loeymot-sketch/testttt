<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\Status;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * [CAYENNE HYGIÈNE 2026-10-18] « Qui a fait le geste ? » : résolution de la personne (tablette partagée) et preuve de son code personnel.
 * Un seul code pour TOUS les écrans qui signent — relevés, huile, réception, traçabilité, nettoyage ET étiquettes DLC —, jamais deux règles.
 *
 * Le contrôleur qui l'utilise fournit `branchId(Request): int` (la branche du restaurant).
 * Pas de constante dans un trait : la production tourne en PHP 8.1.
 */
trait ResolvesOperator
{
    /** Rôles (par NOM : les identifiants diffèrent d'une base à l'autre) de la liste « Qui est-ce ? » — jamais les clients ni les livreurs. */
    protected static function staffRoles(): array
    {
        return ['Admin', 'Waiter', 'Chef', 'Branch Manager', 'POS Operator', 'Stuff'];
    }

    /** Le personnel de CE restaurant (admin multi-branche inclus). */
    protected function staffQuery(Request $request)
    {
        $branch = $this->branchId($request);

        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('roles.name', static::staffRoles()))
            ->where('status', Status::ACTIVE) // un ancien salarié désactivé n'est plus proposé ni accepté comme opérateur
            ->where(fn ($q) => $q->where('branch_id', $branch)->orWhere('branch_id', 0));
    }

    /**
     * Qui a fait le geste ? Tablette partagée : `operator_user_id` désigne la personne, sinon l'utilisateur connecté.
     * Un identifiant hors du personnel du restaurant est REFUSÉ (jamais d'attribution à un inconnu).
     *
     * Si la personne désignée a un CODE PERSONNEL (hygiene_operator_pins), il faut le prouver : `operator_token` (remis après vérification du
     * code, valable quelques minutes, y compris pour un relevé gardé hors ligne : `$at` = l'heure de CHAQUE geste du lot, toutes dans la fenêtre)
     * ou, à défaut, `operator_pin`.
     * Choisir SOI-MÊME (le compte connecté) ne demande rien : la connexion est déjà la preuve.
     *
     * @return array{user_id:?int, name:?string}
     */
    protected function operator(Request $request, \Carbon\CarbonInterface|array|null $at = null): array
    {
        $id = $request->input('operator_user_id');
        if ($id !== null && $id !== '' && (int) $id !== (int) $request->user()?->id) {
            $person = $this->staffQuery($request)->whereKey((int) $id)->first(['id', 'name']);
            if (! $person) {
                abort(response()->json(['message' => 'Opérateur inconnu.', 'errors' => ['operator_user_id' => ['Cette personne ne fait pas partie du personnel.']]], 422));
            }
            $this->assertPinProven($request, $person, $at === null ? [now()] : (is_array($at) ? $at : [$at]));

            return ['user_id' => $person->id, 'name' => $this->cleanName($person->name)];
        }
        $user = $request->user();

        return ['user_id' => $user?->id, 'name' => $this->cleanName($user?->name)];
    }

    /** @param list<\Carbon\CarbonInterface> $instants l'heure de CHAQUE geste du lot : le jeton doit les couvrir tous (pas seulement le plus ancien) */
    private function assertPinProven(Request $request, $person, array $instants): void
    {
        $pins = app(\App\Services\Hygiene\OperatorPins::class);
        $branch = $this->branchId($request);
        if (! $pins->has($branch, (int) $person->id)) return; // aucun code : libre, comme avant
        $name = $this->cleanName($person->name) ?? 'cette personne';
        $refuse = fn (string $field, string $message, int $status = 422) => abort(response()->json(['message' => $message, 'errors' => [$field => [$message]]], $status));

        $token = $request->input('operator_token');
        if (is_string($token) && $token !== '') {
            $r = 'ok';
            foreach ($instants as $instant) {
                $one = $pins->checkToken($branch, (int) $person->id, $token, $instant);
                if ($one !== 'ok') { $r = $one; break; }
            }
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
}
