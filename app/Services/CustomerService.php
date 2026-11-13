<?php

namespace App\Services;

use Exception;
use App\Enums\Ask;
use App\Models\User;
use App\Enums\Role as EnumRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\CustomerRequest;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Http\Requests\ChangeImageRequest;
use App\Http\Requests\UserChangePasswordRequest;


class CustomerService
{
    public object $user;
    public array $phoneFilter = ['phone'];
    public array $roleFilter = ['role_id'];
    public array $userFilter = ['name', 'email', 'username', 'branch_id', 'status', 'phone'];
    public array $blockRoles = [EnumRole::ADMIN];


    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            // [GOAL-pageby-V1.0.2 class-of-bug] Spatie's ->role($int) calls findById($int) (HasRoles L84).
            // Passing EnumRole::CUSTOMER int breaks whenever roles.id AUTO_INCREMENT skipped past it
            // (fresh seed lands at 73-80). Stable identity = role NAME. Pattern from DeliveryBoyService heal (0332e5b7e).
            return User::with('media', 'addresses', 'messages')->role('Customer', 'sanctum')->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->userFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function store(CustomerRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $this->user = User::create([
                    'name'              => $request->name,
                    'email'             => $request->email,
                    'phone'             => $request->phone,
                    'username'          => $this->username($request->email),
                    'password'          => bcrypt($request->password),
                    'branch_id'         => 0,
                    'email_verified_at' => now(),
                    'status'            => $request->status,
                    'country_code'      => $request->country_code,
                    'is_guest'          => Ask::NO,
                ]);
                $this->user->assignRole(EnumRole::CUSTOMER);
            });
            return $this->user;
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(CustomerRequest $request, User $customer)
    {
        // [WAVE5-SEC-001] Verify the route-bound user actually has the expected role
        // BEFORE entering the try/catch (which rewrites everything to 422). The
        // pre-existing $blockRoles tautology never checked the target role, allowing
        // a Branch Manager with `customers_edit` to PUT /api/admin/customer/{any_user_id}
        // and rotate (e.g.) an Admin password. Guard placed at top so the 403 status
        // survives QueryExceptionLibrary::message() rewrap.
        $this->assertTargetRole($customer);

        try {
            if (!in_array(EnumRole::CUSTOMER, $this->blockRoles)) {
                // [AUDIT ABUSIF 2026-11-06 · tour 3 · R3-ADV-002] Même règle que EmployeeService::update : mot de passe ou e-mail
                // changé par un tiers → les sessions ouvertes du compte sont fermées (sinon il reste connecté jusqu'à 8 h).
                $identifiantsChanges = $request->password || mb_strtolower((string) $request->email) !== mb_strtolower((string) $customer->email);
                DB::transaction(function () use ($customer, $request) {
                    $this->user               = $customer;
                    $this->user->name         = $request->name;
                    $this->user->email        = $request->email;
                    $this->user->phone        = $request->phone;
                    $this->user->status       = $request->status;
                    $this->user->country_code = $request->country_code;
                    if ($request->password) {
                        $this->user->password = Hash::make($request->password);
                    }
                    $this->user->save();
                });
                if ($identifiantsChanges && (int) $customer->id !== (int) optional(auth()->user())->id) {
                    $customer->tokens()->delete();
                }
                return $this->user;
            } else {
                throw new Exception(trans('all.message.permission_denied'), 422);
            }
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(User $customer): User
    {
        // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13 / WAVE5-SEC-001]
        // show() never received the role-target guard applied to
        // update/changePassword/changeImage — the check below is a
        // tautology (CUSTOMER is never in blockRoles=[ADMIN]), so any caller
        // with customers_show could read ANY user's record through this
        // endpoint. Must run before the try/catch (which rewrites to 422).
        $this->assertTargetRole($customer);

        try {
            if (!in_array(EnumRole::CUSTOMER, $this->blockRoles)) {
                return $customer;
            } else {
                throw new Exception(trans('all.message.permission_denied'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(User $customer)
    {
        try {
            if (!in_array(EnumRole::CUSTOMER, $this->blockRoles) && $customer->id != 2) {
                // [S18-001] Rôle PRINCIPAL reconnu par son NOM (« Customer »), comme DeactivateController : comparer l'identifiant
                //   (EnumRole::CUSTOMER = 2) casse dès que les rôles n'ont pas été semés dans l'ordre d'origine.
                $premierRole = $customer->roles->first();
                // [test-e2e stores P19-001 · 2026-11-13] Un compte qui porte AUSSI un rôle d'exploitation (Admin + Customer…) n'est pas
                //   anonymisé par cette porte : l'effacement est irréversible. Seul un compte dont TOUS les rôles sont « Customer ».
                $seulementClient = $premierRole && $premierRole->name === 'Customer'
                    && $customer->roles->every(fn ($r) => $r->name === 'Customer');
                if ($seulementClient) {
                    // [P19-003] Même règle que la suppression faite par le client : une commande encore en cours (livraison en route,
                    //   cuisine…) bloque — sinon le code d'entrée et le téléphone disparaissaient pendant la livraison.
                    // [P19-002 / S19-001] Jeton Apple lu AVANT la transaction, révoqué chez Apple APRÈS — comme DeactivateController.
                    $jetonApple = null;
                    try { $jetonApple = $customer->apple_refresh_token; } catch (\Throwable) { $jetonApple = null; }
                    // [test-e2e stores S18-001 · 2026-11-13] Supprimer un client depuis l'administration (demande d'effacement reçue à
                    //   rgpd@…) EFFACE réellement ses données, comme la suppression faite par le client lui-même : avant, une simple
                    //   suppression douce laissait nom, e-mail, téléphone, identifiants Apple/Google et abonnement aux offres — et la
                    //   connexion invité suivante le « ressuscitait ». Même service que DeactivateController : les deux ne divergent plus.
                    // [P20-002] Annulations et contrôle DANS la transaction : un refus annule aussi les annulations.
                    DB::transaction(function () use ($customer) {
                        // [P20-002] Comme pour le client : ce qu'il aurait pu annuler lui-même (jamais commencé, jamais payé) est annulé
                        //   d'abord ; seule une commande réellement en cours (commencée ou réglée) bloque.
                        // [supervision D1] Verrou du client, puis refus AVANT toute annulation (aucune notification pour rien).
                        \App\Models\User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->whereKey($customer->id)->lockForUpdate()->first(['id']);
                        if (app(\App\Services\FrontendOrderService::class)->aUneCommandeBloquantePourSuppression((int) $customer->id)) {
                            throw new Exception('Ce client a une commande en cours (en préparation ou déjà réglée) : supprimez son compte une fois la commande remise.', 422);
                        }
                        app(\App\Services\FrontendOrderService::class)->cancelAllOwnCancellableForAccountDeletion(
                            (int) $customer->id,
                            'Suppression du compte par l\'administration'
                        );
                        $enCours = \App\Models\Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->where('user_id', $customer->id)
                            ->whereNotIn('status', [\App\Enums\OrderStatus::DELIVERED, \App\Enums\OrderStatus::CANCELED, \App\Enums\OrderStatus::REJECTED, \App\Enums\OrderStatus::RETURNED])
                            ->exists();
                        if ($enCours) {
                            throw new Exception('Ce client a une commande en cours (en préparation ou déjà réglée) : supprimez son compte une fois la commande remise.', 422);
                        }
                        $customer->addresses()->delete();
                        $anonymisation = app(\App\Services\Account\AnonymisationCompte::class);
                        $anonymisation->effacerResidus((int) $customer->id);
                        $anonymisation->anonymiserIdentite($customer);
                        $customer->tokens()->delete();
                        $customer->delete();
                    });
                    if (filled($jetonApple)) {
                        app(\App\Services\Auth\AppleTokenRevoker::class)->revoquer((string) $jetonApple);
                    }
                } else {
                    throw new Exception(trans('all.message.permission_denied'), 422);
                }
            } else {
                throw new Exception(trans('all.message.permission_denied'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            DB::rollBack();
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    private function username($email): string
    {
        $emails = explode('@', $email);
        return $emails[0] . mt_rand();
    }

    /**
     * [WAVE5-SEC-001] Defense-in-depth: ensure the route-bound User is actually a
     * Customer before any mutation. The legacy `$blockRoles = [ADMIN]` tautology
     * never checked the target's role, allowing privilege escalation.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 403
     */
    private function assertTargetRole(User $customer): void
    {
        if (! $customer->hasRole(EnumRole::CUSTOMER)) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(
                403,
                'Cannot mutate user outside expected role.'
            );
        }
    }

    /**
     * @throws Exception
     */
    public function changePassword(UserChangePasswordRequest $request, User $customer): User
    {
        // [WAVE5-SEC-001] See update() comment — same role-target guard.
        $this->assertTargetRole($customer);

        try {
            if (!in_array(EnumRole::CUSTOMER, $this->blockRoles)) {
                $customer->password = Hash::make($request->password);
                $customer->save();

                // [MULTI-DEVICE 2026-08-07 — régression fermée] Réinitialiser un
                // mot de passe sert le plus souvent à COUPER un accès. Ce chemin
                // s'appuyait implicitement sur la révocation totale que
                // `LoginController` faisait à la reconnexion suivante ; en la
                // scopant à l'appareil pour permettre le multi-terminaux, ce
                // garde-fou a disparu. On révoque donc explicitement.
                $customer->tokens()->delete();

                return $customer;
            } else {
                throw new Exception(trans('all.message.permission_denied'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function changeImage(ChangeImageRequest $request, User $customer): User
    {
        // [WAVE5-SEC-001] See update() comment — same role-target guard.
        $this->assertTargetRole($customer);

        try {
            if (!in_array(EnumRole::CUSTOMER, $this->blockRoles)) {
                if ($request->image) {
                    $customer->clearMediaCollection('profile');
                    $customer->addMediaFromRequest('image')->toMediaCollection('profile');
                }
                return $customer;
            } else {
                throw new Exception(trans('all.message.permission_denied'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
