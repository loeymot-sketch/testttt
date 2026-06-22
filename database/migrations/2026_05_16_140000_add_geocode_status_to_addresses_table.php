<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [Sprint 2B / DEL-1] Add `geocode_status` column to addresses table.
 *
 * Backstory:
 *   `DeliveryQuoteService::quoteForAddress()` reads `$address['geocode_status']`
 *   (DeliveryQuoteService.php:33) to refuse delivery quotes when a saved
 *   address has not been successfully geocoded by Google Maps autocomplete.
 *   Wave E audit (P0) identified that the column did NOT exist in the
 *   `addresses` schema — meaning the gate read `null`, fell back to `'OK'`
 *   via `?? 'OK'`, and never blocked an address that had bogus coordinates.
 *
 * Compatibility:
 *   - Column is NULLABLE so existing rows continue to read `null` and trip
 *     the `?? 'OK'` fallback. Legacy data is therefore treated as "trusted
 *     OK" — no retro-gate is applied. New writes (autocomplete callback,
 *     admin address creation) populate this column explicitly. A backfill
 *     pass can later reclassify NULL rows once geocoding is mandatory.
 *   - The existing `GeocodeFailureBlocksOrderTest` continues to pass because
 *     the secondary `missing_or_invalid_coordinates` gate (lat/lng empty
 *     string → null) still rejects unusable addresses even with NULL status.
 *
 * Allowed values: OK, PARTIAL, ZERO_RESULTS, ERROR  (Google Geocoding API
 * response statuses). Enforced via CHECK constraint on MySQL/MariaDB/PgSQL.
 * SQLite (test DB only) silently accepts the column as a TEXT field — the
 * application-level `DeliveryQuoteService` is the authoritative validator.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('addresses', 'geocode_status')) {
            return;
        }

        Schema::table('addresses', function (Blueprint $table) {
            // VARCHAR(20) + CHECK constraint = portable across SQLite/MySQL/PgSQL.
            // ENUM would require driver-specific raw SQL and complicates SQLite
            // rebuilds for the test suite.
            $table->string('geocode_status', 20)
                ->nullable()
                ->after('longitude')
                ->comment('Google geocoding status: OK / PARTIAL / ZERO_RESULTS / ERROR. NULL = legacy row, treated as OK by DeliveryQuoteService.');

            $table->index('geocode_status', 'addresses_geocode_status_idx');
        });

        $this->addCheckConstraint();
    }

    public function down(): void
    {
        $this->dropCheckConstraint();

        if (!Schema::hasColumn('addresses', 'geocode_status')) {
            return;
        }

        Schema::table('addresses', function (Blueprint $table) {
            $table->dropIndex('addresses_geocode_status_idx');
            $table->dropColumn('geocode_status');
        });
    }

    private function addCheckConstraint(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite CHECK enforcement requires table rebuild — skipped for the
            // testing :memory: driver. Application-level validation is the SSOT.
            return;
        }

        $sql = "ALTER TABLE addresses
                ADD CONSTRAINT addresses_geocode_status_check
                CHECK (geocode_status IS NULL OR geocode_status IN ('OK','PARTIAL','ZERO_RESULTS','ERROR'))";

        try {
            DB::statement($sql);
        } catch (\Throwable $e) {
            // Constraint may already exist on a re-run; do not abort the migration.
        }
    }

    private function dropCheckConstraint(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            try {
                DB::statement('ALTER TABLE addresses DROP CONSTRAINT IF EXISTS addresses_geocode_status_check');
            } catch (\Throwable) {
                // ignore
            }
            return;
        }

        // MySQL / MariaDB
        try {
            DB::statement('ALTER TABLE addresses DROP CHECK addresses_geocode_status_check');
        } catch (\Throwable) {
            try {
                DB::statement('ALTER TABLE addresses DROP CONSTRAINT addresses_geocode_status_check');
            } catch (\Throwable) {
                // No portable IF EXISTS for CHECK drops on older MySQL — ignore.
            }
        }
    }
};
