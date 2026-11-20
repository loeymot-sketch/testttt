<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [STORES 2026-11-12 · Apple 5.1.1(v)] Jeton de rafraîchissement « Se connecter avec Apple », CHIFFRÉ (cast `encrypted`
 * du modèle User), conservé pour pouvoir le révoquer chez Apple quand le client supprime son compte
 * (App\Services\Auth\AppleTokenRevoker). Effacé à la suppression du compte.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'apple_refresh_token')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->text('apple_refresh_token')->nullable()->after('google_sub');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'apple_refresh_token')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('apple_refresh_token');
        });
    }
};
