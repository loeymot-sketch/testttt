<?php

namespace App\Http\Middleware;

use App\Support\KioskAutoLoginGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers a validated physical-kiosk link without leaving its secret in the
 * address bar after Vue navigation. EncryptCookies authenticates the value;
 * this middleware only ever accepts the decrypted literal `1`.
 */
class RememberKioskAutoLoginGrant
{
    public const COOKIE = 'foodking_kiosk_auto_login';
    public const ATTRIBUTE = 'foodking.kiosk_auto_login_granted';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('kiosk*')) {
            return $next($request);
        }

        $fromMachineLink = KioskAutoLoginGate::matchesMachineSecret(
            $request->query('machine_key'),
            (string) config('kiosk.auto_login_secret', ''),
        );
        $fromEncryptedCookie = $request->cookie(self::COOKIE) === '1';

        $request->attributes->set(self::ATTRIBUTE, $fromMachineLink || $fromEncryptedCookie);

        if ($fromMachineLink) {
            Cookie::queue(cookie()->make(
                self::COOKIE,
                '1',
                max(1, (int) config('kiosk.auto_login_grant_minutes', 60 * 24 * 14)),
                '/kiosk',
                null,
                true,
                true,
                false,
                'strict',
            ));
        }

        return $next($request);
    }
}
