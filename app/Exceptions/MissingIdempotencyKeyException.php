<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * F-VERIFY-09-02 — Thrown by `IdempotencyKeyMiddleware` when an opt-in
 * route is hit without a valid `X-Idempotency-Key` header (or with a
 * malformed one). Auto-rendered as a 422 JSON response.
 */
class MissingIdempotencyKeyException extends \RuntimeException implements HttpExceptionInterface
{
    public function __construct(
        string $message = 'X-Idempotency-Key header required.',
        private readonly int $status = 422,
    ) {
        parent::__construct($message, $status);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function render($request): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->getMessage(),
            'code'    => 'MISSING_IDEMPOTENCY_KEY',
        ], $this->status);
    }
}
