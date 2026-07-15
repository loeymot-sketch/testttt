<?php

namespace App\Http\Controllers\DailyBook;

use App\Http\Controllers\Controller;
use App\Models\DailyBookEntry;
use App\Services\DailyBook\DailyBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * [GOAL RUPTURE-CARNET 2026-07-15 / W4] CRUD des entrées du Carnet.
 * Toutes les routes sont derrière EnsureDailyBookPin (session PIN).
 */
class DailyBookEntryController extends Controller
{
    public function __construct(private DailyBookService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $entries = $this->service->list($validated['date'] ?? null, $validated['month'] ?? null);

        return response()->json([
            'data' => $entries->map(fn (DailyBookEntry $e) => $this->present($e))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(DailyBookEntry::TYPES)],
            'label' => ['required', 'string', 'max:190'],
            'worker_name' => ['required_if:type,advance', 'nullable', 'string', 'max:120'],
            'amount' => [
                'required_unless:type,note', 'nullable', 'numeric', 'min:0', 'max:99999.99',
            ],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
            'photo' => [
                'nullable', 'image', 'mimes:jpg,jpeg,png,webp,heic',
                'max:'.(int) config('daily_book.photo_max_kb', 8192),
            ],
        ]);

        $entry = $this->service->create($validated, $request->file('photo'));

        return response()->json(['data' => $this->present($entry)], 201);
    }

    public function destroy(DailyBookEntry $entry): JsonResponse
    {
        $this->service->delete($entry);

        return response()->json(['message' => 'Entrée supprimée.']);
    }

    private function present(DailyBookEntry $e): array
    {
        $media = $e->getFirstMedia('invoice-photo');

        return [
            'id' => $e->id,
            'type' => $e->type,
            'label' => $e->label,
            'worker_name' => $e->worker_name,
            'amount' => $e->amount !== null ? (float) $e->amount : null,
            'entry_date' => $e->entry_date->format('Y-m-d'),
            'note' => $e->note,
            'photo_url' => $media?->getUrl(),
            'photo_thumb_url' => $media ? ($media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl()) : null,
            'created_at' => $e->created_at?->format('Y-m-d H:i'),
        ];
    }
}
