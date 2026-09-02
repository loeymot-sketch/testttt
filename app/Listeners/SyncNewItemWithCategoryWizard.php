<?php

namespace App\Listeners;

use App\Events\ItemCreated;
use App\Models\Item;
use App\Services\Composer\ComposerProfileService;
use Illuminate\Support\Facades\Log;

/**
 * Un produit ajouté dans une catégorie dont le wizard est publié reçoit immédiatement ses choix
 * (variations/extras/addons) et son clone de profil : plus besoin de « republier » à la main.
 */
class SyncNewItemWithCategoryWizard
{
    public function __construct(private readonly ComposerProfileService $profiles)
    {
    }

    public function handle(ItemCreated $event): void
    {
        try {
            $item = Item::query()->find($event->itemId);
            if (! $item || ! $item->item_category_id) {
                return;
            }

            $this->profiles->syncItemWithCategoryWizard($item);
        } catch (\Throwable $exception) {
            Log::warning('[composer] synchronisation du nouveau produit avec le wizard de sa catégorie impossible', [
                'item_id' => $event->itemId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
