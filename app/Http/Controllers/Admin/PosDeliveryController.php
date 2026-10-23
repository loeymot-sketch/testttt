<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\Delivery\GeocodeUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Delivery\AddressNormalizer;
use App\Services\Delivery\DeliveryAvailability;
use App\Services\Delivery\DeliveryQuoteService;
use App\Services\Delivery\DeliveryTariff;
use App\Services\Delivery\DeliveryWaveService;
use App\Models\DeliveryStop;
use Carbon\Carbon;
use App\Services\Fiscal\AuditLogService;
use App\Services\Identity\CustomerAccountProvisioner;
use App\Services\Identity\PhoneIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * [LIVRAISON 2026-10-20] La livraison se pilote DEPUIS LA CAISSE : suspendre / remettre d'un appui,
 * retrouver ou créer le client par son téléphone, lui enregistrer son adresse UNE fois (géocodée par
 * le serveur) et connaître tout de suite le prix. Porte : `permission:pos` (routes).
 */
class PosDeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryAvailability $availability,
        private readonly CustomerAccountProvisioner $provisioner,
        private readonly PhoneIdentity $phones,
        private readonly AddressNormalizer $normalizer,
        private readonly DeliveryQuoteService $quotes,
        private readonly DeliveryTariff $tariff,
        private readonly DeliveryWaveService $waves,
        private readonly \App\Services\UberDirect\UberDirectDispatchService $uber,
    ) {
    }

    public function status(): JsonResponse
    {
        return response()->json(['status' => true, 'enabled' => $this->availability->isEnabled(), 'grid' => $this->availability->publicGrid()]);
    }

    /** Suspendre ou remettre la livraison. Tracé dans le journal d'audit (qui, quand, vers quel état). */
    public function toggle(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), ['enabled' => ['required', 'boolean']])->validate();
        $enabled = (bool) $data['enabled'];
        $before = $this->availability->isEnabled();

        $this->availability->set($enabled);

        try {
            app(AuditLogService::class)->write([
                'branch_id'   => (int) ($request->user()?->branch_id ?? 0),
                'user_id'     => (int) $request->user()?->id,
                'action'      => $enabled ? 'delivery.resumed' : 'delivery.suspended',
                'resource'    => 'setting',
                'resource_id' => 0,
                'payload'     => ['from' => $before, 'to' => $enabled],
                'ip'          => (string) ($request->ip() ?? ''),
                'user_agent'  => substr((string) $request->userAgent(), 0, 512),
            ]);
        } catch (\Throwable $e) {
            // Le changement a eu lieu ; une trace manquante est signalée, jamais avalée en silence.
            Log::error('delivery.toggle.audit_failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['status' => true, 'enabled' => $this->availability->isEnabled()]);
    }

    /** Recherche SEULE (aucune création) : le caissier tape le numéro, on propose le client et ses adresses. */
    public function lookup(Request $request): JsonResponse
    {
        $data = Validator::make($request->query(), ['phone' => ['required', 'string', 'min:9', 'max:32']])->validate();

        if (! $this->phones->looksComplete($data['phone'])) {
            return response()->json(['status' => true, 'found' => false]);
        }

        $user = User::withoutGlobalScope(BranchScope::class)
            ->whereIn('phone', $this->phones->variants($data['phone']))
            ->orderBy('id')->first();

        // Un compte de l'équipe n'est jamais proposé comme « client à livrer ».
        if (! $user || ! app(\App\Services\Identity\CustomerAccount::class)->isCustomer($user)) {
            return response()->json(['status' => true, 'found' => false]);
        }

        return response()->json(['status' => true, 'found' => true, 'data' => $this->presentCustomer((int) $user->id)]);
    }

    /** Retrouve — ou crée — le client par téléphone et renvoie ses adresses déjà enregistrées. */
    public function customer(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'min:9', 'max:32'],
            'name'  => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
        ], ['phone.min' => 'Ce numéro est incomplet.'])->validate();

        $res = $this->provisioner->ensure($data['phone'], $data['email'] ?? null, $data['name'] ?? null, 'comptoir');
        if (! $res['user_id']) {
            return response()->json(['status' => false, 'code' => strtoupper($res['reason']), 'message' => $this->reasonMessage($res['reason'])], 422);
        }

        return response()->json(['status' => true, 'created' => $res['created'], 'data' => $this->presentCustomer((int) $res['user_id'])]);
    }

    /** Enregistre l'adresse d'un client (une seule fois), géocodée par le serveur, et donne le prix. */
    public function address(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'customer_id' => ['required', 'integer'],
            'address'     => ['required', 'string', 'min:5', 'max:500'],
            'apartment'   => ['nullable', 'string', 'max:200'],
        ])->validate();

        $customer = User::withoutGlobalScope(BranchScope::class)->find($data['customer_id']);
        if (! $customer) {
            return response()->json(['status' => false, 'message' => 'Client introuvable.'], 404);
        }
        // [relecture adverse P1] Une adresse de livraison se rattache à un VRAI client : ni un compte de l'équipe,
        // ni le client « de passage » (compte partagé par toutes les ventes comptoir).
        if (! app(\App\Services\Identity\CustomerAccount::class)->isCustomer($customer)
            || strcasecmp((string) $customer->email, \App\Services\Pos\WalkInCustomerResolver::EMAIL) === 0) {
            return response()->json(['status' => false, 'message' => 'Choisissez le vrai client (téléphone) : ce compte ne reçoit pas d\'adresse de livraison.'], 422);
        }

        $payload = $this->normalizer->normalize([
            'address'   => $data['address'],
            'apartment' => $data['apartment'] ?? null,
            'label'     => 'Livraison',
            'user_id'   => $customer->id,
        ]);

        $address = $this->normalizer->findSamePlace((int) $customer->id, $payload);
        if (! $address) {
            // Étiquette unique par client (contrainte existante). Jamais « nombre d'adresses + 1 » : après une
            // suppression il collisionnerait avec une étiquette existante (500). On cherche la première libre.
            $taken = Address::query()->where('user_id', $customer->id)->pluck('label')->all();
            $label = 'Livraison';
            for ($i = 2; in_array($label, $taken, true); $i++) {
                $label = 'Livraison ' . $i;
            }
            $payload['label'] = $label;
            $address = Address::create($payload);
        }

        return response()->json(['status' => true, 'data' => $this->presentAddress($address)]);
    }

    /** Suggestions d'adresse pour la caisse (même moteur que le site, mais authentifié et limité par utilisateur). */
    public function suggest(Request $request): JsonResponse
    {
        $data = Validator::make($request->query(), ['q' => ['required', 'string', 'min:3', 'max:200']])->validate();

        return response()->json(['status' => true, 'data' => app(\App\Services\Delivery\AddressGeocoder::class)->search($data['q'])]);
    }

    /** Devis pour une adresse choisie : prix, commune, distance, trajet estimé. */
    public function quote(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), ['q' => ['required', 'string', 'min:5', 'max:300']])->validate();

        return response()->json(app(\App\Services\Delivery\DeliveryTextQuote::class)->build($data['q']));
    }

    /** Tableau des tournées d'aujourd'hui pour la caisse : qui part quand, avec quoi, quel livreur. */
    public function board(Request $request): JsonResponse
    {
        // [UBER-DIRECT 2026-10-22] Filet quand le webhook n'est pas branché : les courses Uber actives sont relues (≤ 1 fois / 15 s).
        $this->uber->syncActive();
        $stops = DeliveryStop::query()
            // Tournées à venir ou en cours, et celles remises depuis ce matin — pas celles d'hier soir.
            ->where('wave_at', '>=', Carbon::now()->subDay())
            ->where(fn ($q) => $q->whereNull('delivered_at')->orWhere('delivered_at', '>=', Carbon::today()))
            ->with(['order' => fn ($q) => $q->withoutGlobalScope(BranchScope::class), 'order.user', 'order.address'])
            ->orderBy('wave_at')->orderBy('sequence')->get();
        $couriers = User::query()->withoutGlobalScope(BranchScope::class)->role('Delivery Boy', 'sanctum')
            ->where('status', \App\Enums\Status::ACTIVE)->get(['id', 'name'])->keyBy('id');

        $waves = $stops->groupBy(fn ($s) => $s->wave_at->toDateTimeString())->map(fn ($g, $at) => [
            'wave_at' => $at,
            'time'    => Carbon::parse($at)->format('H:i'),
            // [VG-2026-10-21 M4-005] Le jour de la tournée (date, is_today, day_label) : la planche n'affichait que l'heure.
            ...$this->waves->waveDay(Carbon::parse($at)),
            'left'    => $g->contains(fn ($s) => $s->left_at !== null),
            'courier' => $g->first()->delivery_boy_id ? ($couriers[$g->first()->delivery_boy_id]->name ?? null) : null,
            'courier_id' => $g->first()->delivery_boy_id,
            'stops'   => $g->map(fn ($s) => [
                'id' => $s->id, 'order_id' => $s->order_id, 'order_number' => $s->order?->order_serial_no,
                'customer' => $s->order?->user?->name, 'address' => $s->order?->address?->address,
                'collect_method' => $s->collect_method, 'amount_due' => round($s->amount_due_cents / 100, 2),
                'left_at' => $s->left_at?->toDateTimeString(), 'arrived_at' => $s->arrived_at?->toDateTimeString(),
                'delivered_at' => $s->delivered_at?->toDateTimeString(), 'has_photo' => (bool) $s->proof_photo_path,
                ...$this->uberView($s),
            ])->values()->all(),
        ])->values()->all();

        return response()->json(['status' => true, 'enabled' => $this->availability->isEnabled(), 'uber_enabled' => $this->uber->isAvailable(), 'couriers' => $couriers->values(), 'data' => $waves]);
    }

    /** Désigne le livreur d'une tournée (quand il y en a plusieurs). */
    public function assignCourier(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'wave_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'delivery_boy_id' => ['required', 'integer'],
        ])->validate();

        $isCourier = User::query()->withoutGlobalScope(BranchScope::class)->role('Delivery Boy', 'sanctum')->whereKey($data['delivery_boy_id'])->exists();
        abort_unless($isCourier, 422, 'Ce compte n\'est pas un livreur.');

        $branchId = (int) ($request->user()?->branch_id ?: Branch::query()->orderBy('id')->value('id'));
        $n = $this->waves->assignCourier($branchId, Carbon::parse($data['wave_at']), (int) $data['delivery_boy_id']);

        return response()->json(['status' => true, 'assigned' => $n]);
    }

    /** [UBER-DIRECT 2026-10-22] Qui livre cet arrêt (nos livreurs ou Uber), l'état de la course, et pourquoi Uber est impossible le cas échéant. */
    private function uberView(DeliveryStop $s): array
    {
        $course = $this->uber->activeCourse((int) $s->order_id);
        // [E2E 2026-10-22 · B-001/B-002] Une course qui a mal fini (renvoyée après la prise du sac, annulation non confirmée,
        // réponse perdue) reste dite à la caisse tant que l'arrêt n'est pas remis : jamais une course facturée sans trace.
        $trace = \App\Models\UberDirectDelivery::query()->withoutGlobalScope(BranchScope::class)->where('order_id', $s->order_id)->first();

        return [
            'uber_attention' => $trace && $trace->failure_code && ! $s->delivered_at ? $trace->failure_message : null,
            'dispatch' => $course ? 'uber' : 'own',
            'uber' => $course ? [
                'status' => $course->status, 'tracking_url' => $course->tracking_url,
                'fee' => $course->quote_fee_cents !== null ? round($course->quote_fee_cents / 100, 2) : null,
                'courier_name' => data_get($course->delivery_payload, 'courier.name'),
                'courier_phone' => data_get($course->delivery_payload, 'courier.phone_number'),
                'bag_taken' => in_array((string) $course->status, ['pickup_complete', 'dropoff', 'delivered'], true),
            ] : null,
            'uber_blocked' => $course || $s->delivered_at ? null : $this->uber->blockedReason($s),
        ];
    }

    private function stopOf(Request $request): DeliveryStop
    {
        $data = Validator::make($request->all(), ['stop_id' => ['required', 'integer']])->validate();

        return DeliveryStop::query()->findOrFail((int) $data['stop_id']);
    }

    /** Prix et délai d'Uber pour cet arrêt (gratuit, sans engagement) : le caissier le voit avant de confirmer. */
    public function uberQuote(Request $request): JsonResponse
    {
        $r = $this->uber->quote($this->stopOf($request));

        return response()->json(['status' => $r['ok']] + $r, $r['ok'] ? 200 : 422);
    }

    /** Confie l'arrêt à Uber au prix du devis vu. */
    public function uberDispatch(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), ['quote_id' => ['required', 'string', 'max:191']])->validate();
        $r = $this->uber->dispatch($this->stopOf($request), $data['quote_id'], (int) $request->user()?->id);

        return response()->json(['status' => $r['ok']] + $r, $r['ok'] ? 200 : ((($r['code'] ?? '') === 'quote_changed') ? 409 : 422));
    }

    /** Rend l'arrêt à nos livreurs. */
    public function uberRelease(Request $request): JsonResponse
    {
        $r = $this->uber->release($this->stopOf($request), (int) $request->user()?->id);

        return response()->json(['status' => $r['ok']] + $r, $r['ok'] ? 200 : 422);
    }

    private function presentCustomer(int $userId): array
    {
        $u = User::withoutGlobalScope(BranchScope::class)->find($userId);

        return [
            'id'             => (int) $u->id,
            'name'           => (string) $u->name,
            'phone'          => (string) $u->phone,
            'email'          => $u->email,
            'email_verified' => $u->email_verified_at !== null && $u->email !== null,
            'addresses'      => Address::query()->where('user_id', $u->id)->orderByDesc('id')->get()->map(fn (Address $a) => $this->presentAddress($a))->all(),
        ];
    }

    private function presentAddress(Address $a): array
    {
        $branch = Branch::query()->orderBy('id')->first();
        $quote = ['allowed' => false, 'fee' => 0.0, 'tier' => 'unknown', 'suggest_uber_eats' => false];
        $legacy = config('delivery.geocoder.enabled', true) && blank($a->city) && blank($a->postal_code);
        if ($legacy) {
            // Adresse d'avant le géocodage serveur : jamais facturée, à ressaisir.
            $quote = ['allowed' => false, 'fee' => 0.0, 'tier' => 'unverified', 'suggest_uber_eats' => false];
        } elseif ($branch && $a->geocode_status === 'OK' && is_numeric($a->latitude) && is_numeric($a->longitude)) {
            try {
                $km = $this->quotes->distanceFromBranch($branch, (float) $a->latitude, (float) $a->longitude);
                $quote = $this->tariff->quote($km, $a->city, $a->postal_code) + ['distance_km' => round($km, 2)];
            } catch (GeocodeUnavailableException) {
            }
        }

        return [
            'id' => (int) $a->id, 'address' => (string) $a->address, 'apartment' => $a->apartment,
            'city' => $a->city, 'postal_code' => $a->postal_code,
            'allowed' => $quote['allowed'], 'fee' => $quote['fee'], 'tier' => $quote['tier'],
            'suggest_uber_eats' => $quote['suggest_uber_eats'], 'distance_km' => $quote['distance_km'] ?? null,
        ];
    }

    private function reasonMessage(string $reason): string
    {
        return match ($reason) {
            'phone_invalid'   => 'Ce numéro de téléphone n\'est pas valide.',
            'staff_phone'     => 'Ce numéro appartient à un compte de l\'équipe.',
            'deleted_account' => 'Ce compte client a été supprimé.',
            default           => 'Impossible de créer le compte client.',
        };
    }
}
