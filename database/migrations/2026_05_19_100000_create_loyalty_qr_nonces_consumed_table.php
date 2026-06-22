<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [LCS-S-001 / 2026-05-19] Loyalty QR signed-nonce consumption table.
 *
 * Stores each consumed nonce from a signed loyalty QR token so the same
 * signed payload cannot be replayed (screenshot / clipboard attack).
 *
 * Source : `reports/audit/loyalty-cross-surface-2026-05-18/round-1/STATUS.md`
 *  > "QR signature is unverified server-side — `FK:<loyalty_code>` is a
 *  >  permanent plaintext credential. Mobile 5-min rotation is UX-only.
 *  >  Screenshot/clipboard/OCR of a single victim QR enables indefinite
 *  >  cross-surface redemption fraud."
 *
 * Design (advisor-confirmed 2026-05-19):
 *  - UNIQUE(nonce) is GLOBAL — a customer scans at any branch.
 *  - `customer_id` is a non-unique FK kept for audit / forensic only.
 *  - The race-safe consumption path is INSERT-then-catch QueryException
 *    on UNIQUE violation — that's the replay signal. No pre-SELECT.
 *  - 6-month rolling retention (cron deletes consumed_at < NOW-6mo) is
 *    sufficient : QR TTL is 5 min, so anything older than 5 min cannot
 *    re-validate via the verify path even if the row was pruned.
 *
 * Frozen-zone : new table, no existing row touched. NF525 chain untouched.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('loyalty_qr_nonces_consumed')) {
            return;
        }

        Schema::create('loyalty_qr_nonces_consumed', function (Blueprint $table) {
            $table->id();

            // The opaque nonce from the signed payload (base64url uuid-v4,
            // 22 chars unpadded — cap at 64 chars for forward compat).
            $table->string('nonce', 64);

            // Audit-only — which customer's QR was scanned. Nullable to
            // tolerate legacy paths where the verifier didn't resolve
            // customer_id before consume.
            $table->unsignedBigInteger('customer_id')->nullable();

            // Audit-only — which surface consumed it (kiosk|pos|mobile|...).
            $table->string('source_surface', 32)->nullable();

            // When the token's `exp` claim said the QR would have expired.
            // Used by the retention cron to detect anomalous late-consumption
            // attempts (clock-skew or replay attack on already-expired QR).
            $table->timestamp('exp_at')->nullable();

            // Server-side consumption timestamp.
            $table->timestamp('consumed_at')->useCurrent();

            // GLOBAL unique on nonce (per advisor — customer scans at any
            // branch, no branch_id scoping). This index IS the race-safe
            // replay guard.
            $table->unique('nonce', 'uk_loyalty_qr_nonce');

            // Non-unique indexes for forensic lookup.
            $table->index('customer_id', 'idx_loyalty_qr_customer');
            $table->index('consumed_at', 'idx_loyalty_qr_consumed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_qr_nonces_consumed');
    }
};
