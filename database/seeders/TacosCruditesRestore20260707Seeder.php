<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\ItemExtra;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * [owner 2026-07-07] Restaure les crudités gratuites sur les Tacos (M=26, L=97).
 *
 * Contexte : la décision « tacos SANS crudités » du 2026-06-23
 * (OwnerMenuUpdate20260623Seeder::clearGarnitures) faisait que la borne
 * n'affichait AUCUN choix de crudité pour un tacos (l'étape Garnitures est
 * masquée quand `partitionKioskExtras(item).garnitures` est vide). L'owner a
 * signalé le manque → on réaligne les tacos sur les sandwichs/burgers.
 *
 * Câble Salade / Tomate / Oignon (0 €, group_label='crudite', kiosk-visible).
 * « Oignons cuits » est ajouté séparément par OnionCuitExtra20260706Seeder, qui
 * cible tout porteur de la crudité « Oignon » — DONC ce seeder DOIT tourner
 * AVANT lui dans la chaîne de déploiement (tools/deploy-final-*.sh).
 *
 * Incrémental (le deploy ne relance pas OwnerMenuUpdate) + idempotent
 * (restore si soft-deleted, refresh sinon — jamais de doublon).
 *
 * Rollback : owner « tacos sans crudités » →
 *   UPDATE item_extras SET status=10, deleted_at=NOW()
 *   WHERE item_id IN (26,97) AND group_label='crudite';
 *   Run : php artisan db:seed --class=TacosCruditesRestore20260707Seeder
 */
class TacosCruditesRestore20260707Seeder extends Seeder
{
    /** Tacos M / Tacos L (produits séparés, prix fixes 6,90 / 7,90). */
    private const TACOS_ITEM_IDS = [26, 97];

    /** Crudités gratuites de base (group_label='crudite'), miroir des sandwichs. */
    private const CRUDITES = ['Salade', 'Tomate', 'Oignon'];

    public function run(): void
    {
        $created = 0;
        $refreshed = 0;
        $touchedItems = 0;

        DB::transaction(function () use (&$created, &$refreshed, &$touchedItems) {
            foreach (self::TACOS_ITEM_IDS as $itemId) {
                $before = $created + $refreshed;

                foreach (self::CRUDITES as $name) {
                    $row = ItemExtra::withoutGlobalScopes()
                        ->withTrashed()
                        ->where('item_id', $itemId)
                        ->where('name', $name)
                        ->where('group_label', 'crudite')
                        ->first();

                    if ($row) {
                        if ($row->trashed()) {
                            $row->restore();
                        }
                        $row->fill([
                            'price'        => 0,
                            'status'       => Status::ACTIVE,
                            'group_label'  => 'crudite',
                            'is_available' => 1,
                        ])->save();
                        $refreshed++;

                        continue;
                    }

                    ItemExtra::create([
                        'item_id'      => $itemId,
                        'name'         => $name,
                        'price'        => 0,
                        'status'       => Status::ACTIVE,
                        'group_label'  => 'crudite',
                        'is_available' => 1,
                        'visible_on'   => null,
                    ]);
                    $created++;
                }

                if ($created + $refreshed > $before) {
                    $touchedItems++;
                }
            }
        });

        $this->command?->info("  Tacos crudités: {$created} créées, {$refreshed} rafraîchies (tacos: {$touchedItems}).");
    }
}
