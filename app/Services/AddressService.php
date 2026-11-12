<?php

namespace App\Services;


use Exception;
use App\Models\Address;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\AddressRequest;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Services\Delivery\AddressNormalizer;

class AddressService
{

    public $addressFilter = ['label', 'address', 'apartment', 'latitude', 'longitude'];

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            $query = Address::where('user_id', auth()->user()->id)->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->addressFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }
                }
            });
            // [PROFIL APP 2026-11-12] Sans tri demandé, l'adresse PAR DÉFAUT vient en tête (carnet du profil, choix au paiement).
            if (! $request->filled('order_column') && self::aLaColonneParDefaut()) {
                $query->orderByDesc('is_default');
            }

            return $query->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }

    }

    /**
     * @throws Exception
     */
    public function store(AddressRequest $request)
    {
        try {
            $payload = $request->validated() + ['user_id' => Auth::user()->id];
            // [LIVRAISON 2026-10-20] Géocodage SERVEUR : position + commune viennent de la Base
            // Adresse Nationale, jamais du navigateur. Une panne du fournisseur refuse la sauvegarde.
            $payload = $this->applyServerGeocoding($payload);

            // « Enregistrée une seule fois » : même point + même complément = même adresse.
            $existing = $this->findSamePlace((int) $payload['user_id'], $payload);
            if ($existing) {
                return $existing;
            }

            $address = Address::create($payload);
            // [PROFIL APP 2026-11-12] La PREMIÈRE adresse d'un client devient son adresse par défaut (il pourra en changer).
            if (self::aLaColonneParDefaut()
                && ! Address::where('user_id', $address->user_id)->where('is_default', true)->exists()) {
                $address->forceFill(['is_default' => true])->save();
            }

            return $address;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(AddressRequest $request, Address $address)
    {
        try {
            $valide = $request->validated();
            // [PROFIL APP 2026-11-12] Renommer (« Maison », « Travail »…) sans toucher au texte de l'adresse ni au complément
            // ne rappelle PAS le géocodeur : la position déjà chiffrée reste celle facturée, et une panne du service
            // d'adresses n'empêche pas de renommer. Les coordonnées éventuellement envoyées par le client sont ignorées.
            $memeAdresse = trim((string) ($valide['address'] ?? '')) === trim((string) $address->address)
                && trim((string) ($valide['apartment'] ?? '')) === trim((string) $address->apartment);
            if ($memeAdresse && $address->latitude !== null && $address->latitude !== '') {
                unset($valide['latitude'], $valide['longitude'], $valide['city'], $valide['postal_code']);

                return tap($address)->update($valide);
            }

            $payload = $this->applyServerGeocoding($valide);

            return tap($address)->update($payload);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function applyServerGeocoding(array $payload): array
    {
        return app(AddressNormalizer::class)->normalize($payload);
    }

    /** @param array<string, mixed> $payload */
    private function findSamePlace(int $userId, array $payload): ?Address
    {
        return app(AddressNormalizer::class)->findSamePlace($userId, $payload);
    }

    /**
     * [Sprint 2B / DEL-1] Derive the `geocode_status` value to persist from
     * the lat/lng pair supplied by the autocomplete frontend.
     *
     * The Google Maps autocomplete callback is the ground truth in production —
     * it returns a `status` field (OK / PARTIAL / ZERO_RESULTS / ERROR).
     * Until the frontend pipes that status through to the AddressRequest
     * payload, we infer the closest equivalent from the lat/lng pair:
     *   - both coordinates parse as finite floats in valid ranges → OK
     *     (treat as if Google returned status=OK)
     *   - either coordinate missing or out-of-range → PARTIAL
     *     (matches Google's "PARTIAL_MATCH" semantics)
     *
     * NULL is reserved for legacy rows. New writes never store NULL.
     */
    public static function deriveGeocodeStatus(mixed $latitude, mixed $longitude): string
    {
        if ($latitude === null || $latitude === '' || $longitude === null || $longitude === '') {
            return 'PARTIAL';
        }

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return 'PARTIAL';
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;
        if (! is_finite($lat) || ! is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return 'PARTIAL';
        }

        return 'OK';
    }

    /**
     * [PROFIL APP 2026-11-12] Choisit l'adresse par défaut du client : une seule à la fois, dans une transaction (deux
     * appuis rapprochés ne laissent jamais deux adresses « par défaut »). La propriété est vérifiée par le contrôleur.
     *
     * @throws Exception
     */
    public function setDefault(Address $address): Address
    {
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($address) {
                Address::where('user_id', $address->user_id)->lockForUpdate()->get(['id']);
                Address::where('user_id', $address->user_id)->where('id', '!=', $address->id)->update(['is_default' => false]);
                $address->forceFill(['is_default' => true])->save();
            });

            return $address->refresh();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /** [PROFIL APP 2026-11-12] Vrai quand la migration `is_default` est passée (serveur pas encore migré : rien ne casse). */
    public static function aLaColonneParDefaut(): bool
    {
        static $connu = null;
        if ($connu === null) {
            try {
                $connu = \Illuminate\Support\Facades\Schema::hasColumn('addresses', 'is_default');
            } catch (\Throwable) {
                $connu = false;
            }
        }

        return $connu;
    }

    /**
     * @throws Exception
     */
    public function destroy(Address $address): void
    {
        try {
            $etaitParDefaut = self::aLaColonneParDefaut() && (bool) $address->is_default;
            $userId = (int) $address->user_id;
            $address->delete();
            // [PROFIL APP 2026-11-12] L'adresse par défaut supprimée : la plus récente qui reste prend le relais.
            if ($etaitParDefaut) {
                $suivante = Address::where('user_id', $userId)->orderByDesc('id')->first();
                if ($suivante) {
                    $suivante->forceFill(['is_default' => true])->save();
                }
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
