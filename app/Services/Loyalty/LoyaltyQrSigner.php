<?php

namespace App\Services\Loyalty;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * [LCS-S-001 / 2026-05-19] Loyalty QR token signer and verifier.
 *
 * Token format : `lqr.<base64url payload>.<base64url hmac>`
 *
 * Payload JSON (canonicalised) :
 *   {
 *     "v":   1,                       // version (forward compat)
 *     "cust": <user_id>,              // customer User.id
 *     "code": "<loyalty_code>",       // for fast lookup
 *     "nonce": "<base64url-uuid>",    // anti-replay
 *     "iat":  <unix timestamp>,       // issued-at
 *     "exp":  <iat + ttl_seconds>     // expires-at
 *   }
 *
 * HMAC = hash_hmac('sha256', $payloadJson, config('loyalty.qr.secret'))
 *
 * Race-safe nonce consumption : INSERT INTO loyalty_qr_nonces_consumed and
 * catch QueryException on UNIQUE violation — that's the replay signal. No
 * pre-SELECT then INSERT (TOCTOU).
 *
 * Pattern mirrors FiscalSealingService HMAC-SHA256 chain — see §8 NF525
 * Fiscal Invariants in CLAUDE.md.
 */
class LoyaltyQrSigner
{
    public const TOKEN_PREFIX = 'lqr.';
    public const VERSION = 1;

    /**
     * Mint a fresh signed token for a customer.
     *
     * @param  int     $customerId User.id
     * @param  string  $loyaltyCode User.loyalty_code
     * @param  Carbon|null $now Override clock for tests
     * @return array{token: string, expires_at: int, nonce: string}
     */
    public function sign(int $customerId, string $loyaltyCode, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $iat = $now->getTimestamp();
        $exp = $iat + $this->ttlSeconds();
        $nonce = $this->generateNonce();

        $payload = [
            'v'     => self::VERSION,
            'cust'  => $customerId,
            'code'  => strtoupper(trim($loyaltyCode)),
            'nonce' => $nonce,
            'iat'   => $iat,
            'exp'   => $exp,
        ];

        $token = $this->encodeToken($payload);

        return [
            'token'      => $token,
            'expires_at' => $exp,
            'nonce'      => $nonce,
        ];
    }

    /**
     * Detect if a raw scan string looks like a signed QR token (vs legacy plaintext).
     */
    public function isSignedToken(string $raw): bool
    {
        return str_starts_with($raw, self::TOKEN_PREFIX);
    }

    /**
     * Verify a signed token and consume the nonce.
     *
     * Returns the decoded payload on success.
     *
     * @throws LoyaltyQrInvalidException on any failure (invalid format, bad
     *         HMAC, expired, replay). The exception carries a `code` for
     *         machine-readable surfacing to the kiosk (it MUST translate to
     *         an `error_code` field in the HTTP 200 response — never 4xx,
     *         per LoyaltyController::scan §12 invariant).
     */
    public function verifyAndConsume(string $token, string $sourceSurface = 'kiosk', ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();

        // 1. Format check
        if (! $this->isSignedToken($token)) {
            throw new LoyaltyQrInvalidException('qr_invalid_format');
        }

        $parts = explode('.', $token, 3);
        if (count($parts) !== 3 || $parts[0] !== 'lqr' || $parts[1] === '' || $parts[2] === '') {
            throw new LoyaltyQrInvalidException('qr_invalid_format');
        }

        $payloadB64 = $parts[1];
        $hmacB64 = $parts[2];

        // 2. HMAC verification (constant-time)
        $payloadJson = $this->base64UrlDecode($payloadB64);
        if ($payloadJson === false) {
            throw new LoyaltyQrInvalidException('qr_invalid_format');
        }

        $expectedHmac = hash_hmac('sha256', $payloadJson, $this->secret(), true);
        $providedHmac = $this->base64UrlDecode($hmacB64);
        if ($providedHmac === false || ! hash_equals($expectedHmac, $providedHmac)) {
            throw new LoyaltyQrInvalidException('qr_invalid_signature');
        }

        // 3. Decode payload
        $payload = json_decode($payloadJson, true);
        if (! is_array($payload) || ! isset($payload['v'], $payload['cust'], $payload['code'], $payload['nonce'], $payload['exp'])) {
            throw new LoyaltyQrInvalidException('qr_invalid_format');
        }

        if ((int) $payload['v'] !== self::VERSION) {
            throw new LoyaltyQrInvalidException('qr_unsupported_version');
        }

        // 4. Expiration check (with leeway)
        $exp = (int) $payload['exp'];
        $leeway = $this->leewaySeconds();
        if ($now->getTimestamp() > ($exp + $leeway)) {
            throw new LoyaltyQrInvalidException('qr_expired');
        }

        // 5. Race-safe nonce consumption (INSERT-then-catch on UNIQUE).
        $nonce = (string) $payload['nonce'];
        try {
            DB::table('loyalty_qr_nonces_consumed')->insert([
                'nonce'          => $nonce,
                'customer_id'    => (int) $payload['cust'],
                'source_surface' => $sourceSurface,
                'exp_at'         => Carbon::createFromTimestamp($exp),
                'consumed_at'    => $now,
            ]);
        } catch (QueryException $e) {
            // SQLSTATE 23000 = integrity constraint violation (UNIQUE here).
            // Anything else is propagated as-is (DB outage, etc).
            if ($this->isUniqueViolation($e)) {
                throw new LoyaltyQrInvalidException('qr_replay');
            }
            throw $e;
        }

        return $payload;
    }

    /**
     * Build the wire-format token string.
     */
    private function encodeToken(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('LoyaltyQrSigner: unable to canonicalise QR payload.');
        }

        $payloadB64 = $this->base64UrlEncode($json);
        $hmac = hash_hmac('sha256', $json, $this->secret(), true);
        $hmacB64 = $this->base64UrlEncode($hmac);

        return self::TOKEN_PREFIX . $payloadB64 . '.' . $hmacB64;
    }

    /**
     * URL-safe base64 (no padding) — matches JWT RFC 7515 §3.
     */
    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $b64): string|false
    {
        $b64 = strtr($b64, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($b64, true);
    }

    private function generateNonce(): string
    {
        // 16 random bytes → 22 chars base64url unpadded. Collision space
        // 2^128 — equivalent to UUID v4.
        return $this->base64UrlEncode(random_bytes(16));
    }

    private function ttlSeconds(): int
    {
        $ttl = (int) Config::get('loyalty.qr.ttl_seconds', 300);
        return $ttl > 0 ? $ttl : 300;
    }

    private function leewaySeconds(): int
    {
        return max(0, (int) Config::get('loyalty.qr.leeway_seconds', 30));
    }

    /**
     * Resolve the HMAC secret with the same production-safety pattern as
     * FiscalSealingService (refuse dev sentinels / too-short secrets in prod).
     */
    private function secret(): string
    {
        $secret = Config::get('loyalty.qr.secret');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException(
                'LoyaltyQrSigner: loyalty.qr.secret is not configured. '
                . 'Set LOYALTY_QR_SECRET in .env (openssl rand -hex 32 in production).'
            );
        }

        if (app()->environment('production')) {
            $sentinels = (array) Config::get('loyalty.dev_sentinels', []);
            if (in_array($secret, $sentinels, true)) {
                throw new RuntimeException(
                    'LoyaltyQrSigner: loyalty.qr.secret is set to a known dev sentinel '
                    . 'in APP_ENV=production. Rotate the secret (openssl rand -hex 32).'
                );
            }

            $min = (int) Config::get('loyalty.min_secret_length', 32);
            if (strlen($secret) < $min) {
                throw new RuntimeException(
                    "LoyaltyQrSigner: loyalty.qr.secret is shorter than {$min} characters "
                    . 'in APP_ENV=production. Generate a strong secret (openssl rand -hex 32).'
                );
            }
        }

        return $secret;
    }

    /**
     * Driver-aware UNIQUE-violation detection (MySQL 1062 / SQLite 19 /
     * Postgres 23505 — all surface as SQLSTATE 23000 from PDO).
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();
        if ($sqlState === '23000' || $sqlState === '23505') {
            return true;
        }
        // Fallback : message-based sniff (some drivers omit SQLSTATE).
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'unique') || str_contains($msg, 'duplicate');
    }
}
