<?php

namespace App\Services;

use App\Enums\OtpType;
use App\Events\SendSmsCode;
use App\Http\Requests\VerifyPhoneRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Models\Otp;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Smartisan\Settings\Facades\Settings;

class OtpManagerService
{
    /**
     * @throws Exception
     */
    // [WAVE C EMAIL-OTP 2026-07-28] $dispatchSms=false pour le canal EMAIL
    // (GuestSignupController::emailOtp) : la génération/stockage OTP est
    // identique, mais on ne dispatch PAS SendSmsCode — (a) mandat owner
    // « pas de SMS », (b) SendSmsCodeNotification TypeError (non-catchable
    // par son catch(Exception)) quand aucune gateway SMS n'est configurée.
    // Défaut true = flux SMS existant strictement inchangé.
    public function otp(Request $request, bool $dispatchSms = true): bool
    {
        try {
            // [GAP-20-1] Delete ALL previous OTPs for this phone number regardless of country code.
            // The old query (phone + code) left stale OTPs when the country code changed between
            // requests, allowing token accumulation. A phone number is the unique identity here.
            DB::table('otps')->where('phone', $request->post('phone'))->delete();

            // [GAP-20-4] Opportunistic cleanup: purge all OTPs older than the configured expiry
            // to prevent table bloat. This runs on every OTP request (cheap, indexed on created_at).
            $expireMinutes = (int) Settings::group('otp')->get('otp_expire_time') ?: 5;
            DB::table('otps')
                ->where('created_at', '<', now()->subMinutes($expireMinutes + 1))
                ->delete();

            // [GAP-32-5] Use random_int() (CSPRNG) instead of rand() for OTP generation.
            // rand() is not cryptographically secure; random_int() uses OS entropy.
            if (Settings::group('otp')->get('otp_type') == OtpType::SMS || Settings::group('otp')->get(
                'otp_type'
            ) == OtpType::BOTH) {
                $digits = max(4, (int) Settings::group('otp')->get('otp_digit_limit'));
                $token = random_int((int) pow(10, $digits - 1), (int) pow(10, $digits) - 1);
            } else {
                $token = random_int(1000, 9999);
            }

            $otp = Otp::create([
                'phone' => $request->phone,
                'code' => $request->code,
                'token' => $token,
                'created_at' => now(),
            ]);

            if (! blank($otp) && $dispatchSms) {
                SendSmsCode::dispatch(
                    ['phone' => $request->post('phone'), 'code' => $request->post('code'), 'token' => $token]
                );
            }

            return true;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function verify(VerifyPhoneRequest $request): bool
    {
        try {
            // env('DEMO') === 'false' est truthy en PHP — utiliser un booléen réel.
            // [SECURITY P1-B 2026-07-30] Ce court-circuit accepte N'IMPORTE quel code
            // (fixtures dev sans SMS). En PRODUCTION il serait une prise de compte
            // (GuestSignupController::verify → token kiosk:order pour un tel arbitraire) :
            // c'est pourquoi AppServiceProvider::boot() REFUSE de booter en prod si
            // DEMO=true (jumeau de POS_SIMULATION_HARDWARE/APP_DEBUG). Cette branche
            // n'est donc atteignable qu'en local/dev.
            if (filter_var(env('DEMO', false), FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }

            $phone = $request->post('phone');

            // [NUIT 2026-07-03 — P2 anti-brute-force OTP] Verrou PAR IDENTITÉ (téléphone), en complément du
            // throttle par-IP (3/5min). Le code n'était consommé qu'au SUCCÈS → un attaquant pouvait deviner
            // un code 4-6 chiffres tant qu'il n'expire pas (rejeu du même code, contournable par rotation
            // d'IP). Ici : au-delà de N échecs pour CE téléphone, on BRÛLE le(s) OTP vivant(s) et on force une
            // nouvelle demande (throttlée côté /otp). Compteur en Cache (TTL = fenêtre d'expiration) →
            // fail-open si cache indisponible (repli propre sur le throttle existant, jamais de blocage dur).
            $failKey = 'otp_verify_fail:'.$phone;
            $maxAttempts = 5;
            $ttlMinutes = max(1, (int) Settings::group('otp')->get('otp_expire_time') ?: 5);
            if ((int) Cache::get($failKey, 0) >= $maxAttempts) {
                DB::table('otps')->where('phone', $phone)->delete(); // consume-on-abuse : le code est brûlé
                Cache::forget($failKey);
                throw new Exception(trans('all.message.code_is_invalid'), 422);
            }

            $otp = DB::table('otps')->where([
                ['phone', $phone],
                ['token', $request->post('token')],
            ])->first();
            if ($otp) {
                $difference = Carbon::now()->diffInSeconds($otp->created_at);
                if ($difference > (int) Settings::group('otp')->get('otp_expire_time') * 60) {
                    throw new Exception(trans('all.message.code_is_expired'), 422);
                } else {
                    DB::table('otps')->where([
                        ['phone', $phone],
                        ['token', $request->post('token')],
                    ])->delete();
                    Cache::forget($failKey); // succès → réinitialise le compteur d'échecs par identité
                    // [SELF-AUDIT R5 P1 SÉCURITÉ 2026-07-05] Marqueur de vérification RÉELLE (one-time,
                    // courte durée). L'OTP étant consommé (supprimé) au succès, « pas de ligne otp » est
                    // AMBIGU (vérifié VS jamais demandé) — SignupController::register l'utilisait pour
                    // autoriser un merge dans un compte invité existant → hijack. Ce marqueur donne une
                    // PREUVE positive de possession du téléphone, consommée (pull) par register().
                    Cache::put('phone_verified:'.$phone, true, now()->addMinutes($ttlMinutes));

                    return true;
                }
            } else {
                // Échec : incrémente le compteur d'échecs de CE téléphone (fenêtre = expiration du code).
                Cache::put($failKey, ((int) Cache::get($failKey, 0)) + 1, now()->addMinutes($ttlMinutes));
                throw new Exception(trans('all.message.code_is_invalid'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
