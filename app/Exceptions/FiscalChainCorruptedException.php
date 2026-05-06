<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * [P11-FZH / F-VERIFY-08-01]
 *
 * Thrown by FiscalChainValidator when either the Z report HMAC chain or
 * the audit_logs HMAC chain for a branch is corrupted.
 *
 * Extends \RuntimeException to preserve compatibility with existing tests
 * that expect a RuntimeException via expectException(\RuntimeException::class)
 * (notably ZOpenChainVerifiedTest::test_strict_mode_throws_runtime_exception).
 *
 * The exception message MUST start with 'NF525 Z-chain verification failed'
 * for the Z-chain branch (preserves contract with FiscalArchiveCommand,
 * SIEM correlation, and the same legacy test).
 */
class FiscalChainCorruptedException extends RuntimeException
{
    public const KIND_Z_CHAIN     = 'z_chain';
    public const KIND_AUDIT_CHAIN = 'audit_chain';

    public string $kind;
    public int    $branchId;
    public array  $errors;

    public function __construct(
        string $kind,
        int $branchId,
        array $errors,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->kind     = $kind;
        $this->branchId = $branchId;
        $this->errors   = $errors;
    }
}
