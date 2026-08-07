<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [MULTI-DEVICE 2026-08-07] Identité d'appareil sur les jetons Sanctum.
 *
 * Avant : `LoginController` révoquait TOUS les jetons `auth_token` du compte
 * à chaque connexion (anti-prolifération, Sprint 5D Z6-01). Effet de bord
 * terrain : impossible d'exploiter plusieurs terminaux simultanés sur un
 * même compte — la connexion d'un poste éjectait tous les autres (401).
 *
 * On garde l'anti-prolifération mais on la scope à l'appareil. Il faut donc
 * une identité d'appareil portée par le jeton lui-même.
 *
 * Pourquoi des colonnes et pas un suffixe dans `name` : le champ `name` est
 * porteur de sens ailleurs — `routes/channels.php:65` et
 * `app/Http/Middleware/BlockKioskMachineToken.php:41` comparent
 * `name === 'kiosk-token'` en strict. Y injecter un identifiant d'appareil
 * casserait silencieusement l'autorisation des canaux temps-réel des bornes.
 *
 * Colonnes nullable + rétro-compatibles : les jetons déjà émis restent
 * valides (device_id NULL = appareil inconnu, traité comme « hérité »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // Identifiant stable généré côté client (localStorage) et transmis
            // via l'en-tête `X-Device-Id`. 64 car. max, jamais affiché brut.
            $table->string('device_id', 64)->nullable()->after('abilities');
            // Libellé lisible pour l'écran « Appareils connectés »
            // (« Caisse comptoir », « Tablette salle »…).
            $table->string('device_label', 120)->nullable()->after('device_id');
            // IP au moment de l'émission — pour reconnaître un poste dans la
            // liste. Volontairement PAS mis à jour à chaque requête : ce serait
            // une écriture DB par appel API.
            $table->string('last_ip', 45)->nullable()->after('device_label');

            // La requête chaude est « les jetons de CE compte, pour CE nom de
            // jeton, sur CET appareil » (révocation ciblée à la reconnexion).
            $table->index(
                ['tokenable_id', 'tokenable_type', 'name', 'device_id'],
                'pat_tokenable_name_device_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex('pat_tokenable_name_device_idx');
            $table->dropColumn(['device_id', 'device_label', 'last_ip']);
        });
    }
};
