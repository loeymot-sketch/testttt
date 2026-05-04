<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\ItemPhotoUploadRequest;
use App\Models\Item;
use Illuminate\Http\JsonResponse;

class ItemPhotoController extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(['permission:items_edit'])->only('store');
    }

    public function store(ItemPhotoUploadRequest $request, Item $item): JsonResponse
    {
        $item->clearMediaCollection('item');
        $media = $item->addMediaFromRequest('photo')->toMediaCollection('item');

        return response()->json([
            'id'          => (int) $media->model_id,
            'thumb_url'   => $item->getFirstMediaUrl('item', 'thumb'),
            'cover_url'   => $item->getFirstMediaUrl('item', 'cover'),
            'preview_url' => $item->getFirstMediaUrl('item', 'preview'),
        ]);
    }
}
