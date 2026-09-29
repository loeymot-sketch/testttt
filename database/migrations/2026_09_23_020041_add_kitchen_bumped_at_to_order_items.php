<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // [KDS-ITEM-READY-SYNC 2026-09-23] Audit finding #4 ("Plan de correction
        // complet — Audit Le Cayenne") : la pastille "prêt" par article du KDS
        // vivait uniquement dans le localStorage du navigateur (kds.js
        // STORAGE_BUMPED), invisible d'un second écran/appareil. Ce champ est le
        // SSOT serveur pour "cet article de cette commande a été marqué prêt par
        // la cuisine, à cet instant" — indépendant de `OrderStatus` (qui reste
        // l'état agrégé de la commande entière, inchangé).
        Schema::table('order_items', function (Blueprint $table) {
            $table->timestamp('kitchen_bumped_at')->nullable()->after('instruction');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('kitchen_bumped_at');
        });
    }
};
