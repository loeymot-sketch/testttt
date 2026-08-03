<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [OWNER REPAS-PERSONNEL/PERTES 2026-07-31] Trace horodatée des sorties de stock HORS VENTE :
 * un produit consommé en « repas personnel » (staff_meal) ou perdu/raté (waste). Append-only —
 * l'owner voit tout ce qui part hors vente (marge) + qui l'a saisi. Le décrément du stock direct
 * (items à StockLevel) est fait en parallèle via StockService ; cette table est LA trace, valable
 * même pour les composites (sandwichs) sans stock direct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_outflows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('item_id')->index();
            $table->string('item_name', 191);          // snapshot du nom (survit à un rename/suppression)
            $table->unsignedInteger('quantity');
            $table->string('type', 24);                // 'staff_meal' | 'waste'
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index(); // qui a saisi (accountabilité)
            $table->boolean('stock_decremented')->default(false);       // le stock direct a-t-il été décrémenté ?
            $table->dateTime('created_at')->index();
            // pas d'updated_at : append-only.

            $table->index(['branch_id', 'created_at'], 'idx_branch_created');
            $table->index(['branch_id', 'type'], 'idx_branch_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_outflows');
    }
};
