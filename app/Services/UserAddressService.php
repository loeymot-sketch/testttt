<?php

namespace App\Services;

use Exception;
use App\Models\User;
use App\Models\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;

class UserAddressService
{
    /**
     * @throws Exception
     */
    public $address;
    public $addressFilter = ['label', 'address', 'apartment', 'latitude', 'longitude'];

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request, User $user)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            return Address::where('user_id', $user->id)->where(function ($query) use ($requests) {
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
    public function store($request, User $user): Address
    {
        try {
            DB::transaction(function () use ($request, $user) {
                $payload = $request->validated() + ['user_id' => $user->id];
                // [Sprint 2B / DEL-1] Mirror AddressService::store — derive geocode_status
                // from the supplied lat/lng so the admin-side address writer never
                // produces NULL rows that subsequently bypass the DeliveryQuoteService
                // geocode gate.
                $payload['geocode_status'] = AddressService::deriveGeocodeStatus(
                    $payload['latitude'] ?? null,
                    $payload['longitude'] ?? null
                );
                $this->address = Address::create($payload);
            });
            return $this->address;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update($request, User $user, Address $address)
    {
        try {
            if ($user->id == $address->user_id) {
                $payload = $request->validated();
                $payload['geocode_status'] = AddressService::deriveGeocodeStatus(
                    $payload['latitude'] ?? null,
                    $payload['longitude'] ?? null
                );
                return tap($address)->update($payload);
            } else {
                throw new Exception(trans('all.user_match'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(User $user, Address $address): void
    {
        try {
            if ($user->id == $address->user_id) {
                $address->delete();
            } else {
                throw new Exception(trans('all.user_match'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(User $user, Address $address): Address
    {
        try {
            if ($user->id == $address->user_id) {
                return $address;
            } else {
                throw new Exception(trans('all.user_match'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}