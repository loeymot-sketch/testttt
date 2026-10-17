<?php

namespace App\Http\Controllers\Admin\Hygiene;

use App\Models\HygieneAttachment;
use App\Models\HygieneEquipment;
use App\Models\HygieneOilControl;
use App\Services\Hygiene\OilRules;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Huile de friture : test (composés polaires, température du bain), filtrage, vidange — par friteuse.
 * (Komia n'avait que « changement d'huile », sans mesure ni seuil.)
 *
 * Le SERVEUR décide : au-dessus de la limite légale de composés polaires (25 %, décret 2008-1463) ou bain trop chaud ⇒ écart, et
 * une action corrective est OBLIGATOIRE. Journal append-only (annulation motivée), contexte copié, envoi rejouable (`client_ref`).
 * Les friteuses sont des équipements (kind = fryer) : un seul référentiel, mais jamais montrées à l'écran des températures.
 */
class OilController extends HygieneController
{
    private const KIND = 'fryer';

    // ───────────────────────────── L'écran du jour ─────────────────────────────

    public function today(Request $request): JsonResponse
    {
        $now = now();
        $start = $now->copy()->startOfDay();
        $fryers = $this->fryers()->where('is_active', true)->get();
        $ids = $fryers->pluck('id')->all();

        $live = HygieneOilControl::query()->whereNull('voided_at')->whereIn('equipment_id', $ids === [] ? [0] : $ids)
            ->where('performed_at', '>=', $start->copy()->subDays(400))->orderByDesc('performed_at')->orderByDesc('id')->get()->groupBy('equipment_id');

        $items = $fryers->map(function (HygieneEquipment $f) use ($live, $start, $now): array {
            $rows = $live->get($f->id, collect());
            $testToday = $rows->first(fn ($r) => $r->type === 'test' && $r->performed_at->gte($start));
            $lastTest = $rows->first(fn ($r) => $r->type === 'test');
            $lastChange = $rows->first(fn ($r) => $r->type === 'change');
            $lastFilter = $rows->first(fn ($r) => $r->type === 'filter');

            return $this->presentFryer($f) + [
                'state' => $testToday ? $testToday->status : 'todo',
                'tested_today' => $testToday !== null,
                'last_test' => $lastTest ? $this->present($lastTest) : null,
                'last_change' => $lastChange ? $this->present($lastChange) : null,
                'days_since_change' => $lastChange ? (int) $lastChange->performed_at->copy()->startOfDay()->diffInDays($start) : null,
                'last_filter' => $lastFilter ? $this->present($lastFilter) : null,
                'today' => $rows->filter(fn ($r) => $r->performed_at->gte($start))->reverse()->values()->map(fn ($r) => $this->present($r))->all(),
            ];
        })->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'summary' => [
                    'total' => $items->count(),
                    'todo' => $items->where('state', 'todo')->count(),
                    'ok' => $items->where('state', 'ok')->count(),
                    'warn' => $items->where('state', 'warn')->count(),
                    'alert' => $items->where('state', 'alert')->count(),
                ],
            ] + $this->config() + $this->clock(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'equipment_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(array_keys(config('hygiene.oil.types')))],
            'polar_pct' => ['nullable', 'numeric'],
            'bath_temp_c' => ['nullable', 'numeric'],
            'oil_litres' => ['nullable', 'numeric', 'between:0.5,200'],
            'oil_brand' => ['nullable', 'string', 'max:60'],
            'corrective_action' => ['nullable', Rule::in(array_keys(config('hygiene.oil.corrective_actions')))],
            'corrective_note' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:200'],
            'attachment_id' => ['nullable', 'integer'],
            'operator_user_id' => ['nullable', 'integer'],
            'client_ref' => ['nullable', 'string', 'max:40'],
        ], [], ['polar_pct' => 'composés polaires', 'bath_temp_c' => 'température du bain', 'oil_litres' => 'volume']);

        if (! empty($v['client_ref'])) {
            $dup = HygieneOilControl::query()->where('client_ref', $v['client_ref'])->first();
            if ($dup) return response()->json(['data' => $this->present($dup)], 200);
        }
        $fryer = $this->fryers()->where('is_active', true)->find((int) $v['equipment_id']);
        if (! $fryer) return $this->fail('Friteuse introuvable ou désactivée.', 'equipment_id');

        $type = $v['type'];
        $polar = $bath = $litres = $brand = null;
        $verdict = ['status' => 'ok', 'reasons' => []];
        if ($type === 'test') {
            if (! isset($v['polar_pct']) || $v['polar_pct'] === '') return $this->fail('Saisissez le taux de composés polaires mesuré.', 'polar_pct');
            $polar = round((float) $v['polar_pct'], 1);
            [$lo, $hi] = config('hygiene.oil.plausible_polar');
            if ($polar < $lo || $polar > $hi) return $this->fail('« '.$this->fmt($polar).' % » de composés polaires n’est pas plausible (faute de frappe ?).', 'polar_pct');
            if (isset($v['bath_temp_c']) && $v['bath_temp_c'] !== '') {
                $bath = round((float) $v['bath_temp_c'], 1);
                [$blo, $bhi] = config('hygiene.oil.plausible_bath');
                if ($bath < $blo || $bath > $bhi) return $this->fail('Température du bain « '.$this->fmt($bath).' °C » non plausible (faute de frappe ?).', 'bath_temp_c');
            }
            $verdict = OilRules::evaluate($polar, $bath);
        } elseif ($type === 'change') {
            $litres = isset($v['oil_litres']) ? round((float) $v['oil_litres'], 1) : null;
            $brand = isset($v['oil_brand']) && trim($v['oil_brand']) !== '' ? trim($v['oil_brand']) : null;
        }

        $action = $note = null;
        if ($verdict['status'] === 'alert') {
            $action = $v['corrective_action'] ?? null;
            $note = isset($v['corrective_note']) && trim((string) $v['corrective_note']) !== '' ? trim($v['corrective_note']) : null;
            if ($action === null) return $this->fail('Écart : indiquez ce qui a été fait (huile changée, friteuse arrêtée…).', 'corrective_action');
            if (config("hygiene.oil.corrective_actions.$action.note") && ($note === null || mb_strlen($note) < 3)) {
                return $this->fail('Précisez l’action corrective.', 'corrective_note');
            }
        }

        if (! empty($v['attachment_id']) && ! HygieneAttachment::query()->whereKey((int) $v['attachment_id'])->exists()) {
            return $this->fail('Photo introuvable.', 'attachment_id');
        }

        $op = $this->operator($request);
        $free = isset($v['note']) && trim((string) $v['note']) !== '' ? trim($v['note']) : null;
        $row = new HygieneOilControl([
            'equipment_id' => $fryer->id, 'equipment_name' => $fryer->name, 'type' => $type, 'polar_pct' => $polar, 'bath_temp_c' => $bath,
            'oil_litres' => $litres, 'oil_brand' => $brand, 'status' => $verdict['status'], 'reasons' => $verdict['reasons'] ? implode(',', $verdict['reasons']) : null,
            'polar_limit' => $type === 'test' ? config('hygiene.oil.polar_limit') : null, 'corrective_action' => $action, 'corrective_note' => $note,
            'note' => $free, 'attachment_id' => $v['attachment_id'] ?? null, 'performed_at' => now(), 'timezone' => config('app.timezone'),
            'user_id' => $op['user_id'], 'operator_name' => $op['name'], 'client_ref' => $v['client_ref'] ?? null,
        ]);
        $row->branch_id = $this->branchId($request);
        try {
            $row->save();
        } catch (QueryException $e) {
            if (empty($v['client_ref']) || ! $this->isUniqueViolation($e)) throw $e;
            $row = HygieneOilControl::query()->where('client_ref', $v['client_ref'])->firstOrFail();
        }

        return response()->json(['data' => $this->present($row)], 201);
    }

    public function void(Request $request, string $control): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:120']], [], ['reason' => 'motif']);
        $row = HygieneOilControl::query()->findOrFail((int) $control);
        if ($row->voided_at === null) {
            $row->forceFill(['voided_at' => now(), 'voided_by' => $request->user()?->id, 'void_reason' => trim($v['reason'])])->save();
        }

        return response()->json(['data' => $this->present($row->refresh())]);
    }

    public function history(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'equipment_id' => ['nullable', 'integer'], 'type' => ['nullable', Rule::in(array_keys(config('hygiene.oil.types')))],
            'alerts_only' => ['nullable', 'boolean'], 'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        [$from, $to] = $this->period($v);
        $rows = HygieneOilControl::query()->whereBetween('performed_at', [$from, $to])
            ->when(! empty($v['equipment_id']), fn ($q) => $q->where('equipment_id', (int) $v['equipment_id']))
            ->when(! empty($v['type']), fn ($q) => $q->where('type', $v['type']))
            ->when(! empty($v['alerts_only']), fn ($q) => $q->where('status', 'alert'))
            ->orderByDesc('performed_at')->orderByDesc('id')->limit((int) ($v['limit'] ?? 200))->get()
            ->map(fn ($r) => $this->present($r))->values();

        return response()->json(['data' => $rows, 'meta' => $this->clock()]);
    }

    // ───────────────────────────── Friteuses ─────────────────────────────

    public function fryerList(): JsonResponse
    {
        return response()->json(['data' => $this->fryers()->get()->map(fn ($f) => $this->presentFryer($f))->values(), 'meta' => $this->clock()]);
    }

    /** « J'ai 3 friteuses » : crée « Friteuse 1 »… (une seule → « Friteuse »). Idempotent : un nom déjà pris n'est pas recréé. */
    public function quickSetup(Request $request): JsonResponse
    {
        $v = $request->validate(['count' => ['required', 'integer', 'min:1', 'max:12']], [], ['count' => 'nombre de friteuses']);
        $branch = $this->branchId($request);
        $taken = HygieneEquipment::query()->get(['name'])->map(fn ($e) => mb_strtolower(trim((string) $e->name)))->all();
        $order = (int) HygieneEquipment::query()->max('sort_order');
        $short = config('hygiene.kinds.fryer.short');
        $added = 0;
        for ($i = 1; $i <= (int) $v['count']; $i++) {
            $name = (int) $v['count'] === 1 ? $short : "$short $i";
            if (in_array(mb_strtolower($name), $taken, true)) continue;
            $e = new HygieneEquipment(['name' => $name, 'kind' => self::KIND, 'sort_order' => $order += 10, 'is_active' => true]);
            $e->branch_id = $branch;
            $e->save();
            $taken[] = mb_strtolower($name);
            $added++;
        }

        return response()->json(['data' => ['added' => $added]], $added > 0 ? 201 : 200);
    }

    public function storeFryer(Request $request): JsonResponse
    {
        $v = $this->validateFryer($request);
        $e = new HygieneEquipment(['name' => $v['name'], 'kind' => self::KIND, 'location' => $v['location'] ?? null,
            'sort_order' => (int) HygieneEquipment::query()->max('sort_order') + 10, 'is_active' => true]);
        $e->branch_id = $this->branchId($request);
        $e->save();

        return response()->json(['data' => $this->presentFryer($e->refresh())], 201);
    }

    public function updateFryer(Request $request, string $fryer): JsonResponse
    {
        $e = $this->fryers()->findOrFail((int) $fryer);
        $e->fill($this->validateFryer($request, $e->id))->save();

        return response()->json(['data' => $this->presentFryer($e->refresh())]);
    }

    public function destroyFryer(string $fryer): JsonResponse
    {
        $this->fryers()->findOrFail((int) $fryer)->delete(); // douce : le journal garde le nom copié

        return response()->json(['status' => 'ok']);
    }

    // ───────────────────────────── utilitaires ─────────────────────────────

    private function fryers()
    {
        return HygieneEquipment::query()->where('kind', self::KIND)->orderBy('sort_order')->orderBy('id');
    }

    private function validateFryer(Request $request, ?int $ignoreId = null): array
    {
        $v = $request->validate([
            'name' => [$ignoreId ? 'sometimes' : 'required', 'filled', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['name' => 'nom']);
        if (isset($v['name'])) {
            $v['name'] = trim($v['name']);
            $dup = HygieneEquipment::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($v['name'])])->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
            if ($dup) abort(response()->json(['message' => 'Cet équipement existe déjà.', 'errors' => ['name' => ['Un équipement porte déjà ce nom.']]], 422));
        }
        if (array_key_exists('location', $v)) $v['location'] = isset($v['location']) && trim((string) $v['location']) !== '' ? trim($v['location']) : null;
        if (array_key_exists('is_active', $v) && $v['is_active'] === null) unset($v['is_active']);

        return $v;
    }

    private function config(): array
    {
        return [
            'types' => collect(config('hygiene.oil.types'))->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'corrective_actions' => collect(config('hygiene.oil.corrective_actions'))->map(fn ($a, $k) => ['key' => $k] + $a)->values()->all(),
            'limits' => [
                'polar_limit' => config('hygiene.oil.polar_limit'), 'polar_warn' => config('hygiene.oil.polar_warn'), 'bath_max_c' => config('hygiene.oil.bath_max_c'),
            ],
        ];
    }

    private function fail(string $message, string $field): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    private function fmt(float $v): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.'));
    }

    private function presentFryer(HygieneEquipment $e): array
    {
        return ['id' => $e->id, 'name' => $e->name, 'location' => $e->location, 'sort_order' => $e->sort_order, 'is_active' => $e->is_active];
    }

    private function present(HygieneOilControl $r): array
    {
        return [
            'id' => $r->id, 'equipment_id' => $r->equipment_id, 'equipment_name' => $r->equipment_name, 'type' => $r->type,
            'type_label' => config("hygiene.oil.types.{$r->type}"), 'polar_pct' => $r->polar_pct, 'bath_temp_c' => $r->bath_temp_c,
            'oil_litres' => $r->oil_litres, 'oil_brand' => $r->oil_brand, 'status' => $r->status, 'reasons' => $r->reasons ? explode(',', $r->reasons) : [],
            'polar_limit' => $r->polar_limit, 'corrective_action' => $r->corrective_action,
            'corrective_label' => $r->corrective_action ? config("hygiene.oil.corrective_actions.{$r->corrective_action}.label") : null,
            'corrective_note' => $r->corrective_note, 'note' => $r->note, 'attachment_id' => $r->attachment_id,
            'performed_at' => $r->performed_at->toIso8601String(), 'operator_name' => $r->operator_name,
            'voided' => $r->voided_at !== null, 'voided_at' => $r->voided_at?->toIso8601String(), 'void_reason' => $r->void_reason,
        ];
    }
}
