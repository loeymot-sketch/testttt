<?php

namespace App\Services;

use Smartisan\Settings\Facades\Settings;

class SettingService
{
    public function list() : array
    {
        // [W7-D D-005 2026-11-04] 10 groupes = 10 requêtes SQL à CHAQUE ouverture de l'app/du site/de la borne
        // (`SETTINGS_CACHE_ENABLED` est faux par défaut). Mémoire de 5 min versionnée par (nombre de lignes, dernière
        // modification) : une sauvegarde en admin (`updateOrInsert` pose `updated_at`) change la clé → relecture
        // immédiate, sinon 1 requête au lieu de 19. Jamais en tests (mémoire de processus partagée entre cas).
        if (app()->environment('testing')) {
            return $this->listeComplete();
        }
        $v = \Illuminate\Support\Facades\DB::table('settings')->selectRaw('count(*) as n, max(updated_at) as m')->first();
        $cle = 'frontend.settings.liste.v1:' . (int) ($v->n ?? 0) . ':' . (string) ($v->m ?? '');

        return \Illuminate\Support\Facades\Cache::remember($cle, 300, fn () => $this->listeComplete());
    }

    /** Lecture réelle des 10 groupes (fusion, le dernier gagne — ordre historique conservé). */
    private function listeComplete() : array
    {
        $array = [];
        $array = array_merge($array, Settings::group('company')->all());
        $array = array_merge($array, Settings::group('site')->all());
        $array = array_merge($array, Settings::group('theme')->all());
        $array = array_merge($array, Settings::group('otp')->all());
        $array = array_merge($array, Settings::group('social_media')->all());
        $array = array_merge($array, Settings::group('order_setup')->all());
        $array = array_merge($array, Settings::group('notification')->all());
        $array = array_merge($array, Settings::group('cookies')->all());
        // [KIOSK-15] Merge kiosk_setup so SettingResource can expose kiosk fields to the frontend
        $array = array_merge($array, Settings::group('kiosk_setup')->all());
        // [KIOSK-15] Merge loyalty_setup so kiosk can read conversion rates via /frontend/setting
        return array_merge($array, Settings::group('loyalty_setup')->all());
    }
}
