<?php

use App\Console\Commands\EnsureCayenneMixteCommand;
use Illuminate\Database\Migrations\Migration;

/**
 * [OWNER 2026-08-01] Le choix de viande « Mixte » du Cayenne devient CAISSE-ONLY : la BORNE
 * redevient comme avant (Cayenne mono-viande, sans étape de choix). Un client borne qui veut plus
 * de viande paie le supplément « Viande supplémentaire » @2,50 (inchangé). Re-joue le ensure()
 * corrigé (visible_on=['pos'] sur Poulet mariné + Mixte du #22 ; Mixte seul caisse-only sur la
 * Galette #24). Idempotent. 0 frozen.
 */
return new class extends Migration
{
    public function up(): void
    {
        EnsureCayenneMixteCommand::ensure(false);
    }

    public function down(): void
    {
        // No-op : rendre le choix visible partout serait un retour arrière non désiré.
    }
};
