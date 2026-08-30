<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Services\VoiceOrder\VoiceOrderTranscriptStore;
use Illuminate\Console\Command;

class PurgeVoiceOrderTranscriptsCommand extends Command
{
    protected $signature = 'voice-order:purge-transcripts {--branch=} {--days=}';

    protected $description = 'Supprime les transcriptions téléphoniques arrivées à expiration, filiale par filiale.';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('voice_order.retention_days', 30)));
        $cutoff = now()->subDays($days);
        $requestedBranch = (int) ($this->option('branch') ?: 0);

        $branches = $requestedBranch > 0
            ? collect([$requestedBranch])
            : ActionLog::query()
                ->whereIn('action', [
                    VoiceOrderTranscriptStore::ACTION_TRANSCRIPT,
                    VoiceOrderTranscriptStore::ACTION_ORDER_LINK,
                ])
                ->where('created_at', '<', $cutoff)
                ->whereNotNull('branch_id')
                ->distinct()
                ->pluck('branch_id');

        $deleted = 0;
        foreach ($branches as $branchId) {
            $branchId = (int) $branchId;
            if ($branchId <= 0) {
                continue;
            }

            $resources = ActionLog::query()
                ->where('branch_id', $branchId)
                ->whereIn('action', [
                    VoiceOrderTranscriptStore::ACTION_TRANSCRIPT,
                    VoiceOrderTranscriptStore::ACTION_ORDER_LINK,
                ])
                ->where('created_at', '<', $cutoff)
                ->pluck('resource')
                ->unique();

            foreach ($resources as $resource) {
                $deleted += ActionLog::query()
                    ->where('branch_id', $branchId)
                    ->whereIn('action', [
                        VoiceOrderTranscriptStore::ACTION_TRANSCRIPT,
                        VoiceOrderTranscriptStore::ACTION_ORDER_LINK,
                    ])
                    ->where('resource', $resource)
                    ->delete();
            }
        }

        $this->info(sprintf('%d ligne(s) de transcription supprimée(s).', $deleted));

        return self::SUCCESS;
    }
}
