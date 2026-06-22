<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [AUDIT-F-003 / Sub-task 1] Z report cash columns — observabilité comptable.
 *
 * IMPORTANT — frozen ZReportService preserved : ces colonnes sont
 * persistées POST-close par le décorateur ZReportCashEnrichmentService et
 * ne sont PAS incluses dans la signature HMAC. Elles servent uniquement
 * de cache pour les rapports admin (variance journalière, audit
 * comptable). La signature HMAC continue de couvrir uniquement
 * total_ttc/total_ht/total_tva/order_count/cancel_count/refund_count
 * comme avant — aucun risque de régression chain validation.
 *
 * GATED OWNER : peut être déployée séparément des autres car purement
 * additive ; la lecture est null-safe côté décorateur.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('z_reports')) {
            return;
        }

        Schema::table('z_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('z_reports', 'cash_opening_amount')) {
                $table->decimal('cash_opening_amount', 10, 2)->nullable()->after('refund_count');
            }
            if (!Schema::hasColumn('z_reports', 'cash_closing_amount')) {
                $table->decimal('cash_closing_amount', 10, 2)->nullable()->after('cash_opening_amount');
            }
            if (!Schema::hasColumn('z_reports', 'cash_variance')) {
                $table->decimal('cash_variance', 10, 2)->nullable()->after('cash_closing_amount');
            }
            if (!Schema::hasColumn('z_reports', 'cash_movements_count')) {
                $table->unsignedInteger('cash_movements_count')->nullable()->after('cash_variance');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('z_reports')) {
            return;
        }

        Schema::table('z_reports', function (Blueprint $table) {
            // Note: ne PAS dropper si des Z reports en prod ont déjà des valeurs
            // — ces colonnes sont audit-trail. Down() prévu pour rollback dev only.
            $table->dropColumn(['cash_opening_amount', 'cash_closing_amount', 'cash_variance', 'cash_movements_count']);
        });
    }
};
