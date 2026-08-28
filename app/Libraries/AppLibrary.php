<?php

namespace App\Libraries;

use App\Enums\AmountType;
use App\Enums\CurrencyPosition;
use App\Models\ProductVariation;
use App\Models\Tax;
use App\Models\User;
use Carbon\Carbon;
use DateInterval;
use DateTime;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JetBrains\PhpStorm\ArrayShape;
use Smartisan\Settings\Facades\Settings;

class AppLibrary
{
    // [FR-DATE-SAFE 2026-06-26] env() returns null after `config:cache` (same class as
    // the money flatAmountFormat null bug — BRAIN PR-07) → Carbon::format(null) breaks the
    // date/time on every fiscal-adjacent display. FR-safe defaults mirror the canonical
    // site settings (site_date_format="d-m-Y", site_time_format="H:i" — 24h FR, ADR-007).
    // NB: the live "h:i A" (12h English « PM ») symptom is an env↔DB drift (env TIME_FORMAT
    // mis-set vs DB site_time_format="H:i") — corrected by setting .env TIME_FORMAT="H:i"
    // or re-saving Site settings (SiteService:54 re-propagates). These defaults guarantee a
    // valid FR format even when env is empty/null; they do not override a non-empty env.
    public static function date($date, $pattern = null): string
    {
        if (!$pattern) {
            $pattern = env('DATE_FORMAT') ?: 'd-m-Y';
        }
        return Carbon::parse($date)->format($pattern);
    }

    public static function time($time, $pattern = null): string
    {
        if (!$pattern) {
            $pattern = env('TIME_FORMAT') ?: 'H:i';
        }
        return Carbon::parse($time)->format($pattern);
    }

    public static function datetime($dateTime, $pattern = null): string
    {
        if (!$pattern) {
            $pattern = (env('TIME_FORMAT') ?: 'H:i') . ', ' . (env('DATE_FORMAT') ?: 'd-m-Y');
        }
        return Carbon::parse($dateTime)->format($pattern);
    }

    public static function increaseDate($dateTime, $days, $pattern = null): string
    {
        if (!$pattern) {
            // [FR-DATE-SAFE 2026-06-27] jumeau de date()/datetime() : env() null après
            // config:cache → format(null) casse la date de livraison (KDSOrderDetailsResource
            // delivery_date). Défaut FR-safe d-m-Y.
            $pattern = env('DATE_FORMAT') ?: 'd-m-Y';
        }
        return Carbon::parse($dateTime)->addDays($days)->format($pattern);
    }

    public static function deliveryTime($dateTime, $pattern = null): string
    {
        if (!$pattern) {
            // [FR-DATE-SAFE 2026-06-27] jumeau : créneau de livraison « HH:MM - HH:MM » 24h FR.
            $pattern = env('TIME_FORMAT') ?: 'H:i';
        }
        $explode = explode('-', $dateTime);
        if (count($explode) == 2) {
            return Carbon::parse(trim($explode[0]))->format($pattern) . ' - ' . Carbon::parse(trim($explode[1]))->format($pattern);
        }
        return '';
    }

    public static function associativeToNumericArrayBuilder($array): array
    {
        $i = 1;
        $buildArray = [];
        if (count($array)) {
            foreach ($array as $arr) {
                if (isset($arr['children'])) {
                    $children = $arr['children'];
                    unset($arr['children']);

                    $arr['parent'] = 0;
                    $buildArray[$i] = $arr;
                    $parentId = $i;
                    $i++;
                    foreach ($children as $child) {
                        $child['parent'] = $parentId;
                        $buildArray[$i] = $child;
                        $i++;
                    }
                } else {
                    $arr['parent'] = 0;
                    $buildArray[$i] = $arr;
                    $i++;
                }
            }
        }
        return $buildArray;
    }

    public static function numericToAssociativeArrayBuilder($array): array
    {
        $i = 0;
        $parentId = null;
        $parentIncrementId = null;
        $buildArray = [];
        if (count($array)) {
            foreach ($array as $arr) {
                if (!$arr['parent']) {
                    $parentId = $arr['id'];
                    $parentIncrementId = $i;
                    $buildArray[$i] = $arr;
                    $i++;
                }

                if ($arr['parent'] == $parentId) {
                    $buildArray[$parentIncrementId]['children'][] = $arr;
                }
            }
        }
        if ($buildArray) {
            foreach ($buildArray as $key => $build) {
                if ($build['url'] == "#" && !isset($build['children'])) {
                    unset($buildArray[$key]);
                }
            }
        }

        return $buildArray;
    }

    public static function permissionWithAccess(&$permissions, $rolePermissions): object
    {
        if ($permissions) {
            foreach ($permissions as $permission) {
                if (isset($rolePermissions[$permission->id])) {
                    $permission->access = true;
                } else {
                    $permission->access = false;
                }
            }
        }
        return $permissions;
    }

    public static function menu(&$menus, $permissions): array
    {
        if ($menus && $permissions) {
            foreach ($menus as $key => $menu) {
                if (isset($permissions[$menu['url']]) && !$permissions[$menu['url']]['access']) {
                    if ($menu['url'] != '#') {
                        unset($menus[$key]);
                    }
                }
            }
        }
        return $menus;
    }

    /**
     * [2026-08-12] `$defaulPermission` peut n'avoir AUCUNE propriété `url`.
     *
     * `defaultPermission()` rend un objet vide quand le rôle ne porte aucune permission d'administration
     * — c'est le cas d'un CLIENT. Lire `->url` dessus levait alors une erreur, et la connexion
     * répondait 500 : `AuthComprehensiveTest::test_customer_receives_home_landing` et
     * `AntiGravityLoginRedirectionTest::test_customer_receives_null_landing_url` étaient rouges pour
     * cette seule raison.
     *
     * Pas de permission par défaut = pas de menu par défaut. C'est une réponse, pas une erreur : on
     * rend un tableau vide, et l'appelant retombe sur l'adresse d'atterrissage du rôle.
     */
    public static function defaultMenu($menus, $defaulPermission): array
    {
        $cible = is_object($defaulPermission) ? ($defaulPermission->url ?? null) : null;

        if ($cible === null) {
            return [];
        }

        foreach ($menus as $menu) {
            if (isset($menu['url']) && $menu['url'] === $cible) {
                return $menu;
            }
            if (isset($menu['children']) && is_array($menu['children']) && count($menu['children']) > 0) {
                $found = self::defaultMenu($menu['children'], $defaulPermission);
                if (!empty($found)) {
                    return $found;
                }
            }
        }
        return [];
    }


    public static function pluck($array, $value, $key = null, $type = 'object'): array
    {
        $returnArray = [];
        if ($array) {
            foreach ($array as $item) {
                if ($key != null) {
                    if ($type == 'array') {
                        $returnArray[$item[$key]] = strtolower($value) == 'obj' ? $item : $item[$value];
                    } else {
                        $returnArray[$item[$key]] = strtolower($value) == 'obj' ? $item : $item->$value;
                    }
                } elseif ($value == 'obj') {
                    $returnArray[] = $item;
                } elseif ($type == 'array') {
                    $returnArray[] = $item[$value];
                } else {
                    $returnArray[] = $item->$value;
                }
            }
        }
        return $returnArray;
    }

    public static function username($name)
    {
        if ($name) {
            $username = strtolower(str_replace(' ', '', $name)) . rand(1, 999999);
            if (User::where(['username' => $username])->first()) {
                self::username($name);
            }
            return $username;
        }
    }

    public static function name($firstName, $lastName): string
    {
        return $firstName . ' ' . $lastName;
    }

    public static function branchChecking($branch_id): int
    {
        if (Auth::check()) {
            $branch_id = $branch_id ?? null;
            if ($branch_id === null) {
                $branch_id = Settings::group('site')->get('site_default_branch');
            } elseif ($branch_id === 0) {
                if ($branch_id === Auth::user()->branch_id) {
                    $branch_id = 0;
                } else {
                    $branch_id = Auth::user()->branch_id;
                }
            } else {
                $branch_id = Auth::user()->branch_id;
            }
        }
        return $branch_id;
    }

    public static function amountCheck($amount, $attr = 'price'): object
    {
        $response = [
            'status' => true,
            'message' => ''
        ];

        if (!is_numeric($amount)) {
            $response['status'] = false;
            $response['message'] = "This {$attr} must be integer.";
        }

        if ($amount <= 0) {
            if ($response['status'] == false) {
                return (object)$response;
            } else {
                $response['status'] = false;
                $response['message'] = "This {$attr} negative amount not allow.";
            }
        }

        $replaceValue = str_replace('.', '', $amount);
        if (strlen($replaceValue) > 12) {
            if ($response['status'] == false) {
                return (object)$response;
            } else {
                $response['status'] = false;
                $response['message'] = "This {$attr} length can't be greater than 12 digit.";
            }
        }

        if (!preg_match("/^\d{1,10}(\.\d{1,2})?$/", $amount)) {
            if ($response['status'] == false) {
                return (object)$response;
            } else {
                $response['status'] = false;
                $response['message'] = "This {$attr} amount provide invalid.";
            }
        }

        return (object)$response;
    }

    public static function currencyAmountFormat($amount): string
    {
        // [GOAL-G2-HEAL-02 2026-05-23] FR-canonical currency format per NF525
        // receipt compliance + ISO 4217. Previous implementation hardcoded
        // "." decimal separator and concatenated the symbol with no nbsp →
        // produced "12.50€" on receipts/emails/reports, while PaymentComponent
        // (D3 LOCK_PAY) + formatPrice.js (WT-D-R1-F4) emit canonical "12,50 €"
        // (Intl fr-FR EUR with NBSP). Same caisse, divergent format ≠
        // ISO 4217 + FR locale convention. Surfaced by Phase G.5 finding
        // G5-F-003 P1.
        //
        // Resolution: use NumberFormatter::CURRENCY with fr_FR locale (matches
        // frontend Intl output bit-for-bit) and fall back to manual FR layout
        // (virgule decimal + NBSP + symbol) when ext-intl is unavailable. The
        // env CURRENCY_SYMBOL / CURRENCY_POSITION / CURRENCY_DECIMAL_POINT
        // contract is preserved for the fallback branch — admins keep control
        // if they want a non-EUR display in some downstream surface.
        $amount = (float) $amount;
        $decimal = (int) (env('CURRENCY_DECIMAL_POINT') ?: 2);

        if (class_exists('NumberFormatter')) {
            $fmt = new \NumberFormatter('fr_FR', \NumberFormatter::CURRENCY);
            $fmt->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimal);
            return $fmt->formatCurrency($amount, 'EUR');
        }

        // Fallback: manual FR layout (virgule + nbsp + symbol).
        $symbol    = env('CURRENCY_SYMBOL', '€');
        $position  = env('CURRENCY_POSITION');
        $formatted = number_format($amount, $decimal, ',', "\xC2\xA0");
        return $position == CurrencyPosition::LEFT
            ? $symbol . "\xC2\xA0" . $formatted
            : $formatted . "\xC2\xA0" . $symbol;
    }

    public static function flatAmountFormat($amount): string
    {
        // [MONEY-FIX 2026-06-25] `?? 2` : sous config:cache (prod) env() renvoie null
        // → number_format($x, null) arrondit à l'ENTIER (rapports faux). Garde-fou
        // identique à currencyAmountFormat:289. Idéalement config() (survit au cache).
        return number_format($amount, (int) (env('CURRENCY_DECIMAL_POINT') ?: 2), '.', '');
    }

    public static function convertAmountFormat($amount): float
    {
        return (float)number_format($amount, (int) (env('CURRENCY_DECIMAL_POINT') ?: 2), '.', '');
    }

    public static function fcmDataBind($request)
    {
        $cdn = public_path("firebase-cdn.txt");
        $textContent = public_path("firebase-content.txt");
        $file = public_path("firebase-messaging-sw.js");
        $content = 'let config = {
        apiKey: "' . $request->notification_fcm_api_key . '",
        authDomain: "' . $request->notification_fcm_auth_domain . '",
        projectId: "' . $request->notification_fcm_project_id . '",
        storageBucket: "' . $request->notification_fcm_storage_bucket . '",
        messagingSenderId: "' . $request->notification_fcm_messaging_sender_id . '",
        appId: "' . $request->notification_fcm_app_id . '",
        measurementId: "' . $request->notification_fcm_measurement_id . '",' . "\n" . ' };' . "\n";
        File::put($file, File::get($cdn) . $content . File::get($textContent));
    }

    public static function defaultPermission($permissions)
    {
        $defaultPermission = (object)[];
        if (count($permissions)) {
            foreach ($permissions as $permission) {
                if ($permission->access) {
                    $defaultPermission = $permission;
                    break;
                }
            }
        }
        return $defaultPermission;
    }

    public static function domain($input)
    {
        $input = trim($input, '/');
        if (!preg_match('#^http(s)?://#', $input)) {
            $input = 'http://' . $input;
        }
        $urlParts = parse_url($input);

        $link = '';
        if (isset($urlParts['port'])) {
            $link .= ':' . $urlParts['port'];
        }

        if (isset($urlParts['path'])) {
            $link .= $urlParts['path'];
        }

        return preg_replace('/^www\./', '', ($urlParts['host'] . $link));
    }

    public static function licenseApiResponse($response)
    {
        $header      = explode(';', $response->getHeader('Content-Type')[0]);
        $contentType = $header[0];
        if ($contentType == 'application/json') {
            $contents = $response->getBody()->getContents();
            $data     = json_decode($contents);
            if (json_last_error() == JSON_ERROR_NONE) {
                return $data;
            }
            return $contents;
        }

        return ['status' => false, 'message' => 'data not found'];
    }


    public static function deleteDir($dirPath): void
    {
        if (!is_dir($dirPath)) {
            throw new InvalidArgumentException("$dirPath must be a directory");
        }
        if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
            $dirPath .= '/';
        }
        $files = glob($dirPath . '*', GLOB_MARK);
        foreach ($files as $file) {
            if (is_dir($file)) {
                self::deleteDir($file);
            } else {
                unlink($file);
            }
        }
        rmdir($dirPath);
    }

    public static function reportCurrencyAmountFormat($amount): string
    {
        // [MONEY-FIX 2026-06-25] `?? 2` : voir flatAmountFormat — évite l'arrondi
        // entier des totaux de rapport sous config:cache.
        //
        // [ONB-07 2026-08-28] Les séparateurs étaient `'.', ','` — le format
        // anglo-saxon. Un commerçant français lisait « 1,234.56 » au bas de son PDF
        // de ventes, là où son écran affiche « 1 234,56 € » (`currencyAmountFormat`
        // passe par `NumberFormatter('fr_FR')`). Deux formats pour la même somme,
        // dans le même produit, sur des documents qu'il compare.
        //
        // Pire qu'inélégant : « 1,234.56 » se lit « 1,23 » pour un œil français —
        // un facteur mille sur un document remis au comptable.
        //
        // Espace insécable fine (U+202F) comme séparateur de milliers, virgule
        // décimale : la convention française, et celle que l'écran applique déjà.
        return number_format(
            $amount,
            (int) (env('CURRENCY_DECIMAL_POINT') ?: 2),
            ',',
            "\u{202F}"
        );
    }

    public static function textShortener($text, $number = 30)
    {
        if ($text && mb_strlen($text) > $number) {
            return mb_substr($text, 0, $number) . "..";
        }
        return $text;
    }

    public static function deliveryTimeCheck($dateTime, $pattern = null): string
    {
        if ($dateTime) {
            [$startTime, $endTime] = explode(' - ', $dateTime);
            $currentTime = new DateTime();

            $startTimeObj = DateTime::createFromFormat('H:i', $startTime);
            $endTimeObj = DateTime::createFromFormat('H:i', $endTime);

            if ($startTimeObj && $endTimeObj) {
                $slotDuration = Settings::group('order_setup')->get('order_setup_schedule_order_slot_duration') ?? 30;
                $thirtyMinutesBefore = (clone $startTimeObj)->sub(new DateInterval('PT' . $slotDuration . 'M'));

                if ($currentTime >= $thirtyMinutesBefore && $currentTime <= $endTimeObj) {
                    return "Now";
                } else {
                    if (!$pattern) {
                        // [KDS-FR-TIME HEAL 2026-06-27] Align with sibling methods time():40 /
                        // deliveryTime():68 — `env('TIME_FORMAT') ?: 'H:i'` (24h FR). The old
                        // `env('TIME_FORMAT', 'h:i A')` leaked a 12h en-US default ("08:30 PM")
                        // into the KDS delivery slot after `config:cache` (env() returns its 2nd
                        // arg when the cached config strips the var) — the documented env-null /
                        // flatAmountFormat trap, an ADR-007 FR violation in prod.
                        $pattern = env('TIME_FORMAT') ?: 'H:i';
                    }
                    $explode = explode('-', $dateTime);
                    if (count($explode) == 2) {
                        return Carbon::parse(trim($explode[0]))->format($pattern) . ' - ' . Carbon::parse(trim($explode[1]))->format($pattern);
                    }
                    return '';
                }
            }
            return '';
        }
        return '';
    }
}
