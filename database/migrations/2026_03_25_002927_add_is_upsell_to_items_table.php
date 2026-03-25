<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splash-inspired merchandising: flag items as upsell candidates.
 * Allows the kiosk to suggest specific items (desserts, drinks) at checkout.
 * Separate from is_featured (homepage) and is_popular (trending).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Splash merchandising: item proposé en suggestion panier kiosk
            $table->boolean('is_upsell')->default(false)->after('is_featured');
            // Catégories éligibles à l'upsell (denormalized for query speed)
            // Ex: "drinks,desserts" — si null → toutes catégories
        });

        // Index pour la requête upsell (status=active AND is_upsell=true)
        Schema::table('items', function (Blueprint $table) {
            $table->index(['is_upsell', 'status'], 'items_upsell_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex('items_upsell_status_index');
            $table->dropColumn('is_upsell');
        });
    }
};
