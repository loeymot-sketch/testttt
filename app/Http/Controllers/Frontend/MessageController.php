<?php

namespace App\Http\Controllers\Frontend;

use Exception;
use App\Models\Message;
use App\Services\MessageService;
use App\Http\Requests\MessageRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\MessageResource;
use App\Http\Controllers\Controller;

class MessageController extends Controller
{
    public MessageService $messageService;

    public function __construct(MessageService $messageService)
    {
        $this->messageService = $messageService;
    }

    public function index(PaginateRequest $request)
    {
        try {
            // [ULTRA-AUDIT V4-DEPLOY 2026-07-02 — P2 IDOR] La route frontend/message n'a qu'auth:sanctum et
            // le service filtrait par $request->user_id (contrôlé par le client) → un appelant pouvait lister
            // les messages d'autrui en devinant un user_id. On FORCE la liste au propriétaire authentifié.
            $request->merge(['user_id' => auth()->id()]);
            return MessageResource::collection($this->messageService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function show(Message $message)
    {
        // [ULTRA-AUDIT V4-DEPLOY 2026-07-02 — P2 IDOR] Message n'est PAS branch-scopé (modèle exempté) et
        // la route n'a qu'auth:sanctum → sans ce garde tout appelant authentifié (même un token guest
        // kiosk:order) lisait le message d'autrui par ID. Seul le propriétaire y accède (404 sinon = pas de fuite).
        $this->assertOwnsMessage($message);
        try {
            return new MessageResource($this->messageService->show($message));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(MessageRequest $request): \Illuminate\Http\Response | MessageResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new MessageResource($this->messageService->store($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(Message $message)
    {
        // [ULTRA-AUDIT V4-DEPLOY 2026-07-02 — P2 IDOR] Idem show : sans ce garde tout appelant authentifié
        // SUPPRIMAIT le message d'autrui par ID (destruction cross-utilisateur). Propriétaire seul.
        $this->assertOwnsMessage($message);
        try {
            $this->messageService->destroy($message);
            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [ULTRA-AUDIT V4-DEPLOY 2026-07-02 — P2 IDOR] Le message doit appartenir à l'appelant authentifié.
     * 404 (et non 403) pour ne pas divulguer l'existence d'un message d'autrui.
     */
    private function assertOwnsMessage(Message $message): void
    {
        abort_if((int) $message->user_id !== (int) optional(auth()->user())->id, 404);
    }
}