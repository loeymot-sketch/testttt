<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [GOAL ULTRA-SYNC W4 2026-07-20] Commandes programmées — colonne NEUVE `scheduled_at`
 * (DATETIME NULL, indexée). NULL = ASAP (100% rétro-compatible : tout l'existant reste ASAP).
 *
 * Décision architecte : ne PAS re-sémantiser `is_advance_order` (piège vendor Ask::YES=5,
 * défaut jamais exercé, 5 prédicats legacy à historique zombie) ni `delivery_time` (string
 * « HH:MM - HH:MM » SANS date → ambiguë au passage de minuit, service 18h-00h+). Un datetime
 * complet règle le minuit-straddle proprement. Les deux champs legacy restent intouchés.
 *
 * NF525 : zéro impact fiscal — la commande est créée/scellée normalement ; seule sa
 * VISIBILITÉ sur le board cuisine (KDS/OSS) est gatée par cette date (SELECT-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('scheduled_at')->nullable()->index()->after('is_advance_order');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['scheduled_at']);
            $table->dropColumn('scheduled_at');
        });
    }
};
