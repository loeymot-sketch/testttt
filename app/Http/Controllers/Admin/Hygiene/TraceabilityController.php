<?php

namespace App\Http\Controllers\Admin\Hygiene;

use App\Models\DlcLabelProduct;
use App\Models\HygieneAttachment;
use App\Models\HygieneReception;
use App\Models\HygieneTraceEntry;
use App\Models\LabelPrintLog;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Traçabilité amont : produit · fournisseur · LOT · DLC du fournisseur · bon de livraison · photo
 * de l'étiquette. Et surtout la fiche de RAPPEL : « ce lot — reçu quand, de qui, et quelles préparations (étiquettes DLC) en sont issues ».
 *
 * Un produit sans numéro de lot est tracé seulement avec une explication. Une DLC déjà dépassée à la saisie est un écart (commentaire
 * obligatoire). Journal append-only (annulation motivée), contexte copié, envoi rejouable. Domaine NEUF, ADDITIF, HORS NF525.
 */
class TraceabilityController extends HygieneController
{
    public function today(Request $request): JsonResponse
    {
        $start = now()->startOfDay();
        $rows = HygieneTraceEntry::query()->where('traced_at', '>=', $start)->orderByDesc('traced_at')->orderByDesc('id')->get();
        $live = $rows->whereNull('voided_at');
        $branch = $this->branchId($request);

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->present($r))->values(),
            'meta' => [
                'summary' => ['count' => $live->count(), 'alerts' => $live->where('status', 'alert')->count()],
                'products' => DlcLabelProduct::query()->where('is_active', true)->orderBy('name')->limit(400)->get(['id', 'name', 'category'])
                    ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'category' => $p->category])->values(),
                'suppliers' => Supplier::query()->where('branch_id', $branch)->orderBy('name')->limit(300)->get(['id', 'name'])
                    ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
                'receptions' => HygieneReception::query()->whereNull('voided_at')->where('received_at', '>=', $start->copy()->subDays(2))
                    ->orderByDesc('received_at')->limit(30)->get(['id', 'supplier_id', 'supplier_name', 'delivery_note_no', 'received_at'])
                    ->map(fn ($r) => ['id' => $r->id, 'supplier_id' => $r->supplier_id, 'supplier_name' => $r->supplier_name, 'delivery_note_no' => $r->delivery_note_no, 'received_at' => $r->received_at->toIso8601String()])->values(),
            ] + $this->clock(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'dlc_product_id' => ['nullable', 'integer'],
            'product_name' => ['nullable', 'string', 'max:80'],
            'supplier_id' => ['nullable', 'integer'],
            'supplier_name' => ['nullable', 'string', 'max:80'],
            'reception_id' => ['nullable', 'integer'],
            'lot' => ['nullable', 'string', 'max:60'],
            'dlc_date' => ['nullable', 'date_format:Y-m-d'],
            'dlc_kind' => ['nullable', 'in:dlc,dluo'],
            'note' => ['nullable', 'string', 'max:200'],
            'attachment_id' => ['nullable', 'integer'],
            'operator_user_id' => ['nullable', 'integer'],
            'client_ref' => ['nullable', 'string', 'max:40'],
        ], [], ['product_name' => 'produit', 'dlc_date' => 'DLC', 'note' => 'commentaire']);

        if (! empty($v['client_ref'])) {
            $dup = HygieneTraceEntry::query()->where('client_ref', $v['client_ref'])->first();
            if ($dup) return response()->json(['data' => $this->present($dup)], 200);
        }
        $branch = $this->branchId($request);

        // ── produit : du catalogue DLC ou saisi librement ──
        $productId = null;
        $name = isset($v['product_name']) ? trim($v['product_name']) : '';
        if (! empty($v['dlc_product_id'])) {
            $p = DlcLabelProduct::query()->find((int) $v['dlc_product_id']);
            if (! $p) return $this->fail('Produit introuvable.', 'dlc_product_id');
            $productId = $p->id;
            $name = $p->name;
        }
        if (mb_strlen($name) < 2) return $this->fail('Dites quel produit est tracé.', 'product_name');

        // ── réception liée → fournisseur et BL repris ──
        $reception = null;
        if (! empty($v['reception_id'])) {
            $reception = HygieneReception::query()->whereNull('voided_at')->find((int) $v['reception_id']);
            if (! $reception) return $this->fail('Réception introuvable ou annulée.', 'reception_id');
        }

        // ── fournisseur ──
        $supplierId = null;
        $supplierName = isset($v['supplier_name']) ? trim($v['supplier_name']) : '';
        if (! empty($v['supplier_id'])) {
            $s = Supplier::query()->where('branch_id', $branch)->find((int) $v['supplier_id']);
            if (! $s) return $this->fail('Fournisseur introuvable.', 'supplier_id');
            $supplierId = $s->id;
            $supplierName = $s->name;
        } elseif ($reception && $supplierName === '') {
            $supplierId = $reception->supplier_id;
            $supplierName = $reception->supplier_name;
        }

        // ── lot, DLC, commentaire ──
        $lot = isset($v['lot']) && trim($v['lot']) !== '' ? trim($v['lot']) : null;
        $note = isset($v['note']) && trim((string) $v['note']) !== '' ? trim($v['note']) : null;
        if ($lot === null && ($note === null || mb_strlen($note) < 3)) {
            return $this->fail('Indiquez le numéro de lot — ou expliquez pourquoi il n’y en a pas.', 'lot');
        }
        $status = 'ok';
        if (! empty($v['dlc_date'])) {
            $tz = config('app.timezone');
            $date = \Carbon\Carbon::createFromFormat('Y-m-d', $v['dlc_date'], $tz)->startOfDay();
            if ($date->gt(now()->addYears(6))) return $this->fail('Cette DLC est trop lointaine : vérifiez l’année.', 'dlc_date');
            if ($date->lt(now()->startOfDay())) {
                if ($note === null || mb_strlen($note) < 3) {
                    return $this->fail('DLC déjà dépassée : dites ce qui a été fait du produit.', 'note');
                }
                $status = 'alert';
            }
        }

        if (! empty($v['attachment_id']) && ! HygieneAttachment::query()->whereKey((int) $v['attachment_id'])->exists()) {
            return $this->fail('Photo introuvable.', 'attachment_id');
        }

        $op = $this->operator($request);
        $row = new HygieneTraceEntry([
            'product_name' => mb_substr($name, 0, 80), 'dlc_product_id' => $productId, 'supplier_id' => $supplierId,
            'supplier_name' => $supplierName !== '' ? mb_substr($supplierName, 0, 80) : null, 'reception_id' => $reception?->id,
            'delivery_note_no' => $reception?->delivery_note_no, 'lot' => $lot, 'dlc_date' => $v['dlc_date'] ?? null,
            'dlc_kind' => $v['dlc_kind'] ?? 'dlc', 'status' => $status, 'note' => $note, 'attachment_id' => $v['attachment_id'] ?? null,
            'traced_at' => now(), 'timezone' => config('app.timezone'), 'user_id' => $op['user_id'], 'operator_name' => $op['name'],
            'client_ref' => $v['client_ref'] ?? null,
        ]);
        $row->branch_id = $branch;
        try {
            $row->save();
        } catch (QueryException $e) {
            if (empty($v['client_ref']) || ! $this->isUniqueViolation($e)) throw $e;
            $row = HygieneTraceEntry::query()->where('client_ref', $v['client_ref'])->firstOrFail();
        }

        return response()->json(['data' => $this->present($row)], 201);
    }

    public function void(Request $request, string $entry): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:120']], [], ['reason' => 'motif']);
        $row = HygieneTraceEntry::query()->findOrFail((int) $entry);
        if ($row->voided_at === null) {
            $row->forceFill(['voided_at' => now(), 'voided_by' => $request->user()?->id, 'void_reason' => trim($v['reason'])])->save();
        }

        return response()->json(['data' => $this->present($row->refresh())]);
    }

    /** Journal, avec recherche libre (produit, fournisseur, lot — partielle). */
    public function history(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:60'], 'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        [$from, $to] = $this->period($v);
        $q = isset($v['q']) ? trim($v['q']) : '';
        $rows = HygieneTraceEntry::query()->whereBetween('traced_at', [$from, $to])
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
                $query->where(fn ($w) => $w->where('lot', 'like', $like)->orWhere('product_name', 'like', $like)->orWhere('supplier_name', 'like', $like)->orWhere('delivery_note_no', 'like', $like));
            })
            ->orderByDesc('traced_at')->orderByDesc('id')->limit((int) ($v['limit'] ?? 200))->get()
            ->map(fn ($r) => $this->present($r))->values();

        return response()->json(['data' => $rows, 'meta' => $this->clock()]);
    }

    /**
     * FICHE DE RAPPEL d'un lot fournisseur : où il est entré (traçabilité, réception) et toutes les préparations qui en sont issues
     * (étiquettes DLC portant ce lot fournisseur, puis leurs descendantes : préparé à partir de…). Sur 3 ans de recul, sans fenêtre de 30 jours.
     */
    public function recall(Request $request): JsonResponse
    {
        $v = $request->validate(['lot' => ['required', 'string', 'min:2', 'max:60']], [], ['lot' => 'numéro de lot']);
        $lot = mb_strtolower(trim($v['lot']));

        $entries = HygieneTraceEntry::query()->whereNull('voided_at')->whereRaw('LOWER(TRIM(lot)) = ?', [$lot])->orderBy('traced_at')->get();
        $receptionIds = $entries->pluck('reception_id')->filter()->unique()->all();
        $receptions = $receptionIds === [] ? collect() : HygieneReception::query()->whereIn('id', $receptionIds)->orderBy('received_at')->get();

        $roots = LabelPrintLog::query()->where('kind', LabelPrintLog::KIND_DLC)->whereRaw('LOWER(TRIM(supplier_lot)) = ?', [$lot])
            ->where(fn ($q) => $q->whereNull('discard_reason')->orWhere('discard_reason', '!=', LabelPrintLog::DISCARD_CANCELLED))
            ->orderBy('opened_at')->limit(300)->get();
        $labels = $roots->map(fn ($l) => [$l, 0])->all();
        $seen = $roots->pluck('id')->flip()->all();
        $frontier = $roots->pluck('id')->all();
        for ($depth = 1; $depth <= 10 && $frontier !== [] && count($labels) < 500; $depth++) {
            $children = LabelPrintLog::query()->whereIn('parent_log_id', $frontier)->orderBy('opened_at')->limit(300)->get();
            $frontier = [];
            foreach ($children as $c) {
                if (isset($seen[$c->id])) continue;
                $seen[$c->id] = true;
                $labels[] = [$c, $depth];
                $frontier[] = $c->id;
            }
        }

        return response()->json([
            'data' => [
                'lot' => trim($v['lot']),
                'entries' => $entries->map(fn ($r) => $this->present($r))->values(),
                'receptions' => $receptions->map(fn ($r) => [
                    'id' => $r->id, 'supplier_name' => $r->supplier_name, 'delivery_note_no' => $r->delivery_note_no,
                    'received_at' => $r->received_at->toIso8601String(), 'status' => $r->status, 'decision_label' => config("hygiene.reception.decisions.{$r->decision}"),
                ])->values(),
                'labels' => collect($labels)->map(fn ($pair) => [
                    'id' => $pair[0]->id, 'depth' => $pair[1], 'product_name' => $pair[0]->product_name, 'lot' => $pair[0]->lot,
                    'supplier_lot' => $pair[0]->supplier_lot, 'copies' => $pair[0]->copies,
                    'opened_at' => $pair[0]->opened_at?->toIso8601String(), 'expires_at' => $pair[0]->expires_at?->toIso8601String(),
                    'discarded_at' => $pair[0]->discarded_at?->toIso8601String(),
                    'discard_reason' => $pair[0]->discarded_at ? ($pair[0]->discard_reason ?: LabelPrintLog::DISCARD_DISCARDED) : null,
                ])->values(),
            ],
            'meta' => $this->clock(),
        ]);
    }

    private function fail(string $message, string $field): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    private function present(HygieneTraceEntry $r): array
    {
        return [
            'id' => $r->id, 'product_name' => $r->product_name, 'dlc_product_id' => $r->dlc_product_id, 'supplier_id' => $r->supplier_id,
            'supplier_name' => $r->supplier_name, 'reception_id' => $r->reception_id, 'delivery_note_no' => $r->delivery_note_no, 'lot' => $r->lot,
            'dlc_date' => $r->dlc_date?->toDateString(), 'dlc_kind' => $r->dlc_kind, 'status' => $r->status, 'note' => $r->note,
            'attachment_id' => $r->attachment_id, 'traced_at' => $r->traced_at->toIso8601String(), 'operator_name' => $r->operator_name,
            'voided' => $r->voided_at !== null, 'voided_at' => $r->voided_at?->toIso8601String(), 'void_reason' => $r->void_reason,
        ];
    }
}
