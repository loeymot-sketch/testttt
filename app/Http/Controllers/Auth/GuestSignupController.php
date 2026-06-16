<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Http\Requests\GuestSignupPhoneRequest;
use App\Http\Resources\MenuResource;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\UserResource;
use App\Libraries\AppLibrary;
use App\Services\DefaultAccessService;
use App\Services\MenuService;
use App\Services\PermissionService;
use Carbon\Carbon;
use Exception;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Enums\Role as EnumRole;
use App\Services\OtpManagerService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\VerifyPhoneRequest;
use Smartisan\Settings\Facades\Settings;

class GuestSignupController extends Controller
{
    private OtpManagerService $otpManagerService;
    public string $token;
    public DefaultAccessService $defaultAccessService;
    public PermissionService $permissionService;
    public MenuService $menuService;

    public function __construct(
        OtpManagerService $otpManagerService,
        MenuService $menuService,
        PermissionService $permissionService,
        DefaultAccessService $defaultAccessService
    ) {
        $this->otpManagerService    = $otpManagerService;
        $this->menuService          = $menuService;
        $this->permissionService    = $permissionService;
        $this->defaultAccessService = $defaultAccessService;
    }


    public function otp(GuestSignupPhoneRequest $request
    ) : \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $this->otpManagerService->otp($request);
            return response(['status' => true, 'message' => trans("all.message.check_your_phone_for_code")]);
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function verify(VerifyPhoneRequest $request)
    {
        try {
            if (Settings::group('site')->get('site_phone_verification') == Activity::DISABLE) {
                $otp = DB::table('otps')->where([
                    ['phone', $request->post('phone')]
                ]);
                $otp?->delete();
                return $this->register(
                    ['code' => $request->code, 'phone' => $request->phone, 'token' => $request->token]
                );
            } elseif ($this->otpManagerService->verify($request) && Settings::group('site')->get(
                    'site_phone_verification'
                ) == Activity::ENABLE) {
                return $this->register(
                    ['code' => $request->code, 'phone' => $request->phone, 'token' => $request->token]
                );
            }
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json([
            'status'  => false,
            'message' => trans('all.message.code_is_invalid'),
        ], 422);
    }

    private function register($array) : JsonResponse
    {

        if (Settings::group('site')->get('site_guest_login') == Activity::DISABLE) {
            throw new Exception(trans('all.message.guest_login_is_not_allowed'), 422);
         }

        // [GAP-32-3] Use withoutGlobalScopes + withTrashed to find ALL accounts with this phone,
        // including soft-deleted users and users from other branches (BranchScope).
        // Without this, a deleted or out-of-scope account causes a duplicate to be created.
        $user = User::withoutGlobalScopes()->withTrashed()->where('phone', $array['phone'])->first();

        // [SECURITY] If the phone matches a non-guest account (staff, admin, manager),
        // refuse to issue a guest token. OTP alone must not grant access to privileged accounts.
        if ($user && $user->is_guest != Ask::YES && !$user->trashed()) {
            // The phone belongs to a staff/admin account — do not allow guest login
            throw new \Exception(trans('all.message.credentials_invalid'), 422);
        }

        // Restore soft-deleted guest account instead of creating a duplicate
        if ($user && $user->trashed()) {
            $user->restore();
            $user->status = \App\Enums\Status::ACTIVE;
            $user->save();
        }

        if (!$user) {
            $name = "Guest User";
            $user = User::create([
                'name'              => $name,
                'username'          => Str::slug($name) . \Illuminate\Support\Str::random(5),
                'phone'             => $array['phone'],
                'country_code'      => $array['code'],
                'branch_id'         => 0,
                'email_verified_at' => Carbon::now()->getTimestamp(),
                'is_guest'          => Ask::YES,
                'password'          => Hash::make(\Illuminate\Support\Str::random(10))
            ]);
            $user->assignRole(EnumRole::CUSTOMER);
        }

        if ($user) {
            Auth::guard('web')->loginUsingId($user->id);
            $branchId = Auth::user()->branch_id;
            if (Auth::user()->branch_id == 0) {
                $branchId = Settings::group('site')->get('site_default_branch');
            }

            $this->defaultAccessService->storeOrUpdate(['branch_id' => $branchId]);
            // [GAP-20-5] Guest tokens expire after 30 days. Without expiry, a token issued
            // to a kiosk customer or anonymous web visitor remains valid indefinitely,
            // creating a growing attack surface. 30 days matches typical session expectations.
            // [Sprint H1 Z6-02 2026-05-17] Scope ability to `kiosk:order` only — not
            // wildcard. CLAUDE.md §9 + Wave Z RED-team: a leaked guest token must not
            // be replayable against admin endpoints. Guest UI consumes only
            // `/api/frontend/order/*` which requires `tokenCan('kiosk:order')`
            // (see OrderRequest.php:46-47: both `['*']` and `['kiosk:order']`
            // satisfy that check — the narrow scope is strictly sufficient).
            $this->token = $user->createToken('auth_token', ['kiosk:order'], now()->addDays(30))->plainTextToken;

            // [FIX] Guard against user with no roles (should not happen, but defensive)
            $firstRole = $user->roles->first();
            if (!$firstRole) {
                $user->assignRole(EnumRole::CUSTOMER);
                $user->refresh();
                $firstRole = $user->roles->first();
            }

            $permission        = PermissionResource::collection($this->permissionService->permission($firstRole));
            $defaultPermission = AppLibrary::defaultPermission($permission);

            return new JsonResponse([
                'message'           => trans('all.message.login_success'),
                'token'             => $this->token,
                'branch_id'         => (int)$user->branch_id,
                'user'              => new UserResource($user),
                'menu'              => MenuResource::collection(collect($this->menuService->menu($firstRole))),
                'permission'        => $permission,
                'defaultPermission' => $defaultPermission,
            ], 201);
        }
        return new JsonResponse([
            'status'  => false,
            'message' => trans('all.message.credentials_invalid'),
        ], 422);
    }
}
