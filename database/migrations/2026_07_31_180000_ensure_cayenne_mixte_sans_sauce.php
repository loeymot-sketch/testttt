<?php

use App\Console\Commands\EnsureCayenneMixteCommand;
use Illuminate\Database\Migrations\Migration;

/**
 * [OWNER CAYENNE-MIXTE 2026-07-31] Applique sur la VPS (au migrate --force du deploy) :
 *   - Le Cayenne (pain #22 + galette #24) : « Mixte (hachée + poulet) » = choix de viande GRATUIT
 *     (variation attr1 @0) + « Sans sauce » = choix de sauce GRATUIT (attr5 @0).
 *   - Backfill des 7 viandes canoniques sur le Cayenne pain #22 (avait 0 variation viande → la
 *     viande choisie côté web/borne était droppée au scellement).
 *
 * Délègue à la commande idempotente {@see EnsureCayenneMixteCommand::ensure()} — re-jouable sans
 * doublon (garde par item+attr+nom). DATA only, 0 frozen, aucun PricingService touché (tout @0).
 */
return new class extends Migration
{
    public function up(): void
    {
        EnsureCayenneMixteCommand::ensure(false);
    }

    public function down(): void
    {
        // No-op réversible : les variations @0 sont inertes tant que non sélectionnées, et le
        // backfill viande répare un vrai trou de données (à ne pas re-supprimer). La couverture
        // est ré-assurée par `php artisan menu:ensure-cayenne-mixte` (idempotent).
    }
};
