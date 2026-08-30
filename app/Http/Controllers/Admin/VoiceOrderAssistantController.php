<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\VoiceOrder\VoiceOrderBranchContext;
use App\Services\VoiceOrder\VoiceOrderDraftExtractor;
use App\Services\VoiceOrder\VoiceOrderRecommendedReply;
use App\Services\VoiceOrder\VoiceOrderTranscriptStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VoiceOrderAssistantController extends Controller
{
    public function __construct(
        private VoiceOrderBranchContext $branches,
        private VoiceOrderTranscriptStore $store,
        private VoiceOrderDraftExtractor $extractor,
        private VoiceOrderRecommendedReply $replies
    ) {
    }

    public function snapshot(Request $request): JsonResponse
    {
        $branchId = $this->branches->fromAdminRequest($request);
        if (! (bool) config('voice_order.enabled', false)) {
            return response()->json(['data' => [
                'enabled' => false,
                'active_calls' => [],
                'recent_calls' => [],
                'message' => 'Assistant téléphonique désactivé. La commande téléphone manuelle reste disponible.',
            ]]);
        }

        return response()->json(['data' => ['enabled' => true] + $this->store->snapshot($branchId)]);
    }

    public function show(Request $request, string $callId): JsonResponse
    {
        $branchId = $this->branches->fromAdminRequest($request);
        $call = $this->store->get($branchId, $callId);
        if (! $call) {
            throw new HttpException(404, 'Appel introuvable dans cette filiale.');
        }

        return response()->json(['data' => $call]);
    }

    public function consent(Request $request, string $callId): JsonResponse
    {
        $this->ensureEnabled();
        $branchId = $this->branches->fromAdminRequest($request);
        $validated = $request->validate(['caller_informed' => ['required', 'accepted']]);
        unset($validated);

        return response()->json(['data' => $this->store->consent($branchId, $callId)]);
    }

    public function extract(Request $request, string $callId): JsonResponse
    {
        $this->ensureEnabled();
        $branchId = $this->branches->fromAdminRequest($request);
        $call = $this->store->get($branchId, $callId);
        if (! $call) {
            throw new HttpException(404, 'Appel introuvable dans cette filiale.');
        }

        $transcript = implode("\n", array_map(
            fn (array $turn) => trim((string) ($turn['text'] ?? '')),
            (array) ($call['turns'] ?? [])
        ));
        $draft = $this->extractor->extract($branchId, $transcript);
        $reply = $this->replies->forDraft($draft);

        return response()->json(['data' => $this->store->setDraft($branchId, $callId, $draft, $reply)]);
    }

    public function linkOrder(Request $request, string $callId): JsonResponse
    {
        $this->ensureEnabled();
        $branchId = $this->branches->fromAdminRequest($request);
        $validated = $request->validate(['order_id' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->store->linkOrder(
            $branchId,
            (int) $request->user()->id,
            $callId,
            (int) $validated['order_id']
        )]);
    }

    private function ensureEnabled(): void
    {
        if (! (bool) config('voice_order.enabled', false)) {
            throw new HttpException(503, 'Assistant téléphonique désactivé.');
        }
    }
}
