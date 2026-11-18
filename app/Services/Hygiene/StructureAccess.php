<?php

namespace App\Services\Hygiene;

use App\Models\User;
use Closure;

/**
 * [2026-11-18 · audit E2E] Qui peut modifier la STRUCTURE de l'hygiène : le plan de nettoyage, les friteuses, les
 * équipements de température et leurs seuils. Les saisies du quotidien (relevé, validation, contrôle, annulation
 * motivée) restent ouvertes à tout le personnel.
 *
 * POURQUOI PAS « permission:settings » seul. Le premier jet reprenait la règle des écrans Équipe et Alertes
 * (`settings`). Or le rôle « Branch Manager » n'a pas ce droit (RolePermissionTableSeeder) : le responsable du
 * restaurant aurait perdu la main sur son propre plan, avec un écran qui lui dit « réservé à un responsable ».
 * La structure est donc ouverte à qui porte `settings` OU un rôle de responsable, comparé par NOM (les
 * identifiants de rôles diffèrent d'une base à l'autre). Un caissier (« POS Operator ») reste refusé.
 */
final class StructureAccess
{
    public const MANAGER_ROLES = ['Admin', 'Tenant Admin', 'Branch Manager'];

    public const REFUSAL = 'Réservé à un responsable : demandez-lui de faire ce réglage.';

    public static function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can('settings') || $user->hasAnyRole(self::MANAGER_ROLES);
    }

    /** Middleware de contrôleur : `$this->middleware(StructureAccess::guard())->only([...])`. */
    public static function guard(): Closure
    {
        return static function ($request, Closure $next) {
            abort_unless(self::allows($request->user()), 403, self::REFUSAL);

            return $next($request);
        };
    }
}
