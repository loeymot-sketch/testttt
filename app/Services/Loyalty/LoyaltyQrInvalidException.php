<?php

namespace App\Services\Loyalty;

use RuntimeException;

/**
 * [LCS-S-001 / 2026-05-19] Thrown by LoyaltyQrSigner when a token fails
 * signature, expiration, format, or replay verification.
 *
 * The `code` property is a machine-readable surface code that maps to
 * the LoyaltyController::scan response `error_code` field. The kiosk
 * endpoint still returns HTTP 200 (per §12 invariant : a failed scan
 * MUST NOT block the parcours).
 *
 * Stable codes :
 *  - qr_invalid_format     : malformed token shell (missing prefix, etc)
 *  - qr_invalid_signature  : HMAC mismatch (tampered or wrong key)
 *  - qr_expired            : exp + leeway < now
 *  - qr_replay             : nonce already consumed
 *  - qr_unsupported_version: future-format token from upgraded mobile
 */
class LoyaltyQrInvalidException extends RuntimeException
{
    public function __construct(string $errorCode, string $detail = '')
    {
        $this->message = $detail !== '' ? "{$errorCode}: {$detail}" : $errorCode;
        $this->code = 0;
        $this->errorCode = $errorCode;

        parent::__construct($this->message);
    }

    public string $errorCode;
}
