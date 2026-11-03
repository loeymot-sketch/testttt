<?php

namespace App\Services\Storefront;

use App\Exceptions\Storefront\StorefrontConflictException;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Scopes\BranchScope;
use App\Models\StorefrontBanner;
use App\Models\StorefrontSetting;
use App\Models\StorefrontVersion;
use App\Models\User;
use App\Services\Delivery\DeliveryAvailability;
use App\Services\Fiscal\AuditLogService;
use App\Support\Storefront\Contrast;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Smartisan\Settings\Facades\Settings;

/**
 * [APP HYBRIDE W2a · 2026-11-03] Vitrine distante : brouillon → publication versionnée → lecture publique.
 *
 * Une seule fonction construit ce que l'application reçoit (`payloadFromSnapshot`) : l'API publique l'appelle sur la
 * version publiée, l'écran d'administration l'appelle sur le brouillon pour son aperçu. L'aperçu ne peut donc pas
 * mentir sur ce que l'application affichera.
 */
class StorefrontService
{
    public const TZ = 'Europe/Paris';

    public function __construct(private readonly DeliveryAvailability $delivery)
    {
    }

    // ------------------------------------------------------------------ branche

    /** Branche servie au public : celle du site (`site_default_branch`), sinon la première branche. */
    public function branchForPublic(): int
    {
        try {
            $id = (int) Settings::group('site')->get('site_default_branch');
        } catch (\Throwable) {
            $id = 0;
        }
        if ($id > 0 && Branch::query()->whereKey($id)->exists()) {
            return $id;
        }

        return (int) (Branch::query()->orderBy('id')->value('id') ?? 1);
    }

    /** Branche éditée : celle du responsable ; un administrateur sans branche (0) édite la branche du site. */
    public function branchForUser(?User $user): int
    {
        $id = (int) ($user?->branch_id ?? 0);

        return $id > 0 ? $id : $this->branchForPublic();
    }

    // ------------------------------------------------------------------ lecture

    public function settings(int $branch): ?StorefrontSetting
    {
        return StorefrontSetting::query()->where('branch_id', $branch)->first();
    }

    public function banners(int $branch)
    {
        return StorefrontBanner::query()->where('branch_id', $branch)->orderBy('id')->get();
    }

    public function published(int $branch): ?StorefrontVersion
    {
        return StorefrontVersion::query()->where('branch_id', $branch)->orderByDesc('version')->first();
    }

    /** Le brouillon courant, sous la forme exacte d'un instantané publié. */
    public function draftSnapshot(int $branch): array
    {
        $s = $this->settings($branch);

        return [
            'enabled' => (bool) ($s?->enabled ?? false),
            'theme' => $s?->theme ? $this->normalizeTheme($s->theme) : null,
            'delivery_on_message' => $s?->delivery_on_message,
            'delivery_off_message' => $s?->delivery_off_message,
            'banners' => $this->banners($branch)->map(fn (StorefrontBanner $b) => $this->bannerSnapshot($b))->values()->all(),
        ];
    }

    public function bannerSnapshot(StorefrontBanner $b): array
    {
        return [
            'id' => $b->id,
            'type' => $b->type,
            'title' => $b->title,
            'body' => $b->body,
            'button_label' => $b->button_label,
            'button_link' => $b->button_link,
            'coupon_code' => $b->coupon_code,
            'starts_at' => $this->iso($b->starts_at),
            'ends_at' => $this->iso($b->ends_at),
            'priority' => (int) $b->priority,
            'active' => (bool) $b->active,
        ];
    }

    /** Ce que l'application reçoit. `$snapshot` null = rien de publié : vitrine éteinte, l'application garde son contenu embarqué. */
    public function payloadFromSnapshot(?array $snapshot, int $version, ?CarbonInterface $publishedAt, ?CarbonInterface $now = null): array
    {
        $now = $now ?? Carbon::now(self::TZ);
        $enabled = (bool) ($snapshot['enabled'] ?? false);
        $deliveryOn = $this->delivery->isEnabled();

        $live = [];
        $nextChange = null;
        if ($enabled) {
            foreach ($snapshot['banners'] ?? [] as $b) {
                if (empty($b['active'])) {
                    continue;
                }
                $start = $b['starts_at'] ? Carbon::parse($b['starts_at']) : null;
                $end = $b['ends_at'] ? Carbon::parse($b['ends_at']) : null;
                if ($end && $end->lessThanOrEqualTo($now)) {
                    continue; // terminé (fin exclue)
                }
                if ($start && $start->greaterThan($now)) {
                    $nextChange = $this->earliest($nextChange, $start); // à venir : l'application relira à temps
                    continue;
                }
                if ($end) {
                    $nextChange = $this->earliest($nextChange, $end);
                }
                $live[] = $b;
            }
            usort($live, fn ($a, $b) => [$b['priority'], $a['id']] <=> [$a['priority'], $b['id']]);
            $live = $this->withUsableCoupons($live, $now, $nextChange);
        }

        $custom = $enabled ? ($deliveryOn ? ($snapshot['delivery_on_message'] ?? null) : ($snapshot['delivery_off_message'] ?? null)) : null;

        return [
            'version' => $version,
            'enabled' => $enabled,
            'published_at' => $this->iso($publishedAt),
            'theme' => $enabled && ! empty($snapshot['theme']) ? $this->normalizeTheme($snapshot['theme']) : null,
            'banners' => array_map(fn ($b) => [
                'id' => (int) $b['id'],
                'type' => $b['type'],
                'title' => $b['title'],
                'body' => $b['body'],
                'button' => $b['button_label'] && $b['button_link'] ? ['label' => $b['button_label'], 'link' => $b['button_link']] : null,
                'coupon_code' => $b['coupon_code'],
                'starts_at' => $b['starts_at'],
                'ends_at' => $b['ends_at'],
                'priority' => (int) $b['priority'],
            ], $live),
            'delivery' => [
                'enabled' => $deliveryOn,
                'message' => $custom ?: (string) config('storefront.delivery_messages.'.($deliveryOn ? 'on' : 'off')),
                // [RED cycle 3 · P2-4] vrai si le message a été SAISI en admin : l'application ne remplace son texte d'origine
                // (« La livraison est lancée ! … ») que par un message choisi, jamais par le message générique.
                'custom' => (bool) $custom,
            ],
            'next_change_at' => $this->iso($nextChange),
        ];
    }

    /**
     * [W6 cycle C · C2 2026-11-03] Le code promo d'un bandeau n'est servi que si le coupon est utilisable MAINTENANT (même
     * règle que la saisie admin : actif, dans ses dates). Avant, la validité n'était vérifiée qu'à l'enregistrement : un coupon
     * coupé ou expiré ensuite restait promis « pré-rempli au paiement » par l'application. Le bandeau reste, sans le code.
     * Les bornes du coupon entrent dans `next_change_at` pour que l'application relise au bon moment.
     */
    private function withUsableCoupons(array $live, CarbonInterface $now, ?CarbonInterface &$nextChange): array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            fn ($b) => ! empty($b['coupon_code']) ? mb_strtoupper(trim((string) $b['coupon_code'])) : null,
            $live
        ))));
        if (! $codes) {
            return $live;
        }
        $usable = [];
        foreach (Coupon::query()->whereIn(DB::raw('UPPER(code)'), $codes)->get(['code', 'status', 'start_date', 'end_date']) as $c) {
            $start = $c->start_date ? Carbon::parse($c->start_date) : null;
            $end = $c->end_date ? Carbon::parse($c->end_date) : null;
            if ((int) $c->status !== Status::ACTIVE || ($end && $end->lessThan($now))) {
                continue;
            }
            if ($start && $start->greaterThan($now)) {
                $nextChange = $this->earliest($nextChange, $start);
                continue;
            }
            if ($end) {
                $nextChange = $this->earliest($nextChange, $end);
            }
            $usable[mb_strtoupper((string) $c->code)] = true;
        }

        return array_map(function ($b) use ($usable) {
            if (! empty($b['coupon_code']) && ! isset($usable[mb_strtoupper(trim((string) $b['coupon_code']))])) {
                $b['coupon_code'] = null;
            }

            return $b;
        }, $live);
    }

    /**
     * Lecture PUBLIQUE : sans BranchScope, branche explicite (même règle que les recherches pré-authentification).
     * Le site et l'application envoient parfois le jeton d'un client connecté : avec le scope, un compte rattaché à une
     * autre branche recevrait une vitrine vide (constaté par StorefrontAdminTest::test_isolation_par_branche).
     */
    public function publicPayload(int $branch): array
    {
        $v = StorefrontVersion::query()->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branch)->orderByDesc('version')->first();

        return $this->payloadFromSnapshot($v?->snapshot, (int) ($v?->version ?? 0), $v?->published_at);
    }

    public function hasUnpublishedChanges(int $branch): bool
    {
        $published = $this->published($branch)?->snapshot ?? ['enabled' => false, 'theme' => null, 'delivery_on_message' => null, 'delivery_off_message' => null, 'banners' => []];

        return $this->comparable($this->draftSnapshot($branch)) !== $this->comparable($published);
    }

    /**
     * [RED-W2a P2-2 2026-11-03] Empreinte du brouillon (même forme normalisée que `hasUnpublishedChanges`, insensible à
     * l'ordre des clés JSON de MySQL). L'écran la reçoit avec l'état et la renvoie à « Publier ».
     */
    public function draftHash(int $branch): string
    {
        return sha1((string) json_encode($this->comparable($this->draftSnapshot($branch))));
    }

    // ------------------------------------------------------------------ thème

    /** @return array<string,string> erreurs en français, clé `theme` ou `theme.<jeton>` */
    public function themeErrors(mixed $theme): array
    {
        if ($theme === null) {
            return [];
        }
        $tokens = (array) config('storefront.theme_tokens');
        if (! is_array($theme)) {
            return ['theme' => 'Le thème doit être une liste de couleurs.'];
        }
        $errors = [];
        $unknown = array_diff(array_keys($theme), array_keys($tokens));
        if ($unknown) {
            $errors['theme'] = 'Jeton de couleur inconnu : '.implode(', ', $unknown).'. Seuls ces jetons sont acceptés : '.implode(', ', array_keys($tokens)).'.';
        }
        foreach ($tokens as $key => $label) {
            if (! array_key_exists($key, $theme) || $theme[$key] === null || $theme[$key] === '') {
                $errors["theme.{$key}"] = "« {$label} » est obligatoire.";
            } elseif (! Contrast::isHex($theme[$key])) {
                $errors["theme.{$key}"] = "« {$label} » doit être une couleur au format #RRGGBB (par exemple #FF5A1F).";
            }
        }
        $min = (float) config('storefront.min_contrast', 4.5);
        foreach ((array) config('storefront.contrast_pairs') as [$fg, $bg]) {
            if (isset($errors["theme.{$fg}"]) || isset($errors["theme.{$bg}"])) {
                continue;
            }
            $ratio = Contrast::ratio($theme[$fg], $theme[$bg]);
            if ($ratio < $min) {
                $errors["theme.{$fg}"] = sprintf(
                    'Contraste insuffisant entre « %s » (%s) et « %s » (%s) : %s. Il faut au moins %s pour que le texte reste lisible.',
                    $tokens[$fg], strtoupper($theme[$fg]), $tokens[$bg], strtoupper($theme[$bg]),
                    Contrast::format($ratio), number_format($min, 1, ',', '').':1'
                );
            }
        }
        // [RED cycle 3 · P2-3] couples contre une couleur FIXE des feuilles de style du site (config storefront.fixed_contrast)
        foreach ((array) config('storefront.fixed_contrast') as [$token, $fixe, $seuil, $phrase]) {
            if (isset($errors["theme.{$token}"]) || ! isset($theme[$token])) {
                continue;
            }
            $ratio = Contrast::ratio($theme[$token], $fixe);
            if ($ratio < (float) $seuil) {
                $errors["theme.{$token}"] = sprintf(
                    'Contraste insuffisant entre « %s » (%s) et %s (%s) : %s. Il faut au moins %s pour que le texte reste lisible.',
                    $tokens[$token], strtoupper($theme[$token]), $phrase, $fixe === '#FFFFFF' ? 'blanc' : 'noir',
                    Contrast::format($ratio), number_format((float) $seuil, 1, ',', '').':1'
                );
            }
        }

        return $errors;
    }

    public function normalizeTheme(array $theme): array
    {
        $out = [];
        foreach (array_keys((array) config('storefront.theme_tokens')) as $key) {
            if (isset($theme[$key]) && Contrast::isHex($theme[$key])) {
                $out[$key] = strtoupper($theme[$key]);
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ publication

    /**
     * Publie le brouillon : version +1, instantané gardé, trace d'audit.
     * `$baseVersion` = la version que l'écran avait sous les yeux ; si elle a changé entre-temps (double clic,
     * deuxième publication), rien n'est écrit (409). `$draftHash` (exigé par l'écran admin) = l'empreinte du BROUILLON
     * affiché : si un autre administrateur l'a modifié depuis, rien n'est écrit non plus (409) — on ne publie jamais
     * à l'aveugle ce qu'on n'a pas vu.
     */
    public function publish(int $branch, ?int $userId, int $baseVersion, ?string $draftHash = null): StorefrontVersion
    {
        return $this->writeVersion($branch, $userId, $baseVersion, 'publish', null, $draftHash);
    }

    /** Republie l'instantané `$target` (par défaut : la version juste avant la courante) sous un NOUVEAU numéro, et remet le brouillon dessus. */
    public function rollback(int $branch, ?int $userId, int $baseVersion, ?int $target = null): StorefrontVersion
    {
        $current = (int) ($this->settings($branch)?->version ?? 0);
        $target = $target ?? $current - 1;
        $source = $target > 0 && $target !== $current
            ? StorefrontVersion::query()->where('branch_id', $branch)->where('version', $target)->first()
            : null;
        if (! $source) {
            throw ValidationException::withMessages(['version' => 'Aucune version précédente à republier.'])->status(422);
        }

        return $this->writeVersion($branch, $userId, $baseVersion, 'rollback', $source);
    }

    private function writeVersion(int $branch, ?int $userId, int $baseVersion, string $action, ?StorefrontVersion $source, ?string $draftHash = null): StorefrontVersion
    {
        $before = $this->published($branch)?->snapshot;

        $version = DB::transaction(function () use ($branch, $userId, $baseVersion, $action, $source, $draftHash) {
            $settings = StorefrontSetting::query()->where('branch_id', $branch)->lockForUpdate()->first()
                ?? StorefrontSetting::query()->create(['branch_id' => $branch]);
            $current = (int) $settings->version;
            if ($current !== $baseVersion) {
                throw new StorefrontConflictException($current);
            }
            if ($draftHash !== null && ! hash_equals($this->draftHash($branch), $draftHash)) {
                throw new StorefrontConflictException($current, true);
            }

            if ($source) {
                $this->restoreDraft($settings, $source->snapshot);
            }

            $snapshot = $this->draftSnapshot($branch);
            $errors = $this->themeErrors($snapshot['theme'] === null ? null : $settings->theme);
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $now = Carbon::now();
            $v = StorefrontVersion::query()->create([
                'branch_id' => $branch,
                'version' => $current + 1,
                'action' => $action,
                'source_version' => $source?->version,
                'snapshot' => $snapshot,
                'published_by' => $userId,
                'published_at' => $now,
            ]);
            $settings->forceFill(['version' => $current + 1, 'published_by' => $userId, 'published_at' => $now])->save();

            return $v;
        });

        $this->audit($branch, $userId, $action, $version, $before, $source);

        return $version;
    }

    private function restoreDraft(StorefrontSetting $settings, array $snapshot): void
    {
        $settings->forceFill([
            'enabled' => (bool) ($snapshot['enabled'] ?? false),
            'theme' => $snapshot['theme'] ?? null,
            'delivery_on_message' => $snapshot['delivery_on_message'] ?? null,
            'delivery_off_message' => $snapshot['delivery_off_message'] ?? null,
        ])->save();

        StorefrontBanner::query()->where('branch_id', $settings->branch_id)->get()->each->delete(); // suppression douce : rien ne disparaît
        foreach ($snapshot['banners'] ?? [] as $b) {
            StorefrontBanner::query()->create([
                'branch_id' => $settings->branch_id,
                'type' => $b['type'],
                'title' => $b['title'],
                'body' => $b['body'],
                'button_label' => $b['button_label'],
                'button_link' => $b['button_link'],
                'coupon_code' => $b['coupon_code'],
                'starts_at' => $b['starts_at'] ? Carbon::parse($b['starts_at'])->setTimezone(config('app.timezone')) : null,
                'ends_at' => $b['ends_at'] ? Carbon::parse($b['ends_at'])->setTimezone(config('app.timezone')) : null,
                'priority' => (int) $b['priority'],
                'active' => (bool) $b['active'],
            ]);
        }
    }

    /** Journal d'audit chaîné EXISTANT, par son API publique (AuditLogService gelé, non modifié). */
    private function audit(int $branch, ?int $userId, string $action, StorefrontVersion $v, ?array $before, ?StorefrontVersion $source): void
    {
        try {
            $request = request();
            app(AuditLogService::class)->write([
                'branch_id' => $branch,
                'user_id' => (int) $userId,
                'action' => $action === 'rollback' ? 'storefront.rolled_back' : 'storefront.published',
                'resource' => 'storefront',
                'resource_id' => $v->version,
                'payload' => array_filter([
                    'from_version' => $v->version - 1,
                    'to_version' => $v->version,
                    'restored_version' => $source?->version,
                    'before' => $before,
                    'after' => $v->snapshot,
                ], fn ($value, $key) => $key !== 'restored_version' || $value !== null, ARRAY_FILTER_USE_BOTH),
                'ip' => (string) ($request?->ip() ?? ''),
                'user_agent' => substr((string) $request?->userAgent(), 0, 512),
            ]);
        } catch (\Throwable $e) {
            // La publication a eu lieu (et reste dans storefront_versions) ; une trace manquante est signalée, jamais avalée.
            Log::error('storefront.audit_failed', ['branch_id' => $branch, 'version' => $v->version, 'error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ outils

    public function iso(?CarbonInterface $at): ?string
    {
        return $at ? $at->copy()->setTimezone(self::TZ)->toIso8601String() : null;
    }

    private function earliest(?CarbonInterface $a, CarbonInterface $b): CarbonInterface
    {
        return $a && $a->lessThanOrEqualTo($b) ? $a : $b;
    }

    private function comparable(array $snapshot): string
    {
        $snapshot['banners'] = array_map(function ($b) {
            unset($b['id']);

            return $b;
        }, $snapshot['banners'] ?? []);
        $snapshot['theme'] = $snapshot['theme'] ? $this->normalizeTheme($snapshot['theme']) : null;

        // Tri RÉCURSIF des clés : une colonne JSON MySQL réordonne les clés des objets à l'écriture (longueur puis alphabet).
        // Sans ce tri, la comparaison disait « modifications non publiées » juste après une publication (constaté en navigateur).
        return json_encode($this->sortKeysDeep($snapshot));
    }

    private function sortKeysDeep(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($v) => $this->sortKeysDeep($v), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
