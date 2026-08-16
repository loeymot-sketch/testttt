<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [T-C SUIVI-CLIENT 2026-08-16 · GOAL owner] Suivi de commande temps réel
 * depuis le téléphone du client. `orders.token`/`order_serial_no` sont
 * SÉQUENTIELS et devinables (vérifié en base : valeurs 1, 2, 1, 1, 1…) —
 * inadaptés comme identifiant d'un lien public (un client pourrait deviner
 * l'URL d'une AUTRE commande). `tracking_token` est un identifiant opaque
 * généré aléatoirement (Order::boot() static::creating, cf. app/Models/
 * Order.php), séparé de tout champ métier existant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('tracking_token', 64)->nullable()->unique()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('tracking_token');
        });
    }
};
