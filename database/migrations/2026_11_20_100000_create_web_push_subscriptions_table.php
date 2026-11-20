<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [NFC / NOTIFICATIONS 2026-11-20] Abonnements Web Push des appareils des CLIENTS.
 *
 * Un client peut avoir plusieurs appareils (téléphone, tablette) : une ligne par point d'accès. Le point d'accès est unique
 * (empreinte SHA-256) — se réabonner depuis le même appareil met à jour la ligne, jamais un doublon. Pas de suppression douce :
 * un abonnement mort (404/410 du service de notification) est supprimé pour de bon.
 * Table AJOUTÉE seulement : aucune donnée existante n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('web_push_subscriptions')) {
            return;
        }
        Schema::create('web_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('device_label', 60)->nullable();
            $table->string('origin', 20)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedTinyInteger('failure_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_subscriptions');
    }
};
