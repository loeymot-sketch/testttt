<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Scopes\BranchScope;
use App\Models\UberDirectDelivery;
use App\Services\UberDirect\UberDirectDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * [UBER-DIRECT 2026-10-22] Statuts de course envoyés par Uber Direct (`event.delivery_status`).
 *
 * ⚠️ Ce n'est PAS le webhook de la marketplace (`UberWebhookController`) : autre produit, autre clé de signature.
 *
 * Sécurité : signature HMAC-SHA256 du corps BRUT (en-tête `X-Uber-Signature`), comparaison à temps constant ;
 * sans clé configurée tout est REFUSÉ (fail-closed). Le corps n'est jamais reformaté avant la vérification.
 * Un événement rejoué ou tardif est sans effet (`applyStatus` ne rouvre jamais une course terminée).
 */
class UberDirectWebhookController extends Controller
{
    public function __construct(private readonly UberDirectDispatchService $dispatch)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        if (! $this->signatureValid($request, $raw)) {
            return response()->json(['status' => false], 401);
        }

        $body = json_decode($raw, true);
        if (! is_array($body)) {
            return response()->json(['status' => false], 400);
        }

        $deliveryId = (string) ($body['delivery_id'] ?? data_get($body, 'data.id') ?? '');
        $status = (string) ($body['status'] ?? data_get($body, 'data.status') ?? '');
        if ($deliveryId === '' || $status === '') {
            // Événement d'un autre type (ex. courier_imminent sans statut) : accusé, rien à faire.
            return response()->json(['status' => true, 'ignored' => true]);
        }

        $row = UberDirectDelivery::query()->withoutGlobalScope(BranchScope::class)->where('delivery_id', $deliveryId)->first();
        if (! $row) {
            // Course inconnue (autre environnement, ou course créée hors de la caisse) : accusée pour qu'Uber ne réessaie pas.
            Log::info('uber_direct.webhook.unknown_delivery');

            return response()->json(['status' => true, 'ignored' => true]);
        }

        $this->dispatch->applyStatus($row, $status, is_array($body['data'] ?? null) ? $body['data'] : null);

        return response()->json(['status' => true]);
    }

    private function signatureValid(Request $request, string $raw): bool
    {
        $secret = trim((string) config('uber_direct.webhook_secret', ''));
        // [E2E UBER 2026-10-24 · U2-004] Le nom de l'en-tête n'a jamais été confronté à un vrai Uber (le faux Uber utilisé par les bancs
        // reprenait notre propre hypothèse : preuve circulaire). Uber Direct signe historiquement sous `X-Postmates-Signature`,
        // la marketplace sous `X-Uber-Signature` : on accepte les deux — le HMAC-SHA256 du corps brut reste, lui, strictement vérifié.
        $provided = (string) ($request->header('X-Uber-Signature') ?: $request->header('X-Postmates-Signature', ''));
        if ($secret === '' || $provided === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $raw, $secret), strtolower($provided));
    }
}
