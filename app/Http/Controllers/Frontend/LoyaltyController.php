<?php

namespace App\Http\Controllers\Frontend;

use Exception;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class LoyaltyController extends Controller
{
    /**
     * Validation rules for loyalty code
     */
    private function validateCode(Request $request): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($request->all(), [
            'code' => ['required', 'string', 'min:4', 'max:20', 'regex:/^[A-Z0-9]+$/i'],
        ]);
    }

    /**
     * Validation rules for loyalty registration
     */
    private function validateRegistration(Request $request): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($request->all(), [
            'phone' => ['required', 'string', 'min:8', 'max:20'],
            'name' => ['nullable', 'string', 'min:2', 'max:100'],
            'email' => ['nullable', 'email', 'max:100'],
        ]);
    }

    /**
     * Validation rules for points operations
     */
    private function validatePoints(Request $request): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($request->all(), [
            'code' => ['required', 'string', 'min:4', 'max:20'],
            'points' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function check(Request $request)
    {
        try {
            $validator = $this->validateCode($request);
            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Code invalide',
                    'errors' => $validator->errors()
                ], 422);
            }

            $code = $request->input('code');
            $user = User::where('loyalty_code', $code)->first();

            if ($user && $user->status == 1) { // Assuming 1 means active
                return response()->json([
                    'status' => true,
                    'data' => [
                        'name' => $user->name,
                        'points' => $user->loyalty_points,
                    ]
                ], 200);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Non trouvé'
                ], 404);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }

    public function register(Request $request)
    {
        try {
            $validator = $this->validateRegistration($request);
            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->input('phone'))->first();
            if (!$user) {
                // Création rapide d'un client via le Kiosk
                $user = new User();
                $user->name = $request->input('name') ?? 'Client Loyalty';
                $user->email = $request->input('email') ?? null;
                $user->phone = $request->input('phone');
                $user->username = uniqid('kiosk_');
                $user->password = bcrypt(uniqid());
                $user->status = 1;
            }

            // Génération d'un code de fidélité s'il n'en a pas
            if (!$user->loyalty_code) {
                $user->loyalty_code = strtoupper(substr(md5(uniqid()), 0, 8)); // ex: A1B2C3D4
                $user->loyalty_points = 0;
            }
            $user->save();

            return response()->json([
                'status' => true,
                'data' => [
                    'name' => $user->name,
                    'loyalty_code' => $user->loyalty_code,
                    'points' => $user->loyalty_points,
                ]
            ]);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            return response()->json(['status' => false, 'message' => 'Erreur serveur'], 500);
        }
    }

    public function addPoints(Request $request)
    {
        try {
            $validator = $this->validatePoints($request);
            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('loyalty_code', $request->input('code'))->first();
            if (!$user)
                return response()->json(['status' => false, 'message' => 'Code introuvable'], 404);

            $pointsToAdd = (int) $request->input('points', 0);
            if ($pointsToAdd <= 0) {
                return response()->json(['status' => false, 'message' => 'Points must be positive'], 400);
            }
            $user->loyalty_points += $pointsToAdd;
            $user->save();

            return response()->json([
                'status' => true,
                'message' => "{$pointsToAdd} points ajoutés",
                'data' => ['points' => $user->loyalty_points]
            ]);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            return response()->json(['status' => false, 'message' => 'Erreur serveur'], 500);
        }
    }

    public function redeem(Request $request)
    {
        try {
            $validator = $this->validatePoints($request);
            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('loyalty_code', $request->input('code'))->first();
            if (!$user)
                return response()->json(['status' => false, 'message' => 'Code introuvable'], 404);

            $pointsToRedeem = (int) $request->input('points', 0);
            if ($pointsToRedeem <= 0) {
                return response()->json(['status' => false, 'message' => 'Points must be positive'], 400);
            }
            if ($user->loyalty_points < $pointsToRedeem) {
                return response()->json(['status' => false, 'message' => 'Points insuffisants'], 400);
            }

            $user->loyalty_points -= $pointsToRedeem;
            $user->save();

            return response()->json([
                'status' => true,
                'message' => "{$pointsToRedeem} points utilisés",
                'data' => ['points' => $user->loyalty_points]
            ]);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            return response()->json(['status' => false, 'message' => 'Erreur serveur'], 500);
        }
    }

    public function balance(Request $request)
    {
        return $this->check($request);
    }

    public function history(Request $request)
    {
        // Validate pagination if provided
        $validator = Validator::make($request->all(), [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Paramètres invalides',
                'errors' => $validator->errors()
            ], 422);
        }

        // Placeholder return (à faire si une table transactions est ajoutée)
        return response()->json([
            'status' => true,
            'data' => []
        ]);
    }
}
