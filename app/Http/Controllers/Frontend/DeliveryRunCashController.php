<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\DeliveryBoyCashSession;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Delivery\DeliveryBoyCashSessionService;
use App\Services\Delivery\DeliveryCashRecapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * [LIVREUR CAISSE 2026-11-02] « Ma caisse » du livreur : le récapitulatif de SON service et sa clôture.
 *
 *  - GET  /api/frontend/delivery-run/cash         récap du service courant (fond de début, espèces / carte / titres-restaurant, espèces attendues) ;
 *  - POST /api/frontend/delivery-run/cash/close   {counted_cash, session_id?} clôture le service avec les espèces comptées, rend le récap final.
 *
 * Isolation : un livreur ne voit et ne clôture QUE son service (`delivery_boy_id` = lui, branche = la sienne) ; le service d'un autre
 * livreur répond « introuvable ». Le fond de début est celui de l'ouverture (administration, ou 0 € si le service s'est ouvert tout seul à
 * la première remise encaissée). L'argent n'est jamais modifié ici : on lit, et on clôture via {@see DeliveryBoyCashSessionService::closeSession}.
 */
class DeliveryRunCashController extends Controller
{
    public const ROLE = 'Delivery Boy';

    public function __construct(
        private readonly DeliveryBoyCashSessionService $sessions,
        private readonly DeliveryCashRecapService $recap,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $this->courier($request);
        $open = $this->sessions->findOpenSessionForDeliveryBoy((int) $user->branch_id, (int) $user->id);
        if ($open) {
            return response()->json(['status' => true, 'data' => ['open' => true] + $this->payload($user, $open)]);
        }

        // Pas de service ouvert : message clair, jamais une erreur. Le dernier service clôturé reste consultable.
        $last = $this->mine($user)->orderByDesc('opened_at')->orderByDesc('id')->first();

        return response()->json(['status' => true, 'data' => [
            'open'    => false,
            'message' => 'Aucun service ouvert',
            'last'    => $last ? $this->payload($user, $last) : null,
        ]]);
    }

    public function close(Request $request): JsonResponse
    {
        $user = $this->courier($request);
        $counted = $request->input('counted_cash');
        if (! is_int($counted) && ! is_float($counted) && ! (is_string($counted) && preg_match('/^\d+(\.\d{1,2})?$/', trim($counted)) === 1)) {
            abort(422, 'Saisissez les espèces comptées (un nombre, par exemple 125,50).');
        }
        $counted = (float) $counted;
        if ($counted < 0 || $counted > 1000000 || abs($counted * 100 - round($counted * 100)) > 0.0001) {
            abort(422, 'Les espèces comptées doivent être un montant positif, au centime.');
        }

        // Le service visé : celui que l'écran affichait (`session_id`) s'il est à moi, sinon mon service ouvert.
        $sessionId = $request->input('session_id');
        if ($sessionId !== null) {
            $session = is_numeric($sessionId) ? $this->mine($user)->whereKey((int) $sessionId)->first() : null;
            abort_if($session === null, 404, 'Service introuvable.'); // y compris le service d'un autre livreur : aucune fuite
        } else {
            $session = $this->sessions->findOpenSessionForDeliveryBoy((int) $user->branch_id, (int) $user->id)
                ?? $this->mine($user)->orderByDesc('opened_at')->orderByDesc('id')->first();
            abort_if($session === null, 422, 'Aucun service ouvert : rien à clôturer.');
        }

        // Clôture idempotente : un service déjà clôturé renvoie son récap final, sans rien changer (la valeur reçue est ignorée).
        if ($session->status !== DeliveryBoyCashSession::STATUS_OPEN) {
            return response()->json(['status' => true, 'already' => true, 'data' => $this->payload($user, $session)]);
        }

        $lock = Cache::lock('delivery-run-cash-close:'.$session->id, 15);
        if (! $lock->get()) {
            abort(409, 'Clôture en cours, réessayez dans un instant.');
        }
        try {
            $pending = $this->recap->pendingToCollect((int) $user->branch_id, (int) $user->id);
            if ($pending['count'] > 0) {
                abort(422, sprintf(
                    'Il reste %d livraison%s en route à encaisser (%s, %s) : remettez-%s ou rapportez-%s au restaurant avant de clôturer.',
                    $pending['count'], $pending['count'] > 1 ? 's' : '', implode(', ', $pending['orders']),
                    number_format($pending['amount'], 2, ',', ' ').' €',
                    $pending['count'] > 1 ? 'les' : 'la', $pending['count'] > 1 ? 'les' : 'la'
                ));
            }
            try {
                $closed = $this->sessions->closeSession((int) $session->id, $counted);
            } catch (HttpException $e) {
                abort($e->getStatusCode(), 'La caisse n\'a pas pu être clôturée : voyez la caisse du restaurant.');
            }
        } finally {
            $lock->release();
        }

        return response()->json(['status' => true, 'already' => false, 'data' => $this->payload($user, $closed)]);
    }

    /** @return array<string,mixed> */
    private function payload(User $user, DeliveryBoyCashSession $session): array
    {
        $pending = $session->status === DeliveryBoyCashSession::STATUS_OPEN
            ? $this->recap->pendingToCollect((int) $user->branch_id, (int) $user->id)
            : ['count' => 0, 'amount' => 0.0, 'orders' => []];

        return $this->recap->recap($session) + ['pending' => $pending];
    }

    /** Les services DE CE livreur, sur SA branche — jamais ceux d'un autre. */
    private function mine(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return DeliveryBoyCashSession::query()->withoutGlobalScope(BranchScope::class)
            ->where('delivery_boy_id', (int) $user->id)->where('branch_id', (int) $user->branch_id);
    }

    /** Livreur actif rattaché à un restaurant ; tout autre compte (client, caisse, administration) est refusé. */
    private function courier(Request $request): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && (int) $user->branch_id > 0, 403, 'Cet écran est réservé aux livreurs d\'un restaurant.');
        abort_unless($user->roles()->pluck('name')->contains(self::ROLE), 403, 'Cet écran est réservé aux livreurs.');

        return $user;
    }
}
