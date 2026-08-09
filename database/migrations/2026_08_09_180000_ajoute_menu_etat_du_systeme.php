<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * [PILOTAGE 2026-08-09] Rendre l'écran « État du système » ATTEIGNABLE.
 *
 * L'écran existe (/admin/observability/system) et réunit les cinq contrôles de
 * santé, la fraîcheur de la sauvegarde, le battement du planificateur et les
 * interrupteurs. Sans entrée de menu, il faut connaître l'adresse par cœur —
 * autrement dit il n'existe que pour ceux qui n'en ont pas besoin.
 *
 * Placé sous « Configuration », auprès des Paramètres.
 */
return new class extends Migration
{
    private const LANGUE = 'system_health';

    public function up(): void
    {
        if (DB::table('menus')->where('language', self::LANGUE)->exists()) {
            return; // rejouable sans créer de doublon
        }

        $parent = DB::table('menus')->where('language', 'setup')->value('id');
        if ($parent === null) {
            // Pas de bloc « Configuration » dans cette base : on ne devine pas,
            // on pose l'entrée à la racine plutôt que de la perdre.
            $parent = 0;
        }

        DB::table('menus')->insert([
            'name'       => 'System Health',
            'language'   => self::LANGUE,
            'url'        => 'observability/system',
            'icon'       => 'lab lab-settings',
            'status'     => 1,
            'parent'     => $parent,
            'type'       => 1,
            'priority'   => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('language', self::LANGUE)->delete();
    }
};
