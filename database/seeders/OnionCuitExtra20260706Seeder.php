<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\ItemExtra;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * [OWNER8 2026-07-06 / LOCK_POSWIZARD_KIOSKWIZARD_OWNER8 §4.2] Extra gratuit
 * « Oignons cuits » (0 €) sur chaque item qui propose déjà la crudité « Oignon »
 * (même groupe crudité). L'owner l'écrivait en note libre — désormais :
 *  - wizard caisse + borne : toggle opt-in (défaut OFF) exclusif avec le cru
 *  - cuisine (KDS + ticket) : symbole O̲ (kdsSymbolic.js / KitchenTicketSymbolicFormatter)
 *
 * Idempotent : une seule ligne par item (restore si soft-deleted, refresh sinon).
 * Rollback (LOCK §7) : UPDATE item_extras SET status=10 WHERE name='Oignons cuits'.
 *   Run :  php artisan db:seed --class=OnionCuitExtra20260706Seeder
 */
class OnionCuitExtra20260706Seeder extends Seeder
{
    public const EXTRA_NAME = 'Oignons cuits';

    public function run(): void
    {
        // Porteurs = items avec la crudité « Oignon » ACTIVE et gratuite.
        $carriers = ItemExtra::query()
            ->where('name', 'Oignon')
            ->where('price', 0)
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->get(['id', 'item_id', 'group_label', 'visible_on']);

        if ($carriers->isEmpty()) {
            $this->command?->warn('  aucun item porteur de la crudité « Oignon » — rien à faire.');

            return;
        }

        $created = 0;
        $refreshed = 0;
        DB::transaction(function () use ($carriers, &$created, &$refreshed) {
            foreach ($carriers as $cru) {
                $row = ItemExtra::withTrashed()
                    ->where('item_id', $cru->item_id)
                    ->where('name', self::EXTRA_NAME)
                    ->first();

                if ($row) {
                    if ($row->trashed()) {
                        $row->restore();
                    }
                    $row->fill([
                        'price'        => 0,
                        'status'       => Status::ACTIVE,
                        // même groupe crudité que le cru (toggle crudités des wizards)
                        'group_label'  => $cru->group_label,
                        'is_available' => 1,
                    ])->save();
                    $refreshed++;

                    continue;
                }

                ItemExtra::create([
                    'item_id'      => $cru->item_id,
                    'name'         => self::EXTRA_NAME,
                    'price'        => 0,
                    'status'       => Status::ACTIVE,
                    'group_label'  => $cru->group_label,
                    'is_available' => 1,
                    'visible_on'   => $cru->visible_on,
                ]);
                $created++;
            }
        });

        $this->command?->info("  Oignons cuits: {$created} créés, {$refreshed} rafraîchis (items porteurs: {$carriers->count()}).");
    }
}
