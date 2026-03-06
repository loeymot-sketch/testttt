<?php

namespace App\Services;

use Exception;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use App\Libraries\QueryExceptionLibrary;

class TimezoneService
{
    /**
     * @throws Exception
     */
    public function list()
    {
        try {
            $timezones     = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
            $timezoneArray = [];
            $i             = 1;
            foreach ($timezones as $timezone) {
                $timezoneArray[] = (object)[
                    'id'   => $i,
                    'name' => $timezone
                ];
                $i++;
            }
            return collect($timezoneArray);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
