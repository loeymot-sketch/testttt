<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P0 « alertes hors écran »] Prévenir les responsables SANS qu'ils ouvrent l'écran.
 *
 *   · hygiene_alert_settings   — réglages du restaurant (une ligne par branche) : activé ou non, destinataires, types d'alertes,
 *                                plage d'envoi, rythme de relance. Désactivé par défaut : rien ne part tant que le responsable ne l'a pas voulu.
 *   · hygiene_alert_dispatches — journal des envois, en ajout seul : quoi, à qui, quand, avec quel résultat. C'est la preuve que
 *                                « le responsable a été prévenu à 11 h 42 ».
 * Additif : deux tables neuves, aucune donnée existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hygiene_alert_settings')) {
            Schema::create('hygiene_alert_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('branch_id')->unique();
                $t->boolean('enabled')->default(false);
                $t->json('recipients')->nullable();      // liste d'adresses e-mail
                $t->json('domains')->nullable();         // types d'alertes voulus ; null = tous
                $t->string('send_from', 5)->default('09:00');
                $t->string('send_until', 5)->default('23:30');
                $t->unsignedSmallInteger('repeat_minutes')->default(60);
                $t->unsignedTinyInteger('max_sends')->default(3);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('hygiene_alert_dispatches')) {
            Schema::create('hygiene_alert_dispatches', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('branch_id')->index();
                $t->date('day');
                $t->string('reminder_key', 80);
                $t->string('domain', 20);
                $t->string('level', 10);
                $t->string('text', 300);
                $t->string('channel', 12)->default('mail');
                $t->string('recipient', 190);
                $t->string('status', 10);                // sent | failed | test
                $t->string('error', 200)->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamps();
                $t->index(['branch_id', 'day', 'reminder_key', 'recipient'], 'hy_alert_dedupe');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hygiene_alert_dispatches');
        Schema::dropIfExists('hygiene_alert_settings');
    }
};
