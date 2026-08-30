<?php

namespace App\Services\VoiceOrder;

use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\ActionLog;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VoiceOrderTranscriptStore
{
    public const ACTION_TRANSCRIPT = 'voice_order.transcript.chunk';
    public const ACTION_ORDER_LINK = 'voice_order.order_link';

    public function startCall(int $branchId, string $gatewayId, array $payload): array
    {
        $callId = $this->validCallId($payload['call_id'] ?? null);

        return $this->withCallLock($branchId, $callId, function () use ($branchId, $callId, $gatewayId, $payload) {
            $existing = $this->cached($branchId, $callId);
            if (is_array($existing)
                && ! hash_equals((string) ($existing['gateway_id'] ?? ''), $gatewayId)) {
                throw new HttpException(409, 'Cet appel appartient déjà à une autre passerelle.');
            }
            $state = $existing ?: [
                'call_id' => $callId,
                'branch_id' => $branchId,
                'gateway_id' => $gatewayId,
                'status' => 'ringing',
                'caller_number' => $this->normalizePhone($payload['caller_number'] ?? null),
                'caller_name' => $this->cleanLabel($payload['caller_name'] ?? null, 120),
                'started_at' => $this->isoNow(),
                'consented_at' => null,
                'ended_at' => null,
                'turns' => [],
                'live_turn' => null,
                'draft' => null,
                'recommended_reply' => 'Informez le client avant de démarrer la transcription.',
                'order_id' => null,
                'persisted_at' => null,
            ];

            $state['last_event_at'] = $this->isoNow();
            $this->put($branchId, $callId, $state);
            $this->rememberInIndex($branchId, $callId);

            return $state;
        });
    }

    public function consent(int $branchId, string $callId): array
    {
        $callId = $this->validCallId($callId);

        return $this->withCallLock($branchId, $callId, function () use ($branchId, $callId) {
            $state = $this->requireCached($branchId, $callId);
            if (($state['status'] ?? null) === 'ended') {
                throw new HttpException(409, 'Un appel terminé ne peut pas être réautorisé.');
            }
            if (! $state['consented_at']) {
                $state['consented_at'] = $this->isoNow();
            }
            if ($state['status'] !== 'ended') {
                $state['status'] = 'transcribing';
            }
            $state['recommended_reply'] = 'Écoutez le client ; les éléments incertains resteront à valider.';
            $state['last_event_at'] = $this->isoNow();
            $this->put($branchId, $callId, $state);

            return $state;
        });
    }

    public function isConsented(int $branchId, string $callId, string $gatewayId): bool
    {
        $callId = $this->validCallId($callId);
        $state = $this->cached($branchId, $callId);

        return is_array($state)
            && hash_equals((string) ($state['gateway_id'] ?? ''), $gatewayId)
            && ! empty($state['consented_at'])
            && ($state['status'] ?? null) !== 'ended';
    }

    public function updateTurn(int $branchId, string $gatewayId, array $payload, bool $final): array
    {
        $callId = $this->validCallId($payload['call_id'] ?? null);

        return $this->withCallLock($branchId, $callId, function () use ($branchId, $callId, $gatewayId, $payload, $final) {
            $state = $this->requireCached($branchId, $callId);
            if (! hash_equals((string) $state['gateway_id'], $gatewayId)) {
                throw new HttpException(403, 'Passerelle incorrecte pour cet appel.');
            }
            if (empty($state['consented_at'])) {
                throw new HttpException(409, 'Le client doit être informé avant toute transcription.');
            }
            if (($state['status'] ?? null) === 'ended') {
                throw new HttpException(409, 'Appel déjà terminé.');
            }

            $speaker = $payload['speaker'] ?? 'unknown';
            $turn = [
                'turn_id' => $this->cleanLabel($payload['turn_id'] ?? null, 128)
                    ?: hash('sha256', (string) ($payload['text'] ?? '').'|'.microtime(true)),
                'speaker' => in_array($speaker, ['caller', 'employee', 'unknown'], true)
                    ? $speaker : 'unknown',
                'text' => $this->cleanText($payload['text'] ?? null, 4000),
                'confidence' => isset($payload['confidence'])
                    ? max(0.0, min(1.0, (float) $payload['confidence'])) : null,
                'at' => $this->isoNow(),
            ];
            if ($turn['text'] === '') {
                throw new HttpException(422, 'Tour de parole vide.');
            }

            if ($final) {
                $turnIds = array_column((array) $state['turns'], 'turn_id');
                if (! in_array($turn['turn_id'], $turnIds, true)) {
                    $state['turns'][] = $turn;
                    $state['turns'] = array_slice($state['turns'], -300);
                }
                $state['live_turn'] = null;
            } else {
                $state['live_turn'] = $turn;
            }

            $state['status'] = 'transcribing';
            $state['last_event_at'] = $this->isoNow();
            $this->put($branchId, $callId, $state);

            return $state;
        });
    }

    public function endCall(int $branchId, string $gatewayId, array $payload): array
    {
        $callId = $this->validCallId($payload['call_id'] ?? null);

        return $this->withCallLock($branchId, $callId, function () use ($branchId, $callId, $gatewayId) {
            $state = $this->requireCached($branchId, $callId);
            if (! hash_equals((string) $state['gateway_id'], $gatewayId)) {
                throw new HttpException(403, 'Passerelle incorrecte pour cet appel.');
            }

            $state['status'] = 'ended';
            $state['ended_at'] = $state['ended_at'] ?: $this->isoNow();
            $state['live_turn'] = null;
            $state['last_event_at'] = $this->isoNow();

            if (empty($state['persisted_at']) && ! empty($state['turns'])) {
                $this->persistTranscript($state);
                $state['persisted_at'] = $this->isoNow();
            }

            $this->put($branchId, $callId, $state);

            return $state;
        });
    }

    public function setDraft(int $branchId, string $callId, array $draft, string $reply): array
    {
        $callId = $this->validCallId($callId);

        return $this->withCallLock($branchId, $callId, function () use ($branchId, $callId, $draft, $reply) {
            $state = $this->requireCached($branchId, $callId);
            $state['draft'] = $draft;
            $state['recommended_reply'] = $reply;
            $state['last_event_at'] = $this->isoNow();
            $this->put($branchId, $callId, $state);

            return $state;
        });
    }

    public function get(int $branchId, string $callId): ?array
    {
        $callId = $this->validCallId($callId);
        $cached = $this->cached($branchId, $callId);

        return is_array($cached) ? $cached : $this->fromPersistence($branchId, $callId);
    }

    public function snapshot(int $branchId): array
    {
        $ids = (array) Cache::get($this->indexKey($branchId), []);
        $calls = [];
        foreach ($ids as $callId) {
            $state = $this->cached($branchId, (string) $callId);
            if (is_array($state)) {
                $calls[] = $state;
            }
        }

        $known = array_fill_keys(array_map(fn (array $call) => (string) $call['call_id'], $calls), true);
        foreach ($this->recentPersisted($branchId) as $persisted) {
            if (! isset($known[$persisted['call_id']])) {
                $calls[] = $persisted;
                $known[$persisted['call_id']] = true;
            }
        }

        usort($calls, fn (array $a, array $b) => strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? '')));

        return [
            'active_calls' => array_values(array_filter($calls, fn (array $call) => ($call['status'] ?? null) !== 'ended')),
            'recent_calls' => array_slice($calls, 0, (int) config('voice_order.recent_limit', 30)),
        ];
    }

    public function linkOrder(int $branchId, int $userId, string $callId, int $orderId): array
    {
        $callId = $this->validCallId($callId);
        $order = Order::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereKey($orderId)
            ->first();

        if (! $order) {
            throw new HttpException(404, 'Commande introuvable dans cette filiale.');
        }
        if ((string) $order->source_surface !== 'phone'
            || (int) $order->payment_status !== PaymentStatus::PENDING_COUNTER
            || (int) $order->pos_payment_method !== PosPaymentMethod::COUNTER_DEFERRED) {
            throw new HttpException(422, 'Seule une commande téléphone différée peut être reliée.');
        }
        if (! $this->get($branchId, $callId)) {
            throw new HttpException(404, 'Appel introuvable dans cette filiale.');
        }

        return $this->withCallLock($branchId, $callId, function () use ($branchId, $userId, $callId, $orderId) {
            return DB::transaction(function () use ($branchId, $userId, $callId, $orderId) {
                $existing = ActionLog::query()
                    ->where('branch_id', $branchId)
                    ->where('action', self::ACTION_ORDER_LINK)
                    ->where('resource', $this->resource($callId))
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $details = json_decode((string) $existing->details, true) ?: [];
                    $linkedOrderId = (int) ($details['order_id'] ?? 0);
                    if ($linkedOrderId !== $orderId) {
                        throw new HttpException(409, 'Cet appel est déjà relié à une autre commande.');
                    }

                    return ['call_id' => $callId, 'order_id' => $orderId, 'linked' => true, 'idempotent' => true];
                }

                ActionLog::create([
                    'user_id' => $userId,
                    'branch_id' => $branchId,
                    'action' => self::ACTION_ORDER_LINK,
                    'resource' => $this->resource($callId),
                    'details' => json_encode(['call_id' => $callId, 'order_id' => $orderId], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);

                $state = $this->cached($branchId, $callId);
                if (is_array($state)) {
                    $state['order_id'] = $orderId;
                    $this->put($branchId, $callId, $state);
                }

                return ['call_id' => $callId, 'order_id' => $orderId, 'linked' => true, 'idempotent' => false];
            });
        });
    }

    private function persistTranscript(array $state): void
    {
        $transcript = implode("\n", array_map(function (array $turn) {
            $speaker = match ($turn['speaker'] ?? 'unknown') {
                'caller' => 'Client',
                'employee' => 'Employé',
                default => 'Interlocuteur',
            };

            return $speaker.' : '.trim((string) ($turn['text'] ?? ''));
        }, (array) $state['turns']));

        $chunks = $this->byteChunks($transcript, (int) config('voice_order.transcript_chunk_bytes', 40000));
        DB::transaction(function () use ($state, $chunks) {
            $existing = ActionLog::query()
                ->where('branch_id', (int) $state['branch_id'])
                ->where('action', self::ACTION_TRANSCRIPT)
                ->where('resource', $this->resource((string) $state['call_id']))
                ->lockForUpdate()
                ->exists();
            if ($existing) {
                return;
            }

            foreach ($chunks as $index => $chunk) {
                ActionLog::create([
                    'user_id' => null,
                    'branch_id' => (int) $state['branch_id'],
                    'action' => self::ACTION_TRANSCRIPT,
                    'resource' => $this->resource((string) $state['call_id']),
                    'details' => json_encode([
                        'call_id' => $state['call_id'],
                        'chunk_index' => $index,
                        'chunk_count' => count($chunks),
                        'caller_number' => $state['caller_number'],
                        'caller_name' => $state['caller_name'],
                        'started_at' => $state['started_at'],
                        'consented_at' => $state['consented_at'],
                        'ended_at' => $state['ended_at'],
                        'text' => $chunk,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    private function fromPersistence(int $branchId, string $callId): ?array
    {
        $rows = ActionLog::query()
            ->where('branch_id', $branchId)
            ->where('action', self::ACTION_TRANSCRIPT)
            ->where('resource', $this->resource($callId))
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $decoded = $rows->map(fn (ActionLog $row) => json_decode((string) $row->details, true) ?: [])->all();
        usort($decoded, fn (array $a, array $b) => ((int) ($a['chunk_index'] ?? 0)) <=> ((int) ($b['chunk_index'] ?? 0)));
        $first = $decoded[0];
        $link = ActionLog::query()
            ->where('branch_id', $branchId)
            ->where('action', self::ACTION_ORDER_LINK)
            ->where('resource', $this->resource($callId))
            ->first();
        $linkDetails = $link ? (json_decode((string) $link->details, true) ?: []) : [];

        return [
            'call_id' => $callId,
            'branch_id' => $branchId,
            'status' => 'ended',
            'caller_number' => $first['caller_number'] ?? null,
            'caller_name' => $first['caller_name'] ?? null,
            'started_at' => $first['started_at'] ?? null,
            'ended_at' => $first['ended_at'] ?? null,
            'consented_at' => $first['consented_at'] ?? null,
            'turns' => [[
                'turn_id' => 'persisted',
                'speaker' => 'unknown',
                'text' => implode('', array_column($decoded, 'text')),
                'confidence' => null,
                'at' => $first['ended_at'] ?? null,
            ]],
            'live_turn' => null,
            'draft' => null,
            'recommended_reply' => 'Transcription terminée — vérifiez la commande avant validation.',
            'order_id' => isset($linkDetails['order_id']) ? (int) $linkDetails['order_id'] : null,
            'persisted_at' => $rows->last()?->created_at?->toISOString(),
        ];
    }

    private function recentPersisted(int $branchId): array
    {
        $limit = (int) config('voice_order.recent_limit', 30);
        $rows = ActionLog::query()
            ->where('branch_id', $branchId)
            ->where('action', self::ACTION_TRANSCRIPT)
            ->where('created_at', '>=', now()->subDays((int) config('voice_order.retention_days', 30)))
            ->latest('id')
            ->limit($limit * 8)
            ->get();

        $links = ActionLog::query()
            ->where('branch_id', $branchId)
            ->where('action', self::ACTION_ORDER_LINK)
            ->whereIn('resource', $rows->pluck('resource')->filter()->unique()->values())
            ->get()
            ->keyBy('resource');

        $calls = [];
        foreach ($rows as $row) {
            $details = json_decode((string) $row->details, true) ?: [];
            $callId = (string) ($details['call_id'] ?? '');
            if ($callId === '' || isset($calls[$callId])) {
                continue;
            }
            $link = $links->get($row->resource);
            $linkDetails = $link ? (json_decode((string) $link->details, true) ?: []) : [];
            $calls[$callId] = [
                'call_id' => $callId,
                'branch_id' => $branchId,
                'status' => 'ended',
                'caller_number' => $details['caller_number'] ?? null,
                'caller_name' => $details['caller_name'] ?? null,
                'started_at' => $details['started_at'] ?? null,
                'ended_at' => $details['ended_at'] ?? null,
                'consented_at' => $details['consented_at'] ?? null,
                'turns' => [],
                'live_turn' => null,
                'draft' => null,
                'recommended_reply' => 'Ouvrez cet appel pour relire la transcription archivée.',
                'order_id' => isset($linkDetails['order_id']) ? (int) $linkDetails['order_id'] : null,
                'persisted_at' => $row->created_at?->toISOString(),
            ];
            if (count($calls) >= $limit) {
                break;
            }
        }

        return array_values($calls);
    }

    private function byteChunks(string $text, int $size): array
    {
        if ($text === '') {
            return [''];
        }

        $chunks = [];
        for ($offset = 0, $length = strlen($text); $offset < $length; $offset += strlen($chunk)) {
            $chunk = mb_strcut($text, $offset, $size, 'UTF-8');
            if ($chunk === '') {
                break;
            }
            $chunks[] = $chunk;
        }

        return $chunks ?: [''];
    }

    private function cached(int $branchId, string $callId): ?array
    {
        $state = Cache::get($this->callKey($branchId, $callId));

        return is_array($state) ? $state : null;
    }

    private function requireCached(int $branchId, string $callId): array
    {
        $state = $this->cached($branchId, $callId);
        if (! $state) {
            throw new HttpException(404, 'Appel actif introuvable dans cette filiale.');
        }

        return $state;
    }

    private function put(int $branchId, string $callId, array $state): void
    {
        Cache::put(
            $this->callKey($branchId, $callId),
            $state,
            now()->addSeconds((int) config('voice_order.live_ttl_seconds', 7200))
        );
    }

    private function rememberInIndex(int $branchId, string $callId): void
    {
        Cache::lock($this->indexKey($branchId).':lock', 5)->block(2, function () use ($branchId, $callId) {
            $key = $this->indexKey($branchId);
            $ids = array_values(array_filter((array) Cache::get($key, []), fn ($id) => (string) $id !== $callId));
            array_unshift($ids, $callId);
            Cache::put($key, array_slice($ids, 0, (int) config('voice_order.recent_limit', 30)), now()->addHours(24));
        });
    }

    private function withCallLock(int $branchId, string $callId, callable $callback)
    {
        return Cache::lock($this->callKey($branchId, $callId).':lock', 8)->block(3, $callback);
    }

    private function callKey(int $branchId, string $callId): string
    {
        return 'voice-order:call:'.$branchId.':'.hash('sha256', $callId);
    }

    private function indexKey(int $branchId): string
    {
        return 'voice-order:index:'.$branchId;
    }

    private function resource(string $callId): string
    {
        return 'voice_order_call:'.$callId;
    }

    private function validCallId($value): string
    {
        $callId = trim((string) $value);
        if (! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $callId)) {
            throw new HttpException(422, 'Identifiant d’appel invalide.');
        }

        return $callId;
    }

    private function normalizePhone($value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $leadingPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw) ?: '';

        return $digits === '' ? null : (($leadingPlus ? '+' : '').substr($digits, 0, 20));
    }

    private function cleanLabel($value, int $max): ?string
    {
        $clean = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value) ?? '');

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    private function cleanText($value, int $max): string
    {
        $clean = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', (string) $value) ?? '');

        return mb_substr($clean, 0, $max);
    }

    private function isoNow(): string
    {
        return now()->toISOString();
    }
}
