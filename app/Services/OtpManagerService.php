<?php

namespace App\Services;

use Exception;
use Carbon\Carbon;
use App\Models\Otp;
use App\Enums\OtpType;
use App\Events\SendSmsCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;
use App\Http\Requests\VerifyPhoneRequest;

class OtpManagerService
{

    /**
     * @throws Exception
     */
    public function otp(Request $request) : bool
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
            if (OtpType::SMS == Settings::group('otp')->get('otp_type') || OtpType::BOTH == Settings::group('otp')->get(
                    'otp_type'
                )) {
                $digits = max(4, (int) Settings::group('otp')->get('otp_digit_limit'));
                $token  = random_int((int) pow(10, $digits - 1), (int) pow(10, $digits) - 1);
            } else {
                $token = random_int(1000, 9999);
            }

            $otp = Otp::create([
                'phone' => $request->phone,
                'code' => $request->code,
                'token' => $token,
                'created_at' => now(),
            ]);

            if (!blank($otp)) {
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
    public function verify(VerifyPhoneRequest $request) : bool
    {
        try {
            // env('DEMO') === 'false' est truthy en PHP — utiliser un booléen réel
            if (config('app.demo_mode')) {
                return true;
            }

            $otp = DB::table('otps')->where([
                ['phone', $request->post('phone')],
                ['token', $request->post('token')],
            ])->first();
            if ($otp) {
                $difference = Carbon::now()->diffInSeconds($otp->created_at);
                if ($difference > (int)Settings::group('otp')->get('otp_expire_time') * 60) {
                    throw new Exception(trans('all.message.code_is_expired'), 422);
                } else {
                    DB::table('otps')->where([
                        ['phone', $request->post('phone')],
                        ['token', $request->post('token')],
                    ])->delete();
                    return true;
                }
            } else {
                throw new Exception(trans('all.message.code_is_invalid'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
