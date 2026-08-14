<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PosCustomerCreateRequest;
use App\Http\Requests\PosLoyaltyAttachRequest;
use App\Http\Requests\PosLoyaltyLookupRequest;
use App\Http\Requests\PosLoyaltyManualCreditRequest;
use App\Http\Requests\PosLoyaltyManualDeductRequest;
use App\Http\Requests\PosLoyaltyRedeemRequest;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Services\Identity\CustomerAccountProvisioner;
use App\Services\Loyalty\PosCustomerLookupService;
use App\Services\Loyalty\PosLoyaltyAttachService;
use App\Services\Loyalty\PosManualCreditService;
use App\Services\Loyalty\PosRedemptionException;
use App\Services\Loyalty\PosRedemptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] V1 cashier-facing loyalty redeem
 * endpoint per LOCK §3 Option B (separate controller, 0 frozen-zone touch
 * on PosController.php which is currently DIRTY).
 *
 * Endpoint :
 *   POST /api/admin/pos-order/{order}/redeem-loyalty
 *   Body : { "points": int, "loyalty_code": string }
 *   Auth : sanctum + permission:pos.redeem-loyalty (via PosLoyaltyRedeemRequest)
 *   Middleware : route-level `idempotency` (anti-doublon)
 *
 * Returns 200 + { status: true, data: { discount_eur, balance_after, ... } }
 * on success, or a stable error envelope { status: false, code, message } on
 * any anti-fraud violation (see PosRedemptionException for the error_code list).
 */
final class PosLoyaltyController extends Controller
{
    public function __construct(
        private readonly PosRedemptionService $redemptionService,
        private readonly PosCustomerLookupService $lookupService,
        private readonly CustomerAccountProvisioner $provisioner,
        private readonly PosLoyaltyAttachService $attachService,
        private readonly PosManualCreditService $manualCreditService,
    ) {
    }

    /**
     * RATTACHER LE CLIENT À CETTE VENTE, pour que ses points lui soient crédités.
     *
     * [2026-08-10 · propriétaire : « pouvoir lui ajouter des points pour sa commande »]
     *
     * Mesuré en base : 1411 ventes de caisse arrivées à DELIVERED, UNE SEULE rattachée à un client.
     * Le crédit fonctionnait ; personne ne pouvait dire à qui créditer.
     *
     * La vérification de caisse reprend mot pour mot celle du débit (`redeem` plus bas) : la
     * permission Spatie est GLOBALE par utilisateur, pas liée à une caisse — sans ce contrôle après
     * lecture, un caissier de la caisse 5 rattacherait un client sur une commande de la caisse 3.
     */
    public function attachCustomer(PosLoyaltyAttachRequest $request, int $orderId): JsonResponse
    {
        $order = Order::withoutGlobalScope(BranchScope::class)->find($orderId);
        if (! $order) {
            return response()->json([
                'status' => false, 'code' => 'ORDER_NOT_FOUND', 'message' => 'Commande introuvable',
            ], 404);
        }

        $userBranchId = (int) ($request->user()?->branch_id ?? -1);
        if ($userBranchId !== 0 && $userBranchId !== (int) $order->branch_id) {
            abort(403, 'Cross-branch access denied');
        }

        try {
            $resultat = $this->attachService->attach(
                $order,
                (string) $request->input('loyalty_code'),
                $request->user()?->id
            );
        } catch (\Throwable $e) {
            Log::error('pos.loyalty.rattachement_echoue', [
                'order' => $orderId, 'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false, 'code' => 'ATTACH_FAILED', 'message' => 'Rattachement impossible pour le moment.',
            ], 500);
        }

        if (! ($resultat['ok'] ?? false)) {
            return response()->json([
                'status'  => false,
                'code'    => $resultat['code'] ?? 'ATTACH_REFUSED',
                'message' => $resultat['message'] ?? 'Rattachement refusé.',
            ], 422);
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'customer'       => $resultat['customer'],
                // Combien de points viennent d'être crédités POUR CETTE VENTE. Zéro est une réponse
                // légitime : la commande n'a pas encore atteint son déclencheur, le crédit viendra
                // par la voie normale. L'écran doit dire laquelle des deux, pas rester muet.
                'points_awarded' => (int) ($resultat['points_awarded'] ?? 0),
            ],
        ]);
    }

    /**
     * INSCRIRE LE CLIENT QUI EST DEVANT LE COMPTOIR.
     *
     * [2026-08-10 · propriétaire : « je veux ajouter la section pour pouvoir créer un compte pour un
     * client »]
     *
     * Le service employé est celui de la roue — le MÊME, déménagé sous `App\Services\Identity`.
     * Deux façons de créer un client, c'est tôt ou tard deux comptes pour un même humain, deux
     * soldes de points, et une plainte au comptoir qu'on ne saura pas expliquer.
     *
     * Un numéro qui a DÉJÀ un compte n'en reçoit pas un second : on rend le compte existant, et le
     * caissier voit tout de suite le solde au lieu d'un doublon vide. C'est le comportement utile —
     * un client qui dit « je ne suis pas inscrit » se trompe une fois sur deux.
     */
    public function createCustomer(PosCustomerCreateRequest $request): JsonResponse
    {
        $resultat = $this->provisioner->ensure(
            (string) $request->input('phone'),
            $request->input('email'),
            $request->input('name'),
            'comptoir'
        );

        // `ensure()` ne jette jamais — il RAPPORTE. Chaque motif mérite une phrase que le caissier
        // peut lire au client, pas un code technique : un refus qu'on ne peut pas expliquer, c'est un
        // caissier qui répète « ça ne marche pas » et un client qui s'en va.
        if ($resultat['user_id'] === null) {
            $messages = [
                'phone_invalid'    => 'Ce numéro est incomplet.',
                'staff_phone'      => 'Ce numéro est celui d\'un compte de l\'équipe.',
                'deleted_account'  => 'Un compte supprimé existe pour ce numéro — un responsable doit le rétablir.',
                'error'            => 'Création impossible pour le moment.',
            ];
            $motif = (string) ($resultat['reason'] ?? 'error');

            Log::warning('pos.loyalty.creation_refusee', [
                'reason'  => $motif,
                'cashier' => $request->user()?->id,
            ]);

            return response()->json([
                'status'  => false,
                'code'    => strtoupper($motif),
                'message' => $messages[$motif] ?? $messages['error'],
            ], $motif === 'error' ? 500 : 422);
        }

        $user = \App\Models\User::find($resultat['user_id']);

        Log::info('pos.loyalty.compte', [
            'created' => (bool) $resultat['created'],
            'reason'  => $resultat['reason'],
            'cashier' => $request->user()?->id,
        ]);

        return response()->json([
            'status' => true,
            'data'   => [
                // On dit s'il a été CRÉÉ ou simplement RETROUVÉ : l'écran n'annonce pas « compte
                // créé » à un client qui était déjà inscrit depuis six mois.
                'created'  => (bool) $resultat['created'],
                'customer' => $user ? $this->lookupService->presenter($user) : null,
            ],
        ], $resultat['created'] ? 201 : 200);
    }

    /**
     * L'HISTORIQUE DES POINTS D'UN CLIENT — « pourquoi j'ai ce solde ? ».
     *
     * [2026-08-12] Un solde sans histoire ne se défend pas. Trois personnes posent la même question
     * et aucune n'avait de réponse : le client qui conteste (« j'avais plus que ça »), le responsable
     * qui cherche un écart, le caissier qui a rattaché la mauvaise commande. Le grand-livre
     * `loyalty_transactions` existe et est immuable depuis des mois — il n'était lu nulle part.
     *
     * En GET : c'est une lecture, elle doit être rejouable, partageable et sans effet. La porte reste
     * la même que la recherche (`permission:pos`) et le débit est borné par le même limiteur : un
     * historique est aussi un oracle, il dit qu'un code existe.
     */
    public function history(Request $request): JsonResponse
    {
        if (! ($request->user()?->can('pos') ?? false)) {
            return response()->json([
                'status' => false, 'code' => 'FORBIDDEN', 'message' => 'Droit caisse requis.',
            ], 403);
        }

        $code = strtoupper(trim((string) $request->query('loyalty_code', '')));

        if (strlen($code) < 4) {
            return response()->json([
                'status' => false, 'code' => 'CODE_TOO_SHORT', 'message' => 'Code fidélité manquant.',
            ], 422);
        }

        // On n'ouvre l'historique QUE d'un compte que le comptoir a le droit de voir : sans cette
        // vérification, le code d'un membre de l'équipe rendrait ses mouvements de points.
        $trouve = $this->lookupService->byCode($code);
        if (($trouve['status'] ?? '') !== \App\Services\Loyalty\PosCustomerLookupService::TROUVE) {
            return response()->json([
                'status' => false, 'code' => 'NO_ACCOUNT', 'message' => 'Aucun compte client à ce code.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'customer' => $trouve['customer'],
                'entries'  => $this->lookupService->history($code, (int) $request->query('limit', 20)),
            ],
        ]);
    }

    /**
     * QUI EST LE CLIENT DEVANT LE COMPTOIR ?
     *
     * [2026-08-10 · propriétaire : « pouvoir utiliser les points accumulés ou lui en ajouter pour sa
     * commande ; l'accumulation avec le numéro de téléphone, c'est préférable ; on scanne le QR
     * directement avec la tablette »]
     *
     * Le logiciel savait déjà créditer (`AwardLoyaltyPointsOnDelivery` lit
     * `orders.loyalty_customer_code`) et débiter (`redeem` ci-dessous), et la commande de caisse
     * acceptait déjà le champ de rattachement. Il n'existait AUCUN moyen de dire qui est le client —
     * d'où 2 lignes de gain « surface caisse » dans toute la base. Cette route est ce maillon.
     *
     * Réponse toujours 200 quand la requête est valide : « pas de compte » est une information de
     * comptoir, pas une erreur de programme. Le caissier doit lire une phrase, pas un code HTTP.
     */
    public function lookup(PosLoyaltyLookupRequest $request): JsonResponse
    {
        [$critere, $valeur] = $request->critere();

        try {
            $resultat = match ($critere) {
                'qr'   => $this->lookupService->byQr($valeur),
                'code' => $this->lookupService->byCode($valeur),
                default => $this->lookupService->byPhone($valeur),
            };
        } catch (\Throwable $e) {
            Log::error('pos.loyalty.lookup.echec', [
                'critere'  => $critere,
                'cashier'  => $request->user()?->id,
                'message'  => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'code'    => 'LOOKUP_FAILED',
                'message' => 'Recherche impossible pour le moment.',
            ], 500);
        }

        // On ne journalise JAMAIS le critère de recherche : un journal qui contient les numéros de
        // téléphone tapés au comptoir est un carnet d'adresses en clair, conservé sans limite.
        Log::info('pos.loyalty.lookup', [
            'via'     => $resultat['via'] ?? $critere,
            'status'  => $resultat['status'],
            'cashier' => $request->user()?->id,
        ]);

        return response()->json(['status' => true, 'data' => $resultat]);
    }

    /**
     * CRÉDITER MANUELLEMENT DES POINTS — un montant en euros choisi par le caissier, sans commande.
     *
     * [2026-08-14 · propriétaire : « quand je lui ajoute un montant équivalent fidélité, par
     * exemple sept euros, directement dans son compte »]
     *
     * Distinct de `attachCustomer` (qui relance le crédit AUTOMATIQUE proportionnel à une vente) :
     * ici le caissier choisit lui-même la somme. `order_id` est facultatif et ne sert qu'à noter
     * « pour quelle vente » dans le grand-livre — cette route ne modifie JAMAIS le total ni la
     * remise d'une commande, donc rien qui touche NF525.
     */
    public function creditManual(PosLoyaltyManualCreditRequest $request): JsonResponse
    {
        try {
            $resultat = $this->manualCreditService->credit(
                (string) $request->input('loyalty_code'),
                (float) $request->input('euros'),
                $request->user()?->id,
                $request->input('order_id') ? (int) $request->input('order_id') : null,
                $request->input('reason'),
            );
        } catch (PosRedemptionException $e) {
            return response()->json([
                'status' => false, 'code' => $e->errorCode, 'message' => $e->getMessage(),
            ], $e->httpStatus);
        } catch (\Throwable $e) {
            Log::error('pos.loyalty.credit_manuel_echoue', [
                'error' => $e->getMessage(), 'cashier' => $request->user()?->id,
            ]);

            return response()->json([
                'status' => false, 'code' => 'CREDIT_FAILED', 'message' => 'Crédit impossible pour le moment.',
            ], 500);
        }

        $client = \App\Models\User::find($resultat['customer_id']);

        return response()->json([
            'status' => true,
            'data'   => [
                'points_added'  => $resultat['points_added'],
                'balance_after' => $resultat['balance_after'],
                'customer'      => $client ? $this->lookupService->presenter($client) : null,
            ],
        ]);
    }

    /**
     * RETIRER MANUELLEMENT DES POINTS — correction d'un sur-crédit, sans annuler l'écriture posée.
     *
     * [2026-08-14 · propriétaire] « je préfère diminuer ici… je veux pas annuler [ce qui est déjà
     * fait] ». Miroir de `creditManual` : même porte, même style de réponse.
     */
    public function deductManual(PosLoyaltyManualDeductRequest $request): JsonResponse
    {
        try {
            $resultat = $this->manualCreditService->deduct(
                (string) $request->input('loyalty_code'),
                (int) $request->input('points'),
                $request->user()?->id,
                $request->input('order_id') ? (int) $request->input('order_id') : null,
                $request->input('reason'),
            );
        } catch (PosRedemptionException $e) {
            return response()->json([
                'status' => false, 'code' => $e->errorCode, 'message' => $e->getMessage(),
            ], $e->httpStatus);
        } catch (\Throwable $e) {
            Log::error('pos.loyalty.retrait_manuel_echoue', [
                'error' => $e->getMessage(), 'cashier' => $request->user()?->id,
            ]);

            return response()->json([
                'status' => false, 'code' => 'DEDUCT_FAILED', 'message' => 'Retrait impossible pour le moment.',
            ], 500);
        }

        $client = \App\Models\User::find($resultat['customer_id']);

        return response()->json([
            'status' => true,
            'data'   => [
                'points_removed' => $resultat['points_removed'],
                'balance_after'  => $resultat['balance_after'],
                'customer'       => $client ? $this->lookupService->presenter($client) : null,
            ],
        ]);
    }

    public function redeem(PosLoyaltyRedeemRequest $request, int $orderId): JsonResponse
    {
        // [HEAL-A.1 2026-05-19] Z6+Z8 cross-confirmed P0: Spatie permission
        // `pos.redeem-loyalty` is global per-user, NOT branch-bound. Pre-heal
        // a cashier on branch=5 could redeem against an order on branch=3.
        // Mirror PosOrderController::show:113-121 pattern: bypass BranchScope
        // (singular — preserves SoftDeletingScope so soft-deleted orders are
        // not silently leaked, per Z6 P1-Z6-03) then explicit post-fetch
        // branch check. Admin (branch_id=0) bypass per BranchScope:33-36.
        $order = Order::withoutGlobalScope(BranchScope::class)->find($orderId);
        if (!$order) {
            return response()->json([
                'status'  => false,
                'code'    => 'ORDER_NOT_FOUND',
                'message' => 'Commande introuvable',
            ], 404);
        }
        $userBranchId = (int) ($request->user()?->branch_id ?? -1);
        if ($userBranchId !== 0 && $userBranchId !== (int) $order->branch_id) {
            abort(403, 'Cross-branch access denied');
        }

        try {
            $result = $this->redemptionService->applyToOrder(
                $order,
                (int) $request->input('points'),
                (string) $request->input('loyalty_code'),
                (int) ($request->user()?->id ?? 0) ?: null,
            );

            return response()->json([
                'status' => true,
                'data'   => [
                    'discount_eur'  => $result['discount_eur'],
                    'balance_after' => $result['balance_after'],
                    'order'         => [
                        'id'                    => $result['order']->id,
                        'subtotal'              => (float) $result['order']->subtotal,
                        'discount'              => (float) $result['order']->discount,
                        'total'                 => (float) $result['order']->total,
                        'loyalty_customer_code' => $result['order']->loyalty_customer_code,
                    ],
                    'transaction_id' => $result['transaction']->id,
                ],
            ], 200);
        } catch (PosRedemptionException $e) {
            return response()->json([
                'status'  => false,
                'code'    => $e->errorCode,
                'message' => $e->getMessage(),
            ], $e->httpStatus);
        } catch (\Throwable $e) {
            Log::error('[PosLoyaltyRedeem] ' . $e->getMessage(), [
                'order_id'   => $orderId,
                'cashier_id' => $request->user()?->id,
            ]);
            return response()->json([
                'status'  => false,
                'code'    => 'INTERNAL_ERROR',
                'message' => 'Erreur serveur',
            ], 500);
        }
    }
}
