<?php

namespace App\Services\VoiceOrder;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class VoiceOrderDraftExtractor
{
    public function __construct(private VoiceOrderCatalogMatcher $matcher)
    {
    }

    public function extract(int $branchId, string $transcript): array
    {
        $catalog = $this->matcher->catalog($branchId);
        $fallback = $this->matcher->deterministic($transcript, $catalog);
        $key = (string) config('services.openai.key', '');

        if (! (bool) config('voice_order.openai.enabled', false) || $key === '' || trim($transcript) === '') {
            return $fallback;
        }

        $redacted = $this->redact($transcript);
        $rateKey = 'voice-order:extract:min-interval:'.$branchId;
        if (! Cache::add($rateKey, true, now()->addSeconds((int) config('voice_order.openai.minimum_interval_seconds', 3)))) {
            return $fallback + ['provider_status' => 'rate_limited'];
        }

        // Réduire coût et latence : le modèle ne reçoit qu'un shortlist lexical,
        // jamais les centaines de produits/options de toute la carte.
        $catalogPayload = array_map(fn (array $item) => [
            'id' => $item['id'],
            'name' => $item['name'],
            'slots' => $item['slots'],
            'options' => array_slice($item['options'], 0, 30),
        ], $this->shortlist($redacted, $catalog));

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout((int) config('voice_order.openai.timeout_seconds', 8))
                ->post(rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/').'/responses', [
                    'model' => (string) config('voice_order.openai.model', 'gpt-5.6-luna'),
                    'store' => false,
                    'reasoning' => ['effort' => 'none'],
                    'max_output_tokens' => 1200,
                    'input' => [[
                        'role' => 'user',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => json_encode([
                                'instruction' => 'Associe uniquement les produits cités aux IDs de ce catalogue. N’invente aucun prix, statut ou disponibilité. Toute personnalisation incertaine va dans notes et needs_review=true.',
                                'transcript_redacted' => $redacted,
                                'catalog' => $catalogPayload,
                            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        ]],
                    ]],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'voice_order_draft',
                            'strict' => true,
                            'schema' => $this->schema(),
                        ],
                    ],
                ]);
        } catch (\Throwable) {
            return $fallback + ['provider_status' => 'unavailable'];
        }

        if (! $response->successful()) {
            return $fallback + ['provider_status' => 'unavailable'];
        }

        $content = $this->outputText((array) $response->json());
        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return $fallback + ['provider_status' => 'invalid_response'];
        }

        return $this->validateModelDraft($decoded, $catalog, $fallback);
    }

    public function redact(string $transcript): string
    {
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[EMAIL]', $transcript) ?? $transcript;
        $text = preg_replace('/(?<!\w)(?:\+?33|0)[\s.\-]*(?:\d[\s.\-]*){9}(?!\w)/u', '[TELEPHONE]', $text) ?? $text;

        return mb_substr($text, 0, 16000);
    }

    private function validateModelDraft(array $decoded, array $catalog, array $fallback): array
    {
        $allowed = collect($catalog)->keyBy(fn (array $item) => (int) $item['id']);
        $lines = [];
        foreach ((array) ($decoded['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $itemId = (int) ($line['item_id'] ?? 0);
            $item = $allowed->get($itemId);
            if (! $item) {
                continue;
            }
            $notes = trim((string) ($line['notes'] ?? ''));
            $lines[] = [
                'item_id' => $itemId,
                'name' => $item['name'],
                'quantity' => max(1, min(20, (int) ($line['quantity'] ?? 1))),
                'notes' => $notes === '' ? null : mb_substr($notes, 0, 500),
                'confidence' => max(0.0, min(1.0, (float) ($line['confidence'] ?? 0.5))),
                'missing_slots' => array_values(array_intersect((array) ($line['missing_slots'] ?? []), (array) $item['slots'])),
                'needs_review' => true,
            ];
        }

        if ($lines === []) {
            return $fallback + ['provider_status' => 'no_valid_catalog_match'];
        }

        return [
            'source' => 'openai',
            'provider_status' => 'ok',
            'lines' => $lines,
            'ambiguities' => array_slice(array_values(array_filter(array_map('strval', (array) ($decoded['ambiguities'] ?? [])))), 0, 12),
            'needs_review' => true,
            'generated_at' => now()->toISOString(),
        ];
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['lines', 'ambiguities'],
            'properties' => [
                'lines' => [
                    'type' => 'array',
                    'maxItems' => 30,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['item_id', 'quantity', 'notes', 'confidence', 'missing_slots'],
                        'properties' => [
                            'item_id' => ['type' => 'integer'],
                            'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                            'notes' => ['type' => ['string', 'null'], 'maxLength' => 500],
                            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                            'missing_slots' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 12],
                        ],
                    ],
                ],
                'ambiguities' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 12],
            ],
        ];
    }

    private function shortlist(string $transcript, array $catalog): array
    {
        $normalized = $this->matcher->normalize($transcript);
        $tokens = array_values(array_filter(explode(' ', $normalized), fn (string $token) => strlen($token) >= 3));

        $ranked = collect($catalog)->map(function (array $item) use ($normalized, $tokens) {
            $name = $this->matcher->normalize((string) $item['name']);
            $haystack = $name.' '.$this->matcher->normalize(implode(' ', array_slice((array) $item['options'], 0, 30)));
            $score = str_contains($normalized, $name) ? 100 : 0;
            foreach ($tokens as $token) {
                if (str_contains($haystack, $token)) {
                    $score += 3;
                }
            }

            return ['score' => $score, 'item' => $item];
        })->sortByDesc('score');

        $positive = $ranked->filter(fn (array $entry) => $entry['score'] > 0)->take(120)->pluck('item')->values();
        if ($positive->isNotEmpty()) {
            return $positive->all();
        }

        // Aucun indice lexical : assez de noms pour demander une clarification,
        // mais plafond strict afin de garder le coût prévisible.
        return $ranked->take(80)->pluck('item')->values()->all();
    }

    private function outputText(array $payload): string
    {
        if (is_string($payload['output_text'] ?? null) && trim($payload['output_text']) !== '') {
            return $payload['output_text'];
        }

        foreach ((array) ($payload['output'] ?? []) as $output) {
            foreach ((array) ($output['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    return $content['text'];
                }
            }
        }

        return '';
    }
}
