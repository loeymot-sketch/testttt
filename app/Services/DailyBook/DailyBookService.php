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

    /** Bornes [premier jour, dernier jour] d'un mois Y-m — requête sargable sur l'index. */
    private static function monthBounds(string $month): array
    {
        $start = \Carbon\Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    public function create(array $data, ?UploadedFile $photo = null): DailyBookEntry
    {
        // [DEEP-R2 2026-07-15 / P2] Transaction : un échec d'addMedia (disque plein,
        // HEIC non convertible…) annulait l'upload mais laissait l'entrée committée →
        // 500 + re-soumission = dépense comptée deux fois, ou dépense sans justificatif.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($data, $photo) {
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
        });
    }

    public function delete(DailyBookEntry $entry): void
    {
        // SoftDelete : l'historique de gestion reste consultable en DB.
        $entry->delete();
    }

    /** Entrées d'un jour (Y-m-d) ou d'un mois (Y-m), plus récentes d'abord. */
    public function list(?string $date = null, ?string $month = null): Collection
    {
        // [BRAIN-SUPERVISOR 2026-07-15] with('media') : sans lui, present() lazy-load
        // la relation par entrée (N+1). whereBetween : whereYear/whereMonth enveloppent
        // la colonne d'une fonction → l'index entry_date était inutilisé.
        return DailyBookEntry::query()
            ->with('media')
            ->when($date, fn ($q) => $q->whereDate('entry_date', $date))
            ->when(!$date && $month, fn ($q) => $q->whereBetween('entry_date', self::monthBounds($month)))
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
        $entries = DailyBookEntry::query()
            ->whereBetween('entry_date', self::monthBounds($month))
            ->get();

        $expenses = $entries->where('type', DailyBookEntry::TYPE_EXPENSE);
        $advances = $entries->where('type', DailyBookEntry::TYPE_ADVANCE);

        return [
            'month' => $month,
            'total_expenses' => round((float) $expenses->sum('amount'), 2),
            'total_advances' => round((float) $advances->sum('amount'), 2),
            'total_out' => round((float) $expenses->sum('amount') + (float) $advances->sum('amount'), 2),
            'notes_count' => $entries->where('type', DailyBookEntry::TYPE_NOTE)->count(),
            // [W6 heal P2] Groupement insensible à la casse/espaces : « karim » /
            // « Karim  » = même travailleur (nom libre saisi vite en service) ;
            // l'affichage garde la première casse rencontrée.
            'by_worker' => $advances
                ->groupBy(fn ($e) => mb_strtolower(trim((string) $e->worker_name)))
                ->map(fn ($group) => [
                    'worker_name' => trim((string) $group->first()->worker_name),
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
