<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [NUIT-A1 2026-07-03] Index de durabilité sous croissance (audit Wave A, 2 findings confirmés).
 *
 * Projection : ~30k commandes/an (3079 en 5 semaines). Deux requêtes CHAUDES faisaient un FULL SCAN,
 * dégradation linéaire avec le volume :
 *   1. /counter-collect/pending → `where payment_status = PENDING_COUNTER` (+ BranchScope branch_id) :
 *      AUCUN index ne couvrait payment_status → scan complet à chaque affichage de la file « à encaisser ».
 *   2. KDS historyToday() → `whereIn status (PREPARED, OUT_FOR_DELIVERY, DELIVERED)` + fenêtre temporelle :
 *      pas d'index (status, updated_at) → scan de toutes les commandes du statut.
 *
 * Deux index composites additifs (aucune donnée modifiée, réversibles, hors zone gelée). Guards
 * anti-duplication (idempotent sur re-run / DB déjà migrée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function ($table) {
            // File d'encaissement (borne Plan B) : (branch_id, payment_status) sert le filtre scopé branche.
            if (! $this->indexExists('orders', 'idx_orders_branch_payment')) {
                $table->index(['branch_id', 'payment_status'], 'idx_orders_branch_payment');
            }
            // Historique KDS / resync : (status, updated_at) sert le whereIn(status)+borne temporelle.
            if (! $this->indexExists('orders', 'idx_orders_status_updated')) {
                $table->index(['status', 'updated_at'], 'idx_orders_status_updated');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function ($table) {
            foreach (['idx_orders_branch_payment', 'idx_orders_status_updated'] as $idx) {
                if ($this->indexExists('orders', $idx)) {
                    $table->dropIndex($idx);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $conn = Schema::getConnection();
        $db = $conn->getDatabaseName();
        $driver = $conn->getDriverName();
        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $r) {
                if (($r->name ?? null) === $index) {
                    return true;
                }
            }
            return false;
        }
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$db, $table, $index]
        );
        return ! empty($rows);
    }
};
