<?php

use App\Console\Commands\WizardPagesBootstrapCommand;
use Illuminate\Database\Migrations\Migration;

/**
 * [GOAL DASHBOARD-PILOTABLE 2026-09-02] Bibliothèque de pages de wizard construite depuis le catalogue
 * existant (attributs, variations, extras, addons) et étapes existantes reliées à leur page. Data only,
 * idempotente, ré-exécutable à la main : `php artisan wizard-pages:bootstrap`. Base vide : no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        WizardPagesBootstrapCommand::bootstrap(false);
    }

    public function down(): void
    {
        // Volontairement non réversible : les pages sont des données du gérant (comme un produit).
        // Elles disparaissent avec `2026_09_02_100000_create_wizard_pages_tables`.
    }
};
