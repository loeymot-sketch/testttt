<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\ItemPhotoUploadRequest;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class ItemPhotoController extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(['permission:items_edit'])->only('store');
    }

    public function store(ItemPhotoUploadRequest $request, Item $item): JsonResponse
    {
        $originalMediaIds = $item->getMedia('item')->pluck('id')->all();

        try {
            $media = $this->addPhotoMedia($item);

            $item->unsetRelation('media');
            $item->getMedia('item')
                ->reject(fn ($existingMedia) => (int) $existingMedia->id === (int) $media->id)
                ->each(fn ($oldMedia) => $oldMedia->delete());

            $item->unsetRelation('media');
        } catch (Throwable $exception) {
            $this->removeMediaCreatedDuringFailedUpload($item, $originalMediaIds);

            Log::error('Item photo upload failed', [
                'item_id' => (int) $item->id,
                'error'   => $exception->getMessage(),
            ]);

            return response()->json([
                'message'    => 'Photo upload failed',
                'error_code' => 'UPLOAD_FAILED',
            ], 500);
        }

        return response()->json([
            'id'          => (int) $media->model_id,
            'thumb_url'   => $item->getFirstMediaUrl('item', 'thumb'),
            'cover_url'   => $item->getFirstMediaUrl('item', 'cover'),
            'preview_url' => $item->getFirstMediaUrl('item', 'preview'),
        ]);
    }

    protected function addPhotoMedia(Item $item): Media
    {
        return $item->addMediaFromRequest('photo')->toMediaCollection('item');
    }

    private function removeMediaCreatedDuringFailedUpload(Item $item, array $originalMediaIds): void
    {
        $item->unsetRelation('media');

        $item->getMedia('item')
            ->reject(fn ($media) => in_array($media->id, $originalMediaIds, true))
            ->each(fn ($media) => $media->delete());

        $item->unsetRelation('media');
    }
}
