<?php

namespace Tests\Feature\VoiceOrder;

use App\Services\VoiceOrder\VoiceOrderTranscriptStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class VoiceOrderGatewaySecurityTest extends TestCase
{
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = str_repeat('s', 40);
        Config::set('voice_order.enabled', true);
        Config::set('voice_order.gateway.gateways', [
            'gw-branch-a' => ['branch_id' => 41, 'secret' => $this->secret],
        ]);
        Config::set('voice_order.gateway.timestamp_tolerance_seconds', 300);
        Config::set('voice_order.gateway.replay_ttl_seconds', 600);
        Cache::flush();
    }

    public function test_valid_signature_derives_branch_and_replay_is_rejected(): void
    {
        $payload = ['event' => 'call.started', 'call_id' => 'call-secure-0001', 'caller_number' => '0612345678'];
        $first = $this->signedPost('/api/voice-order/gateway/events', $payload, eventId: 'event-secure-0001');
        $first->assertStatus(202)->assertJsonPath('data.accepted', true);

        $state = app(VoiceOrderTranscriptStore::class)->get(41, 'call-secure-0001');
        $this->assertSame(41, $state['branch_id']);
        $this->assertSame('0612345678', $state['caller_number']);

        $this->signedPost('/api/voice-order/gateway/events', $payload, eventId: 'event-secure-0001')
            ->assertStatus(409);
    }

    public function test_invalid_stale_and_body_selected_branch_fail_closed(): void
    {
        $payload = ['event' => 'call.started', 'call_id' => 'call-secure-0002'];

        $this->signedPost('/api/voice-order/gateway/events', $payload, eventId: 'event-secure-0002', signature: str_repeat('0', 64))
            ->assertStatus(401);

        $this->signedPost('/api/voice-order/gateway/events', $payload, eventId: 'event-secure-0003', timestamp: time() - 900)
            ->assertStatus(401);

        $this->signedPost('/api/voice-order/gateway/events', $payload + ['branch_id' => 999], eventId: 'event-secure-0004')
            ->assertStatus(422);
        $this->assertNull(Cache::get('voice-order:call:999:'.hash('sha256', 'call-secure-0002')));
    }

    public function test_media_is_denied_until_per_call_consent_and_pre_consent_text_is_rejected(): void
    {
        $callId = 'call-consent-0001';
        $this->signedPost('/api/voice-order/gateway/events', [
            'event' => 'call.started', 'call_id' => $callId,
        ], eventId: 'event-consent-0001')->assertStatus(202);

        $this->signedPost('/api/voice-order/gateway/authorize-media', ['call_id' => $callId], eventId: 'event-consent-0002')
            ->assertOk()->assertJsonPath('data.media_authorized', false);

        $this->signedPost('/api/voice-order/gateway/events', [
            'event' => 'transcript.final', 'call_id' => $callId, 'turn_id' => 'turn-before-0001', 'text' => 'avant consentement',
        ], eventId: 'event-consent-0003')->assertStatus(409);

        app(VoiceOrderTranscriptStore::class)->consent(41, $callId);
        $this->signedPost('/api/voice-order/gateway/authorize-media', ['call_id' => $callId], eventId: 'event-consent-0004')
            ->assertOk()->assertJsonPath('data.media_authorized', true);
    }

    private function signedPost(
        string $uri,
        array $payload,
        string $eventId,
        ?int $timestamp = null,
        ?string $signature = null
    ) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stamp = (string) ($timestamp ?? time());
        $signature ??= hash_hmac('sha256', $stamp."\n".$eventId."\n".$body, $this->secret);

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_VOICE_GATEWAY_ID' => 'gw-branch-a',
            'HTTP_X_VOICE_TIMESTAMP' => $stamp,
            'HTTP_X_VOICE_EVENT_ID' => $eventId,
            'HTTP_X_VOICE_SIGNATURE' => $signature,
        ], $body);
    }
}
