<?php

namespace App\Services\Idempotency;

/**
 * F-VERIFY-09-02 — Immutable DTO representing a single idempotency replay record.
 *
 * Used by IdempotencyKeyRepository implementations to serialize/deserialize
 * the response payload that should be replayed on a duplicate POST.
 */
final class IdempotencyRecord
{
    public function __construct(
        public readonly int    $status,
        public readonly array  $headers,
        public readonly string $bodyBase64,
        public readonly string $payloadHash,
        public readonly string $createdAt,
        public readonly string $state, // 'PENDING' | 'COMPLETED'
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            status:      (int) ($data['status'] ?? 0),
            headers:     (array) ($data['headers'] ?? []),
            bodyBase64:  (string) ($data['body_b64'] ?? ''),
            payloadHash: (string) ($data['payload_hash'] ?? ''),
            createdAt:   (string) ($data['created_at'] ?? ''),
            state:       (string) ($data['state'] ?? 'COMPLETED'),
        );
    }

    public function toArray(): array
    {
        return [
            'status'       => $this->status,
            'headers'      => $this->headers,
            'body_b64'     => $this->bodyBase64,
            'payload_hash' => $this->payloadHash,
            'created_at'   => $this->createdAt,
            'state'        => $this->state,
        ];
    }
}
