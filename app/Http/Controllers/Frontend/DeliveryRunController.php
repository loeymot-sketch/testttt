<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Services\Delivery\DoorPaymentSplit;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * [LIVRAISON 2026-10-20] La tournée du livreur : ses départs de 30 minutes, l'adresse et l'itinéraire
 * (lien Waze, où s'affiche le temps réel), ce qu'il doit encaisser, puis départ → arrivée (photo) → remise.
 *
 * La remise passe TOUJOURS par `OrderService::deliveryBoyOrderChangeStatus` : encaissement à la porte,
 * séquence fiscale NF525, journal d'audit et notifications restent exactement ceux de la route existante,
 * et `OrderStateMachine` (zone gelée) n'est pas touchée. Cette classe ne fait que tenir le journal du
 * parcours (`delivery_stops`) et la photo de preuve.
 */
class DeliveryRunController extends Controller
{
    public function __construct(private readonly OrderService $orders, private readonly \App\Services\Delivery\DeliveryWaveService $waves)
    {
    }

    /** Mes tournées : non remises + remises depuis ce matin. */
    public function index(): JsonResponse
    {
        $stops = DeliveryStop::query()
            ->where('delivery_boy_id', Auth::id())
            ->where(function ($q) {
                $q->whereNull('delivered_at')->orWhere('delivered_at', '>=', Carbon::today());
            })
            ->where('wave_at', '>=', Carbon::now()->subDay())
            ->with(['order' => fn ($q) => $q->withoutGlobalScope(BranchScope::class), 'order.address', 'order.user'])
            ->orderBy('wave_at')->orderBy('sequence')
            ->get();

        $waves = $stops->groupBy(fn (DeliveryStop $s) => $s->wave_at->toDateTimeString())->map(function ($group, $waveAt) {
            return [
                'wave_at' => $waveAt,
                'time'    => Carbon::parse($waveAt)->format('H:i'),
                // [VG-2026-10-21 M4-005] Le jour : « Départ 00:30 » se lisait comme ce soir pour une tournée 24 h plus tard.
                ...$this->waves->waveDay(Carbon::parse($waveAt)),
                'left'    => $group->contains(fn ($s) => $s->left_at !== null),
                // [2026-10-23] Combien de temps la tournée prend VRAIMENT, retour au restaurant compris (itinéraire routier).
                'tour'    => $group->every(fn ($s) => $s->delivered_at !== null) ? null : $this->waves->tourSummary((int) $group->first()->branch_id, $group),
                'stops'   => $group->map(fn (DeliveryStop $s) => $this->present($s))->values()->all(),
            ];
        })->values()->all();

        return response()->json(['status' => true, 'data' => $waves]);
    }

    /** Le livreur part avec les commandes PRÊTES de la tournée ; les autres sont signalées. */
    public function depart(Request $request): JsonResponse
    {
        $data = $request->validate(['wave_at' => ['required', 'date_format:Y-m-d H:i:s']]);

        $stops = DeliveryStop::query()->where('delivery_boy_id', Auth::id())->where('wave_at', $data['wave_at'])
            ->whereNull('left_at')->whereNull('delivered_at')->with('order')->get();
        abort_if($stops->isEmpty(), 404, 'Aucune livraison à partir pour cette tournée.');

        // [VG-2026-10-21 M4-004] Pas de départ plus de `delivery.depart_max_advance_minutes` avant la tournée : un départ
        // la FERME à toute nouvelle commande (la tournée du lendemain 18:00 partait la veille à 17:10).
        $opensAt = $this->waves->departureOpensAt(Carbon::parse($data['wave_at']));
        if (Carbon::now()->lt($opensAt)) {
            abort(422, $opensAt->isSameDay(Carbon::now())
                ? 'Départ possible à partir de ' . $opensAt->format('H:i') . '.'
                : 'Départ possible le ' . $opensAt->format('d/m') . ' à partir de ' . $opensAt->format('H:i') . '.');
        }

        // Aucun départ à moitié : si UNE commande de la tournée n'est pas (ou plus) attribuée à ce livreur côté commande,
        // on refuse d'emblée, avant de déplacer la moindre commande (sinon un 403 au 2ᵉ arrêt laisserait le 1ᵉʳ parti).
        $foreign = Order::query()->withoutGlobalScope(BranchScope::class)->whereIn('id', $stops->pluck('order_id'))
            ->where(fn ($q) => $q->whereNull('delivery_boy_id')->orWhere('delivery_boy_id', '!=', Auth::id()))->pluck('id');
        abort_if($foreign->isNotEmpty(), 409, 'Une commande de cette tournée n\'est plus attribuée à vous : demandez à la caisse de vérifier.');

        $left = [];
        $notReady = [];
        foreach ($stops as $stop) {
            $order = Order::query()->withoutGlobalScope(BranchScope::class)->find($stop->order_id);
            if ((int) $order->status !== OrderStatus::PREPARED) {
                $notReady[] = $stop->order_id;
                continue;
            }
            $this->orders->deliveryBoyOrderChangeStatus($order, Request::create('', 'POST', ['status' => OrderStatus::OUT_FOR_DELIVERY]));
            $stop->forceFill(['left_at' => now()])->save();
            $left[] = $stop->order_id;
        }

        return response()->json(['status' => true, 'left' => $left, 'not_ready' => $notReady]);
    }

    /** Arrivée chez le client : horodatage, position, photo de preuve (facultative mais conseillée). */
    public function arrive(Request $request, DeliveryStop $stop): JsonResponse
    {
        $this->authorizeStop($stop);
        $data = $request->validate([
            'photo' => ['nullable', 'file', 'mimes:jpeg,jpg,png,webp,heic,heif', 'max:' . (int) config('delivery.proof_photo.max_kb', 6144)],
            'lat'   => ['nullable', 'numeric', 'between:-90,90'],
            'lng'   => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        abort_if($stop->left_at === null, 409, 'Partez d\'abord avec la tournée.');
        abort_if($stop->delivered_at !== null, 409, 'Cette commande est déjà remise.');

        $path = $stop->proof_photo_path;
        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store(trim((string) config('delivery.proof_photo.directory', 'delivery-proofs'), '/') . '/' . now()->format('Y-m'), 'local');
        }

        $stop->forceFill([
            'arrived_at'       => $stop->arrived_at ?? now(),
            'arrival_lat'      => $data['lat'] ?? $stop->arrival_lat,
            'arrival_lng'      => $data['lng'] ?? $stop->arrival_lng,
            'proof_photo_path' => $path,
        ])->save();

        return response()->json(['status' => true, 'data' => $this->present($stop->fresh(['order.address', 'order.user']))]);
    }

    /** Remise au client : passe la commande à DELIVERED par la voie officielle (encaissement inclus). */
    public function deliver(Request $request, DeliveryStop $stop): JsonResponse
    {
        $this->authorizeStop($stop);
        $data = $request->validate(['collected_cents' => ['nullable', 'integer', 'min:0']]);
        abort_if($stop->left_at === null, 409, 'Partez d\'abord avec la tournée.');
        abort_if($stop->delivered_at !== null, 409, 'Cette commande est déjà remise.');
        // [LIVREUR CAISSE 2026-11-02] Règlement en plusieurs moyens (facultatif) : `payments = [{mode: cash|card|meal_voucher, amount}]`.
        // Absent = chemin historique STRICTEMENT inchangé (le mode est celui que le client a choisi).
        $payments = $request->input('payments');
        abort_if($payments !== null && ! is_array($payments), 422, 'Moyens de paiement illisibles : recommencez la saisie.');

        $order = Order::query()->withoutGlobalScope(BranchScope::class)->findOrFail($stop->order_id);
        // [VG-2026-10-21 M4-102] Annulée en caisse pendant la tournée : ni remise ni encaissement (500 « transition invalide » avant).
        abort_if($this->isCancelled($order), 422, 'Commande annulée : ne la remettez pas, rapportez-la au restaurant.');
        $due = $this->waves->collectionFor($order); // ce qui restait RÉELLEMENT à encaisser avant la remise
        $tranches = null;
        $deja = null; // tranches DÉJÀ enregistrées à la porte (reprise après un échec survenu après le COMMIT)
        if ($payments !== null) {
            if ((int) $due['amount_due_cents'] <= 0) {
                // [RED-TEAM P2-2] Réglée et remise au serveur, mais l'arrêt n'a pas été fermé (échec après le COMMIT) :
                // un rejeu doit TERMINER l'arrêt avec ce qui a réellement été encaissé, pas rester coincé en 422.
                $deja = $this->tranchesDejaEnregistrees($order);
                abort_if($deja === null, 422, 'Cette commande est déjà payée : il n\'y a rien à encaisser.');
            } else {
                // Contrôle d'emblée (rien n'est écrit) ; OrderService le refait sous verrou avec le total relu en base.
                $tranches = DoorPaymentSplit::normalize($payments, (int) $due['amount_due_cents']);
            }
        }
        if ($deja === null) {
            $this->orders->deliveryBoyOrderChangeStatus($order, Request::create('', 'POST', ['status' => OrderStatus::DELIVERED]), null, $payments);
            // [RED-TEAM P2-1] Un jumeau (QR / ancien écran) a pu livrer la commande entre le contrôle et le verrou : le service
            // rend alors la main sans rien écrire. On n'écrit « réglé en plusieurs moyens » QUE si les lignes existent vraiment.
            if ($tranches !== null && $this->tranchesDejaEnregistrees($order) === null) {
                $tranches = null;
            }
        } else {
            $tranches = $deja;
        }
        $stop->forceFill(['collect_method' => $due['collect_method'], 'amount_due_cents' => $due['amount_due_cents']]);

        $stop->forceFill([
            'arrived_at'             => $stop->arrived_at ?? now(),
            'delivered_at'           => now(),
            // [AUDIT ABUSIF 2026-11-06 · W5-P1-1] Le montant encaissé est ce que le serveur a ENREGISTRÉ (le dû), jamais le chiffre
            // envoyé par le livreur : collected_cents=1 était accepté et faussait la planche de la caisse.
            'amount_collected_cents' => $stop->amount_due_cents > 0 ? $stop->amount_due_cents : 0,
        ]);
        if ($tranches !== null) { // le montant encaissé est la somme VÉRIFIÉE des tranches (jamais le champ client)
            $stop->forceFill([
                'collect_method'         => count($tranches) > 1 ? 'split' : $tranches[0]['mode'],
                'amount_collected_cents' => array_sum(array_column($tranches, 'cents')),
            ]);
        }
        $stop->save();

        return response()->json(['status' => true, 'data' => $this->present($stop->fresh(['order.address', 'order.user']))]);
    }

    /** Photo de preuve : privée, visible du livreur concerné et de la caisse (permission pos). */
    public function photo(DeliveryStop $stop): StreamedResponse
    {
        $user = Auth::user();
        abort_unless($user && ((int) $stop->delivery_boy_id === (int) $user->id || $user->can('pos')), 403);
        abort_unless($stop->proof_photo_path && Storage::disk('local')->exists($stop->proof_photo_path), 404);

        return Storage::disk('local')->response($stop->proof_photo_path);
    }

    private function authorizeStop(DeliveryStop $stop): void
    {
        abort_unless((int) $stop->delivery_boy_id === (int) Auth::id(), 403, 'Cette livraison ne vous est pas attribuée.');
    }

    /** [VG-2026-10-21 M4-102] Commande annulée ou refusée (en caisse) : plus rien à remettre ni à encaisser. */
    private function isCancelled(Order $order): bool
    {
        return in_array((int) $order->status, [OrderStatus::CANCELED, OrderStatus::REJECTED], true);
    }

    /**
     * Tranches réellement écrites à la porte pour cette commande (lignes `order_payments` de référence « livraison »),
     * ou null s'il n'y en a aucune. Ne se fie jamais à ce que l'écran a envoyé.
     *
     * @return list<array{mode: string, cents: int}>|null
     */
    private function tranchesDejaEnregistrees(Order $order): ?array
    {
        $rows = \App\Models\OrderPayment::query()->withoutGlobalScope(BranchScope::class)
            ->where('order_id', $order->id)->where('reference', 'livraison')->orderBy('id')->get();
        // Statut relu EN BASE : l'objet reçu a été chargé avant l'appel au service et peut être périmé.
        $statut = (int) Order::query()->withoutGlobalScope(BranchScope::class)->whereKey($order->id)->value('status');
        if ($rows->isEmpty() || $statut !== OrderStatus::DELIVERED) {
            return null;
        }

        return $rows->map(fn ($p) => [
            'mode'  => DoorPaymentSplit::modeOfPosMethod((int) $p->mode) ?? 'other',
            'cents' => (int) round((float) $p->amount * 100),
        ])->values()->all();
    }

    /** @return list<array{mode: string, label: string, amount: float}> */
    private function doorPayments(DeliveryStop $s): array
    {
        $rows = \App\Models\OrderPayment::query()->withoutGlobalScope(BranchScope::class)
            ->where('order_id', $s->order_id)->where('reference', 'livraison')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            // Espèces historiques : pas de ligne `order_payments`, la preuve est le montant encaissé de l'arrêt.
            return ($s->collect_method === DeliveryStop::COLLECT_CASH && (int) $s->amount_collected_cents > 0)
                ? [['mode' => DoorPaymentSplit::CASH, 'label' => DoorPaymentSplit::LABELS[DoorPaymentSplit::CASH], 'amount' => round($s->amount_collected_cents / 100, 2)]]
                : [];
        }

        return $rows->map(function ($p) {
            $mode = DoorPaymentSplit::modeOfPosMethod((int) $p->mode);

            return ['mode' => $mode ?? 'other', 'label' => $mode ? DoorPaymentSplit::LABELS[$mode] : 'Autre', 'amount' => round((float) $p->amount, 2)];
        })->values()->all();
    }

    private function present(DeliveryStop $s): array
    {
        $order = $s->order;
        $addr = $order?->address;
        $lat = $addr?->latitude;
        $lng = $addr?->longitude;
        $hasPoint = is_numeric($lat) && is_numeric($lng);
        // [relecture adverse P1] Montant et mode à encaisser relus EN DIRECT sur la commande : un paiement en ligne
        // fait après la création ne doit pas laisser « Encaisser par carte X € » à l'écran du livreur. Une fois
        // remise, on garde ce qui a réellement été noté.
        $live = ($order && ! $s->delivered_at)
            ? $this->waves->collectionFor($order)
            : ['collect_method' => $s->collect_method, 'amount_due_cents' => (int) $s->amount_due_cents];
        // [VG-2026-10-21 M4-102] Annulée / refusée pendant la tournée : la carte disait « En route · Déjà payé » + « Remis au
        // client » (ou « Encaisser en espèces ») ; le livreur remettait un repas remboursé. Rien à encaisser, à rapporter.
        $cancelled = $order !== null && ! $s->delivered_at && $this->isCancelled($order);
        if ($cancelled) {
            $live = ['collect_method' => 'cancelled', 'amount_due_cents' => 0];
        }

        return [
            'id'             => (int) $s->id,
            'order_id'       => (int) $s->order_id,
            'order_number'   => $order?->order_serial_no,
            'sequence'       => (int) $s->sequence,
            'ready'          => $order && (int) $order->status === OrderStatus::PREPARED,
            'order_status'   => $order ? (int) $order->status : null,
            'cancelled'      => $cancelled, // [VG-2026-10-21 M4-102]
            'customer_name'  => $order?->user?->name,
            'customer_phone' => $order?->user?->phone,
            'address'        => $addr?->address,
            'apartment'      => $addr?->apartment,
            'city'           => $addr?->city,
            'postal_code'    => $addr?->postal_code,
            // Waze affiche le temps de trajet RÉEL : on lui passe la main plutôt que de l'estimer.
            'waze_url'       => $hasPoint ? sprintf('https://waze.com/ul?ll=%s,%s&navigate=yes&zoom=17', $lat, $lng) : null,
            'maps_url'       => $hasPoint ? sprintf('https://www.google.com/maps/dir/?api=1&destination=%s,%s&travelmode=driving', $lat, $lng) : null,
            'collect_method' => $live['collect_method'],
            'collect_label'  => $cancelled ? 'ANNULÉE — ne pas remettre' : match ($live['collect_method']) {
                DeliveryStop::COLLECT_CASH => 'Encaisser en espèces',
                DeliveryStop::COLLECT_CARD => 'Encaisser par carte',
                // [VG-2026-10-21 L2] Le livreur récupère le titre-restaurant à la porte : un vrai libellé, pas « carte ».
                \App\Services\Delivery\DeliveryWaveService::COLLECT_MEAL_VOUCHER => 'Encaisser en titre-restaurant',
                'split'                    => 'Réglé en plusieurs moyens',
                default                    => 'Déjà payé',
            },
            'amount_due'       => round($live['amount_due_cents'] / 100, 2),
            'amount_due_cents' => (int) $live['amount_due_cents'],
            // [LIVREUR CAISSE 2026-11-02] Peut-on régler cet arrêt à la porte en choisissant / combinant les moyens ?
            'door_settleable'  => $order !== null && ! $s->delivered_at && ! $cancelled && (int) $live['amount_due_cents'] > 0
                && in_array((int) $order->payment_method, [\App\Enums\PaymentGateway::CASH_ON_DELIVERY, \App\Enums\PaymentGateway::TICKET_RESTAURANT], true),
            // Ce qui a réellement été encaissé à la porte, moyen par moyen (arrêt remis seulement).
            'door_payments'    => $s->delivered_at ? $this->doorPayments($s) : [],
            // [VG-2026-10-21 M4-004] Depuis le départ réel s'il a eu lieu (« ETA 18:32 » pour un livreur parti la veille).
            'eta_at'         => $this->waves->etaAt($s)->toDateTimeString(),
            'eta_minutes'    => (int) $s->eta_minutes,
            'left_at'        => $s->left_at?->toDateTimeString(),
            'arrived_at'     => $s->arrived_at?->toDateTimeString(),
            'delivered_at'   => $s->delivered_at?->toDateTimeString(),
            'has_photo'      => (bool) $s->proof_photo_path,
            'photo_url'      => $s->proof_photo_path ? url("/api/frontend/delivery-run/stops/{$s->id}/photo") : null,
        ];
    }
}
