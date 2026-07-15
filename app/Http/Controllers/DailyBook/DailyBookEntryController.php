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
            // [W6 heal P2] prohibited_if : une note avec montant fantôme faussait
            // le total du jour (frontend somme tout) vs le mois (backend filtre).
            'amount' => [
                'required_unless:type,note', 'prohibited_if:type,note',
                'nullable', 'numeric', 'min:0.01', 'max:99999.99', // 0,00 = acompte fantôme dans by_worker
            ],
            // [W6 heal P3] entry_date bornée : pas de mois fantômes (passé
            // lointain / futur) dans les résumés.
            'entry_date' => [
                'required', 'date_format:Y-m-d',
                'after_or_equal:2024-01-01',
                'before_or_equal:'.now()->addDay()->format('Y-m-d'),
            ],
            'note' => ['nullable', 'string', 'max:2000'],
            'photo' => [
                'nullable', 'image', 'mimes:jpg,jpeg,png,webp,heic',
                'max:'.(int) config('daily_book.photo_max_kb', 8192),
            ],
        ]);

        $entry = $this->service->create($validated, $request->file('photo'));

        return response()->json(['data' => $this->present($entry)], 201);
    }

    /**
     * [BRAIN-SUPERVISOR 2026-07-15 / P3] Sert la photo de facture DERRIÈRE le gate
     * PIN (disque local privé) — l'URL /storage directe contournait EnsureDailyBookPin.
     */
    public function photo(DailyBookEntry $entry, Request $request)
    {
        $media = $entry->getFirstMedia('invoice-photo');
        abort_unless($media, 404);

        $conversion = $request->query('c') === 'thumb' && $media->hasGeneratedConversion('thumb') ? 'thumb' : '';
        $path = $media->getPath($conversion);
        abort_unless(is_file($path), 404);

        return response()->file($path);
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
            // URLs gated PIN (disque privé — pas d'URL /storage publique).
            'photo_url' => $media ? route('daily-book.entries.photo', ['entry' => $e->id]) : null,
            'photo_thumb_url' => $media ? route('daily-book.entries.photo', ['entry' => $e->id, 'c' => 'thumb']) : null,
            'created_at' => $e->created_at?->format('Y-m-d H:i'),
        ];
    }
}
