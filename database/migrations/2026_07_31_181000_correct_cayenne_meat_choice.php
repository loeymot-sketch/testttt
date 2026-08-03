<?php

use App\Console\Commands\EnsureCayenneMixteCommand;
use Illuminate\Database\Migrations\Migration;

/**
 * [OWNER CAYENNE-MIXTE 2026-07-31 · CORRECTIF] La migration 180000 (version initiale de la commande)
 * backfillait 7 viandes sur le Cayenne sandwich #22 → le transformait en « build-your-own ». Or le
 * Cayenne est mono-viande SIGNATURE (Poulet mariné, cf. web mkItem 101 viandes:0). La commande a été
 * corrigée : choix LIMITÉ [Poulet mariné (défaut), Mixte]. Ce correctif re-joue le ensure() corrigé
 * sur la VPS (180000 a déjà tourné, ne se re-jouera pas) → soft-delete les 6 viandes en trop de #22.
 * Idempotent. La Galette Cayenne #24 (vrai choix de 7 viandes) n'est pas touchée (garde 7 + Mixte).
 */
return new class extends Migration
{
    public function up(): void
    {
        EnsureCayenneMixteCommand::ensure(false);
    }

    public function down(): void
    {
        // No-op : les 6 viandes soft-delete restent récupérables (deleted_at) si besoin.
    }
};
