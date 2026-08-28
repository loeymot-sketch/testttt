<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [ONB-12 2026-08-28] Le tampon « 100 % Halal » devient une donnée.
 *
 * Il était écrit en dur dans `KioskIdleScreenComponent.vue`. Le nouveau réglage
 * `kiosk_setup.kiosk_halal_stamp` vaut 0 par défaut : on n'affirme rien sur la
 * nourriture d'un établissement tant qu'il ne l'a pas déclaré lui-même.
 *
 * Mais un établissement DÉJÀ EN SERVICE affiche ce tampon aujourd'hui, et il est
 * vrai pour lui. Le passer à 0 le lui retirerait sans prévenir — une régression
 * visible sur un écran client, pour un défaut qui n'est pas le sien.
 *
 * Cette migration déclare donc la valeur qui correspond à la réalité actuelle :
 *   - installation qui a déjà des produits actifs → tampon déclaré (1)
 *   - installation vierge                          → rien de déclaré (0)
 *
 * Le critère est un choix assumé : la présence d'une carte est le signe le plus
 * fiable, dans ce schéma, qu'un établissement tourne déjà. Il est écrit ici plutôt
 * que deviné plus tard.
 */
return new class extends Migration
{
    private const GROUPE = 'kiosk_setup';
    private const CLE = 'kiosk_halal_stamp';

    public function up(): void
    {
        $table = config('settings.repositories.database.table', 'settings');

        if (! Schema::hasTable($table) || ! Schema::hasTable('items')) {
            return;
        }

        $dejaPose = DB::table($table)
            ->where('group', self::GROUPE)
            ->where('key', self::CLE)
            ->exists();

        if ($dejaPose) {
            return;
        }

        $etablissementEnService = DB::table('items')->whereNull('deleted_at')->exists();

        DB::table($table)->insert([
            'key'        => self::CLE,
            'payload'    => json_encode($etablissementEnService ? 1 : 0),
            'group'      => self::GROUPE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $table = config('settings.repositories.database.table', 'settings');

        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)
            ->where('group', self::GROUPE)
            ->where('key', self::CLE)
            ->delete();
    }
};
