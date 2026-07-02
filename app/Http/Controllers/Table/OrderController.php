<?php

namespace App\Http\Controllers\Table;


use App\Http\Controllers\Controller;
use App\Http\Requests\TableOrderRequest;
use App\Models\FrontendOrder;
use App\Models\Order;
use Smartisan\Settings\Facades\Settings;
use Exception;
use Illuminate\Http\Response;
use App\Services\OrderService;
use App\Http\Resources\OrderDetailsResource;


class OrderController extends Controller
{
    private OrderService $orderService;

    public function __construct(OrderService $order)
    {
        $this->orderService = $order;
    }

    /**
     * [ULTRA-AUDIT V2 2026-07-02 — P2 IDOR] Le service dine-in QR est DORMANT en V1
     * (Settings pos.pos_dine_in_enabled=false par défaut). Or `show/{frontendOrder}` était
     * NON-authentifié (QR, groupe apiKey seul) et exposait la PII client (nom/tél/email/solde)
     * par simple énumération de PK. On ferme la surface (404 fail-closed) tant que le dine-in
     * est OFF. Appelé DANS les méthodes (pas le constructeur : le constructeur s'exécute à chaque
     * instanciation, y compris hors requête — route-scan/tests — où la table settings n'existe pas ;
     * cf. IdempotencyRequiredRoutesCoverageTest). Quand l'owner activera le dine-in, prévoir en plus
     * une ressource status-only PII-minimale pour le show public (cf. mur OSS public).
     */
    private function abortIfDineInDisabled(): void
    {
        abort_unless(
            (bool) Settings::group('pos')->get('pos_dine_in_enabled', false),
            Response::HTTP_NOT_FOUND
        );
    }

    public function store(TableOrderRequest $request): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        $this->abortIfDineInDisabled();
        try {
            return new OrderDetailsResource($this->orderService->tableOrderStore($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(FrontendOrder $frontendOrder): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        $this->abortIfDineInDisabled();
        try {
            return new OrderDetailsResource($frontendOrder);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}