<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add loyalty_customer_code to the `orders` table (FrontendOrder uses $table = "orders").
     * AwardLoyaltyPointsOnDelivery reads loyalty_customer_code to credit the kiosk customer
     * (user_id on kiosk orders is the machine, not the loyalty customer).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'loyalty_customer_code')) {
                $table->string('loyalty_customer_code', 25)->nullable()->after('loyalty_points_awarded');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'loyalty_customer_code')) {
                $table->dropColumn('loyalty_customer_code');
            }
        });
    }
};
