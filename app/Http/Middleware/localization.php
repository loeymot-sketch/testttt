<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * [PHASE-37] Enhanced localization middleware.
 *
 * Supports multiple detection methods:
 * 1. Header x-localization (legacy, for backward compatibility)
 * 2. Query parameter ?locale=xx
 * 3. Accept-Language header (standard HTTP)
 * 4. Default: 'fr' (FoodKing default)
 *
 * Supported locales: fr, en, ar
 */
class localization
{
    /** Supported locales */
    protected array $supported = ['fr', 'en', 'ar'];

    /** Default locale */
    protected string $default = 'fr';

    public function handle(Request $request, Closure $next)
    {
        $locale = $this->detectLocale($request);
        App::setLocale($locale);

        return $next($request);
    }

    /**
     * Detect locale from request using multiple methods.
     */
    protected function detectLocale(Request $request): string
    {
        // 1. Legacy header x-localization (backward compatibility)
        $headerLocale = $request->header('x-localization');
        if ($headerLocale && in_array($headerLocale, $this->supported, true)) {
            return $headerLocale;
        }

        // 2. Query parameter ?locale=xx
        $queryLocale = $request->query('locale');
        if ($queryLocale && in_array($queryLocale, $this->supported, true)) {
            return $queryLocale;
        }

        // 3. Accept-Language header (standard HTTP)
        $acceptLanguage = $request->header('Accept-Language');
        if ($acceptLanguage) {
            $parsedLocale = $this->parseAcceptLanguage($acceptLanguage);
            if ($parsedLocale) {
                return $parsedLocale;
            }
        }

        // 4. Default
        return $this->default;
    }

    /**
     * Parse Accept-Language header and return best matching locale.
     */
    protected function parseAcceptLanguage(string $header): ?string
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
            
            // Normalize language code
            $lang = str_replace('_', '-', $lang);
            $baseLang = explode('-', $lang)[0]; // Get base language (fr from fr-FR)
            
            $locales[$baseLang] = max($locales[$baseLang] ?? 0, $quality);
        }

        // Sort by quality descending
        arsort($locales);

        // Return first supported locale
        foreach (array_keys($locales) as $locale) {
            if (in_array($locale, $this->supported, true)) {
                return $locale;
            }
        }

        return null;
    }
}
