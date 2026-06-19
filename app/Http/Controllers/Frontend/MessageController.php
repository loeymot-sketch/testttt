<?php

namespace App\Http\Controllers\Frontend;

use Exception;
use App\Models\Message;
use App\Services\MessageService;
use App\Http\Requests\MessageRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\MessageResource;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

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
            // [abuse-heal 2026-06-19 msg-idor] Object-level authz: this is the
            // CUSTOMER self-thread surface ('auth:sanctum' only, no permission
            // gate). MessageService::list() filters by the CLIENT-supplied
            // user_id, which let any token pass ?user_id=<victim> and read
            // another user's private thread (IDOR / cross-user PII leak). Force
            // user_id to the authenticated user so a customer can ONLY read
            // their own thread. The shared service is left untouched so the
            // 'permission:messages'-gated Admin\MessageController keeps its
            // legitimate any-thread access.
            $request->merge(['user_id' => Auth::id()]);

            return MessageResource::collection($this->messageService->list($request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }


    public function show(Message $message)
    {
        try {
            // [abuse-heal 2026-06-19 msg-idor] Route-model binding resolves an
            // un-scoped Message (no BranchScope), so any customer could open an
            // arbitrary message id. Only the thread owner may read it.
            abort_unless((int) $message->user_id === (int) Auth::id(), 403);

            return new MessageResource($this->messageService->show($message));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function store(MessageRequest $request): \Illuminate\Http\Response | MessageResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new MessageResource($this->messageService->store($request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function destroy(Message $message)
    {
        try {
            // [abuse-heal 2026-06-19 msg-idor] Same object-level authz as show():
            // a customer may only delete their own thread, not an arbitrary one.
            abort_unless((int) $message->user_id === (int) Auth::id(), 403);

            $this->messageService->destroy($message);
            return response('', 202);
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }
}