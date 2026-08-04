<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [SYNC-P2-1 2026-08-04] Marqueur de LIVRAISON séparé du marqueur de CLAIM.
 *
 * Racine (audit adversarial synchro) : `dispatched_at` est posé en Phase 1 (claim) AVANT le
 * broadcast → un worker tué en plein broadcast laisse une ligne `dispatched_at` posé /
 * `last_error` null, INDISTINGUABLE d'un succès. Après ≥5 crashes elle devient orpheline :
 * rescue skip (attempts≥5), retry-failed skip (pending-only), monitor skip (last_error null),
 * prune la SUPPRIME à 90j comme « livrée ». La garantie at-least-once de l'outbox est cassée
 * en silence.
 *
 * Fix : `broadcast_at` posé UNIQUEMENT en Phase 3a (après un broadcast RÉUSSI). rescue / monitor
 * / prune s'appuient dessus (livraison réelle) au lieu de `dispatched_at` (claim). Additif,
 * nullable, rétro-compatible au niveau schéma.
 *
 * ── BACKFILL OBLIGATOIRE (sinon régression de déploiement critique) ─────────────────────────
 * Toutes les lignes déjà LIVRÉES sous l'ancien job ont `dispatched_at` posé mais `broadcast_at`
 * NULL (la colonne n'existait pas). Sans backfill, les nouveaux prédicats les lisent comme
 * « jamais livrées » :
 *   - MonitorOutboxStaleness (orphanCutoff = 10 MIN, pas 90j) → ALARME sur CHAQUE ligne livrée
 *     de plus de 10 min → pages massives / santé rouge permanente.
 *   - OutboxRescueCommand → RE-DIFFUSE tout l'historique (500/min) vers toutes les surfaces
 *     (borne/caisse/KDS) → flood d'events order.created/paid périmés.
 * Backfill : `broadcast_at = dispatched_at` pour les livraisons génuines historiques =
 * `dispatched_at IS NOT NULL AND broadcast_at IS NULL AND last_error vide`. Sous l'ancien job,
 * dispatched_at-posé + last_error-null = un succès Phase 3a (~100 % des cas ; un orphelin
 * crash-1er-essai est rarissime et le traiter comme livré est SÛR vs. re-diffuser tout
 * l'historique). Les lignes `dispatched_at` posé + `last_error` NON vide restent broadcast_at
 * NULL = vraies orphelines claim-sans-livraison → récupérées proprement par la nouvelle lane
 * rescue post-deploy (peu nombreuses, c'est l'intention).
 *
 * ── FENÊTRE DE DÉPLOIEMENT (P2 audit RED) ───────────────────────────────────────────────────
 * Sur MySQL le DDL (ADD COLUMN) AUTO-COMMIT avant l'UPDATE de backfill → il existe une fenêtre
 * SOUS-SECONDE où les lignes livrées ont broadcast_at NULL. Si `foodking:outbox:monitor` (chaque
 * minute) ou `:rescue` tombe pile dans cette fenêtre il les lit comme orphelines (fausse alarme /
 * re-broadcast transitoire). Auto-guéri dès la fin du backfill, négligeable sur mono-poste V1
 * (table minuscule, backfill ~ms). DÉPLOIEMENT PROPRE : lancer `php artisan migrate` AVANT de
 * ré-armer le scheduler, ou tolérer le tick transitoire (il se résorbe au tick suivant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_events', function (Blueprint $table): void {
            $table->dateTime('broadcast_at', 3)->nullable()->after('dispatched_at');
            // Détection efficace des orphelins claim-sans-livraison.
            $table->index(['broadcast_at', 'dispatched_at'], 'idx_delivery');
        });

        // Backfill chunké par plage de clé primaire (borne le lock sur une grande table).
        // Chunk par `id` et NON par `->limit()->update()` : SQLite (tests) ne supporte pas
        // UPDATE ... LIMIT. Idempotent : ne touche que broadcast_at IS NULL.
        $maxId = (int) (DB::table('domain_events')->max('id') ?? 0);
        $chunk = 5000;

        for ($from = 0; $from < $maxId; $from += $chunk) {
            DB::table('domain_events')
                ->where('id', '>', $from)
                ->where('id', '<=', $from + $chunk)
                ->whereNotNull('dispatched_at')
                ->whereNull('broadcast_at')
                ->where(function ($q): void {
                    $q->whereNull('last_error')->orWhere('last_error', '=', '');
                })
                ->update(['broadcast_at' => DB::raw('dispatched_at')]);
        }
    }

    public function down(): void
    {
        Schema::table('domain_events', function (Blueprint $table): void {
            $table->dropIndex('idx_delivery');
            $table->dropColumn('broadcast_at');
        });
    }
};
