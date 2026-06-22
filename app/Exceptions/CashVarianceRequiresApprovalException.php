<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * [Sprint 1D / F-4 — 2026-05-16]
 *
 * Thrown by {@see \App\Services\Cash\CashDrawerService::reconcileSession()}
 * (and {@see \App\Services\Cash\CashDrawerService::closeSession()} indirectly
 * through reconcile) when a cash variance crosses the configured threshold
 * (`config('cash.variance_threshold_eur')`) and either:
 *
 *   - `variance_reason` is missing / blank, or
 *   - the operating user does not hold the
 *     `cash.reconcile.variance.override` permission (Admin / Branch Manager).
 *
 * Renders as HTTP 422 — same shape as the other fiscal/cash exceptions so the
 * front-end can intercept on `code` rather than parsing the message.
 *
 * NF525 rationale: cashiers must never silently book a discrepancy.
 * Either the variance stays under the threshold, or a manager actively
 * approves it with a written reason that lands in the audit_logs chain.
 */
class CashVarianceRequiresApprovalException extends \RuntimeException implements HttpExceptionInterface
{
    public const CODE_REASON_REQUIRED = 'CASH_VARIANCE_REASON_REQUIRED';
    public const CODE_MANAGER_APPROVAL = 'CASH_VARIANCE_MANAGER_APPROVAL_REQUIRED';

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly float $variance,
        private readonly float $threshold,
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

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getVariance(): float
    {
        return $this->variance;
    }

    public function getThreshold(): float
    {
        return $this->threshold;
    }

    public function render($request): JsonResponse
    {
        return new JsonResponse([
            'success'   => false,
            'message'   => $this->getMessage(),
            'code'      => $this->errorCode,
            'variance'  => $this->variance,
            'threshold' => $this->threshold,
        ], $this->status);
    }
}
