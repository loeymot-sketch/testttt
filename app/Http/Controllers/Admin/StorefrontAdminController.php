<?php

namespace App\Http\Controllers\Admin;

use App\Models\Coupon;
use App\Models\StorefrontBanner;
use App\Models\User;
use App\Services\Delivery\DeliveryAvailability;
use App\Services\Storefront\StorefrontService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * [APP HYBRIDE W2a · 2026-11-03 · propriétaire : « changer pendant les fêtes sans nouvelle version de l'app »]
 * Écran « Vitrine » : bandeaux, offres flash (vers un code promo), couleurs. Brouillon → « Publier » (version +1) →
 * l'application le lit. « Retour à la version précédente » republie l'instantané d'avant.
 *
 * Porte : `permission:settings` — le responsable (même droit que les alertes d'hygiène et les réglages). Les offres
 * utilisent `offers*`, mais leur module est désactivé en V1 (OfferController) : on n'y adosse pas un écran actif.
 * Validation en ligne (pas de FormRequest) : un `authorize(): true` de plus ferait monter le cliquet
 * FormRequestAuthzDriftSentinelTest ; la porte est le middleware de permission.
 * La livraison on/off n'est PAS réécrite ici : on affiche l'interrupteur existant (DeliveryAvailability).
 */
class StorefrontAdminController extends AdminController
{
    public function __construct(private readonly StorefrontService $storefront, private readonly DeliveryAvailability $delivery)
    {
        parent::__construct();
        $this->middleware(['permission:settings']);
    }

    public function show(Request $request): JsonResponse
    {
        return $this->state($this->branch($request));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        $v = Validator::make($request->all(), [
            'enabled' => ['required', 'boolean'],
            'theme' => ['nullable'],
            'delivery_on_message' => ['nullable', 'string', 'max:140', $this->noTags()],
            'delivery_off_message' => ['nullable', 'string', 'max:140', $this->noTags()],
        ], $this->messages(), $this->attributes());
        $v->after(function ($validator) use ($request) {
            foreach ($this->storefront->themeErrors($request->input('theme')) as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        });
        $data = $v->validate();

        $settings = $this->storefront->settings($branch) ?? new \App\Models\StorefrontSetting(['branch_id' => $branch]);
        $settings->forceFill([
            'branch_id' => $branch,
            'enabled' => (bool) $data['enabled'],
            'theme' => $request->input('theme') === null ? null : $this->storefront->normalizeTheme($request->input('theme')),
            'delivery_on_message' => $this->clean($data['delivery_on_message'] ?? null),
            'delivery_off_message' => $this->clean($data['delivery_off_message'] ?? null),
        ])->save();

        return $this->state($branch);
    }

    public function storeBanner(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        $data = $this->validateBanner($request);
        if (StorefrontBanner::query()->where('branch_id', $branch)->count() >= (int) config('storefront.max_banners', 20)) {
            return response()->json(['status' => false, 'message' => 'Vingt bandeaux au plus : supprimez-en un avant d\'en créer un autre.', 'errors' => ['title' => ['Vingt bandeaux au plus : supprimez-en un avant d\'en créer un autre.']]], 422);
        }
        StorefrontBanner::query()->create(['branch_id' => $branch] + $data);

        return $this->state($branch, 201);
    }

    public function updateBanner(Request $request, int $banner): JsonResponse
    {
        $branch = $this->branch($request);
        $model = StorefrontBanner::query()->where('branch_id', $branch)->findOrFail($banner);
        $model->update($this->validateBanner($request));

        return $this->state($branch);
    }

    public function destroyBanner(Request $request, int $banner): JsonResponse
    {
        $branch = $this->branch($request);
        StorefrontBanner::query()->where('branch_id', $branch)->findOrFail($banner)->delete();

        return $this->state($branch);
    }

    public function publish(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        // [RED-W2a P2-2 2026-11-03] `base_version` n'empêche que deux PUBLICATIONS simultanées. Le brouillon est partagé : sans
        // empreinte, un administrateur publiait les ajouts d'un autre sans les avoir vus. L'écran renvoie l'empreinte du
        // brouillon qu'il affiche (`draft_hash` de l'état) ; le service la revérifie sous verrou → 409 si elle a changé.
        $data = Validator::make($request->all(), [
            'base_version' => ['required', 'integer', 'min:0'],
            'draft_hash' => ['required', 'string', 'size:40'],
        ], $this->messages(), $this->attributes())->validate();
        $this->storefront->publish($branch, (int) $request->user()->id, (int) $data['base_version'], (string) $data['draft_hash']);

        return $this->state($branch);
    }

    public function rollback(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        $data = Validator::make($request->all(), [
            'base_version' => ['required', 'integer', 'min:0'],
            'version' => ['nullable', 'integer', 'min:1'],
        ], $this->messages())->validate();
        $this->storefront->rollback($branch, (int) $request->user()->id, (int) $data['base_version'], isset($data['version']) ? (int) $data['version'] : null);

        return $this->state($branch);
    }

    // ------------------------------------------------------------------ interne

    private function branch(Request $request): int
    {
        return $this->storefront->branchForUser($request->user());
    }

    private function validateBanner(Request $request): array
    {
        $routes = implode('|', array_map('preg_quote', array_keys((array) config('storefront.link_routes'))));
        $data = Validator::make($request->all(), [
            'type' => ['required', 'in:info,flash'],
            'title' => ['required', 'string', 'max:80', $this->noTags()],
            'body' => ['nullable', 'string', 'max:240', $this->noTags()],
            'button_label' => ['nullable', 'string', 'max:30', 'required_with:button_link', $this->noTags()],
            'button_link' => ['nullable', 'string', 'max:120', 'required_with:button_label', function ($attr, $value, $fail) use ($routes) {
                if ($value !== null && ! preg_match('~^#(?:(?:'.$routes.')|menu/[A-Za-z0-9][A-Za-z0-9_-]{0,80})$~', (string) $value)) {
                    $fail('Le lien doit mener à un écran interne de l\'application : #menu, #loyalty, #home ou #menu/<produit>. Aucune adresse web.');
                }
            }],
            'coupon_code' => ['nullable', 'string', 'max:40', function ($attr, $value, $fail) use ($request) {
                if ($value === null) {
                    return;
                }
                $coupon = Coupon::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim((string) $value))])->first();
                if (! $coupon) {
                    $fail('Ce code promo n\'existe pas. Créez-le d\'abord dans « Coupons », puis revenez.');

                    return;
                }
                // [RED-W2a P3 2026-11-03] Un code coupé ou hors de ses dates ne doit pas être promis dans l'application.
                $now = Carbon::now();
                if ((int) $coupon->status !== \App\Enums\Status::ACTIVE
                    || ($coupon->start_date && Carbon::parse($coupon->start_date)->greaterThan($now))
                    || ($coupon->end_date && Carbon::parse($coupon->end_date)->lessThan($now))) {
                    $fail('Ce code promo n\'est pas utilisable aujourd\'hui (désactivé ou hors de ses dates dans « Coupons »).');

                    return;
                }
                // [W6 cycle D · D1] Un code réservé à un autre canal (borne, caisse…) ou à une autre branche ne sera JAMAIS
                // accepté au paiement de l'application (Coupon::isUsableNow($branch, 'web')) : ne pas le laisser promettre.
                // Les jours / heures ne sont pas exigés ici (une offre du soir se prépare le matin) : la vitrine publique
                // ne sert le code que pendant sa fenêtre.
                $surfaces = is_array($coupon->surfaces) ? array_map('strtolower', $coupon->surfaces) : [];
                $scope = is_array($coupon->branch_scope) ? array_map('intval', $coupon->branch_scope) : [];
                if (($surfaces && ! in_array('web', $surfaces, true)) || ($scope && ! in_array($this->branch($request), $scope, true))) {
                    $fail('Ce code promo est réservé à un autre canal ou à un autre restaurant dans « Coupons » : l\'application ne pourra pas l\'accepter.');
                }
            }],
            // [RED-W2a P2-1 2026-11-03] `bail` : une date illisible s'arrête au 422 de la règle `date`, sans atteindre l'analyse
            // ci-dessous (qui levait une exception → 500). L'analyse reste en plus protégée.
            'starts_at' => ['nullable', 'bail', 'date'],
            'ends_at' => ['nullable', 'bail', 'date', 'required_if:type,flash', function ($attr, $value, $fail) use ($request) {
                if ($value === null) {
                    return;
                }
                try {
                    $end = Carbon::parse($value, StorefrontService::TZ);
                    $start = $request->input('starts_at') ? Carbon::parse($request->input('starts_at'), StorefrontService::TZ) : null;
                } catch (\Throwable) {
                    $fail('Date illisible : utilisez le sélecteur de date.');

                    return;
                }
                // [RED-W2a P3] Un bandeau terminé doit rester DÉSACTIVABLE : la fin passée n'est refusée que s'il reste actif.
                $actif = ! $request->has('active') || filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
                if ($start && $end->lessThanOrEqualTo($start)) {
                    $fail('La fin doit être après le début.');
                } elseif ($actif && $end->lessThanOrEqualTo(Carbon::now())) {
                    $fail('La date de fin est déjà passée : ce bandeau ne s\'afficherait jamais.');
                }
            }],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'active' => ['nullable', 'boolean'],
        ], $this->messages(), $this->attributes())->validate();

        $tz = config('app.timezone');

        return [
            'type' => $data['type'],
            'title' => $this->clean($data['title']),
            'body' => $this->clean($data['body'] ?? null),
            'button_label' => $this->clean($data['button_label'] ?? null),
            'button_link' => $this->clean($data['button_link'] ?? null),
            'coupon_code' => isset($data['coupon_code']) ? mb_strtoupper(trim($data['coupon_code'])) : null,
            'starts_at' => ! empty($data['starts_at']) ? Carbon::parse($data['starts_at'], StorefrontService::TZ)->setTimezone($tz) : null,
            'ends_at' => ! empty($data['ends_at']) ? Carbon::parse($data['ends_at'], StorefrontService::TZ)->setTimezone($tz) : null,
            'priority' => (int) ($data['priority'] ?? 0),
            'active' => array_key_exists('active', $data) && $data['active'] !== null ? (bool) $data['active'] : true,
        ];
    }

    private function state(int $branch, int $status = 200): JsonResponse
    {
        $s = $this->storefront;
        $settings = $s->settings($branch);
        $published = $s->published($branch);
        $draft = $s->draftSnapshot($branch);
        $now = Carbon::now(StorefrontService::TZ);
        $names = User::query()->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->whereIn('id', \App\Models\StorefrontVersion::query()->where('branch_id', $branch)->pluck('published_by')->filter()->unique())->pluck('name', 'id');

        $banners = collect($draft['banners'])->sortBy([['priority', 'desc'], ['id', 'asc']])->values()->map(function ($b) use ($now) {
            $start = $b['starts_at'] ? Carbon::parse($b['starts_at']) : null;
            $end = $b['ends_at'] ? Carbon::parse($b['ends_at']) : null;
            $b['state'] = ! $b['active'] ? 'off' : ($end && $end->lessThanOrEqualTo($now) ? 'ended' : ($start && $start->greaterThan($now) ? 'scheduled' : 'live'));

            return $b;
        })->all();

        return response()->json([
            'status' => true,
            'data' => [
                'draft' => [
                    'enabled' => $draft['enabled'],
                    'theme' => $settings?->theme ? $s->normalizeTheme($settings->theme) : null,
                    'delivery_on_message' => $draft['delivery_on_message'],
                    'delivery_off_message' => $draft['delivery_off_message'],
                ],
                'banners' => $banners,
                'published' => [
                    'version' => (int) ($published?->version ?? 0),
                    'published_at' => $s->iso($published?->published_at),
                    'published_by' => $published?->published_by ? ($names[$published->published_by] ?? null) : null,
                ],
                'history' => \App\Models\StorefrontVersion::query()->where('branch_id', $branch)->orderByDesc('version')
                    ->limit((int) config('storefront.history_shown', 15))->get()
                    ->map(fn ($v) => [
                        'version' => $v->version,
                        'action' => $v->action,
                        'source_version' => $v->source_version,
                        'published_at' => $s->iso($v->published_at),
                        'published_by' => $v->published_by ? ($names[$v->published_by] ?? null) : null,
                        'banners' => count($v->snapshot['banners'] ?? []),
                        'enabled' => (bool) ($v->snapshot['enabled'] ?? false),
                    ])->values()->all(),
                'has_unpublished_changes' => $s->hasUnpublishedChanges($branch),
                // [RED-W2a P2-2] Empreinte du brouillon affiché : à renvoyer telle quelle avec « Publier ».
                'draft_hash' => $s->draftHash($branch),
                'preview' => $s->payloadFromSnapshot($draft, (int) ($published?->version ?? 0), $published?->published_at, null, $branch),
                'delivery' => ['enabled' => $this->delivery->isEnabled()],
                'meta' => [
                    'tokens' => collect((array) config('storefront.theme_tokens'))->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
                    'contrast_pairs' => (array) config('storefront.contrast_pairs'),
                    'min_contrast' => (float) config('storefront.min_contrast', 4.5),
                    'default_theme' => (array) config('storefront.default_theme'),
                    'link_routes' => collect((array) config('storefront.link_routes'))->map(fn ($label, $route) => ['route' => $route, 'link' => '#'.$route, 'label' => $label])->values()->all(),
                    'max_banners' => (int) config('storefront.max_banners', 20),
                    'delivery_messages' => (array) config('storefront.delivery_messages'),
                    'timezone' => StorefrontService::TZ,
                ],
            ],
        ], $status);
    }

    private function noTags(): \Closure
    {
        return function ($attr, $value, $fail) {
            if (is_string($value) && preg_match('/[<>]/', $value)) {
                $fail('Les balises (< et >) ne sont pas acceptées : écrivez du texte simple.');
            }
        };
    }

    private function clean(?string $v): ?string
    {
        $v = $v === null ? null : trim($v);

        return $v === '' ? null : $v;
    }

    private function messages(): array
    {
        return [
            'required' => ':attribute est obligatoire.',
            'button_link.required_with' => 'Un bouton a besoin d\'un lien vers un écran de l\'application.',
            'button_label.required_with' => 'Un lien a besoin d\'un libellé de bouton.',
            'required_if' => 'Une offre flash doit avoir une date de fin (le compte à rebours en dépend).',
            'max' => ':attribute : :max caractères au plus.',
            'priority.max' => 'La priorité va de 0 à 100.',
            'priority.min' => 'La priorité va de 0 à 100.',
            'min' => ':attribute doit valoir au moins :min.',
            'in' => 'Type de bandeau inconnu : « info » ou « flash ».',
            'boolean' => ':attribute doit être oui ou non.',
            'date' => ':attribute n\'est pas une date valide.',
            'integer' => ':attribute doit être un nombre entier.',
            'string' => ':attribute doit être un texte.',
            'draft_hash.required' => 'Rechargez la vitrine avant de publier (l\'écran n\'a pas envoyé l\'état du brouillon qu\'il affiche).',
            'draft_hash.size' => 'Rechargez la vitrine avant de publier (état du brouillon illisible).',
        ];
    }

    private function attributes(): array
    {
        return [
            'title' => 'Le titre', 'body' => 'Le texte', 'button_label' => 'Le libellé du bouton', 'button_link' => 'Le lien du bouton',
            'coupon_code' => 'Le code promo', 'starts_at' => 'Le début', 'ends_at' => 'La fin', 'priority' => 'La priorité',
            'active' => 'Actif', 'type' => 'Le type', 'enabled' => 'Vitrine active', 'base_version' => 'La version de départ',
            'delivery_on_message' => 'Le message « livraison active »', 'delivery_off_message' => 'Le message « livraison suspendue »',
        ];
    }
}
