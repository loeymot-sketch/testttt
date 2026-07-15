<?php

namespace App\Services\DailyBook;

use App\Models\DailyBookEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * [GOAL RUPTURE-CARNET 2026-07-15 / W4] Logique du Carnet : CRUD des entrées +
 * agrégats jour / mois / par travailleur. Registre interne — hors NF525.
 */
class DailyBookService
{
    private const BRANCH_ID = 1;

    public function create(array $data, ?UploadedFile $photo = null): DailyBookEntry
    {
        $entry = DailyBookEntry::create([
            'type' => $data['type'],
            'label' => $data['label'],
            'worker_name' => $data['worker_name'] ?? null,
            'amount' => $data['amount'] ?? null,
            'entry_date' => $data['entry_date'],
            'note' => $data['note'] ?? null,
            'branch_id' => self::BRANCH_ID,
        ]);

        if ($photo !== null) {
            $entry->addMedia($photo)->toMediaCollection('invoice-photo');
        }

        return $entry;
    }

    public function delete(DailyBookEntry $entry): void
    {
        // SoftDelete : l'historique de gestion reste consultable en DB.
        $entry->delete();
    }

    /** Entrées d'un jour (Y-m-d) ou d'un mois (Y-m), plus récentes d'abord. */
    public function list(?string $date = null, ?string $month = null): Collection
    {
        return DailyBookEntry::query()
            ->when($date, fn ($q) => $q->whereDate('entry_date', $date))
            ->when(!$date && $month, function ($q) use ($month) {
                [$y, $m] = explode('-', $month);
                $q->whereYear('entry_date', (int) $y)->whereMonth('entry_date', (int) $m);
            })
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Résumé d'un mois (Y-m) : totaux dépenses / acomptes, détail par
     * travailleur et par jour — « combien on a donné à cette personne ce mois ».
     */
    public function monthSummary(string $month): array
    {
        [$y, $m] = explode('-', $month);
        $entries = DailyBookEntry::query()
            ->whereYear('entry_date', (int) $y)
            ->whereMonth('entry_date', (int) $m)
            ->get();

        $expenses = $entries->where('type', DailyBookEntry::TYPE_EXPENSE);
        $advances = $entries->where('type', DailyBookEntry::TYPE_ADVANCE);

        return [
            'month' => $month,
            'total_expenses' => round((float) $expenses->sum('amount'), 2),
            'total_advances' => round((float) $advances->sum('amount'), 2),
            'total_out' => round((float) $expenses->sum('amount') + (float) $advances->sum('amount'), 2),
            'notes_count' => $entries->where('type', DailyBookEntry::TYPE_NOTE)->count(),
            'by_worker' => $advances
                ->groupBy(fn ($e) => (string) $e->worker_name)
                ->map(fn ($group, $worker) => [
                    'worker_name' => $worker,
                    'total' => round((float) $group->sum('amount'), 2),
                    'count' => $group->count(),
                ])->values()->all(),
            'by_day' => $entries
                ->whereIn('type', [DailyBookEntry::TYPE_EXPENSE, DailyBookEntry::TYPE_ADVANCE])
                ->groupBy(fn ($e) => $e->entry_date->format('Y-m-d'))
                ->sortKeys()
                ->map(fn ($group, $day) => [
                    'date' => $day,
                    'total' => round((float) $group->sum('amount'), 2),
                ])->values()->all(),
        ];
    }
}
