<?php

namespace App\Http\Controllers\Auth;


use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\MenuResource;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\UserResource;
use App\Libraries\AppLibrary;
use App\Models\Branch;
use App\Models\User;
use App\Services\DefaultAccessService;
use App\Services\MenuService;
use App\Services\Fiscal\AuditLogService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Smartisan\Settings\Facades\Settings;

class LoginController extends Controller
{
    public string $token;
    public DefaultAccessService $defaultAccessService;
    public PermissionService $permissionService;
    public MenuService $menuService;

    public function __construct(
        MenuService $menuService,
        PermissionService $permissionService,
        DefaultAccessService $defaultAccessService
    ) {
        $this->menuService          = $menuService;
        $this->permissionService    = $permissionService;
        $this->defaultAccessService = $defaultAccessService;
    }

    /**
     * @throws \Exception
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => ['required', 'string', 'email', 'max:255'],
            // [GOAL-HEAL-SEC-002 2026-05-23] OWASP guidance: don't expose password
            // policy at login (information leak). bcrypt + rate-limiter enforce
            // correctness server-side. Min:6 here was Laravel scaffold legacy drift
            // — EmployeeRequest creation enforces Password::min(12) for new users;
            // legacy users with weak passwords will be forced to rotate via V1.0.2
            // post-login password-rotation prompt.
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return new JsonResponse([
                'errors' => $validator->errors()
            ], 422);
        }

        $request->merge(['status' => Status::ACTIVE]);

        if (!Auth::guard('web')->attempt($request->only('email', 'password', 'status'))) {
            // [SEC-LOGIN-TIMING heal 2026-06-15 — ultra-review] Constant-time on the
            // failed-login path. Auth::attempt() runs Hash::check ONLY when a user is
            // found; for an unknown email it returns fast (no bcrypt) → a username-
            // enumeration timing oracle (~35ms). Burn an equivalent bcrypt verification
            // against a fixed dummy hash so the response time no longer branches on
            // account existence. Mirrors the kiosk-login (#3) and forgot-password (UA-P1)
            // hardening. The result is discarded — this is timing-only.
            if (!\App\Models\User::where('email', $request->input('email'))->exists()) {
                \Illuminate\Support\Facades\Hash::check(
                    (string) $request->input('password'),
                    '$2y$12$iCTCxFekHaXKl6P5xGvk6eZOWy6HTwGk6DCXW5xj8MuK104fpZh2W'
                );
            }

            return new JsonResponse([
                'errors' => ['validation' => trans('all.message.credentials_invalid')]
            ], 400);
        }

        $branchId = (int) Auth::user()->branch_id;
        if ($branchId === 0) {
            $branchId = (int) (Settings::group('site')->get('site_default_branch') ?: 0);
        }
        if ($branchId === 0) {
            $branchId = (int) (Branch::query()->orderBy('id')->value('id') ?: 0);
        }
        if ($branchId === 0) {
            Auth::guard('web')->logout();

            return new JsonResponse([
                'errors' => ['validation' => trans('all.message.composer.preview_unavailable')],
            ], 422);
        }
        $this->defaultAccessService->storeOrUpdate(['branch_id' => $branchId]);

        // API SPA : fermer la session web après attempt(). Sinon, les requêtes suivantes avec
        // Bearer sont résolues par Sanctum via la session → TransientToken, et logout ne peut
        // pas révoquer le PersonalAccessToken.
        $user = User::where('email', $request['email'])->first();

        // [V1.0.1 RED-team P1 — Wave 5G 2026-05-17] Transparent bcrypt rounds
        // upgrade on login. config/hashing.php was bumped 10 → 12 but existing
        // hashes stay at their original cost factor forever unless rehashed.
        // We have the plain password in $request['password'] at this exact
        // moment (Auth::attempt already validated it) so we rehash silently
        // if Hash::needsRehash() reports a stale cost. Zero impact on UX —
        // login succeeds either way. The check uses the current config rounds,
        // so once a user is upgraded to 12 they are not re-saved on next login.
        if (Hash::needsRehash($user->password)) {
            $user->password = Hash::make($request['password']);
            $user->save();
        }

        // [H.1 P2 AMBER 2026-05-24 / H2-HEAL-02] NF525 6-year traceability:
        // append user.login event on the HMAC chain BEFORE Auth::guard('web')
        // ->logout() so user_id + branch_id are still resolvable and Auth::user()
        // is still populated for AuditLogService's defaulting logic. We also
        // pass branch_id/user_id explicitly so the row stays on the correct
        // tenant chain even if a future refactor moves this call after logout().
        // Failure to write must NOT block the already-validated login — wrap
        // in try/catch + Log::warning so a transient cache/DB hiccup doesn't
        // lock the cashier out.
        try {
            app(AuditLogService::class)->write([
                'branch_id'   => (int) $branchId,
                'user_id'     => (int) $user->id,
                'action'      => 'user.login',
                'resource'    => 'user',
                'resource_id' => (int) $user->id,
                'payload'     => [
                    'user_id'    => (int) $user->id,
                    'user_email' => (string) ($user->email ?? ''),
                    'branch_id'  => (int) $branchId,
                    'ip'         => (string) ($request->ip() ?? ''),
                    'user_agent' => substr((string) $request->userAgent(), 0, 512),
                    'role'       => isset($user->roles[0]) ? (string) $user->roles[0]->name : null,
                ],
                'ip'         => (string) ($request->ip() ?? null),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
            ]);
        } catch (\Throwable $auditEx) {
            // Non-blocking: login already validated. Surface to log-stream for
            // SIEM but never throw — a cashier locked out by an audit-chain
            // hiccup is worse than a missing audit row in a single login event.
            Log::warning('[H2-HEAL-02] user.login audit write failed', [
                'user_id'   => (int) $user->id,
                'branch_id' => (int) $branchId,
                'error'     => $auditEx->getMessage(),
            ]);
        }

        Auth::guard('web')->logout();

        // [Sprint 5D Z6-01 2026-05-16] Revoke prior `auth_token` rows on relogin
        // to prevent token sprawl. CLAUDE.md §9 explicitly required this; the
        // original LoginController issued a fresh token every login without
        // invalidating the previous one, leaving stale tokens valid for the
        // full 480-min TTL after a user logged in from a second device or
        // re-authenticated after a password change. Scoped by name so we
        // never touch kiosk:order tokens (different name, separate concern).
        $user->tokens()->where('name', 'auth_token')->delete();

        $this->token = $user->createToken(
            'auth_token',
            ['*'],
            now()->addMinutes((int) config('sanctum.expiration', 480))
        )->plainTextToken;

        if (!isset($user->roles[0])) {
            return new JsonResponse([
                'errors' => ['validation' => trans('all.message.role_exist')]
            ], 400);
        }

        $permission        = PermissionResource::collection($this->permissionService->permission($user->roles[0]));
        $menus             = MenuResource::collection(collect($this->menuService->menu($user->roles[0])));
        $defaultPermission = AppLibrary::defaultPermission($permission);
        $defaultMenu       = (object)AppLibrary::defaultMenu($this->menuService->menu($user->roles[0]), $defaultPermission);

        if ($user->roles->count() > 0 && !empty($user->roles[0]->landing_url)) {
            $landingUrl = $user->roles[0]->landing_url;
            if (preg_match('/^[a-zA-Z0-9\-_\/]*$/', $landingUrl)) {
                $defaultPermission->url = $landingUrl;
            }
        }

        return new JsonResponse([
            'message'           => trans('all.message.login_success'),
            'token'             => $this->token,
            'branch_id'         => (int)$user->branch_id,
            'user'              => new UserResource($user),
            'menu'              => $menus,
            'permission'        => $permission,
            'defaultPermission' => $defaultPermission,
            'defaultMenu'       => $defaultMenu,
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user?->currentAccessToken();

        // [H.1 P2 AMBER 2026-05-24 / H2-HEAL-02] Capture identity BEFORE the
        // token is destroyed so we can still attribute the user.logout audit
        // row to the right user + branch (defense in depth: even though
        // $request->user() stays in cache after $current->delete(), pinning
        // the values now removes any future refactor risk).
        $userIdForAudit = $user ? (int) $user->getAuthIdentifier() : null;
        $branchIdForAudit = $user ? (int) ($user->branch_id ?? 0) : 0;
        $userEmailForAudit = $user ? (string) ($user->email ?? '') : '';

        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        } elseif ($user && ($bearer = $request->bearerToken())) {
            $accessToken = PersonalAccessToken::findToken($bearer);
            if ($accessToken
                && (int) $accessToken->tokenable_id === (int) $user->getAuthIdentifier()
                && $accessToken->tokenable_type === $user->getMorphClass()) {
                $accessToken->delete();
            }
        }

        // [H.1 P2 AMBER 2026-05-24 / H2-HEAL-02] NF525 6-year traceability:
        // append user.logout event on the HMAC chain. Pass branch_id + user_id
        // EXPLICITLY (we captured them above) — by this point Auth::user()
        // may be unresolvable (no session, token deleted). Same try/catch
        // pattern as user.login: a failed audit write must never break logout
        // (the token is already destroyed, the user is functionally logged out).
        if ($userIdForAudit !== null && $branchIdForAudit > 0) {
            try {
                app(AuditLogService::class)->write([
                    'branch_id'   => $branchIdForAudit,
                    'user_id'     => $userIdForAudit,
                    'action'      => 'user.logout',
                    'resource'    => 'user',
                    'resource_id' => $userIdForAudit,
                    'payload'     => [
                        'user_id'    => $userIdForAudit,
                        'user_email' => $userEmailForAudit,
                        'branch_id'  => $branchIdForAudit,
                        'ip'         => (string) ($request->ip() ?? ''),
                        'user_agent' => substr((string) $request->userAgent(), 0, 512),
                    ],
                    'ip'         => (string) ($request->ip() ?? null),
                    'user_agent' => substr((string) $request->userAgent(), 0, 512),
                ]);
            } catch (\Throwable $auditEx) {
                Log::warning('[H2-HEAL-02] user.logout audit write failed', [
                    'user_id'   => $userIdForAudit,
                    'branch_id' => $branchIdForAudit,
                    'error'     => $auditEx->getMessage(),
                ]);
            }
        }

        return new JsonResponse([
            'message' => trans('all.message.logout_success')
        ], 200);
    }
}
