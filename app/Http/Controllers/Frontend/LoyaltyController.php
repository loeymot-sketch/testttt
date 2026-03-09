<?php

namespace App\Http\Controllers\Frontend;

use Exception;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;

class LoyaltyController extends Controller
{
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function check(Request $request)
    {
        try {
            $code = $request->input('code');

            if (!$code) {
                return response()->json([
                    'status' => false,
                    'message' => 'Code manquant'
                ], 400);
            }

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
            $user = User::where('loyalty_code', $request->input('code'))->first();
            if (!$user)
                return response()->json(['status' => false, 'message' => 'Code introuvable'], 404);

            $pointsToAdd = (int) $request->input('points', 0);
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
            $user = User::where('loyalty_code', $request->input('code'))->first();
            if (!$user)
                return response()->json(['status' => false, 'message' => 'Code introuvable'], 404);

            $pointsToRedeem = (int) $request->input('points', 0);
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
        // Placeholder return (à faire si une table transactions est ajoutée)
        return response()->json([
            'status' => true,
            'data' => []
        ]);
    }
}
