<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\PaymentGateways\Gateways\Mollie;
use App\Models\FrontendOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * [W5 STRUCTURE Mollie — GOAL_ULTRA_SYNC_STRUCTURE_2026-07-20]
 *
 * POST /api/frontend/order/{frontendOrder}/mollie-checkout
 * (zone frontend existante : installed + apiKey + localization + auth:sanctum,
 * throttle dédié au niveau route).
 *
 * Crée le paiement Mollie d'une commande UNPAID du client authentifié et
 * renvoie l'URL de checkout hébergée. FAIL-CLOSED : 503 « Mollie non
 * configuré » tant que flag/clé absents (gate owner G-W5).
 *
 * Le montant envoyé à Mollie est le TOTAL SCELLÉ BACKEND (jamais un montant
 * client) — voir Mollie::createPayment. La commande n'est JAMAIS marquée
 * PAID ici : seul le webhook (vérité re-fetchée) le fait.
 */
class MolliePaymentController extends Controller
{
    public function checkout(FrontendOrder $frontendOrder, Request $request): JsonResponse
    {
        $gateway = new Mollie();

        if (!$gateway->isMollieConfigured()) {
            return response()->json([
                'status'  => false,
                'message' => 'Mollie non configuré.',
            ], 503);
        }

        $authenticatedUserId = (int) ($request->user('sanctum')?->id ?? $request->user()?->id ?? Auth::id() ?? 0);
        if ($authenticatedUserId <= 0) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated'], 401);
        }

        // Propriété : un client ne peut lancer un checkout QUE sur sa commande.
        if ((int) $frontendOrder->user_id !== $authenticatedUserId) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        // Funnel carte web uniquement (paymentMethod=4 côté site).
        if ((int) $frontendOrder->payment_method !== PaymentGateway::CARD) {
            return response()->json([
                'status'  => false,
                'message' => 'Cette commande n\'attend pas un paiement carte en ligne.',
            ], 422);
        }

        if ((int) $frontendOrder->payment_status === PaymentStatus::PAID) {
            return response()->json([
                'status'  => false,
                'message' => 'Commande déjà payée.',
            ], 409);
        }

        if ((int) $frontendOrder->payment_status !== PaymentStatus::UNPAID) {
            return response()->json([
                'status'  => false,
                'message' => 'Cette commande doit être encaissée en caisse.',
            ], 422);
        }

        if (in_array((int) $frontendOrder->status, [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED], true)) {
            return response()->json([
                'status'  => false,
                'message' => 'Commande annulée — paiement impossible.',
            ], 422);
        }

        // [OWNER 2026-08-01 · PAIEMENT DANS LA PAGE] Token à usage unique produit par Mollie
        // Components : la carte est saisie SUR notre page, jamais sur un site tiers. Le token
        // n'est ni un numéro de carte ni un montant — le total reste scellé backend.
        $cardToken = (string) $request->input('card_token', '');
        if ($cardToken !== '' && !preg_match('/^[A-Za-z0-9_\-]{8,190}$/', $cardToken)) {
            return response()->json(['status' => false, 'message' => 'Jeton carte invalide.'], 422);
        }

        try {
            $created = $gateway->createPayment($frontendOrder, $cardToken);
        } catch (Throwable $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 502);
        }

        $inline = (bool) ($created['inline'] ?? false);
        $mollieStatus = (string) ($created['status'] ?? '');
        $checkoutUrl = $created['checkout_url'] ?: null;

        // [OWNER 2026-08-04 P1-B] Raison honnête pour le front :
        //  - inline           → payé dans la page (status=paid confirmé) ;
        //  - checkout_url + carte → 3ds (authentification forte requise) ;
        //  - carte SANS url et non-payé → refused (refus synchrone : jamais « payé ») ;
        //  - sinon (pas de jeton) → hosted (parcours hébergé classique).
        if ($inline) {
            $reason = null;
        } elseif ($checkoutUrl && $cardToken !== '') {
            $reason = '3ds';
        } elseif ($cardToken !== '' && in_array($mollieStatus, ['failed', 'canceled', 'expired'], true)) {
            $reason = 'refused';
        } elseif ($cardToken !== '' && ! $checkoutUrl) {
            // paiement pris mais pas encore scellé (pending/authorized) → le front sonde le serveur.
            $reason = 'pending';
        } else {
            $reason = 'hosted';
        }

        return response()->json([
            'status'       => true,
            'checkout_url' => $inline ? null : $checkoutUrl,
            'payment_id'   => $created['payment_id'],
            'inline'       => $inline,
            'reason'       => $reason,
        ], 200);
    }
}
