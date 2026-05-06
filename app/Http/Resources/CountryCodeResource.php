<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;
use Throwable;
use Traversable;

class CountryCodeResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $payload = $this->normalizeCountryPayload($this->resource);

        return [
            'calling_code' => $this->firstCallingCode($payload),
            'flag_emoji' => $this->stringAt($this->flagArray($payload), 'emoji'),
            'flag_svg' => $this->stringAt($this->flagArray($payload), 'svg'),
            'flag_svg_path' => $this->stringAt($this->flagArray($payload), 'svg_path'),
            'capital' => (string) ($payload['capital_rinvex'] ?? $payload['capital'] ?? ''),
            'nationality' => (string) ($payload['demonym'] ?? $payload['nationality'] ?? ''),
        ];
    }

    /**
     * PragmaRX Countries renvoie souvent des Coollection : l'accès direct à {@code calling_codes}
     * peut lever « Property [calling_codes] does not exist on this collection instance ».
     * On normalise en tableau via JSON pour des lectures stables.
     *
     * @param  mixed  $resource
     * @return array<string, mixed>
     */
    private function normalizeCountryPayload($resource): array
    {
        if ($resource === null) {
            return [];
        }

        if (is_array($resource)) {
            return $resource;
        }

        if ($resource instanceof JsonSerializable) {
            try {
                $decoded = json_decode(json_encode($resource->jsonSerialize(), JSON_THROW_ON_ERROR), true);

                return is_array($decoded) ? $decoded : [];
            } catch (Throwable) {
                return [];
            }
        }

        try {
            $decoded = json_decode(json_encode($resource, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function flagArray(array $payload): array
    {
        $flag = $payload['flag'] ?? null;
        if (is_array($flag)) {
            return $flag;
        }

        return [];
    }

    private function stringAt(array $arr, string $key): string
    {
        $v = $arr[$key] ?? '';

        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function firstCallingCode(array $payload): string
    {
        $codes = $payload['calling_codes'] ?? null;

        if ($codes instanceof Traversable && ! is_string($codes)) {
            $codes = iterator_to_array($codes, false);
        }

        if ($codes instanceof JsonSerializable) {
            try {
                $codes = json_decode(json_encode($codes->jsonSerialize(), JSON_THROW_ON_ERROR), true);
            } catch (Throwable) {
                $codes = [];
            }
        }

        if (! is_array($codes)) {
            return '+33';
        }

        $first = $codes[0] ?? '+33';
        $first = is_scalar($first) ? (string) $first : '+33';

        return $first === '+1201' ? '+1' : $first;
    }
}
