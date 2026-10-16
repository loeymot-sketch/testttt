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
        // [E2E stores · revue adverse B2-R2-02 · 2026-10-01] Toute commande reçoit à sa création le
        // temps de préparation PAR DÉFAUT des réglages (30 min en production). Le suivi client le
        // prenait pour un temps FIXÉ PAR LE CAISSIER et, dès l'acceptation, passait de « ~10-15 min »
        // (décision propriétaire du 2026-09-23) à « ~30 min » — pour une commande prête 100 s plus
        // tard. Ce champ dit qu'un caissier a VRAIMENT choisi le temps à l'acceptation : seul ce
        // cas remplace la fourchette générique. Nul = jamais choisi (toutes les commandes passées).
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('preparation_time_confirmed_at')->nullable()->after('preparation_time');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('preparation_time_confirmed_at');
        });
    }
};
