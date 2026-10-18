<?php

namespace App\Http\Controllers\Admin\Hygiene;

use App\Models\HygieneAttachment;
use App\Models\HygieneReception;
use App\Models\Supplier;
use App\Services\Hygiene\ReceptionRules;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Contrôle à la réception des marchandises (équivalent du « Contrôles à réception » de Komia,
 * avec ce qui lui manque : limites par famille de température, contrôles visuels, décision et commentaire en cas d'écart).
 *
 * Verdict calculé par le SERVEUR. Un écart interdit « accepté » : réserve ou refus, avec un commentaire. Journal append-only
 * (annulation motivée seulement), contexte copié, envoi rejouable (`client_ref`). Fournisseurs : la table `suppliers` existante.
 */
class ReceptionController extends HygieneController
{
    public function today(Request $request): JsonResponse
    {
        $start = now()->startOfDay();
        $rows = HygieneReception::query()->where('received_at', '>=', $start)->orderByDesc('received_at')->orderByDesc('id')->get();
        $live = $rows->whereNull('voided_at');

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->present($r))->values(),
            'meta' => [
                'summary' => [
                    'count' => $live->count(),
                    'alerts' => $live->where('status', HygieneReception::STATUS_ALERT)->count(),
                    'refused' => $live->where('decision', 'refused')->count(),
                ],
            ] + $this->config($request) + $this->clock(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'supplier_name' => ['nullable', 'string', 'max:80'],
            'delivery_note_no' => ['nullable', 'string', 'max:40'],
            'delivered_by' => ['nullable', 'string', 'max:60'],
            'temperatures' => ['required', 'array'],
            'temperatures.*' => ['nullable', 'numeric'],
            'checks' => ['required', 'array'],
            'checks.*' => ['boolean'],
            'decision' => ['nullable', 'in:accepted,reserve,refused'],
            'note' => ['nullable', 'string', 'max:300'],
            'attachment_id' => ['nullable', 'integer'],
            'attachment_ids' => ['nullable', 'array', 'max:4'],
            'attachment_ids.*' => ['integer'],
            'operator_user_id' => ['nullable', 'integer'],
            'client_ref' => ['nullable', 'string', 'max:40'],
        ], [], ['temperatures' => 'températures', 'checks' => 'contrôles', 'note' => 'commentaire']);

        if (! empty($v['client_ref'])) {
            $dup = HygieneReception::query()->where('client_ref', $v['client_ref'])->first();
            if ($dup && ($dup->delivery_note_no ?? '') !== (trim((string) ($v['delivery_note_no'] ?? '')))) return response()->json(['message' => 'Cette référence d’envoi a déjà enregistré un autre élément : rechargez l’écran et recommencez.', 'errors' => ['client_ref' => ['Référence d’envoi déjà utilisée.']]], 409);
            if ($dup) return response()->json(['data' => $this->present($dup)], 200);
        }

        $branch = $this->branchId($request);
        $cats = config('hygiene.reception.categories');

        // ── fournisseur ──
        $name = isset($v['supplier_name']) ? trim($v['supplier_name']) : '';
        if (empty($v['supplier_id']) && $name === '') {
            return $this->fail('Choisissez ou saisissez le fournisseur.', 'supplier_id');
        }

        // ── températures ──
        foreach (array_keys($v['temperatures']) as $k) {
            if (! array_key_exists($k, $cats)) return $this->fail('Famille de température inconnue : '.$k.'.', 'temperatures');
        }
        $temps = [];
        foreach ($cats as $key => $cat) {
            $raw = $v['temperatures'][$key] ?? null;
            if ($raw === null || $raw === '') continue;
            $value = round((float) $raw, 1);
            [$lo, $hi] = $cat['plausible'];
            if ($value < $lo || $value > $hi) {
                return $this->fail('Température « '.mb_strtolower($cat['label']).' » : '.str_replace('.', ',', (string) $value).' °C n’est pas plausible (faute de frappe ?).', "temperatures.$key");
            }
            $temps[] = ['category' => $key, 'label' => $cat['label'], 'value_c' => $value, 'max_c' => $cat['max'], 'status' => ReceptionRules::temperatureStatus($key, $value)];
        }
        if ($temps === []) {
            return $this->fail('Relevez au moins une température (frais, surgelé ou ambiant).', 'temperatures');
        }

        // ── contrôles visuels : tous répondus ──
        $checks = [];
        foreach (config('hygiene.reception.checks') as $key => $label) {
            if (! array_key_exists($key, $v['checks'])) {
                return $this->fail('Répondez à tous les contrôles : « '.$label.' ».', "checks.$key");
            }
            $checks[] = ['key' => $key, 'label' => $label, 'ok' => (bool) $v['checks'][$key]];
        }
        foreach (array_keys($v['checks']) as $k) {
            if (! array_key_exists($k, config('hygiene.reception.checks'))) return $this->fail('Contrôle inconnu : '.$k.'.', 'checks');
        }

        // ── verdict, décision, commentaire ──
        $status = ReceptionRules::overall($temps, $checks);
        $note = isset($v['note']) && trim((string) $v['note']) !== '' ? trim($v['note']) : null;
        $decision = 'accepted';
        if ($status === HygieneReception::STATUS_ALERT) {
            $decision = $v['decision'] ?? null;
            if (! in_array($decision, ['reserve', 'refused'], true)) {
                return $this->fail('Livraison non conforme : choisissez « Accepté avec réserve » ou « Refusé ».', 'decision');
            }
            if ($note === null || mb_strlen($note) < 3) {
                return $this->fail('Livraison non conforme : dites ce qui ne va pas et ce qui a été décidé.', 'note');
            }
        }

        // ── pièces jointes (de CE restaurant) ──
        $ids = collect([$v['attachment_id'] ?? null])->merge($v['attachment_ids'] ?? [])->filter()->unique()->values();
        if ($ids->isNotEmpty() && HygieneAttachment::query()->whereIn('id', $ids)->count() !== $ids->count()) {
            return $this->fail('Photo introuvable.', 'attachment_id');
        }

        $op = $this->operator($request);
        $now = now();
        $row = null;
        try {
            DB::transaction(function () use (&$row, $v, $branch, $name, $temps, $checks, $status, $decision, $note, $op, $now): void {
                $supplier = $this->resolveSupplier($branch, $v['supplier_id'] ?? null, $name);
                $row = new HygieneReception([
                    'supplier_id' => $supplier->id, 'supplier_name' => mb_substr($supplier->name, 0, 80),
                    'delivery_note_no' => $this->nullable($v['delivery_note_no'] ?? null), 'delivered_by' => $this->nullable($v['delivered_by'] ?? null),
                    'temperatures' => $temps, 'checks' => $checks, 'status' => $status, 'decision' => $decision, 'note' => $note,
                    'attachment_id' => $v['attachment_id'] ?? null, 'attachment_ids' => array_values($v['attachment_ids'] ?? []),
                    'received_at' => $now, 'timezone' => config('app.timezone'), 'user_id' => $op['user_id'], 'operator_name' => $op['name'],
                    'client_ref' => $v['client_ref'] ?? null,
                ]);
                $row->branch_id = $branch;
                $row->save();
            });
        } catch (QueryException $e) {
            if (empty($v['client_ref']) || ! $this->isUniqueViolation($e)) throw $e;
            $row = HygieneReception::query()->where('client_ref', $v['client_ref'])->firstOrFail();
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 'supplier_id');
        }

        return response()->json(['data' => $this->present($row)], 201);
    }

    public function void(Request $request, string $reception): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:120']], [], ['reason' => 'motif']);
        $row = HygieneReception::query()->findOrFail((int) $reception);
        if ($row->voided_at === null) {
            $row->forceFill(['voided_at' => now(), 'voided_by' => $this->operator($request)['user_id'], 'void_reason' => trim($v['reason'])])->save();
        }

        return response()->json(['data' => $this->present($row->refresh())]);
    }

    public function history(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'supplier_id' => ['nullable', 'integer'], 'alerts_only' => ['nullable', 'boolean'], 'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        [$from, $to] = $this->period($v);
        $rows = HygieneReception::query()->whereBetween('received_at', [$from, $to])
            ->when(! empty($v['supplier_id']), fn ($q) => $q->where('supplier_id', (int) $v['supplier_id']))
            ->when(! empty($v['alerts_only']), fn ($q) => $q->where('status', HygieneReception::STATUS_ALERT))
            ->orderByDesc('received_at')->orderByDesc('id')->limit((int) ($v['limit'] ?? 200))->get()
            ->map(fn ($r) => $this->present($r))->values();

        return response()->json(['data' => $rows, 'meta' => $this->clock()]);
    }

    // ───────────────────────────── Fournisseurs ─────────────────────────────

    public function suppliers(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->supplierList($request), 'meta' => $this->clock()]);
    }

    /** Création à la volée : un nom déjà connu (sans tenir compte de la casse) renvoie le fournisseur existant, jamais un doublon. */
    public function storeSupplier(Request $request): JsonResponse
    {
        $v = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80']], [], ['name' => 'nom']);
        $branch = $this->branchId($request);
        $existing = $this->supplierQuery($branch)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($v['name']))])->first();
        $supplier = $existing ?: $this->resolveSupplier($branch, null, trim($v['name']));

        return response()->json(['data' => ['id' => $supplier->id, 'name' => $supplier->name]], $existing ? 200 : 201);
    }

    // ───────────────────────────── utilitaires ─────────────────────────────

    private function supplierQuery(int $branch)
    {
        return Supplier::query()->where('branch_id', $branch);
    }

    private function supplierList(Request $request): array
    {
        return $this->supplierQuery($this->branchId($request))->orderBy('name')->limit(300)->get(['id', 'name'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all();
    }

    /** Le fournisseur désigné (de CE restaurant) ou créé à la volée. */
    private function resolveSupplier(int $branch, $id, string $name): Supplier
    {
        if (! empty($id)) {
            $s = $this->supplierQuery($branch)->find((int) $id);
            if (! $s) throw new \InvalidArgumentException('Fournisseur introuvable.');

            return $s;
        }
        // corbeille comprise : (branch_id, name) est unique même pour un fournisseur supprimé — on le RESTAURE au lieu d'échouer
        $existing = $this->supplierQuery($branch)->withTrashed()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($existing) {
            if ($existing->trashed()) $existing->restore();

            return $existing;
        }

        try {
            return Supplier::query()->create(['branch_id' => $branch, 'name' => mb_substr($name, 0, 80)]);
        } catch (QueryException $e) {
            // deux livraisons saisies en même temps pour un nouveau fournisseur : (branch_id, name) est unique, on reprend le gagnant
            if (! $this->isUniqueViolation($e)) throw $e;

            return $this->supplierQuery($branch)->withTrashed()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->firstOrFail();
        }
    }

    private function config(Request $request): array
    {
        return [
            'categories' => collect(config('hygiene.reception.categories'))->map(fn ($c, $k) => ['key' => $k, 'label' => $c['label'], 'max' => $c['max'], 'negative' => $c['negative']])->values()->all(),
            'checks' => collect(config('hygiene.reception.checks'))->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'decisions' => collect(config('hygiene.reception.decisions'))->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'suppliers' => $this->supplierList($request),
        ];
    }

    private function fail(string $message, string $field): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    private function nullable($v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    private function present(HygieneReception $r): array
    {
        return [
            'id' => $r->id, 'supplier_id' => $r->supplier_id, 'supplier_name' => $r->supplier_name, 'delivery_note_no' => $r->delivery_note_no,
            'delivered_by' => $r->delivered_by, 'temperatures' => $r->temperatures, 'checks' => $r->checks, 'status' => $r->status,
            'decision' => $r->decision, 'decision_label' => config("hygiene.reception.decisions.{$r->decision}"), 'note' => $r->note,
            'attachment_id' => $r->attachment_id, 'attachment_ids' => $r->attachment_ids ?? [],
            'received_at' => $r->received_at->toIso8601String(), 'operator_name' => $r->operator_name,
            'voided' => $r->voided_at !== null, 'voided_at' => $r->voided_at?->toIso8601String(), 'void_reason' => $r->void_reason,
        ];
    }
}
