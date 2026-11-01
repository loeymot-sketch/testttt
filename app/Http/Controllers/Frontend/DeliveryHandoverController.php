<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Auth\DeviceTokenService;
use App\Services\Auth\HandoverTokenPolicy;
use App\Services\Delivery\DeliveryHandoverService;
use App\Services\Delivery\HandoverRefused;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    /**
     * Hachage factice comparé quand le compte n'existe pas : le temps de réponse ne dit pas « compte inconnu ». Il DOIT avoir le
     * même coût que les vrais hachages (config/hashing.php) : un coût 10 contre 12 donnait 60 ms contre 239 ms (relecture adverse C).
     * Fabriqué une fois par processus (et mémorisé en cache, clé par coût) : le recalculer à chaque requête coûterait ce qu'on cache.
     */
    private static ?string $dummyHash = null;

    public static function dummyHash(): string
    {
        $cost = (int) config('hashing.bcrypt.rounds', 12);
        if (self::$dummyHash !== null && (int) (Hash::info(self::$dummyHash)['options']['cost'] ?? 0) === $cost) {
            return self::$dummyHash;
        }
        try {
            $cached = Cache::get('delivery-handover.dummy-hash.'.$cost);
        } catch (\Throwable $e) {
            $cached = null;
        }
        if (! is_string($cached) || (int) (Hash::info($cached)['options']['cost'] ?? 0) !== $cost) {
            $cached = Hash::make(Str::random(40));
            try {
                Cache::forever('delivery-handover.dummy-hash.'.$cost, $cached);
            } catch (\Throwable $e) {
                // sans cache : la valeur reste mémorisée pour le processus
            }
        }

        return self::$dummyHash = $cached;
    }

    public function login(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');
        if (! is_string($email) || ! is_string($password) || $email === '' || $password === '' || strlen($email) > 255 || strlen($password) > 1024) {
            return $this->badCredentials();
        }

        $user = User::query()->withoutGlobalScope(BranchScope::class)->where('email', $email)->first();
        $passwordOk = Hash::check($password, $user ? (string) $user->password : self::dummyHash());
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

    /** Où la page renvoie le livreur après « Remis » : une constante, jamais une valeur venue de la requête. */
    public const NEXT_URL = '/livreur';

    /** Lit l'arrêt derrière le QR. Réponse IDENTIQUE pour un jeton inconnu, mort ou qui n'est pas celui de ce livreur. */
    public function resolve(Request $request): JsonResponse
    {
        $token = $request->input('token');
        try {
            if (! is_string($token)) {
                throw HandoverRefused::unavailable();
            }

            return response()->json(['status' => true, 'data' => app(DeliveryHandoverService::class)->resolve($request->user(), $token)]);
        } catch (HandoverRefused $e) {
            return $this->refusal($e);
        }
    }

    /** « Remis » : une transaction, un jeton brûlé, la remise officielle, la trace. Rejouable sans effet pour le même livreur. */
    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = Validator::make($request->all(), [
            'token'           => ['required', 'string', 'max:64'],
            'declared_mode'   => ['nullable', 'string', 'max:16'],
            'collected_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'lat'             => ['nullable', 'numeric', 'between:-90,90'],
            'lng'             => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy'        => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        if ($v->fails()) {
            return response()->json(['status' => false, 'code' => 'invalid', 'message' => 'Données invalides : vérifiez le mode, le montant et la position.'], 422);
        }
        $in = $v->validated();
        $in['device_id'] = $user->currentAccessToken()?->device_id;
        $in['ip'] = (string) $request->ip();
        $in['user_agent'] = (string) $request->userAgent();
        $service = app(DeliveryHandoverService::class);
        $ids = ['order_id' => null, 'stop_id' => null, 'token_id' => null];

        try {
            $result = $service->confirm($user, (string) $in['token'], $in);

            return response()->json(['status' => true, 'already' => $result['already'], 'data' => $result['data'], 'next_url' => self::NEXT_URL]);
        } catch (HandoverRefused $e) {
            $this->audit('delivery.handover_refused', $user, $request, [
                'reason' => $e->errorCode, 'order_id' => $e->orderId, 'stop_id' => $e->stopId, 'token_id' => $e->tokenId,
                'declared_mode' => $in['declared_mode'] ?? null,
            ], $e->orderId);

            return $this->refusal($e);
        } catch (HttpException $e) { // refus de la voie officielle (OrderService::abort) : la transaction est annulée, le jeton intact
            $this->audit('delivery.handover_refused', $user, $request, ['reason' => 'order_service', 'detail' => substr($e->getMessage(), 0, 160)]);

            return response()->json(['status' => false, 'code' => 'caisse_required', 'message' => 'Cette commande ne peut pas être soldée à la porte : renvoyez-la à la caisse.'], $e->getStatusCode() >= 400 ? $e->getStatusCode() : 422);
        } catch (\Throwable $e) {
            Log::error('delivery.handover.confirm_failed', ['user_id' => $user->id, 'error' => $e->getMessage(), 'class' => get_class($e)]);

            return response()->json(['status' => false, 'code' => 'error', 'message' => 'La remise n\'a pas pu être enregistrée complètement. Touchez de nouveau « Remis » : rien ne sera encaissé deux fois.'], 500);
        }
    }

    private function refusal(HandoverRefused $e): JsonResponse
    {
        return response()->json(['status' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()], $e->httpStatus);
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
