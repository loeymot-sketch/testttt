<?php

namespace App\Http\Controllers\Auth;

use App\Events\SendResetPassword;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Smartisan\Settings\Facades\Settings;

class ForgotPasswordController extends Controller
{

    public int $pin;
    public string $token = '';

    public function forgotPassword(Request $request) : JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            return new JsonResponse(['errors' => $validator->errors()], 422);
        }

        $verify = User::where('email', $request->post('email'))->exists();

        if ($verify) {
            $verify = DB::table('password_resets')->where([
                ['email', $request->post('email')]
            ]);

            if ($verify->exists()) {
                $verify->delete();
            }

            $this->pin = random_int(100000, 999999);

            $password_reset = DB::table('password_resets')->insert([
                'email'      => $request->post('email'),
                'token'      => $this->pin,
                'created_at' => Carbon::now()
            ]);

            if ($password_reset) {
                SendResetPassword::dispatch(['email' => $request->post('email'), 'pin' => $this->pin]);
                return new JsonResponse([
                    'message' => trans('all.message.check_your_email_for_code')
                ], 200);
            } else {
                return new JsonResponse([
                    'errors' => ['email' => [trans('all.message.token_created_fail')]]
                ], 400);
            }
        } else {
            return new JsonResponse([
                'errors' => ['email' => [trans('all.message.email_does_not_exist')]]
            ], 400);
        }
    }

    public function verifyCode(Request $request) : JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
            'code'  => ['required'],
        ]);

        if ($validator->fails()) {
            return new JsonResponse(['errors' => $validator->errors()], 422);
        }

        $check = DB::table('password_resets')->where([
            ['email', $request->post('email')],
            ['token', $request->post('code')],
        ]);

        $checkRecord = $check->first();
        if ($checkRecord) {
            $difference = Carbon::now()->diffInSeconds(Carbon::parse($checkRecord->created_at));

            if ($difference > (int)Settings::group('otp')->get('otp_expire_time') * 60) {
                return new JsonResponse([
                    'errors' => ['code' => [trans('all.message.code_is_expired')]]
                ], 400);
            }

            DB::transaction(function () use ($check, $request) {
                $check->delete();

                $this->token = Str::random(64);

                DB::table('password_resets')->insert([
                    'email'      => $request->post('email'),
                    'token'      => $this->token,
                    'created_at' => Carbon::now(),
                ]);
            });

            return new JsonResponse([
                'message'     => trans('all.message.you_can_reset_your_password'),
                'reset_token' => $this->token,
            ], 200);
        } else {
            return new JsonResponse([
                'errors' => ['code' => [trans('all.message.code_is_invalid')]]
            ], 400);
        }
    }

    public function resetPassword(Request $request) : JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'                 => ['required', 'string', 'email', 'max:255'],
            'reset_token'           => ['required', 'string', 'size:64'],
            // [F-2 AUTH R1 V1.0.1 quick win — 2026-05-19]
            // Bumped min:6 -> min:12 for parity with staff create/update and the
            // bcrypt-rounds-12 hardening shipped in Wave 5G. Sentinel:
            // tests/Feature/Sentinels/PasswordResetMinLengthSentinelTest.php
            // Source: reports/audit/foundation-2026-05-18/round-1/F-2-AUTH/STATUS.md §5.1 R1
            'password'              => ['required', 'string', 'min:12', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            return new JsonResponse(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->post('email'))->first();
        if (!$user) {
            return new JsonResponse([
                'errors' => ['email' => [trans('all.message.user_match')]]
            ], 404);
        }

        $resetRecord = DB::table('password_resets')->where([
            ['email', $request->post('email')],
            ['token', $request->post('reset_token')],
        ])->first();

        if (!$resetRecord) {
            return new JsonResponse([
                'errors' => ['reset_token' => [trans('all.message.token_is_invalid')]]
            ], 422);
        }

        $difference = Carbon::now()->diffInSeconds(Carbon::parse($resetRecord->created_at));
        if ($difference > (int)Settings::group('otp')->get('otp_expire_time') * 60) {
            DB::table('password_resets')->where('email', $request->post('email'))->delete();

            return new JsonResponse([
                'errors' => ['reset_token' => [trans('all.message.code_is_expired')]]
            ], 400);
        }

        DB::transaction(function () use ($request, $user) {
            DB::table('password_resets')->where('email', $request->post('email'))->delete();

            $user->update([
                'password' => Hash::make($request->post('password'))
            ]);

            // [WJ-3 WI-5 SEC-RST-01 V1.0.1 P1 — 2026-05-19]
            // Revoke ALL existing Sanctum tokens BEFORE minting the new
            // session token. A password reset signals potential credential
            // compromise — any token already in circulation (any name, any
            // ability scope) must be invalidated, otherwise an attacker who
            // exfiltrated a token retains access for up to 480 min after
            // the legitimate owner completed the reset. Mirror of the
            // Sprint 5D Z6-01 relogin-revoke in LoginController.php:109,
            // broader scope (all token names, not just `auth_token`)
            // because reset = full session purge by definition.
            // Sentinel: tests/Feature/Sentinels/PasswordResetRevokesTokensSentinelTest.php
            $user->tokens()->delete();

            $this->token = $user->createToken(
                'auth_token',
                ['*'],
                now()->addMinutes((int) config('sanctum.expiration', 480))
            )->plainTextToken;
        });

        return new JsonResponse([
            'message' => "Your password has been reset",
            'token'   => $this->token
        ], 200);
    }
}
