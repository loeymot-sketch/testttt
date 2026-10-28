<?php

use App\Enums\Status;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * [VG-2026-10-21 R2-002] Un bol se vend AVEC sa viande, sur la caisse, la borne et le site, au prix de la carte.
 *
 * VÉRITÉ DU CATALOGUE (base locale, 2026-10-22) :
 *   - fiche : « Frites maison, viande au choix, sauce et suppléments » / « Riz, viande au choix, … » ;
 *   - catalogue : 7 viandes ACTIVES sur l'attribut « Viande 1 » (min_select 1) pour chaque bol, à 0 € ;
 *   - `config/menu_meat_rules.php` : bols « inchangés (1, leur seul emplacement Viande 1) » → viande_count = 1 ;
 *   - site (data/menu.js) : `viandes: 1` sur les deux bols ;
 *   - MAIS le profil wizard PUBLIÉ (profil de catégorie « Bols » et profils produit recopiés à sa publication du
 *     2026-09-02 18:32:45) a son étape « viande » ÉTEINTE (is_active = 0), seule « sauce_bol » décrit les variations.
 *
 * EFFET. PricingService (gelé) fait foi du profil publié dès qu'il décrit une famille : la viande envoyée par la
 * caisse est refusée (« Composition : le choix #546 n'appartient pas au profil publié », 422) et la borne, qui suit le
 * profil, vend le bol SANS viande (7,90 €). Aucune ligne de bol vendue depuis le 2026-09-03.
 *
 * CE QUI EST FAIT, par article retrouvé par son NOM (les identifiants diffèrent d'une base à l'autre) : l'étape
 * « Viande 1 » ÉTEINTE est rallumée sur les profils wizard des bols ET sur le profil de leur catégorie (sinon la
 * prochaine publication de la catégorie l'éteindrait de nouveau). Rien d'autre n'est modifié : ni prix, ni variation,
 * ni min/max. Le prix reste celui de la carte (les viandes « Viande 1 » des bols sont à 0 €).
 * Garde : un bol sans AUCUNE viande active au catalogue n'est pas touché (l'étape obligatoire n'aurait rien à proposer
 * et le bol deviendrait invendable) ; il est signalé au journal.
 *
 * IDEMPOTENTE (seules les étapes encore éteintes sont modifiées) et RÉVERSIBLE : chaque valeur remplacée est notée dans
 * `migration_undo_log` ; `down()` la remet exactement, puis supprime la table si elle est vide.
 */
return new class extends Migration
{
    private const NAME = '2026_10_22_180000_bols_viande_au_choix_dans_le_profil_publie';

    private const BOLS = ['Bol Frites', 'Bol Riz'];

    private const MEAT_ATTRIBUTE = 'Viande 1';

    public function up(): void
    {
        foreach (['items', 'item_attributes', 'item_variations', 'item_wizard_profiles', 'item_wizard_steps'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $attributeIds = DB::table('item_attributes')->where('name', self::MEAT_ATTRIBUTE)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($attributeIds === []) {
            return;
        }

        $itemIds = [];
        $categoryIds = [];
        foreach (DB::table('items')->whereIn('name', self::BOLS)->whereNull('deleted_at')->get(['id', 'name', 'item_category_id']) as $item) {
            $hasMeat = DB::table('item_variations')
                ->where('item_id', $item->id)
                ->whereIn('item_attribute_id', $attributeIds)
                ->where('status', Status::ACTIVE)
                ->whereNull('deleted_at')
                ->exists();
            if (! $hasMeat) {
                Log::warning('[VG-2026-10-21 R2-002] '.self::NAME." : « {$item->name} » #{$item->id} sans viande active au catalogue — étape laissée éteinte.");

                continue;
            }
            $itemIds[] = (int) $item->id;
            if ($item->item_category_id !== null) {
                $categoryIds[(int) $item->item_category_id] = true;
            }
        }
        if ($itemIds === []) {
            return;
        }

        $profileIds = DB::table('item_wizard_profiles')
            ->where(function ($q) use ($itemIds, $categoryIds): void {
                $q->whereIn('item_id', $itemIds);
                if ($categoryIds !== []) {
                    $q->orWhere(fn ($c) => $c->whereNull('item_id')->whereIn('item_category_id', array_keys($categoryIds)));
                }
            })
            ->pluck('id')->all();
        if ($profileIds === []) {
            return;
        }

        // Une étape ÉTEINTE n'est pas toujours liée à son attribut (le profil de catégorie local n° 39 porte
        // source_ref vide : la liaison n'est posée qu'aux étapes actives, à la publication) — sa page wizard, si.
        $pageIds = Schema::hasTable('wizard_pages')
            ? DB::table('wizard_pages')->whereIn('item_attribute_id', $attributeIds)->pluck('id')->all()
            : [];

        $stepIds = DB::table('item_wizard_steps')
            ->whereIn('profile_id', $profileIds)
            ->where('source_type', 'item_attribute')
            ->where('is_active', 0)
            ->where(function ($q) use ($attributeIds, $pageIds): void {
                $q->whereIn('source_item_attribute_id', $attributeIds)
                    ->orWhereIn('source_ref', array_map('strval', $attributeIds))
                    ->orWhere('source_ref', self::MEAT_ATTRIBUTE);
                if ($pageIds !== []) {
                    $q->orWhereIn('wizard_page_id', $pageIds);
                }
            })
            ->pluck('id')->all();

        if ($this->switchOn($stepIds)) {
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

    /** @param array<int, int|string> $stepIds */
    private function switchOn(array $stepIds): bool
    {
        if ($stepIds === []) {
            return false;
        }
        $this->ensureUndoLog();

        DB::transaction(function () use ($stepIds): void {
            foreach ($stepIds as $id) {
                DB::table('migration_undo_log')->insertOrIgnore([
                    'migration'   => self::NAME,
                    'table_name'  => 'item_wizard_steps',
                    'row_id'      => (int) $id,
                    'column_name' => 'is_active',
                    'old_value'   => '0',
                    'created_at'  => now(),
                ]);
            }
            DB::table('item_wizard_steps')->whereIn('id', $stepIds)->update(['is_active' => 1, 'updated_at' => now()]);
        });

        return true;
    }

    private function ensureUndoLog(): void
    {
        if (Schema::hasTable('migration_undo_log')) {
            return;
        }
        // Même journal générique que 2026_10_22_130000 : une ligne par valeur remplacée.
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
            Log::info('[VG-2026-10-21 R2-002] '.self::NAME.' : version de carte non incrémentée ('.$e->getMessage().').');
        }
    }
};
