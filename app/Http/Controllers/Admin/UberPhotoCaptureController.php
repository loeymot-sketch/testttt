<?php

namespace App\Http\Controllers\Admin;

use App\Models\UberTicketCapture;
use App\Services\Uber\UberOrderIngestor;
use App\Services\Uber\UberPhotoOrderMapper;
use App\Services\Uber\UberTicketPreviewBuilder;
use App\Services\Uber\Vision\OpenAiUberTicketVisionService;
use App\Services\Uber\Vision\UberTicketVisionContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * [UBER-PHOTO 2026-08-10 · owner] « Je photographie le ticket Uber sur la tablette, il part en
 * cuisine, il s'imprime, avec Uber écrit dessus et le nom du client. »
 *
 * LE PARCOURS, EN DEUX TEMPS
 * --------------------------
 *   1. `scan`    — les photos arrivent, sont stockées, lues, mises en correspondance avec le
 *                  catalogue, et l'APERÇU de ce que verra la cuisine est renvoyé. RIEN n'est
 *                  encore parti : aucune commande, aucune impression, aucun stock touché.
 *   2. `confirm` — un humain a regardé l'aperçu et l'a validé (éventuellement corrigé). C'est
 *                  seulement là que la commande naît et que la cuisine la reçoit.
 *
 * POURQUOI DEUX TEMPS PLUTÔT QU'UN
 * --------------------------------
 * Parce qu'une lecture automatique peut se tromper, et que l'erreur ne se voit qu'au moment de
 * remettre le sac au livreur — trop tard. Le temps de validation coûte deux secondes à quelqu'un
 * qui tient déjà la tablette en main ; une commande fausse coûte un plat, un client, et une note.
 * L'aperçu est calculé par les services de la cuisine eux-mêmes, donc ce qui est validé est
 * exactement ce qui sera préparé.
 *
 * IDEMPOTENCE
 * -----------
 * Deux barrières, parce qu'en coup de feu on appuie deux fois :
 *   · la même PHOTO (empreinte sha256 du contenu) retombe sur la même capture ;
 *   · une capture déjà confirmée retourne SA commande au lieu d'en créer une seconde.
 *
 * Domaine NEUF, ADDITIF, HORS NF525 : canal Uber non fiscalisé, aucune séquence ni chaîne d'audit
 * touchée (CLAUDE.md §8).
 */
class UberPhotoCaptureController extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        // Même porte que la liste « commandes en cours » de la caisse : c'est le personnel qui
        // tient la tablette et suit le flux qui utilise cet écran.
        $this->middleware(['permission:pos-orders|pos']);
    }

    /**
     * Reçoit 1..N photos d'UN ticket, les lit, et rend l'aperçu cuisine. Ne crée aucune commande.
     */
    public function scan(
        Request $request,
        UberTicketVisionContract $vision,
        UberPhotoOrderMapper $mapper,
        UberTicketPreviewBuilder $preview
    ): JsonResponse {
        $max = max(1, (int) config('uber.photo_max_files', 6));
        $maxKb = max(256, (int) config('uber.photo_max_kb', 12288));

        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:'.$max],
            // `file` et non `image` : certaines tablettes livrent du HEIC, que la validation
            // `image` de PHP refuse alors que le lecteur, lui, le traite très bien.
            'photos.*' => ['required', 'file', 'max:'.$maxKb],
        ], [], ['photos' => 'photos du ticket']);

        $branchId = (int) ($request->user()?->branch_id ?: config('uber.branch_id', 1));

        $files = array_values($request->file('photos'));
        // L'empreinte porte sur le CONTENU de toutes les photos, dans l'ordre de prise de vue :
        // renvoyer le même ticket deux fois ne doit pas produire deux commandes.
        $hash = 'sha256:'.hash('sha256', implode('|', array_map(static fn ($f): string => hash('sha256', (string) $f->get()), $files)));

        $existing = UberTicketCapture::query()->where('photo_hash', $hash)->where('branch_id', $branchId)->first();
        if ($existing) {
            return response()->json($this->present($existing, $mapper, $preview, true));
        }

        $paths = [];
        foreach ($files as $file) {
            $paths[] = $file->store('uber-tickets', 'local');
        }

        $capture = new UberTicketCapture([
            'branch_id' => $branchId,
            'user_id' => $request->user()?->id,
            'photo_paths' => $paths,
            'photo_hash' => $hash,
            'status' => UberTicketCapture::STATUS_PENDING,
            'vision_driver' => $vision->driverName(),
        ]);
        $capture->branch_id = $branchId;
        $capture->save();

        try {
            $ticket = $vision->readTicket(array_map(
                static fn (string $p): string => Storage::disk('local')->path($p),
                $paths
            ));
        } catch (\Throwable $e) {
            // Une lecture qui échoue ne fait perdre NI les photos NI la trace : le personnel
            // saisira la commande à la main, et l'erreur reste lisible pour comprendre pourquoi.
            Log::warning('[UberPhotoCapture] lecture du ticket échouée', ['capture' => $capture->id, 'error' => $e->getMessage()]);
            $capture->forceFill([
                'status' => UberTicketCapture::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            return response()->json($this->present($capture, $mapper, $preview, false), 200);
        }

        $ticket = OpenAiUberTicketVisionService::normalize($ticket);

        $capture->forceFill([
            'status' => $ticket['items'] === [] ? UberTicketCapture::STATUS_FAILED : UberTicketCapture::STATUS_EXTRACTED,
            'extracted' => $ticket,
            'customer_name' => $ticket['customer_name'] ?: null,
            'display_id' => $ticket['display_id'] ?: null,
            'items_count' => count($ticket['items']),
            'total' => $ticket['total'],
            'error_message' => $ticket['items'] === [] ? 'Aucune ligne lisible sur la photo.' : null,
        ])->save();

        return response()->json($this->present($capture, $mapper, $preview, false));
    }

    /**
     * Validation humaine → la commande naît et la cuisine la reçoit (écran + impression).
     *
     * Le corps peut contenir un ticket CORRIGÉ : c'est celui-là qui fait foi. Sans corps, on
     * envoie la lecture telle quelle.
     */
    public function confirm(
        Request $request,
        int $capture,
        UberPhotoOrderMapper $mapper,
        UberTicketPreviewBuilder $preview,
        UberOrderIngestor $ingestor
    ): JsonResponse {
        $model = UberTicketCapture::query()->findOrFail($capture);

        // Déjà confirmée : on rend SA commande. Un double appui ne crée pas un deuxième plat.
        if ($model->status === UberTicketCapture::STATUS_CONFIRMED && $model->order_id) {
            return response()->json([
                'status' => 'already_confirmed',
                'capture' => $this->present($model, $mapper, $preview, true),
                'order_id' => (int) $model->order_id,
            ]);
        }

        $validated = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:60'],
            'display_id' => ['nullable', 'string', 'max:60'],
            'order_type' => ['nullable', 'string', 'in:delivery,pickup'],
            'total' => ['nullable', 'numeric', 'min:0'],
            'items' => ['nullable', 'array', 'max:60'],
            'items.*.title' => ['required_with:items', 'string', 'max:180'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'items.*.options' => ['nullable', 'array', 'max:30'],
            'items.*.options.*' => ['nullable', 'string', 'max:180'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $ticket = OpenAiUberTicketVisionService::normalize(
            array_filter($validated, static fn ($v): bool => $v !== null) + (array) $model->extracted
        );

        if ($ticket['items'] === []) {
            // On refuse d'envoyer une commande VIDE en cuisine : elle occuperait une place sur
            // l'écran, sortirait un ticket blanc, et personne ne saurait quoi préparer.
            return response()->json([
                'status' => 'empty_ticket',
                'message' => 'Aucun article à envoyer : corrigez la lecture ou reprenez la photo.',
            ], 422);
        }

        // Numéro d'appel : une commande sans numéro ne peut pas être annoncée au comptoir ni
        // appariée par le cuisinier. Quand le ticket n'en porte aucun de lisible, on en dérive un
        // de la capture elle-même (« UP12 ») plutôt que de laisser la carte muette.
        if ($ticket['display_id'] === '') {
            $ticket['display_id'] = 'P'.$model->id;
        }

        $mapped = $mapper->map($ticket);
        $orderId = $ingestor->ingest($mapped, 'uber-photo:'.$model->photo_hash);

        if ($orderId === null) {
            return response()->json(['status' => 'not_created', 'message' => 'Commande non créée.'], 409);
        }

        $model->forceFill([
            'status' => UberTicketCapture::STATUS_CONFIRMED,
            'confirmed_payload' => $ticket,
            'customer_name' => $ticket['customer_name'] ?: $model->customer_name,
            'display_id' => $ticket['display_id'] ?: $model->display_id,
            'items_count' => count($ticket['items']),
            'total' => $ticket['total'] ?? $model->total,
            'order_id' => $orderId,
            'confirmed_at' => now(),
        ])->save();

        Log::info('[UberPhotoCapture] commande Uber envoyée en cuisine', [
            'capture' => $model->id, 'order_id' => $orderId, 'lignes' => count($ticket['items']),
        ]);

        return response()->json([
            'status' => 'ok',
            'order_id' => $orderId,
            'capture' => $this->present($model->refresh(), $mapper, $preview, true),
        ]);
    }

    /** Lecture jetée (mauvais cadrage, doublon) : les photos restent, rien n'est parti en cuisine. */
    public function discard(int $capture, UberPhotoOrderMapper $mapper, UberTicketPreviewBuilder $preview): JsonResponse
    {
        $model = UberTicketCapture::query()->findOrFail($capture);

        if ($model->status === UberTicketCapture::STATUS_CONFIRMED) {
            // Une commande déjà partie en cuisine ne s'annule pas en jetant sa photo : elle
            // s'annule depuis la caisse, où l'annulation libère aussi le stock.
            return response()->json([
                'status' => 'already_confirmed',
                'message' => 'Cette commande est déjà partie en cuisine : annulez-la depuis la caisse.',
            ], 409);
        }

        $model->forceFill(['status' => UberTicketCapture::STATUS_DISCARDED])->save();

        return response()->json(['status' => 'ok', 'capture' => $this->present($model, $mapper, $preview, true)]);
    }

    /** Les dernières captures du service — l'écran de la tablette montre ce qui vient de passer. */
    public function recent(Request $request, UberPhotoOrderMapper $mapper, UberTicketPreviewBuilder $preview): JsonResponse
    {
        $captures = UberTicketCapture::query()
            ->orderByDesc('id')
            ->limit(min(30, max(1, (int) $request->query('limit', 12))))
            ->get();

        return response()->json([
            'data' => $captures->map(fn (UberTicketCapture $c): array => $this->present($c, $mapper, $preview, true))->all(),
        ]);
    }

    /**
     * Forme rendue à l'écran : l'état, la lecture, et l'APERÇU cuisine calculé par les services
     * de la cuisine eux-mêmes.
     *
     * @return array<string, mixed>
     */
    private function present(
        UberTicketCapture $capture,
        UberPhotoOrderMapper $mapper,
        UberTicketPreviewBuilder $preview,
        bool $deduped
    ): array {
        $ticket = (array) ($capture->confirmed_payload ?? $capture->extracted ?? []);
        $apercu = ['cuisson' => '', 'lignes' => []];
        $nonMappes = 0;

        if (($ticket['items'] ?? []) !== []) {
            $mapped = $mapper->map(OpenAiUberTicketVisionService::normalize($ticket));
            $apercu = $preview->build($mapped);
            $nonMappes = (int) ($mapped['unmapped'] ?? 0);
        }

        return [
            'id' => (int) $capture->id,
            'status' => (string) $capture->status,
            'deja_lue' => $deduped,
            'photos' => count((array) $capture->photo_paths),
            'lecteur' => (string) ($capture->vision_driver ?? ''),
            'client' => (string) ($capture->customer_name ?? ''),
            'numero' => (string) ($capture->display_id ?? ''),
            'total' => $capture->total !== null ? (float) $capture->total : null,
            'order_id' => $capture->order_id ? (int) $capture->order_id : null,
            'erreur' => (string) ($capture->error_message ?? ''),
            'ticket' => $ticket,
            'apercu' => $apercu,
            'articles_non_reconnus' => $nonMappes,
            'cree_le' => optional($capture->created_at)->toIso8601String(),
        ];
    }
}
