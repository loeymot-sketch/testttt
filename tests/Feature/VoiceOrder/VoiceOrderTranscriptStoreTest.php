<?php

namespace Tests\Feature\VoiceOrder;

use App\Models\ActionLog;
use App\Services\VoiceOrder\VoiceOrderTranscriptStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VoiceOrderTranscriptStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('voice_order.transcript_chunk_bytes', 10000);
    }

    public function test_final_transcript_is_chunked_transactionally_and_scoped_to_branch(): void
    {
        $store = app(VoiceOrderTranscriptStore::class);
        $callId = 'call-persist-0001';
        $store->startCall(11, 'gw-a', ['call_id' => $callId, 'caller_number' => '+33612345678']);

        try {
            $store->updateTurn(11, 'gw-a', ['call_id' => $callId, 'turn_id' => 'turn-denied-01', 'text' => 'secret'], true);
            $this->fail('A transcript must not be accepted before consent.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $store->consent(11, $callId);
        for ($i = 0; $i < 4; $i++) {
            $store->updateTurn(11, 'gw-a', [
                'call_id' => $callId,
                'turn_id' => 'turn-final-000'.$i,
                'speaker' => $i % 2 ? 'employee' : 'caller',
                'text' => str_repeat('sandwich cayenne salade tomate oignon ', 100),
                'confidence' => 0.91,
            ], true);
        }
        $store->endCall(11, 'gw-a', ['call_id' => $callId]);

        $rows = ActionLog::query()
            ->where('branch_id', 11)
            ->where('action', VoiceOrderTranscriptStore::ACTION_TRANSCRIPT)
            ->orderBy('id')
            ->get();
        $this->assertGreaterThan(1, $rows->count());
        $this->assertSame(0, (int) json_decode($rows->first()->details, true)['chunk_index']);
        $this->assertNull($store->get(12, $callId));
        $this->assertNotNull($store->get(11, $callId));

        Cache::flush();
        $recent = $store->snapshot(11)['recent_calls'];
        $this->assertSame($callId, $recent[0]['call_id']);
        $archived = $store->get(11, $callId);
        $this->assertNotEmpty($archived['turns'][0]['text']);
        $this->assertNotNull($archived['consented_at']);
    }

    public function test_duplicate_final_turn_and_duplicate_end_do_not_duplicate_persistence(): void
    {
        $store = app(VoiceOrderTranscriptStore::class);
        $callId = 'call-idempotent-01';
        $store->startCall(21, 'gw-a', ['call_id' => $callId]);
        $store->consent(21, $callId);
        $turn = ['call_id' => $callId, 'turn_id' => 'turn-idempotent-01', 'text' => 'un cheeseburger'];
        $store->updateTurn(21, 'gw-a', $turn, true);
        $store->updateTurn(21, 'gw-a', $turn, true);
        $store->endCall(21, 'gw-a', ['call_id' => $callId]);
        $store->endCall(21, 'gw-a', ['call_id' => $callId]);

        try {
            $store->consent(21, $callId);
            $this->fail('An ended call must not be consented again.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(1, ActionLog::query()
            ->where('branch_id', 21)
            ->where('action', VoiceOrderTranscriptStore::ACTION_TRANSCRIPT)
            ->count());
    }

    public function test_purge_deletes_only_expired_voice_rows_with_explicit_branch_scope(): void
    {
        $old = now()->subDays(45);
        DB::table('action_logs')->insert([
            ['branch_id' => 31, 'user_id' => null, 'action' => VoiceOrderTranscriptStore::ACTION_TRANSCRIPT, 'resource' => 'voice_order_call:old-call', 'details' => '{}', 'created_at' => $old, 'updated_at' => $old],
            ['branch_id' => 31, 'user_id' => null, 'action' => VoiceOrderTranscriptStore::ACTION_ORDER_LINK, 'resource' => 'voice_order_call:old-call', 'details' => '{}', 'created_at' => $old, 'updated_at' => $old],
            ['branch_id' => 31, 'user_id' => null, 'action' => 'another.action', 'resource' => 'voice_order_call:old-call', 'details' => '{}', 'created_at' => $old, 'updated_at' => $old],
            ['branch_id' => 32, 'user_id' => null, 'action' => VoiceOrderTranscriptStore::ACTION_TRANSCRIPT, 'resource' => 'voice_order_call:recent-call', 'details' => '{}', 'created_at' => now(), 'updated_at' => now()],
            ['branch_id' => 33, 'user_id' => null, 'action' => VoiceOrderTranscriptStore::ACTION_ORDER_LINK, 'resource' => 'voice_order_call:orphan-link', 'details' => '{}', 'created_at' => $old, 'updated_at' => $old],
        ]);

        $this->artisan('voice-order:purge-transcripts', ['--days' => 30])->assertSuccessful();

        $this->assertDatabaseMissing('action_logs', ['branch_id' => 31, 'action' => VoiceOrderTranscriptStore::ACTION_TRANSCRIPT]);
        $this->assertDatabaseMissing('action_logs', ['branch_id' => 31, 'action' => VoiceOrderTranscriptStore::ACTION_ORDER_LINK]);
        $this->assertDatabaseHas('action_logs', ['branch_id' => 31, 'action' => 'another.action']);
        $this->assertDatabaseHas('action_logs', ['branch_id' => 32, 'action' => VoiceOrderTranscriptStore::ACTION_TRANSCRIPT]);
        $this->assertDatabaseMissing('action_logs', ['branch_id' => 33, 'action' => VoiceOrderTranscriptStore::ACTION_ORDER_LINK]);
    }
}
