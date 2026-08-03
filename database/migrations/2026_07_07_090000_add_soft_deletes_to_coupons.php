<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [P3 heal 2026-07-07] Coupon hard-delete → coupon_id orphelin dans order_coupons.
 *
 * `order_coupons.coupon_id` est un simple unsignedBigInteger (aucune FK) et le
 * modèle Coupon n'avait pas SoftDeletes : `$coupon->delete()` faisait un HARD
 * delete, laissant les redemptions historiques (order_coupons) pointer dans le
 * vide — une commande passée perdait la référence de son coupon (casse NF525 :
 * l'historique fiscal doit rester résolvable 6 ans). On ajoute `deleted_at` pour
 * que la suppression devienne un soft-delete : le coupon disparaît des listes et
 * ne peut plus être appliqué à une nouvelle commande, mais reste résolvable via
 * `Coupon::withTrashed()->find($orderCoupon->coupon_id)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('coupons', 'deleted_at')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('coupons', 'deleted_at')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
