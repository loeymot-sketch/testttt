<?php

use App\Enums\Status;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * [VG-2026-10-21 M5-001 M1-004 M5-001b M3-101 · OWNER 2026-10-22] Viandes INCLUSES, données du catalogue.
 *
 * Décisions du propriétaire : sandwich (Cayenne, Classique) = 1 viande, la 2e PAYANTE 2,50 € sur tous les canaux ;
 * Tacos M = 1, L = 2, XL = 3 ; Méga et Terminator = 2.
 *
 * CAUSE. Un « emplacement » de viande gratuite est un attribut « Viande N » portant des variations à 0 €. La
 * matérialisation des pages wizard du 2026-09-02 a posé « Viande 2 » sur le Cayenne et le Sandwich Classique, et
 * « Viande 2/3 » sur les trois Tacos. Or PricingService (gelé) facture le prix de la VARIATION choisie : chaque
 * emplacement en trop est une viande offerte — à la caisse (pos-wizard.js, gelé, compte ces attributs comme viandes
 * incluses : Cayenne = 2), à la borne et au site (qui posent la i-ème viande sur le i-ème attribut).
 *
 * VOIE CHOISIE : retirer l'emplacement plutôt que lui donner un prix de 2,50 €. Les trois canaux facturent déjà la viande
 * au-delà des incluses par l'extra « Viande supplémentaire » @2,50 (caisse : VIANDE_SUPPL_PRICE + extra ; borne :
 * supplCount → extra ; site : surplus → extra). Une « Viande 2 » à 2,50 € serait facturée 2,50 € par le serveur alors
 * que la caisse (gelée) l'afficherait « incluse » : écran ≠ ticket. Sans emplacement, le prix est le même partout :
 * base + 2,50 €, et une « Viande 2 » renvoyée par un client périmé est REFUSÉE (variation inactive, PricingService).
 *
 * CE QUI EST FAIT, par article retrouvé par son NOM (les identifiants diffèrent d'une base à l'autre) :
 *   - les variations ACTIVES des attributs en trop passent INACTIVES (rien n'est supprimé ; l'admin peut les rallumer) ;
 *   - les étapes des profils wizard DE CET ARTICLE qui pointent ces attributs sont éteintes : sinon la prochaine
 *     publication du profil (WizardPageMaterializer) rallumerait les variations gratuites.
 *   Garde : un article sans extra « Viande supplémentaire » payant actif n'est PAS touché (sa 2e viande deviendrait
 *   invendable) ; il est signalé au journal.
 *
 * Non touchés : Méga, Terminator, Suprême (2 viandes), Tacos XL (3), bols, burgers, galettes (1). « Viande 3 »
 * (item_attributes, min_select=1) ne subsiste plus que sur le Tacos XL, où 3 viandes sont imposées : laissé tel quel.
 *
 * IDEMPOTENTE (seules les lignes encore actives sont modifiées) et RÉVERSIBLE : chaque valeur remplacée est notée dans
 * `migration_undo_log` ; `down()` la remet exactement, puis supprime la table si elle est vide.
 */
return new class extends Migration
{
    private const NAME = '2026_10_22_130000_viandes_incluses_decision_proprietaire';

    /** Article => attributs « Viande N » qui ne doivent plus être des emplacements gratuits. */
    private const TARGETS = [
        'Cayenne'            => ['Viande 2'],
        'Sandwich Classique' => ['Viande 2'],
        'Tacos M'            => ['Viande 2', 'Viande 3'],
        'Tacos L'            => ['Viande 3'],
    ];

    public function up(): void
    {
        foreach (['items', 'item_attributes', 'item_variations', 'item_extras', 'item_wizard_profiles', 'item_wizard_steps'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $changed = false;
        foreach (self::TARGETS as $itemName => $attributeNames) {
            $attributeIds = DB::table('item_attributes')->whereIn('name', $attributeNames)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($attributeIds === []) {
                continue;
            }

            foreach (DB::table('items')->where('name', $itemName)->whereNull('deleted_at')->pluck('id') as $itemId) {
                if (! $this->hasPaidMeatExtra((int) $itemId)) {
                    Log::warning('[VG-2026-10-21 M5-001] '.self::NAME." : « {$itemName} » #{$itemId} sans extra « Viande supplémentaire » payant — emplacements laissés en place.");

                    continue;
                }

                $variationIds = DB::table('item_variations')
                    ->where('item_id', $itemId)
                    ->whereIn('item_attribute_id', $attributeIds)
                    ->where('status', Status::ACTIVE)
                    ->whereNull('deleted_at')
                    ->pluck('id')->all();
                $changed = $this->switchOff('item_variations', $variationIds, 'status', (string) Status::ACTIVE, Status::INACTIVE) || $changed;

                $profileIds = DB::table('item_wizard_profiles')->where('item_id', $itemId)->pluck('id')->all();
                $stepIds = $profileIds === [] ? [] : DB::table('item_wizard_steps')
                    ->whereIn('profile_id', $profileIds)
                    ->where('source_type', 'item_attribute')
                    ->where('is_active', 1)
                    ->where(function ($q) use ($attributeIds, $attributeNames): void {
                        $q->whereIn('source_item_attribute_id', $attributeIds)
                            ->orWhereIn('source_ref', array_map('strval', $attributeIds))
                            ->orWhereIn('source_ref', $attributeNames);
                    })
                    ->pluck('id')->all();
                $changed = $this->switchOff('item_wizard_steps', $stepIds, 'is_active', '1', 0) || $changed;
            }
        }

        if ($changed) {
            $this->bumpMenuSnapshots();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('migration_undo_log')) {
            return;
        }

        $rows = DB::table('migration_undo_log')->where('migration', self::NAME)->orderByDesc('id')->get();
        foreach ($rows as $row) {
            DB::table($row->table_name)->where('id', $row->row_id)->update([
                $row->column_name => $row->old_value,
                'updated_at'      => now(),
            ]);
        }
        DB::table('migration_undo_log')->where('migration', self::NAME)->delete();

        if (DB::table('migration_undo_log')->count() === 0) {
            Schema::drop('migration_undo_log');
        }
        if ($rows->isNotEmpty()) {
            $this->bumpMenuSnapshots();
        }
    }

    private function hasPaidMeatExtra(int $itemId): bool
    {
        return DB::table('item_extras')
            ->where('item_id', $itemId)
            ->whereRaw('LOWER(name) LIKE ?', ['viande suppl%'])
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->where('price', '>', 0)
            ->exists();
    }

    /** @param array<int, int|string> $ids */
    private function switchOff(string $table, array $ids, string $column, string $oldValue, int $newValue): bool
    {
        if ($ids === []) {
            return false;
        }
        $this->ensureUndoLog();

        DB::transaction(function () use ($table, $ids, $column, $oldValue, $newValue): void {
            foreach ($ids as $id) {
                DB::table('migration_undo_log')->insertOrIgnore([
                    'migration'   => self::NAME,
                    'table_name'  => $table,
                    'row_id'      => (int) $id,
                    'column_name' => $column,
                    'old_value'   => $oldValue,
                    'created_at'  => now(),
                ]);
            }
            DB::table($table)->whereIn('id', $ids)->update([$column => $newValue, 'updated_at' => now()]);
        });

        return true;
    }

    private function ensureUndoLog(): void
    {
        if (Schema::hasTable('migration_undo_log')) {
            return;
        }
        // Journal générique de retour arrière des migrations de DONNÉES : une ligne par valeur remplacée.
        Schema::create('migration_undo_log', function (Blueprint $table): void {
            $table->id();
            $table->string('migration', 191);
            $table->string('table_name', 64);
            $table->unsignedBigInteger('row_id');
            $table->string('column_name', 64);
            $table->string('old_value', 191)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['migration', 'table_name', 'row_id', 'column_name'], 'migration_undo_log_unique');
        });
    }

    /** Les caisses, bornes et sites rechargent la carte quand la version change (au mieux : jamais bloquant). */
    private function bumpMenuSnapshots(): void
    {
        try {
            $branchIds = Schema::hasTable('branches') ? DB::table('branches')->pluck('id')->all() : [];
            foreach ($branchIds as $branchId) {
                MenuSnapshot::make()->bump((int) $branchId);
            }
        } catch (\Throwable $e) {
            Log::info('[VG-2026-10-21 M5-001] '.self::NAME.' : version de carte non incrémentée ('.$e->getMessage().').');
        }
    }
};
