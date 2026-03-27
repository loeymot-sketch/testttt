<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * [PHASE-37] Set application locale based on Accept-Language header or query parameter.
 *
 * Supports: fr, en, ar
 * Priority: 1. Query param ?locale=xx, 2. Accept-Language header, 3. Default 'fr'
 */
class SetLocale
{
    /** Supported locales */
    protected array $supported = ['fr', 'en', 'ar'];

    /** Default locale */
    protected string $default = 'fr';

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $locale = $this->detectLocale($request);

        App::setLocale($locale);

        // Log for debugging in non-production
        if (!App::environment('production')) {
            Log::debug('[SetLocale] Locale set: ' . $locale);
        }

        return $next($request);
    }

    /**
     * Detect locale from request.
     */
    protected function detectLocale($request): string
    {
        // 1. Check query parameter (highest priority for explicit selection)
        $queryLocale = $request->query('locale');
        if ($queryLocale && in_array($queryLocale, $this->supported, true)) {
            return $queryLocale;
        }

        // 2. Check Accept-Language header
        $acceptLanguage = $request->header('Accept-Language');
        if ($acceptLanguage) {
            // Parse header (e.g., "fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7")
            $locales = $this->parseAcceptLanguage($acceptLanguage);
            foreach ($locales as $locale) {
                $lang = explode('_', $locale)[0]; // Get base language (fr from fr_FR)
                if (in_array($lang, $this->supported, true)) {
                    return $lang;
                }
            }
        }

        // 3. Default
        return $this->default;
    }

    /**
     * Parse Accept-Language header into ordered array.
     */
    protected function parseAcceptLanguage(string $header): array
    {
        $locales = [];
        $parts = explode(',', $header);

        foreach ($parts as $part) {
            $part = trim($part);
            // Check for quality value (e.g., "en;q=0.8")
            if (strpos($part, ';') !== false) {
                [$lang, $q] = explode(';', $part, 2);
                $quality = (float) str_replace('q=', '', $q);
            } else {
                $lang = $part;
                $quality = 1.0;
            }
            $locales[$lang] = $quality;
        }

        // Sort by quality descending
        arsort($locales);

        return array_keys($locales);
    }
}
