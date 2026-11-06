<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\Subscriber;
use App\Exports\SubscriberExport;
use App\Services\SubscriberService;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\SubscriberResource;
use App\Http\Requests\SubscriberEmailRequest;


class SubscriberController extends AdminController
{
    public SubscriberService $subscriberService;

    public function __construct(SubscriberService $subscriberService)
    {
        parent::__construct();
        $this->subscriberService = $subscriberService;
        // [GOAL-2026-05-30 SUB-1] Gate sendEmail too: POST /admin/subscriber/send-email is a
        // mutating mass-email to the entire subscriber base; it was missing from the only()
        // list, so any authenticated staff (without permission:subscribers) could trigger it.
        $this->middleware(['permission:subscribers'])->only('index', 'destroy', 'export', 'sendEmail');
    }

    public function index(PaginateRequest $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return SubscriberResource::collection($this->subscriberService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(Subscriber $subscriber): \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $this->subscriberService->destroy($subscriber);
            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function export(PaginateRequest $request): \Illuminate\Http\Response | \Symfony\Component\HttpFoundation\BinaryFileResponse | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return Excel::download(new SubscriberExport($this->subscriberService, $request), 'Subscribers.xlsx');
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function sendEmail(SubscriberEmailRequest $request): \Illuminate\Http\Response | SubscriberResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            // [ONB-09 2026-08-28] Répondait « Email envoyé avec succès » sans condition,
            // y compris quand aucun abonné n'existait — le service sortait alors sans
            // rien faire. Mesuré : 0 abonné en base, donc 100 % des envois depuis cet
            // écran étaient des faux succès. On dit désormais à combien de personnes le
            // message est parti, et on le dit franchement quand il n'est parti à
            // personne. Zéro n'est pas une erreur, c'est une information.
            $destinataires = $this->subscriberService->sendEmail($request);

            return response([
                'status'  => true,
                'message' => $destinataires === 0
                    ? trans('all.message.email_no_subscriber')
                    : trans('all.message.email_send_count', ['count' => $destinataires]),
                'count'   => $destinataires,
            ], 200);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
