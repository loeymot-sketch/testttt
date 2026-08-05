<?php

namespace App\Http\Controllers\Admin;

use App\Http\Resources\SimpleOrderResource;
use App\Models\ThemeSetting;
use App\Services\CompanyService;
use App\Services\ThemeService;
use Exception;
use App\Models\Order;
use App\Exports\OrderExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\OrderService;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\OrderStatusRequest;
use App\Http\Requests\PaymentStatusRequest;
use App\Http\Resources\OrderDetailsResource;
use Smartisan\Settings\Facades\Settings;
use Barryvdh\DomPDF\Facade\Pdf;

class OnlineOrderController extends AdminController
{
    private OrderService $orderService;
    private CompanyService $companyService;
    private ThemeService $themeService;

    public function __construct(OrderService $order, CompanyService $companyService, ThemeService $themeService)
    {
        parent::__construct();
        $this->orderService = $order;
        $this->companyService = $companyService;
        $this->themeService  = $themeService;
        $this->middleware(['permission:online-orders'])->only(
            'index',
            'show',
            'export',
            'pdf',
            'changeStatus',
            'changePaymentStatus',
            'selectDeliveryBoy'
        );
    }

    public function index(PaginateRequest $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return SimpleOrderResource::collection($this->orderService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(Order $order): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->show($order, false));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function export(PaginateRequest $request): \Illuminate\Http\Response | \Symfony\Component\HttpFoundation\BinaryFileResponse | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return Excel::download(new OrderExport($this->orderService, $request), 'Online-Order.xlsx');
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
    public function pdf(PaginateRequest $request): mixed
    {
        try {
            // [ULTRA-LOOP R2 P1 2026-07-07 — 3e jumeau de troncature PDF, raté au R1]
            // L'UI envoie paginate=1&per_page=10 ; OrderService::list voit paginate==1 →
            // ->paginate(10), donc le blade n'itérait QUE la 1re page ET le "Total"
            // (agrégé dans @foreach) sous-déclarait massivement le CA (DB : 3129 commandes,
            // SUM(total)=745 633,62 € réels, mais le Total du PDF tronqué=58,20 €).
            // Miroir exact des 2 PDF déjà guéris (Sales/Items) + du jumeau Excel OrderExport:28.
            $request->merge(['paginate' => 0]);
            $company = $this->companyService->list();
            $theme_logo   = ThemeSetting::where(['key' => 'theme_logo'])->first()?->logo;
            $copyright   = Settings::group('site')->get('site_copyright');
            $orders = $this->orderService->list($request);

            // [ULTRA-LOOP R2 P2 2026-07-07 — garde anti-OOM] paginate=0 sans filtre de date
            // force le rendu de TOUTES les commandes ; dompdf épuise la mémoire/le temps sur
            // ~3129 lignes → PHP Error fatale (500 non attrapé par catch(Exception)). On coupe
            // proprement AVANT le rendu : les rapports datés (jour/semaine/mois) passent ; seul
            // l'export intégral non filtré pathologique est refusé avec un message clair.
            $maxRows = (int) config('report.pdf_max_rows', 2000);
            if ($orders->count() > $maxRows) {
                return response([
                    'status' => false,
                    'message' => 'Trop de lignes pour un export PDF ('.$orders->count().' lignes). '
                        .'Affinez la période avec un filtre de date.',
                ], 422);
            }

            $pdf = Pdf::loadView('pdf.online_orders', compact('company', 'theme_logo', 'orders', 'copyright'))
                ->setPaper('a4');
            return response()->stream(
                fn() => print($pdf->output()),
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="online_order_report.pdf"',
                ]
            );
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changeStatus(Order $order, OrderStatusRequest $request): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        // [REFUND-BYPASS-GUARD TWIN 2026-06-27 / P1 defense-in-depth] Mirror of
        // PosOrderController::changeStatus (commit 10e462149). RETURNED (22) is the refund
        // transition (cashBack + loyalty refund). Gate it on `pos-refund` here too so the
        // online-orders route can never become a refund-bypass if that permission is ever
        // granted to a non-refund role. Only status=RETURNED is gated; authz at the controller.
        // [F-CANCEL-REFUND-PARITY 2026-07-15 / P1] Même trou que PosOrderController :
        // CANCELED (16) / REJECTED (19) d'une commande PAYÉE déclenche aussi une sortie
        // tiroir/refund (OrderService.php:2286-2320). Gate étendu à CANCELED/REJECTED
        // quand payment_status=PAID (annulation d'une commande non payée reste ouverte).
        $refundLikeStatus = (int) $request->status;
        $movesCashOnStatusChange = $refundLikeStatus === \App\Enums\OrderStatus::RETURNED
            || (in_array($refundLikeStatus, [\App\Enums\OrderStatus::CANCELED, \App\Enums\OrderStatus::REJECTED], true)
                && (int) $order->payment_status === \App\Enums\PaymentStatus::PAID);
        if ($movesCashOnStatusChange) {
            abort_unless(
                auth()->user()?->can('pos-refund') ?? false,
                403,
                'Permission insuffisante pour effectuer un remboursement.'
            );
        }

        // [OWNER 2026-08-04 R1 SÉCU] Une commande CARTE WEB dont le paiement en ligne n'a
        // PAS abouti (UNPAID = en vol/refusé) ne doit JAMAIS être acceptée en caisse : sinon
        // le client annule au 3DS, la garde `status===PENDING` du webhook cancel ne joue plus
        // → zombie ACCEPT+UNPAID « en préparation » invisible en cuisine (le trap owner).
        // Une carte web PAYÉE est déjà promue en cuisine par le webhook — elle ne passe pas ici.
        if ((int) $request->status === \App\Enums\OrderStatus::ACCEPT
            // [PROCUREUR cycle 7 — 2026-08-05 · P1 F-E] Jumelle de la garde R1 d'OrderService :
            // elle aussi ne connaissait que 'web' et laissait passer une commande LIVRAISON
            // carte impayée (surface 'delivery'). Les deux routes doivent porter la MÊME garde,
            // sinon la fermer d'un côté ne fait que déplacer le trou.
            && in_array(strtolower((string) $order->source_surface), ['web', 'delivery'], true)
            && (int) $order->payment_method === \App\Enums\PaymentGateway::CARD
            && (int) $order->payment_status === \App\Enums\PaymentStatus::UNPAID) {
            return response([
                'status'  => false,
                'message' => 'Paiement en ligne en cours : cette commande carte sera acceptée automatiquement une fois payée.',
            ], 422);
        }

        // [SYNC-WEB-KDS-01 2026-07-15 / P1] Une commande online ACCEPTÉE sans encaissement
        // (bouton « Accepter » nu → PENDING→ACCEPT sans payer) restait UNPAID → JAMAIS libérée
        // sur le board cuisine (KitchenReleaseRule::applyBoardReleaseFilter exige
        // PAID|PENDING_COUNTER|POS-cash) → commande fantôme acceptée + client notifié
        // « acceptée », que la cuisine ne voit JAMAIS. On bascule le paiement en
        // PENDING_COUNTER (dû au comptoir/à la livraison = sémantique Plan B, identique aux
        // commandes téléphone qui sont board-released à l'acceptation). Le fiscal_seq reste
        // alloué au VRAI encaissement (changePaymentStatus→PAID) → NF525 inchangé. Idempotent.
        // [S5 2026-07-18 · accept web atomique] Le flip (PENDING_COUNTER + marqueur) et le
        // changeStatus sont enveloppés dans une SEULE DB::transaction (miroir de l'atomicité borne
        // FrontendOrderService.php:633-637) : si changeStatus jette APRÈS le flip, la transaction
        // rollback tout — jamais d'état incohérent (PENDING_COUNTER + statut encore PENDING). Les 403
        // (HttpException) et les autres erreurs restent remontés à l'identique HORS transaction.
        try {
            return DB::transaction(function () use ($order, $request) {
                // [S1 2026-07-18 · jumeau non-COD de P1-3] Le flip board-release la commande
                // (KitchenReleaseRule admet PENDING_COUNTER). Il est donc gaté sur la COLLECTABILITÉ
                // = CASH_ON_DELIVERY : une web NON-COD (carte/null) attend son paiement EN LIGNE et ne
                // doit PAS être préparée avant paiement — sinon elle serait board-released MAIS jamais
                // encaissable (assertCounterDeferredOrder exige COD et aucun marqueur COUNTER_DEFERRED
                // n'est posé) → orpheline « préparée jamais encaissable ». Le prédicat du flip est
                // désormais COHÉRENT avec celui du marqueur ci-dessous (tous deux exigent COD). Le flux
                // vivant V1 (100 % web = COD → P1-3) reste board-released + encaissable, inchangé.
                if ((int) $request->status === \App\Enums\OrderStatus::ACCEPT
                    && (int) $order->payment_status === \App\Enums\PaymentStatus::UNPAID
                    && (int) $order->order_type !== \App\Enums\OrderType::POS
                    && (int) $order->payment_method === \App\Enums\PaymentGateway::CASH_ON_DELIVERY) {
                    $order->payment_status = \App\Enums\PaymentStatus::PENDING_COUNTER;

                    // [P1-3 2026-07-18] Sans marqueur, le flip PENDING_COUNTER rendait la web
                    // INENCAISSABLE (file /pos/counter-collect + assertCounterDeferredOrder rejetaient
                    // 'web'). Pour un TAKEAWAY web COD, on COMPLÈTE le marqueur counter-deferred canonique
                    // (pos_payment_method=COUNTER_DEFERRED) → commande différée de PLEIN DROIT, encaissable
                    // au comptoir via confirmCounterPayment (allocation fiscale NF525 + mouvement tiroir),
                    // au même titre qu'une commande téléphone/borne. La LIVRAISON web ne reçoit PAS ce
                    // marqueur : elle est encaissée au doorstep par le livreur
                    // (OrderService::deliveryBoyOrderChangeStatus, sceau COD), elle garde juste
                    // PENDING_COUNTER pour la visibilité cuisine (heal SYNC-WEB-KDS-01 préservé).
                    if ((int) $order->order_type === \App\Enums\OrderType::TAKEAWAY
                        && (int) $order->payment_method === \App\Enums\PaymentGateway::CASH_ON_DELIVERY
                        && $order->pos_payment_method === null) {
                        $order->pos_payment_method = \App\Enums\PosPaymentMethod::COUNTER_DEFERRED;
                    }

                    $order->save();
                }

                return new OrderDetailsResource($this->orderService->changeStatus($order, $request));
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
            throw $http; // 403 must reach the client intact.
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changePaymentStatus(Order $order, PaymentStatusRequest $request): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        // [P2-e 2026-07-18 / P2 twin-route authz parity] REFUNDED est la transition de
        // remboursement. La route sœur POS gate cette arête sur `pos-refund`
        // (PosOrderController::changePaymentStatus:372-378), mais ce chemin ONLINE ne l'avait
        // JAMAIS — il n'était gardé que par `permission:online-orders` (constructeur), qu'un
        // POS Operator POSSÈDE → il pouvait marquer une commande en ligne REMBOURSÉE sans le
        // droit de remboursement (void off-book / vecteur de remboursements de masse). On
        // miroir EXACTEMENT le gate de la sœur, fail-fast AVANT de déléguer (hors try → le 403
        // n'est pas masqué en 422).
        if ((int) $request->payment_status === \App\Enums\PaymentStatus::REFUNDED) {
            abort_unless(
                auth()->user()?->can('pos-refund') ?? false,
                403,
                'Permission insuffisante pour effectuer un remboursement.'
            );
        }

        try {
            return new OrderDetailsResource($this->orderService->changePaymentStatus($order, $request));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
            throw $http; // 403 must reach the client intact.
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function selectDeliveryBoy(Order $order, Request $request): \Illuminate\Http\Response | OrderDetailsResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new OrderDetailsResource($this->orderService->selectDeliveryBoy($order, $request));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
            // [GOAL-2026-05-18 P0-LIV-01] Branch/role guards in
            // OrderService::selectDeliveryBoy raise 403/422 via abort().
            // Propagate so the multi-tenant guard reaches the client.
            throw $http;
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
