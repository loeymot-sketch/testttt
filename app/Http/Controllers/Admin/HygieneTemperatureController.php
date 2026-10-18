<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Hygiene\HygieneController;
use App\Models\HygieneEquipment;
use App\Models\HygieneTemperatureReading;
use App\Services\Hygiene\TemperatureRules;
use App\Support\Hygiene\Kinds;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Relevés de température : quatre frigos positifs, deux congélateurs négatifs… chaque
 * jour on saisit la valeur, on valide, c'est enregistré avec tout son contexte.
 *
 * LE SERVEUR EST LA SOURCE DE VÉRITÉ du verdict (conforme / écart), de l'horloge et du journal. Un relevé n'est jamais
 * modifié ni supprimé : une faute de frappe s'ANNULE (motif obligatoire) et la ligne reste au journal.
 *
 * Porte : la même que les étiquettes (permission:pos-orders|pos|kitchen-display-system) — le personnel qui tient la tablette.
 * Domaine NEUF, ADDITIF, HORS NF525. Contrat du module : config/hygiene.php.
 */
class HygieneTemperatureController extends HygieneController
{
    // ───────────────────────────── Équipements ─────────────────────────────

    public function equipment(): JsonResponse
    {
        $rows = HygieneEquipment::query()->whereIn('kind', Kinds::keys('temperature'))->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (HygieneEquipment $e): array => $this->presentEquipment($e))->values();

        return response()->json([
            'data' => $rows,
            'meta' => ['kinds' => $this->kinds(), 'corrective_actions' => $this->correctiveActions()] + $this->clock(),
        ]);
    }

    public function storeEquipment(Request $request): JsonResponse
    {
        $data = $this->validateEquipment($request);
        $kind = $data['kind'];
        $defaults = TemperatureRules::defaults($kind);
        $e = new HygieneEquipment([
            'name' => $data['name'],
            'kind' => $kind,
            'location' => $data['location'] ?? null,
            'min_c' => array_key_exists('min_c', $data) ? $data['min_c'] : $defaults['min'],
            'max_c' => array_key_exists('max_c', $data) ? $data['max_c'] : $defaults['max'],
            'sort_order' => $data['sort_order'] ?? ((int) HygieneEquipment::query()->max('sort_order') + 10),
            'is_active' => $data['is_active'] ?? true,
        ]);
        $e->branch_id = $this->branchId($request);
        $e->save();

        return response()->json(['data' => $this->presentEquipment($e->refresh())], 201);
    }

    public function updateEquipment(Request $request, string $equipment): JsonResponse
    {
        $model = HygieneEquipment::query()->whereIn('kind', Kinds::keys('temperature'))->findOrFail((int) $equipment);
        $model->fill($this->validateEquipment($request, $model->id))->save();

        return response()->json(['data' => $this->presentEquipment($model->refresh())]);
    }

    public function destroyEquipment(string $equipment): JsonResponse
    {
        // Suppression douce : le journal garde le nom et les seuils copiés à chaque relevé.
        HygieneEquipment::query()->whereIn('kind', Kinds::keys('temperature'))->findOrFail((int) $equipment)->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Démarrage en un geste : « j'ai 4 frigos, 2 congélateurs et un bain-marie ». Crée « Frigo 1 »… « Congélateur 2 », « Bain-marie » avec les seuils du type.
     * Idempotent : un nom déjà pris (sans tenir compte de la casse) n'est pas recréé ; la numérotation reprend là où elle s'est arrêtée.
     */
    public function quickSetup(Request $request): JsonResponse
    {
        $v = $request->validate([
            'fridge' => ['nullable', 'integer', 'min:0', 'max:20'],
            'freezer' => ['nullable', 'integer', 'min:0', 'max:20'],
            'cold_room' => ['nullable', 'integer', 'min:0', 'max:10'],
            'display' => ['nullable', 'integer', 'min:0', 'max:10'],
            'hot_holding' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);
        $branch = $this->branchId($request);
        $taken = HygieneEquipment::query()->get(['name'])->map(fn ($e) => mb_strtolower(trim((string) $e->name)))->all();
        $order = (int) HygieneEquipment::query()->max('sort_order');
        $added = 0;
        foreach (['fridge', 'cold_room', 'display', 'freezer', 'hot_holding'] as $kind) {
            $want = (int) ($v[$kind] ?? 0);
            $short = config("hygiene.kinds.$kind.short");
            $defaults = TemperatureRules::defaults($kind);
            // « j'en ai N » = N équipements de ce type AU TOTAL (même renommés) : on ne crée que ce qui manque
            $have = HygieneEquipment::query()->where('kind', $kind)->count();
            $missing = max(0, $want - $have);
            for ($i = 1, $made = 0; $made < $missing && $i <= 60; $i++) {
                $name = $want === 1 ? $short : "$short $i";
                if (in_array(mb_strtolower($name), $taken, true)) continue;
                $made++;
                $e = new HygieneEquipment(['name' => $name, 'kind' => $kind, 'min_c' => $defaults['min'], 'max_c' => $defaults['max'],
                    'sort_order' => $order += 10, 'is_active' => true]);
                $e->branch_id = $branch;
                $e->save();
                $taken[] = mb_strtolower($name);
                $added++;
            }
        }

        return response()->json(['data' => ['added' => $added]], $added > 0 ? 201 : 200);
    }

    // ───────────────────────────── Relevés du jour ─────────────────────────────

    /**
     * L'écran du jour : chaque équipement actif avec son état (à faire / conforme / écart), son dernier relevé d'aujourd'hui et
     * celui de la veille (pour juger d'un coup d'œil), et les compteurs de la journée.
     */
    public function today(): JsonResponse
    {
        $now = now();
        $start = $now->copy()->startOfDay();
        $equipments = HygieneEquipment::query()->whereIn('kind', Kinds::keys('temperature'))->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        $todayRows = HygieneTemperatureReading::query()
            ->whereNull('voided_at')->where('recorded_at', '>=', $start)->orderBy('recorded_at')->orderBy('id')->get()
            ->groupBy('equipment_id');
        $previous = HygieneTemperatureReading::query()
            ->whereNull('voided_at')->where('recorded_at', '<', $start)->where('recorded_at', '>=', $start->copy()->subDays(14))
            ->orderByDesc('recorded_at')->get()->unique('equipment_id')->keyBy('equipment_id');

        $period = TemperatureRules::periodFor($now);
        $items = $equipments->map(function (HygieneEquipment $e) use ($todayRows, $previous, $period): array {
            $rows = $todayRows->get($e->id, collect());
            $last = $rows->last();
            $prev = $previous->get($e->id);

            return $this->presentEquipment($e) + [
                'state' => $last === null ? 'todo' : ($last->status === HygieneTemperatureReading::STATUS_ALERT ? 'alert' : 'ok'),
                'last_today' => $last ? $this->presentReading($last) : null,
                'previous' => $prev ? ['value_c' => $prev->value_c, 'recorded_at' => $prev->recorded_at->toIso8601String(), 'status' => $prev->status] : null,
                'done_periods' => $rows->pluck('period')->unique()->values()->all(),
                'done_this_period' => $rows->contains(fn ($r) => $r->period === $period),
            ];
        })->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'period' => $period,
                'periods' => config('hygiene.periods'),
                'summary' => [
                    'total' => $items->count(),
                    'todo' => $items->where('state', 'todo')->count(),
                    'ok' => $items->where('state', 'ok')->count(),
                    'alert' => $items->where('state', 'alert')->count(),
                ],
                'kinds' => $this->kinds(),
                'corrective_actions' => $this->correctiveActions(),
            ] + $this->clock(),
        ]);
    }

    /**
     * Enregistre un ou plusieurs relevés en UNE fois (« tout valider »). Tout ou rien : un seul relevé invalide — valeur
     * absurde, écart sans action corrective — refuse le lot entier avec le détail, pour que la personne corrige puis revalide.
     * `client_ref` rend l'envoi rejouable (coupure réseau) : un relevé déjà enregistré n'est jamais doublé.
     */
    public function storeReadings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'readings' => ['required', 'array', 'min:1', 'max:60'],
            'readings.*.equipment_id' => ['required', 'integer'],
            'readings.*.value' => ['required', 'numeric'],
            'readings.*.period' => ['nullable', Rule::in(array_keys(config('hygiene.periods')))],
            'readings.*.corrective_action' => ['nullable', Rule::in(array_keys(config('hygiene.corrective_actions')))],
            'readings.*.corrective_note' => ['nullable', 'string', 'max:200'],
            'readings.*.client_ref' => ['nullable', 'string', 'max:40'],
            'readings.*.measured_at' => ['nullable', 'date'],
            'operator_user_id' => ['nullable', 'integer'],
        ], [], ['readings' => 'relevés', 'readings.*.value' => 'température']);

        $min = (float) config('hygiene.plausible_min');
        $max = (float) config('hygiene.plausible_max');
        $now = now();
        $branch = $this->branchId($request);
        // tablette partagée : la personne choisie sous « Qui ? », sinon le compte connecté. Si elle a un code, le jeton doit couvrir l'heure du
        // GESTE : celle de la mesure pour un lot gardé hors ligne (la plus ancienne), sinon maintenant.
        $stamps = collect($validated['readings'])->pluck('measured_at')->filter()->map(fn ($m) => \Carbon\Carbon::parse($m));
        $op = $this->operator($request, $stamps->isNotEmpty() ? $stamps->min() : null);
        $equipments = HygieneEquipment::query()->whereIn('kind', Kinds::keys('temperature'))->where('is_active', true)
            ->whereIn('id', collect($validated['readings'])->pluck('equipment_id')->unique()->all())->get()->keyBy('id');

        $errors = [];
        $prepared = [];
        $seenEquipment = [];
        foreach ($validated['readings'] as $i => $r) {
            $equipment = $equipments->get((int) $r['equipment_id']);
            if (! $equipment) {
                $errors["readings.$i.equipment_id"] = ['Équipement introuvable ou désactivé.'];
                continue;
            }
            if (isset($seenEquipment[$equipment->id])) {
                $errors["readings.$i.equipment_id"] = ['« '.$equipment->name.' » figure deux fois dans cet envoi.'];
                continue;
            }
            $seenEquipment[$equipment->id] = true;
            // Heure du relevé : saisi hors ligne, il garde l'heure où il a été PRIS (envoyée par la tablette), dans des bornes ; sinon l'heure du serveur.
            $measured = null;
            if (! empty($r['measured_at'])) {
                $m = \Carbon\Carbon::parse($r['measured_at'])->setTimezone(config('app.timezone'));
                if ($m->gt($now->copy()->addMinutes((int) config('hygiene.offline.future_minutes', 5)))) {
                    $errors["readings.$i.measured_at"] = ['« '.$equipment->name.' » : l’heure de la tablette est en avance sur celle du serveur — corrigez l’heure de la tablette puis ressaisissez le relevé.'];
                    continue;
                }
                if ($m->lt($now->copy()->subHours((int) config('hygiene.offline.max_hours', 72)))) {
                    $errors["readings.$i.measured_at"] = ['« '.$equipment->name.' » : ce relevé date de plus de '.(int) config('hygiene.offline.max_hours', 72).' h — ressaisissez-le avec une mesure actuelle.'];
                    continue;
                }
                $measured = $m;
            }
            $value = round((float) $r['value'], 1);
            [$lo, $hi] = config("hygiene.kinds.{$equipment->kind}.plausible") ?? [$min, $max];
            if ($value < $lo || $value > $hi) {
                $errors["readings.$i.value"] = ['« '.$equipment->name.' » : '.$this->fmt($value).' °C n’est pas plausible pour '.($this->isHot($equipment->kind) ? 'un équipement chaud' : 'une enceinte froide').' (faute de frappe ?).'];
                continue;
            }
            $verdict = TemperatureRules::evaluate($value, $equipment->min_c, $equipment->max_c);
            $action = $r['corrective_action'] ?? null;
            $note = isset($r['corrective_note']) && trim((string) $r['corrective_note']) !== '' ? trim((string) $r['corrective_note']) : null;
            if ($verdict['status'] === HygieneTemperatureReading::STATUS_ALERT) {
                if ($action === null) {
                    $errors["readings.$i.corrective_action"] = ['« '.$equipment->name.' » : '.$this->fmt($value).' °C est hors seuil — indiquez ce qui a été fait.'];
                    continue;
                }
                $for = config("hygiene.corrective_actions.$action.for");
                if ($for !== null && $for !== ($this->isHot($equipment->kind) ? 'hot' : 'cold')) {
                    $errors["readings.$i.corrective_action"] = ['« '.$equipment->name.' » : cette action ne convient pas à ce type d’équipement — choisissez-en une autre.'];
                    continue;
                }
                if (config("hygiene.corrective_actions.$action.note") && $note === null) {
                    $errors["readings.$i.corrective_note"] = ['« '.$equipment->name.' » : précisez l’action corrective.'];
                    continue;
                }
            } else {
                // un relevé conforme ne porte ni action ni commentaire d'écart
                $action = null;
                $note = null;
            }
            $prepared[] = compact('equipment', 'value', 'verdict', 'action', 'note') + [
                'period' => $r['period'] ?? TemperatureRules::periodFor($measured ?? $now),
                'client_ref' => $r['client_ref'] ?? null,
                'recorded_at' => $measured ?? $now,
                // la tablette n'envoie l'heure de la mesure QUE lorsqu'elle a gardé le relevé hors ligne : sa présence suffit (dit au journal, jamais caché)
                'source' => $measured !== null ? 'offline' : 'manual',
            ];
        }
        if ($errors !== []) {
            return response()->json(['message' => 'Relevés refusés : '.collect($errors)->flatten()->first(), 'errors' => $errors], 422);
        }

        // une référence d'envoi désigne UN relevé : réutilisée pour un autre équipement ou une autre valeur, elle ne doit pas rendre l'ancienne
        // ligne en silence (la correction serait perdue sous un « enregistré »), ni deux fois dans le même lot
        $refs = [];
        foreach ($prepared as $p) {
            if ($p['client_ref'] === null) continue;
            if (isset($refs[$p['client_ref']])) {
                return response()->json(['message' => 'Référence d’envoi en double dans le lot.', 'errors' => ['readings' => ['Référence d’envoi en double dans le lot.']]], 422);
            }
            $refs[$p['client_ref']] = true;
            $dup = HygieneTemperatureReading::query()->where('client_ref', $p['client_ref'])->first();
            if ($dup && ($dup->equipment_id !== $p['equipment']->id || round((float) $dup->value_c, 1) !== round((float) $p['value'], 1))) {
                return response()->json(['message' => 'Cette référence d’envoi a déjà enregistré un autre relevé : rechargez l’écran et ressaisissez.', 'errors' => ['readings' => ['Référence d’envoi déjà utilisée par un autre relevé.']]], 409);
            }
        }

        $saved = [];
        DB::transaction(function () use ($prepared, $branch, $op, $now, &$saved): void {
            foreach ($prepared as $p) {
                if ($p['client_ref'] !== null) {
                    $dup = HygieneTemperatureReading::query()->where('client_ref', $p['client_ref'])->first();
                    if ($dup) { $saved[] = $dup; continue; }
                }
                $row = new HygieneTemperatureReading([
                    'equipment_id' => $p['equipment']->id,
                    'equipment_name' => $p['equipment']->name,
                    'equipment_kind' => $p['equipment']->kind,
                    'min_c' => $p['equipment']->min_c,
                    'max_c' => $p['equipment']->max_c,
                    'value_c' => $p['value'],
                    'status' => $p['verdict']['status'],
                    'alert_reason' => $p['verdict']['reason'],
                    'period' => $p['period'],
                    'source' => $p['source'],
                    'recorded_at' => $p['recorded_at'],
                    'timezone' => config('app.timezone'),
                    'user_id' => $op['user_id'],
                    'operator_name' => $op['name'],
                    'corrective_action' => $p['action'],
                    'corrective_note' => $p['note'],
                    'client_ref' => $p['client_ref'],
                ]);
                $row->branch_id = $branch;
                try {
                    $row->save();
                } catch (QueryException $e) {
                    if (! $this->isUniqueViolation($e)) throw $e;
                    $row = HygieneTemperatureReading::query()->where('client_ref', $p['client_ref'])->firstOrFail(); // rejoué en parallèle
                }
                $saved[] = $row;
            }
        });

        $rows = collect($saved)->map(fn ($r) => $this->presentReading($r))->values();

        return response()->json(['data' => $rows, 'meta' => ['alerts' => $rows->where('status', 'alert')->count()] + $this->clock()], 201);
    }

    /** Annule un relevé (faute de frappe) : motif obligatoire, la ligne reste au journal. */
    public function voidReading(Request $request, string $reading): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:120']], [], ['reason' => 'motif']);
        $row = HygieneTemperatureReading::query()->findOrFail((int) $reading);
        if ($row->voided_at === null) {
            $row->forceFill([
                'voided_at' => now(), 'voided_by' => $request->user()?->id, 'void_reason' => trim($v['reason']),
            ])->save();
        }

        return response()->json(['data' => $this->presentReading($row->refresh())]);
    }

    /** Journal : filtres période / équipement / écarts seuls ; annulés inclus (marqués). */
    public function history(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'equipment_id' => ['nullable', 'integer'],
            'alerts_only' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $from = isset($v['from']) ? \Carbon\Carbon::createFromFormat('Y-m-d', $v['from'], config('app.timezone'))->startOfDay() : now()->subDays(30)->startOfDay();
        $to = isset($v['to']) ? \Carbon\Carbon::createFromFormat('Y-m-d', $v['to'], config('app.timezone'))->endOfDay() : now()->endOfDay();

        $rows = HygieneTemperatureReading::query()
            ->whereBetween('recorded_at', [$from, $to])
            ->when(! empty($v['equipment_id']), fn ($q) => $q->where('equipment_id', (int) $v['equipment_id']))
            ->when(! empty($v['alerts_only']), fn ($q) => $q->where('status', HygieneTemperatureReading::STATUS_ALERT))
            ->orderByDesc('recorded_at')->orderByDesc('id')
            ->limit((int) ($v['limit'] ?? 200))->get()
            ->map(fn ($r) => $this->presentReading($r))->values();

        return response()->json(['data' => $rows, 'meta' => $this->clock()]);
    }

    // ───────────────────────────── utilitaires ─────────────────────────────

    private function validateEquipment(Request $request, ?int $ignoreId = null): array
    {
        $kinds = Kinds::keys('temperature');
        $v = $request->validate([
            'name' => [$ignoreId ? 'sometimes' : 'required', 'filled', 'string', 'max:80'],
            'kind' => [$ignoreId ? 'sometimes' : 'required', Rule::in($kinds)],
            'location' => ['nullable', 'string', 'max:60'],
            'min_c' => ['nullable', 'numeric', 'between:-60,99.9'],
            'max_c' => ['nullable', 'numeric', 'between:-60,99.9'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['name' => 'nom', 'kind' => 'type', 'min_c' => 'seuil bas', 'max_c' => 'seuil haut']);

        if (isset($v['name'])) {
            $v['name'] = trim($v['name']);
            $dup = HygieneEquipment::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($v['name'])])
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
            if ($dup) {
                abort(response()->json(['message' => 'Cet équipement existe déjà.', 'errors' => ['name' => ['Un équipement porte déjà ce nom.']]], 422));
            }
        }
        if (array_key_exists('location', $v)) {
            $v['location'] = isset($v['location']) && trim((string) $v['location']) !== '' ? trim((string) $v['location']) : null;
        }
        foreach (['min_c', 'max_c'] as $f) {
            if (array_key_exists($f, $v) && $v[$f] !== null) $v[$f] = round((float) $v[$f], 1);
        }
        $this->assertThresholds($v, $ignoreId);
        foreach (['sort_order', 'is_active'] as $f) {
            if (array_key_exists($f, $v) && $v[$f] === null) unset($v[$f]);
        }

        return $v;
    }

    /**
     * Un seuil se règle dans des BORNES (config threshold_bounds) et ne se désarme jamais : seuil haut obligatoire, seuil bas jamais au-dessus.
     * Vérifié sur les valeurs EFFECTIVES (celles envoyées, sinon celles déjà enregistrées) : une mise à jour partielle ne peut pas
     * produire des seuils incohérents. À la création sans seuil, les valeurs du type s'appliquent (storeEquipment).
     */
    private function assertThresholds(array $v, ?int $ignoreId): void
    {
        $current = $ignoreId ? HygieneEquipment::query()->find($ignoreId) : null;
        $kind = $v['kind'] ?? $current?->kind;
        $touched = array_key_exists('min_c', $v) || array_key_exists('max_c', $v);
        if ($kind === null || (! $touched && $ignoreId && ! isset($v['kind']))) return;

        $defaults = TemperatureRules::defaults($kind);
        $max = array_key_exists('max_c', $v) ? $v['max_c'] : ($current ? $current->max_c : $defaults['max']);
        $min = array_key_exists('min_c', $v) ? $v['min_c'] : ($current ? $current->min_c : $defaults['min']);
        $fail = fn (string $field, string $msg) => abort(response()->json(['message' => $msg, 'errors' => [$field => [$msg]]], 422));

        // Équipement chaud : c'est le seuil BAS qui protège (le plat ne doit pas descendre sous 63 °C) ; le haut est facultatif.
        if ($this->isHot($kind)) {
            $hot = config('hygiene.threshold_bounds.hot');
            if ($min === null) $fail('min_c', 'Le seuil bas est obligatoire pour un équipement chaud : sans lui, un plat tiède ne serait jamais signalé.');
            [$hlo, $hhi] = $hot['min'];
            if ($min < $hlo || $min > $hhi) $fail('min_c', 'Seuil bas hors limites pour un équipement chaud : entre '.$this->fmt($hlo).' et '.$this->fmt($hhi).' °C.');
            if ($max !== null) {
                [$xlo, $xhi] = $hot['max'];
                if ($max < $xlo || $max > $xhi) $fail('max_c', 'Seuil haut hors limites : entre '.$this->fmt($xlo).' et '.$this->fmt($xhi).' °C.');
                if ($max < $min) $fail('max_c', 'Le seuil haut ne peut pas être sous le seuil bas.');
            }

            return;
        }
        $bounds = config('hygiene.threshold_bounds.'.(config("hygiene.kinds.$kind.negative") ? 'freezer' : 'cold'));

        if ($max === null) $fail('max_c', 'Le seuil haut est obligatoire : un équipement sans seuil ne signalerait plus jamais un écart.');
        [$lo, $hi] = $bounds['max'];
        if ($max < $lo || $max > $hi) $fail('max_c', 'Seuil haut hors limites pour ce type d’équipement : entre '.$this->fmt($lo).' et '.$this->fmt($hi).' °C.');
        if ($min !== null) {
            [$mlo, $mhi] = $bounds['min'];
            if ($min < $mlo || $min > $mhi) $fail('min_c', 'Seuil bas hors limites : entre '.$this->fmt($mlo).' et '.$this->fmt($mhi).' °C.');
            if ($min > $max) $fail('min_c', 'Le seuil bas ne peut pas dépasser le seuil haut.');
        }
    }

    private function presentEquipment(HygieneEquipment $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'kind' => $e->kind,
            'kind_label' => config("hygiene.kinds.{$e->kind}.label"),
            'negative' => (bool) config("hygiene.kinds.{$e->kind}.negative"),
            'hot' => $this->isHot($e->kind),
            'location' => $e->location,
            'min_c' => $e->min_c,
            'max_c' => $e->max_c,
            'sort_order' => $e->sort_order,
            'is_active' => $e->is_active,
        ];
    }

    private function presentReading(HygieneTemperatureReading $r): array
    {
        $action = $r->corrective_action;

        return [
            'id' => $r->id,
            'equipment_id' => $r->equipment_id,
            'equipment_name' => $r->equipment_name,
            'equipment_kind' => $r->equipment_kind,
            'value_c' => $r->value_c,
            'min_c' => $r->min_c,
            'max_c' => $r->max_c,
            'status' => $r->status,
            'alert_reason' => $r->alert_reason,
            'period' => $r->period,
            'source' => $r->source,
            'offline' => $r->source === 'offline',
            'synced_at' => $r->source === 'offline' ? $r->created_at?->toIso8601String() : null,
            'recorded_at' => $r->recorded_at->toIso8601String(),
            'operator_name' => $r->operator_name,
            'corrective_action' => $action,
            'corrective_label' => $action ? config("hygiene.corrective_actions.$action.label") : null,
            'corrective_note' => $r->corrective_note,
            'voided' => $r->voided_at !== null,
            'voided_at' => $r->voided_at?->toIso8601String(),
            'void_reason' => $r->void_reason,
        ];
    }

    private function isHot(?string $kind): bool
    {
        return $kind !== null && (bool) config("hygiene.kinds.$kind.hot");
    }

    private function kinds(): array
    {
        return collect(Kinds::all('temperature'))->map(fn ($k, $key) => ['key' => $key] + $k)->values()->all();
    }

    private function correctiveActions(): array
    {
        return collect(config('hygiene.corrective_actions'))->map(fn ($a, $key) => ['key' => $key] + $a)->values()->all();
    }

    private function fmt(float $v): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.'));
    }

}
