<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;
use App\Http\Requests\SocialMediaRequest;

class SocialMediaService
{

    /**
     * @throws Exception
     */
    public function list()
    {
        try {
            return Settings::group('social_media')->all();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @param SocialMediaRequest $request
     * @return
     * @throws Exception
     */
    public function update(SocialMediaRequest $request)
    {
        try {
            Settings::group('social_media')->set($request->validated());
            return $this->list();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
