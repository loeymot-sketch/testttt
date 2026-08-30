<?php

namespace App\Http\Controllers;

use App\Services\VoiceOrder\VoiceOrderGatewayAuthenticator;
use App\Services\VoiceOrder\VoiceOrderTranscriptStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VoiceOrderGatewayController extends Controller
{
    public function __construct(
        private VoiceOrderGatewayAuthenticator $authenticator,
        private VoiceOrderTranscriptStore $store
    ) {
    }

    public function event(Request $request): JsonResponse
    {
        $context = $this->authenticator->authenticate($request);
        $payload = json_decode($context['raw_body'], true);
        if (! is_array($payload) || array_is_list($payload)) {
            throw new HttpException(422, 'Événement JSON invalide.');
        }
        if (array_key_exists('branch_id', $payload)) {
            throw new HttpException(422, 'La passerelle ne peut pas choisir la filiale.');
        }

        $validated = Validator::make($payload, [
            'event' => ['required', 'string', 'in:call.started,transcript.update,transcript.final,call.ended,gateway.status'],
            'call_id' => ['required_unless:event,gateway.status', 'nullable', 'string', 'regex:/^[A-Za-z0-9._:-]{8,128}$/'],
            'caller_number' => ['nullable', 'string', 'max:40'],
            'caller_name' => ['nullable', 'string', 'max:120'],
            'turn_id' => ['required_if:event,transcript.update,transcript.final', 'nullable', 'string', 'max:128'],
            'speaker' => ['nullable', 'string', 'in:caller,employee,unknown'],
            'text' => ['required_if:event,transcript.update,transcript.final', 'nullable', 'string', 'max:4000'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'reason' => ['nullable', 'string', 'max:120'],
        ])->validate();

        $state = match ($validated['event']) {
            'call.started' => $this->store->startCall($context['branch_id'], $context['gateway_id'], $validated),
            'transcript.update' => $this->store->updateTurn($context['branch_id'], $context['gateway_id'], $validated, false),
            'transcript.final' => $this->store->updateTurn($context['branch_id'], $context['gateway_id'], $validated, true),
            'call.ended' => $this->store->endCall($context['branch_id'], $context['gateway_id'], $validated),
            default => null,
        };

        return response()->json([
            'data' => [
                'accepted' => true,
                'event_id' => $context['event_id'],
                'call_id' => $state['call_id'] ?? null,
            ],
        ], 202);
    }

    public function authorizeMedia(Request $request): JsonResponse
    {
        $context = $this->authenticator->authenticate($request);
        $payload = json_decode($context['raw_body'], true);
        if (! is_array($payload) || array_is_list($payload) || array_key_exists('branch_id', $payload)) {
            throw new HttpException(422, 'Demande d’autorisation invalide.');
        }

        $validated = Validator::make($payload, [
            'call_id' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,128}$/'],
        ])->validate();

        return response()->json([
            'data' => [
                'call_id' => $validated['call_id'],
                'media_authorized' => $this->store->isConsented(
                    $context['branch_id'],
                    $validated['call_id'],
                    $context['gateway_id']
                ),
            ],
        ]);
    }
}
