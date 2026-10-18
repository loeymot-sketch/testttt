<?php

namespace App\Http\Controllers\Admin\Hygiene;

use App\Models\HygieneAttachment;
use App\Models\HygieneTaskCompletion;
use App\Models\HygieneTaskTemplate;
use App\Services\Hygiene\TaskSchedule;
use App\Support\Hygiene\RecommendedTasks;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Plan de nettoyage : les fiches du jour (« 3/8 faites »), la validation par une personne
 * identifiée, le journal. Une fiche = une checklist ; un point non fait est permis MAIS motivé (jamais de case décochée muette).
 *
 * Journal append-only : une validation n'est jamais modifiée ni effacée, elle s'ANNULE avec un motif. La checklist est COPIÉE sur
 * la validation : modifier une fiche ne réécrit jamais ce qui a été attesté. Domaine NEUF, ADDITIF, HORS NF525.
 */
class TaskController extends HygieneController
{
    // ───────────────────────────── Aujourd'hui ─────────────────────────────

    public function today(Request $request): JsonResponse
    {
        $now = now();
        $day = $now->copy()->startOfDay();
        $templates = HygieneTaskTemplate::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (HygieneTaskTemplate $t) => TaskSchedule::isDueOn($t, $day))->values();

        $done = HygieneTaskCompletion::query()->whereNull('voided_at')->whereDate('due_date', $day->toDateString())
            ->orderBy('completed_at')->orderBy('id')->get()->groupBy('template_id');

        $items = $templates->map(function (HygieneTaskTemplate $t) use ($done, $now, $day): array {
            $last = $done->get($t->id, collect())->last();
            $dueAt = TaskSchedule::dueAt($t, $day);
            $state = $last === null ? 'todo' : ($last->complete ? 'done' : 'partial');

            return $this->presentTemplate($t) + [
                'state' => $state,
                'overdue' => $state === 'todo' && $dueAt !== null && $now->gt($dueAt),
                'due_at' => $dueAt?->toIso8601String(),
                'completion' => $last ? $this->presentCompletion($last) : null,
            ];
        })->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'summary' => [
                    'total' => $items->count(),
                    'done' => $items->where('state', 'done')->count(),
                    'partial' => $items->where('state', 'partial')->count(),
                    'todo' => $items->where('state', 'todo')->count(),
                    'overdue' => $items->where('overdue', true)->count(),
                ],
                'recommended' => $this->recommendedState(),
            ] + $this->clock(),
        ]);
    }

    /**
     * Valide une fiche. Les libellés viennent de la FICHE (jamais du client) ; le client ne dit que « ce point est fait / pas fait ».
     * Un point non fait exige un commentaire. Une fiche déjà validée complète aujourd'hui n'est pas redoublée (un seul geste).
     */
    public function complete(Request $request): JsonResponse
    {
        $v = $request->validate([
            'template_id' => ['required', 'integer'],
            'done' => ['nullable', 'array', 'max:40'],
            'done.*' => ['string', 'max:20'],
            'all' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:200'],
            'attachment_id' => ['nullable', 'integer'],
            'operator_user_id' => ['nullable', 'integer'],
            'attested' => ['nullable', 'boolean'],
            'client_ref' => ['nullable', 'string', 'max:40'],
        ], [], ['note' => 'commentaire']);

        $branch = $this->branchId($request);
        $now = now();
        $day = $now->copy()->startOfDay();

        if (! empty($v['client_ref'])) {
            $dup = HygieneTaskCompletion::query()->where('client_ref', $v['client_ref'])->first();
            if ($dup && $dup->template_id !== (int) $v['template_id']) return $this->refConflict();
            if ($dup) return response()->json(['data' => $this->presentCompletion($dup)], 200);
        }

        $t = HygieneTaskTemplate::query()->where('is_active', true)->find((int) $v['template_id']);
        if (! $t) return response()->json(['message' => 'Fiche introuvable ou désactivée.'], 404);
        if (! TaskSchedule::isDueOn($t, $day)) {
            return response()->json(['message' => 'Cette fiche n’est pas à faire aujourd’hui.', 'errors' => ['template_id' => ['Pas due aujourd’hui.']]], 422);
        }

        $checklist = collect($t->checklist ?? []);
        $all = ! empty($v['all']);
        $doneKeys = $all ? $checklist->pluck('key')->all() : array_values(array_unique($v['done'] ?? []));
        $unknown = array_diff($doneKeys, $checklist->pluck('key')->all());
        if ($unknown !== []) {
            return response()->json(['message' => 'Point inconnu sur cette fiche.', 'errors' => ['done' => ['Un point coché n’existe pas sur la fiche.']]], 422);
        }
        $items = $checklist->map(fn ($i) => ['key' => $i['key'], 'label' => $i['label'], 'done' => in_array($i['key'], $doneKeys, true)])->values()->all();
        $total = count($items);
        $doneCount = count(array_filter($items, fn ($i) => $i['done']));
        $complete = $doneCount === $total;
        $note = isset($v['note']) && trim((string) $v['note']) !== '' ? trim((string) $v['note']) : null;
        if (! $complete && ($note === null || mb_strlen($note) < 3)) {
            return response()->json([
                'message' => 'Il reste '.($total - $doneCount).' point'.(($total - $doneCount) > 1 ? 's' : '').' non fait'.(($total - $doneCount) > 1 ? 's' : '').' : dites pourquoi.',
                'errors' => ['note' => ['Commentaire obligatoire quand un point n’est pas fait.']],
            ], 422);
        }
        $attachmentId = null;
        if (! empty($v['attachment_id'])) {
            $attachmentId = HygieneAttachment::query()->whereKey((int) $v['attachment_id'])->value('id');
            if (! $attachmentId) return response()->json(['message' => 'Photo introuvable.', 'errors' => ['attachment_id' => ['Photo introuvable.']]], 422);
        }

        $op = $this->operator($request);
        $dueAt = TaskSchedule::dueAt($t, $day);
        $alreadyMsg = '« '.$t->name.' » est déjà validée aujourd’hui. Annulez la validation pour la refaire.';
        $row = new HygieneTaskCompletion([
            'template_id' => $t->id, 'task_name' => $t->name, 'zone' => $t->zone, 'due_date' => $day->toDateString(),
            'items' => $items, 'items_total' => $total, 'items_done' => $doneCount, 'complete' => $complete, 'note' => $complete ? null : $note,
            'late' => $dueAt !== null && $now->gt($dueAt), 'completed_at' => $now, 'timezone' => config('app.timezone'),
            'user_id' => $op['user_id'], 'operator_name' => $op['name'], 'attachment_id' => $attachmentId, 'client_ref' => $v['client_ref'] ?? null,
        ]);
        $row->branch_id = $branch;
        // l'engagement (« je confirme l'exactitude… ») est daté quand la personne l'a coché ; absent = validation sans engagement (anciens écrans)
        $row->attested_at = ! empty($v['attested']) ? $now : null;
        try {
            // Verrou sur la fiche : deux validations « complètes » simultanées (double appui, deux tablettes) ne passent pas toutes les deux.
            $refused = DB::transaction(function () use ($t, $day, $row): bool {
                HygieneTaskTemplate::query()->whereKey($t->id)->lockForUpdate()->first();
                $already = HygieneTaskCompletion::query()->whereNull('voided_at')->where('template_id', $t->id)->whereDate('due_date', $day->toDateString())->where('complete', true)->exists();
                if ($already) return true;
                $row->save();

                return false;
            });
            if ($refused) {
                return response()->json(['message' => $alreadyMsg, 'errors' => ['template_id' => ['Déjà validée.']]], 422);
            }
        } catch (QueryException $e) {
            if (empty($v['client_ref']) || ! $this->isUniqueViolation($e)) throw $e;
            $row = HygieneTaskCompletion::query()->where('client_ref', $v['client_ref'])->firstOrFail();
        }

        return response()->json(['data' => $this->presentCompletion($row)], 201);
    }

    public function void(Request $request, string $completion): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:120']], [], ['reason' => 'motif']);
        $row = HygieneTaskCompletion::query()->findOrFail((int) $completion);
        if ($row->voided_at === null) {
            $row->forceFill(['voided_at' => now(), 'voided_by' => $this->operator($request)['user_id'], 'void_reason' => trim($v['reason'])])->save();
        }

        return response()->json(['data' => $this->presentCompletion($row->refresh())]);
    }

    public function history(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'template_id' => ['nullable', 'integer'], 'incomplete_only' => ['nullable', 'boolean'], 'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        [$from, $to] = $this->period($v);
        $rows = HygieneTaskCompletion::query()
            ->whereBetween('completed_at', [$from, $to])
            ->when(! empty($v['template_id']), fn ($q) => $q->where('template_id', (int) $v['template_id']))
            ->when(! empty($v['incomplete_only']), fn ($q) => $q->where('complete', false))
            ->orderByDesc('completed_at')->orderByDesc('id')->limit((int) ($v['limit'] ?? 200))->get()
            ->map(fn ($r) => $this->presentCompletion($r))->values();

        return response()->json(['data' => $rows, 'meta' => $this->clock()]);
    }

    // ───────────────────────────── Fiches (plan de nettoyage) ─────────────────────────────

    public function templates(): JsonResponse
    {
        $rows = HygieneTaskTemplate::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($t) => $this->presentTemplate($t))->values();

        return response()->json(['data' => $rows, 'meta' => ['recommended' => $this->recommendedState()] + $this->clock()]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $this->validateTemplate($request);
        $t = new HygieneTaskTemplate($data + ['sort_order' => (int) HygieneTaskTemplate::query()->max('sort_order') + 10, 'is_active' => $data['is_active'] ?? true]);
        $t->branch_id = $this->branchId($request);
        $t->save();

        return response()->json(['data' => $this->presentTemplate($t->refresh())], 201);
    }

    public function updateTemplate(Request $request, string $template): JsonResponse
    {
        $t = HygieneTaskTemplate::query()->findOrFail((int) $template);
        $t->fill($this->validateTemplate($request, $t))->save();

        return response()->json(['data' => $this->presentTemplate($t->refresh())]);
    }

    public function destroyTemplate(string $template): JsonResponse
    {
        // Suppression douce : le journal garde le nom et la checklist copiés à chaque validation.
        HygieneTaskTemplate::query()->findOrFail((int) $template)->delete();

        return response()->json(['status' => 'ok']);
    }

    /** Installe le plan de départ. `only_if_empty` : appel automatique du premier lancement — rien si le restaurant a déjà (ou avait) des fiches. */
    public function installRecommended(Request $request): JsonResponse
    {
        $v = $request->validate(['only_if_empty' => ['nullable', 'boolean']]);
        $state = $this->recommendedState();
        if (! empty($v['only_if_empty']) && ! $state['never_configured']) {
            return response()->json(['data' => ['added' => 0] + $state]);
        }
        $live = HygieneTaskTemplate::query()->get(['catalog_key', 'name']);
        $keys = $live->pluck('catalog_key')->filter()->all();
        $names = $live->map(fn ($t) => mb_strtolower(trim((string) $t->name)))->all();
        $branch = $this->branchId($request);
        $order = (int) HygieneTaskTemplate::query()->max('sort_order');
        $added = 0;
        foreach (RecommendedTasks::all() as $row) {
            if (in_array($row['catalog_key'], $keys, true) || in_array(mb_strtolower($row['name']), $names, true)) continue;
            $t = new HygieneTaskTemplate([
                'catalog_key' => $row['catalog_key'], 'name' => $row['name'], 'zone' => $row['zone'], 'how_to' => $row['how_to'],
                'checklist' => $this->keyed($row['checklist']), 'frequency' => $row['frequency'], 'weekday' => $row['weekday'],
                'month_day' => $row['month_day'], 'sort_order' => $order + $row['sort_order'], 'is_active' => true,
            ]);
            $t->branch_id = $branch;
            $t->save();
            $added++;
        }

        return response()->json(['data' => ['added' => $added] + $this->recommendedState()], $added > 0 ? 201 : 200);
    }

    // ───────────────────────────── utilitaires ─────────────────────────────

    private function refConflict(): JsonResponse
    {
        return response()->json(['message' => 'Cette référence d’envoi a déjà enregistré une autre fiche : rechargez l’écran et recommencez.', 'errors' => ['client_ref' => ['Référence d’envoi déjà utilisée.']]], 409);
    }

    private function recommendedState(): array
    {
        $existing = HygieneTaskTemplate::query()->withTrashed()->get(['catalog_key', 'name', 'deleted_at']);
        $liveKeys = $existing->whereNull('deleted_at')->pluck('catalog_key')->filter()->all();
        $liveNames = $existing->whereNull('deleted_at')->map(fn ($t) => mb_strtolower(trim((string) $t->name)))->all();
        $all = RecommendedTasks::all();
        $missing = collect($all)->filter(fn ($r) => ! in_array($r['catalog_key'], $liveKeys, true) && ! in_array(mb_strtolower($r['name']), $liveNames, true))->count();

        return ['total' => count($all), 'missing' => $missing, 'never_configured' => $existing->isEmpty()];
    }

    /** Les libellés → [{key, label}] avec des clés stables (k1, k2…) ; une clé fournie est conservée, sinon la première libre. */
    private function keyed(array $items): array
    {
        $out = [];
        $used = [];
        foreach ($items as $it) {
            $key = is_array($it) ? (string) ($it['key'] ?? '') : '';
            if ($key !== '' && ! in_array($key, $used, true) && preg_match('/^[a-z0-9_]{1,20}$/', $key)) { $used[] = $key; }
            else { $key = ''; }
            $out[] = ['key' => $key, 'label' => trim((string) (is_array($it) ? ($it['label'] ?? '') : $it))];
        }
        $n = 1;
        foreach ($out as &$o) {
            if ($o['key'] === '') {
                while (in_array('k'.$n, $used, true)) $n++;
                $o['key'] = 'k'.$n;
                $used[] = $o['key'];
            }
        }

        return $out;
    }

    private function validateTemplate(Request $request, ?HygieneTaskTemplate $current = null): array
    {
        $req = $current ? 'sometimes' : 'required';
        $v = $request->validate([
            'name' => [$req, 'filled', 'string', 'max:80'],
            'zone' => ['nullable', 'string', 'max:40'],
            'how_to' => ['nullable', 'string', 'max:600'],
            'checklist' => [$req, 'array', 'min:1', 'max:20'],
            'checklist.*' => ['required'],
            'frequency' => [$req, Rule::in(['daily', 'weekly', 'monthly'])],
            'weekday' => ['nullable', 'integer', 'between:1,7'],
            'month_day' => ['nullable', 'integer', 'between:1,28'],
            'due_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['name' => 'nom', 'checklist' => 'points à vérifier', 'due_time' => 'horaire']);

        if (isset($v['checklist'])) {
            foreach ($v['checklist'] as $raw) {
                $okShape = is_string($raw) || (is_array($raw) && is_string($raw['label'] ?? null) && (! isset($raw['key']) || is_string($raw['key'])));
                if (! $okShape) {
                    abort(response()->json(['message' => 'Point à vérifier invalide.', 'errors' => ['checklist' => ['Chaque point doit être un texte (libellé de 1 à 80 caractères).']]], 422));
                }
            }
            $items = $this->keyed($v['checklist']);
            foreach ($items as $it) {
                if ($it['label'] === '' || mb_strlen($it['label']) > 80) {
                    abort(response()->json(['message' => 'Point à vérifier invalide.', 'errors' => ['checklist' => ['Chaque point doit avoir un libellé de 1 à 80 caractères.']]], 422));
                }
            }
            $v['checklist'] = $items;
        }
        if (isset($v['name'])) {
            $v['name'] = trim($v['name']);
            $dup = HygieneTaskTemplate::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($v['name'])])
                ->when($current, fn ($q) => $q->where('id', '!=', $current->id))->exists();
            if ($dup) abort(response()->json(['message' => 'Cette fiche existe déjà.', 'errors' => ['name' => ['Une fiche porte déjà ce nom.']]], 422));
        }
        foreach (['zone', 'how_to'] as $f) {
            if (array_key_exists($f, $v)) $v[$f] = isset($v[$f]) && trim((string) $v[$f]) !== '' ? trim((string) $v[$f]) : null;
        }
        $freq = $v['frequency'] ?? $current?->frequency ?? 'daily';
        if ($freq === 'weekly' && empty($v['weekday'] ?? $current?->weekday)) $v['weekday'] = 1;
        if ($freq === 'monthly' && empty($v['month_day'] ?? $current?->month_day)) $v['month_day'] = 1;
        if (array_key_exists('frequency', $v)) {
            if ($freq !== 'weekly') $v['weekday'] = null;
            if ($freq !== 'monthly') $v['month_day'] = null;
        }
        foreach (['sort_order', 'is_active'] as $f) {
            if (array_key_exists($f, $v) && $v[$f] === null) unset($v[$f]);
        }

        return $v;
    }

    private function presentTemplate(HygieneTaskTemplate $t): array
    {
        return [
            'id' => $t->id, 'catalog_key' => $t->catalog_key, 'name' => $t->name, 'zone' => $t->zone, 'how_to' => $t->how_to,
            'checklist' => collect($t->checklist ?? [])->map(fn ($i) => ['key' => $i['key'], 'label' => $i['label']])->values()->all(),
            'frequency' => $t->frequency, 'weekday' => $t->weekday, 'month_day' => $t->month_day, 'due_time' => $t->due_time,
            'sort_order' => $t->sort_order, 'is_active' => $t->is_active,
        ];
    }

    private function presentCompletion(HygieneTaskCompletion $r): array
    {
        return [
            'id' => $r->id, 'template_id' => $r->template_id, 'task_name' => $r->task_name, 'zone' => $r->zone,
            'due_date' => $r->due_date?->toDateString(), 'items' => $r->items, 'items_total' => $r->items_total, 'items_done' => $r->items_done,
            'complete' => $r->complete, 'note' => $r->note, 'late' => $r->late, 'completed_at' => $r->completed_at->toIso8601String(),
            'operator_name' => $r->operator_name, 'attachment_id' => $r->attachment_id, 'attested' => $r->attested_at !== null,
            'voided' => $r->voided_at !== null, 'voided_at' => $r->voided_at?->toIso8601String(), 'void_reason' => $r->void_reason,
        ];
    }
}
