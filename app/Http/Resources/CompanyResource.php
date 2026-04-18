<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{

    public $info;

    public function __construct($info)
    {
        parent::__construct($info);
        $this->info = $info;
    }

    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request) : array
    {
        return [
            "company_name"         => $this->info['company_name'] ?? null,
            "company_email"        => $this->info['company_email'] ?? null,
            "company_phone"        => $this->info['company_phone'] ?? null,
            "company_website"      => $this->info['company_website'] ?? null,
            "company_city"         => $this->info['company_city'] ?? null,
            "company_state"        => $this->info['company_state'] ?? null,
            "company_country_code" => $this->info['company_country_code'] ?? null,
            "company_zip_code"     => $this->info['company_zip_code'] ?? null,
            "company_address"      => $this->info['company_address'] ?? null,
        ];
    }

}
