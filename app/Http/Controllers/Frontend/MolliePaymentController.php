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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
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
 *
 * [OWNER 2026-08-06 · PORTEFEUILLES] Entrées optionnelles du corps :
 *  - `card_token` : carte saisie dans notre page (Mollie Components) ;
 *  - `method`     : `applepay` | `googlepay` (whitelist stricte, exclusive
 *                   de `card_token`) → `reason=wallet` + URL de la feuille.
 * Aucune des deux ne change le montant, le webhook ni le chemin fiscal.
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

        // [OWNER 2026-08-06 · PORTEFEUILLES] Apple Pay / Google Pay. La valeur part TELLE QUELLE
        // chez Mollie comme `method`, donc la whitelist est stricte : tout ce qui n'est pas un
        // portefeuille connu est refusé ici plutôt que relayé. `null`/absent = parcours carte
        // inchangé (un front qui envoie `method: null` ne doit pas être puni).
        $walletMethod = $request->input('method') ?? '';
        if (!is_string($walletMethod)
            || ($walletMethod !== '' && !in_array($walletMethod, Mollie::WALLET_METHODS, true))) {
            return response()->json([
                'status'  => false,
                'message' => 'Moyen de paiement en ligne non pris en charge.',
            ], 422);
        }

        // Un portefeuille n'a PAS de jeton carte : la carte reste dans le téléphone, c'est la
        // feuille Apple/Google qui l'autorise. Recevoir les deux = front incohérent → on refuse
        // au lieu de deviner lequel prime (deviner ferait débiter par un rail non choisi).
        if ($walletMethod !== '' && $cardToken !== '') {
            return response()->json([
                'status'  => false,
                'message' => 'Choisissez soit le paiement par carte, soit un portefeuille — pas les deux.',
            ], 422);
        }

        // [OWNER 2026-08-08 · FEUILLE NATIVE] Jeton produit par la feuille Apple Pay ouverte DANS
        // la page (`ApplePaySession`). C'est un objet JSON signé par Apple, opaque pour nous :
        // on ne le lit pas, on le relaie. On borne seulement sa TAILLE et son type — un jeton
        // Apple pèse quelques kilo-octets, jamais mégaoctets.
        $applePayToken = $request->input('apple_pay_token');
        if ($applePayToken !== null && !is_string($applePayToken)) {
            return response()->json(['status' => false, 'message' => 'Jeton Apple Pay invalide.'], 422);
        }
        $applePayToken = (string) ($applePayToken ?? '');
        if ($applePayToken !== '' && strlen($applePayToken) > 20000) {
            return response()->json(['status' => false, 'message' => 'Jeton Apple Pay invalide.'], 422);
        }
        if ($applePayToken !== '' && $walletMethod !== 'applepay') {
            return response()->json([
                'status'  => false,
                'message' => 'Un jeton Apple Pay exige le moyen applepay.',
            ], 422);
        }

        // [OWNER 2026-08-08 · GOOGLE PAY NATIF] Symétrique du jeton Apple. Champ Mollie vérifié
        // par sonde : `googlePayPaymentToken`. Opaque pour nous : borné en taille, jamais lu.
        $googlePayToken = $request->input('google_pay_token');
        if ($googlePayToken !== null && !is_string($googlePayToken)) {
            return response()->json(['status' => false, 'message' => 'Jeton Google Pay invalide.'], 422);
        }
        $googlePayToken = (string) ($googlePayToken ?? '');
        if ($googlePayToken !== '' && strlen($googlePayToken) > 20000) {
            return response()->json(['status' => false, 'message' => 'Jeton Google Pay invalide.'], 422);
        }
        if ($googlePayToken !== '' && $walletMethod !== 'googlepay') {
            return response()->json([
                'status'  => false,
                'message' => 'Un jeton Google Pay exige le moyen googlepay.',
            ], 422);
        }
        if ($googlePayToken !== '' && $applePayToken !== '') {
            return response()->json([
                'status'  => false,
                'message' => 'Un seul jeton de paiement à la fois.',
            ], 422);
        }

        try {
            $created = $gateway->createPayment($frontendOrder, $cardToken, $walletMethod, $applePayToken, $googlePayToken);
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
        } elseif ($walletMethod !== '') {
            // [OWNER 2026-08-06 · PORTEFEUILLES] Distinguable de `hosted` : l'URL n'est pas une
            // page « choisissez un moyen » mais la feuille du portefeuille. Le site peut donc
            // afficher un écran calme (« Ouverture de votre portefeuille… ») au lieu du discours
            // « vous allez être redirigé vers un site de paiement ». Placé APRÈS les branches
            // carte — toutes exigent un cardToken, exclusif du portefeuille — pour qu'aucune
            // raison existante (null / 3ds / refused / pending / hosted) ne change de sens.
            $reason = 'wallet';
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

    /**
     * [OWNER 2026-08-08 · FEUILLE APPLE PAY NATIVE] Validation marchand — l'étape qu'Apple exige
     * avant d'ouvrir la feuille sur NOTRE domaine.
     *
     * Le navigateur nous transmet une `validationURL` signée par Apple ; nous la relayons à
     * Mollie, qui répond une session marchand. Sans ce relais, `ApplePaySession` ne s'ouvre pas
     * et il ne reste que la redirection vers une page hébergée — précisément ce que l'owner
     * refuse (« pourquoi tu fais toute cette complexité »).
     *
     * Deux gardes qui comptent :
     *  · l'URL de validation doit appartenir à APPLE. Relayer une URL arbitraire ferait de ce
     *    point d'entrée un proxy authentifié vers n'importe quel serveur (SSRF).
     *  · le domaine annoncé est celui de la REQUÊTE, jamais une valeur envoyée par le client.
     */
    public function applePaySession(Request $request): JsonResponse
    {
        $gateway = new Mollie();
        if (!$gateway->isMollieConfigured()) {
            return response()->json(['status' => false, 'message' => 'Mollie non configuré.'], 503);
        }

        $validationUrl = (string) $request->input('validation_url', '');
        $hote = parse_url($validationUrl, PHP_URL_HOST) ?: '';

        // Apple documente ses passerelles sous *.apple.com — on n'accepte rien d'autre.
        if ($validationUrl === ''
            || parse_url($validationUrl, PHP_URL_SCHEME) !== 'https'
            || !preg_match('/(^|\.)apple\.com$/', $hote)) {
            return response()->json([
                'status'  => false,
                'message' => "URL de validation Apple invalide.",
            ], 422);
        }

        $reponse = Http::withToken((string) config('payment.mollie.api_key'))
            ->acceptJson()->asJson()->timeout(15)
            ->post(config('payment.mollie.api_base') . '/wallets/applepay/sessions', [
                'domain'        => $request->getHost(),
                'validationUrl' => $validationUrl,
            ]);

        if (!$reponse->successful()) {
            Log::channel('fiscal')->error('mollie.applepay.session.failed', [
                'status' => $reponse->status(),
                'domain' => $request->getHost(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => "La feuille Apple Pay n'a pas pu s'ouvrir. Réessaie, paie par carte, ou choisis « Payer sur place ».",
            ], 502);
        }

        // Le corps est la session marchand d'Apple : opaque pour nous, destinée telle quelle à
        // `completeMerchantValidation()`.
        return response()->json(['status' => true, 'session' => $reponse->json()], 200);
    }
}
