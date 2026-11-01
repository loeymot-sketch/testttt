<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Auth\DeviceTokenService;
use App\Services\Auth\HandoverTokenPolicy;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * [LIVRAISON QR 2026-11-01] Remise par QR : connexion dédiée, lecture de l'arrêt, validation.
 *
 * La page /livreur/remise ne reçoit RIEN avant la connexion. La connexion ici ne ressemble pas à `/api/auth/login` :
 * elle refuse tout compte qui n'est pas EXACTEMENT « Delivery Boy » AVANT d'émettre le moindre jeton, et le jeton émis est
 * court, d'un nom et d'une aptitude à part — il ne chasse donc pas la session /livreur du même compte et n'ouvre aucune
 * autre route ({@see HandoverTokenPolicy}).
 */
class DeliveryHandoverController extends Controller
{
    public const SESSION_TTL_MINUTES = 15;
    public const ROLE = 'Delivery Boy';

    /** Hachage valide quelconque, comparé quand le compte n'existe pas : le temps de réponse ne dit pas « compte inconnu ». */
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function login(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');
        if (! is_string($email) || ! is_string($password) || $email === '' || $password === '' || strlen($email) > 255 || strlen($password) > 1024) {
            return $this->badCredentials();
        }

        $user = User::query()->withoutGlobalScope(BranchScope::class)->where('email', $email)->first();
        $passwordOk = Hash::check($password, $user ? (string) $user->password : self::DUMMY_HASH);
        // Mauvais mot de passe, compte inconnu ou désactivé : UNE seule réponse (aucune énumération de comptes).
        if (! $user || ! $passwordOk || (int) $user->status !== Status::ACTIVE) {
            return $this->badCredentials();
        }

        // À partir d'ici le mot de passe est prouvé : on peut dire « ce n'est pas un compte livreur » sans rien énumérer.
        $roles = $user->roles()->pluck('name')->all();
        if ($roles !== [self::ROLE]) {
            $this->audit('delivery.handover_refused', $user, $request, ['reason' => 'role', 'role_count' => count($roles)]);

            return response()->json(['status' => false, 'message' => 'Ce compte n\'est pas un compte livreur.'], 403);
        }
        if ((int) $user->branch_id <= 0) {
            $this->audit('delivery.handover_refused', $user, $request, ['reason' => 'branch']);

            return response()->json(['status' => false, 'message' => 'Ce compte livreur n\'est rattaché à aucun restaurant. Voyez la caisse.'], 403);
        }

        // Jetons de remise périmés de ce compte : ménage (ils ne servent plus et ne comptent pas dans le plafond d'appareils).
        $user->tokens()->where('name', HandoverTokenPolicy::TOKEN_NAME)->where('expires_at', '<', now())->delete();

        $issued = app(DeviceTokenService::class)->issueForDevice(
            $user,
            HandoverTokenPolicy::TOKEN_NAME,
            [HandoverTokenPolicy::ABILITY],
            $request,
            self::SESSION_TTL_MINUTES,
            'handover-'.Str::random(24), // un identifiant unique : deux scans ne s'évincent pas, et /livreur (auth_token) n'est jamais touché
            'Remise QR'
        );

        $this->audit('delivery.handover_login', $user, $request, ['device_id' => $issued->accessToken->device_id]);

        return response()->json([
            'token'      => $issued->plainTextToken,
            'expires_in' => self::SESSION_TTL_MINUTES * 60,
            'user'       => ['name' => $user->name],
        ], 201);
    }

    private function badCredentials(): JsonResponse
    {
        return response()->json(['status' => false, 'message' => 'Identifiants incorrects.'], 422);
    }

    /** Trace chaînée (NF525) ; une panne d'audit ne bloque jamais la remise. */
    private function audit(string $action, User $user, Request $request, array $payload = [], ?int $resourceId = null): void
    {
        try {
            app(AuditLogService::class)->write([
                'branch_id'   => (int) $user->branch_id,
                'user_id'     => (int) $user->id,
                'action'      => $action,
                'resource'    => 'delivery_handover',
                'resource_id' => $resourceId ?? (int) $user->id,
                'payload'     => $payload + ['delivery_boy_id' => (int) $user->id, 'via' => 'qr'],
                'ip'          => (string) ($request->ip() ?? ''),
                'user_agent'  => substr((string) $request->userAgent(), 0, 512),
            ]);
        } catch (\Throwable $e) {
            Log::warning('delivery.handover.audit_failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
