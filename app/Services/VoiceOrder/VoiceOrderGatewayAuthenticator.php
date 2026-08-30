<?php

namespace App\Services\VoiceOrder;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VoiceOrderGatewayAuthenticator
{
    /**
     * @return array{gateway_id:string,branch_id:int,event_id:string,timestamp:int,raw_body:string}
     */
    public function authenticate(Request $request): array
    {
        if (! (bool) config('voice_order.enabled', false)) {
            throw new HttpException(503, 'Assistant téléphonique désactivé.');
        }

        $rawBody = (string) $request->getContent();
        $maxBytes = (int) config('voice_order.gateway.max_payload_bytes', 65536);
        if ($rawBody === '' || strlen($rawBody) > $maxBytes) {
            throw new HttpException(413, 'Payload passerelle vide ou trop volumineux.');
        }

        $gatewayId = trim((string) $request->header(config('voice_order.gateway.id_header'), ''));
        $timestampRaw = trim((string) $request->header(config('voice_order.gateway.timestamp_header'), ''));
        $eventId = trim((string) $request->header(config('voice_order.gateway.event_header'), ''));
        $provided = strtolower(trim((string) $request->header(config('voice_order.gateway.signature_header'), '')));

        if (! preg_match('/^[A-Za-z0-9._:-]{3,80}$/', $gatewayId)
            || ! preg_match('/^[0-9]{10}$/', $timestampRaw)
            || ! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $eventId)
            || ! preg_match('/^[a-f0-9]{64}$/', $provided)) {
            throw new HttpException(401, 'Signature passerelle invalide.');
        }

        $gateway = config('voice_order.gateway.gateways.'.$gatewayId);
        $branchId = (int) ($gateway['branch_id'] ?? 0);
        $secret = (string) ($gateway['secret'] ?? '');
        if ($branchId <= 0 || strlen($secret) < 24) {
            throw new HttpException(401, 'Passerelle inconnue ou incomplète.');
        }

        $timestamp = (int) $timestampRaw;
        $tolerance = (int) config('voice_order.gateway.timestamp_tolerance_seconds', 300);
        if (abs(time() - $timestamp) > $tolerance) {
            throw new HttpException(401, 'Signature passerelle expirée.');
        }

        $expected = hash_hmac('sha256', $timestampRaw."\n".$eventId."\n".$rawBody, $secret);
        if (! hash_equals($expected, $provided)) {
            throw new HttpException(401, 'Signature passerelle invalide.');
        }

        $replayKey = 'voice-order:replay:'.$gatewayId.':'.hash('sha256', $eventId);
        $reserved = Cache::add(
            $replayKey,
            true,
            now()->addSeconds((int) config('voice_order.gateway.replay_ttl_seconds', 600))
        );
        if (! $reserved) {
            throw new HttpException(409, 'Événement passerelle déjà traité.');
        }

        return [
            'gateway_id' => $gatewayId,
            'branch_id' => $branchId,
            'event_id' => $eventId,
            'timestamp' => $timestamp,
            'raw_body' => $rawBody,
        ];
    }
}
