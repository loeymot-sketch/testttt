<?php

namespace App\Console\Commands;

use App\Enums\Status;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [OWNER CAYENNE-MIXTE 2026-07-31] Raccourci CAISSE UNIQUEMENT sur le Cayenne : « Mixte (hachée +
 * poulet) » @2,50 — pour les anciens clients qui demandent un Cayenne mixte (99 % = viande hachée +
 * poulet). Le caissier coche « Mixte » d'un geste au lieu de saisir « Viande en plus » générique.
 *
 * SURFACE : `visible_on=['pos']` → VÉRIFIÉ visible SEULEMENT sur la caisse :
 *   - Caisse : ItemController surface='pos' → MenuProjectionService `isVisibleOn('pos')` = true → affiché.
 *   - Borne  : KioskMenuService `isVisibleOn('kiosk')` = false → EXCLU.
 *   - Web    : menu.js-driven (« Mixte » absent du miroir) → jamais affiché.
 * Money-path : c'est un vrai ItemExtra @2,50 (group 'supplement') → la caisse envoie son id réel,
 * facturé @2,50. « Viande en plus » générique reste, partout. DATA only, idempotent, 0 frozen.
 */
class EnsureCayenneMixteCommand extends Command
{
    protected $signature = 'menu:ensure-cayenne-mixte {--dry-run : Compter sans écrire}';

    protected $description = "Raccourci caisse « Mixte (hachée + poulet) » @2,50 sur le Cayenne (visible_on=pos). Idempotent.";

    public const EXTRA_NAME = 'Mixte (hachée + poulet)';

    public const UNIT_PRICE = 2.50;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $created = self::ensure($dry);
        $this->info(($dry ? '[dry-run] ' : '')."Cayenne « Mixte » — {$created} extra ajouté(s).");

        return self::SUCCESS;
    }

    public static function ensure(bool $dryRun = false): int
    {
        // Le Cayenne — catégorie Sandwichs (1), par nom (robuste aux ids variables).
        $cayenne = DB::table('items')
            ->where('item_category_id', 1)
            ->where('name', 'Cayenne')
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->first();

        if (! $cayenne) {
            return 0;
        }

        $exists = DB::table('item_extras')
            ->where('item_id', $cayenne->id)
            ->where('name', self::EXTRA_NAME)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return 0;
        }

        if ($dryRun) {
            return 1;
        }

        DB::table('item_extras')->insert([
            'item_id'     => $cayenne->id,
            'name'        => self::EXTRA_NAME,
            'price'       => self::UNIT_PRICE,
            'group_label' => 'supplement',
            'status'      => Status::ACTIVE,
            'visible_on'  => json_encode(['pos']), // CAISSE uniquement
            'is_available' => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return 1;
    }
}
