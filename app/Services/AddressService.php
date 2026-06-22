<?php

namespace App\Services;


use Exception;
use App\Models\Address;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\AddressRequest;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;

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

            return Address::where('user_id', auth()->user()->id)->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->addressFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method(
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
            // [Sprint 2B / DEL-1] Derive geocode_status from the supplied lat/lng pair
            // so new writes never produce NULL rows that subsequently bypass the
            // DeliveryQuoteService geocode gate.
            $payload['geocode_status'] = self::deriveGeocodeStatus(
                $payload['latitude'] ?? null,
                $payload['longitude'] ?? null
            );

            return Address::create($payload);
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
            $payload = $request->validated();
            $payload['geocode_status'] = self::deriveGeocodeStatus(
                $payload['latitude'] ?? null,
                $payload['longitude'] ?? null
            );

            return tap($address)->update($payload);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
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
     * @throws Exception
     */
    public function destroy(Address $address): void
    {
        try {
            $address->delete();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
