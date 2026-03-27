<?php

namespace App\Http\Controllers\Frontend;

use Exception;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Smartisan\Settings\Facades\Settings;

class LoyaltyController extends Controller
{
    /**
     * Validation rules for loyalty lookup (code OR phone number).
     * Accepts alphanumeric codes (A1B2C3D4) and phone numbers (digits, +, spaces, dashes).
     */
    private function validateCode(Request $request): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($request->all(), [
            'code' => ['required', 'string', 'min:4', 'max:25', 'regex:/^[A-Z0-9\+\s\-]+$/i'],
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

            $input = trim($request->input('code'));

            // Try by loyalty code first, then fall back to phone number
            $user = User::where('loyalty_code', $input)->first();
            if (!$user) {
                // Normalize phone: keep digits, leading +
                $phone = preg_replace('/[\s\-]/', '', $input);
                $user = User::where('phone', $phone)->first();
            }

            if ($user && $user->status == 1) {
                // Ensure the user has a loyalty code (may have registered by phone only)
                if (!$user->loyalty_code) {
                    $user->loyalty_code = strtoupper(substr(md5(uniqid()), 0, 8));
                    $user->save();
                }
                // [SPLASH] Return points + computed discount value so kiosk can display it
                $discountValue = $this->pointsToDiscount($user->loyalty_points);
                return response()->json([
                    'status' => true,
                    'data' => [
                        'name'           => $user->name,
                        'points'         => $user->loyalty_points,
                        'discount_value' => $discountValue,
                        'loyalty_code'   => $user->loyalty_code,
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

            // [AUDIT-P50-BUG8] Check for email conflict before creating/updating
            $email = $request->input('email');
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $existingByEmail = User::where('email', $email)->first();
                if ($existingByEmail && (!$user || $existingByEmail->id !== $user->id)) {
                    // Email belongs to a different account — suggest using existing loyalty account
                    return response()->json([
                        'status' => false,
                        'code' => 'EMAIL_EXISTS',
                        'message' => 'Cet email est déjà associé à un compte fidélité.',
                        'data' => [
                            'existing_loyalty_code' => $existingByEmail->loyalty_code,
                            'existing_phone' => $existingByEmail->phone,
                            'suggestion' => 'Utilisez ce compte existant ou entrez un autre email.'
                        ]
                    ], 409);
                }
            }

            if (!$user) {
                // Création rapide d'un client via le Kiosk
                $user = new User();
                $user->name = $request->input('name') ?? 'Client Loyalty';
                $user->email = $email ?: null;
                $user->phone = $request->input('phone');
                $user->username = uniqid('kiosk_');
                $user->password = bcrypt(uniqid());
                $user->status = 1;
            } else {
                // [AUDIT-P50-BUG8] Update email on existing phone-based account if provided and empty
                if ($email && empty($user->email)) {
                    $user->email = $email;
                }
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

    /**
     * [SECURITY] Only staff/admin/manager roles can manually credit points.
     * Kiosk machine users cannot call this — points are awarded automatically via listener.
     */
    public function addPoints(Request $request)
    {
        // Only staff roles can add points manually (not kiosk machines or customers)
        $caller = $request->user();
        if (!$caller || !$caller->hasAnyRole(['admin', 'manager', 'staff'])) {
            return response()->json(['status' => false, 'message' => 'Non autorisé'], 403);
        }

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

            $newBalance = \Illuminate\Support\Facades\DB::transaction(function () use ($user, $pointsToAdd, $caller) {
                // Atomic increment — no race condition
                User::where('id', $user->id)->increment('loyalty_points', $pointsToAdd);
                $balance = (int) \Illuminate\Support\Facades\DB::table('users')
                    ->where('id', $user->id)
                    ->value('loyalty_points');

                if (\Illuminate\Support\Facades\Schema::hasTable('loyalty_transactions')) {
                    \Illuminate\Support\Facades\DB::table('loyalty_transactions')->insert([
                        'user_id'        => $user->id,
                        'loyalty_code'   => $user->loyalty_code,
                        'order_id'       => null,
                        'type'           => 'manual_add',
                        'points'         => $pointsToAdd,
                        'balance_after'  => $balance,
                        'source_surface' => 'admin',
                        'description'    => 'Ajout manuel par staff #' . $caller->id,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }
                return $balance;
            });

            Log::info("Loyalty addPoints: {$pointsToAdd} pts added to user #{$user->id} by staff #{$caller->id}");

            return response()->json([
                'status' => true,
                'message' => "{$pointsToAdd} points ajoutés",
                'data' => ['points' => $newBalance]
            ]);
        } catch (Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['status' => false, 'message' => 'Erreur serveur'], 500);
        }
    }

    /**
     * [SECURITY] Redeem can be called by:
     * - A kiosk machine user (tokenCan 'kiosk:order')
     * - A user redeeming their own code (code belongs to caller)
     * - Staff/admin roles
     */
    public function redeem(Request $request)
    {
        $caller = $request->user();
        $isKiosk = $caller && $caller->tokenCan('kiosk:order');
        $isStaff = $caller && $caller->hasAnyRole(['admin', 'manager', 'staff', 'cashier']);

        try {
            $validator = $this->validatePoints($request);
            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            // [ATOMIC] Use DB transaction + lockForUpdate to prevent race condition
            // Two simultaneous kiosk orders with the same loyalty_code could overdraw points
            $result = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $isKiosk, $isStaff, $caller) {
                $user = User::where('loyalty_code', $request->input('code'))->lockForUpdate()->first();
                if (!$user) {
                    return ['error' => 'Code introuvable', 'status' => 404];
                }

                // Non-kiosk, non-staff: only the owner of the code can redeem
                if (!$isKiosk && !$isStaff && $caller && $caller->id !== $user->id) {
                    return ['error' => 'Non autorisé', 'status' => 403];
                }

                $pointsToRedeem = (int) $request->input('points', 0);
                if ($pointsToRedeem <= 0) {
                    return ['error' => 'Points must be positive', 'status' => 400];
                }
                // [AUDIT-P49-BUG5] Validate points is a multiple of the conversion rate to prevent micro-transactions.
                // Rate: loyalty_points_for_1_euro_discount (default 100 points = 1€).
                $rate = (int) Settings::group('loyalty_setup')->get('loyalty_points_for_1_euro_discount', 100);
                if ($rate <= 0) {
                    $rate = 100;
                }
                if ($pointsToRedeem % $rate !== 0) {
                    $nearestLower = (int) (floor($pointsToRedeem / $rate) * $rate);
                    return ['error' => "Les points doivent être un multiple de {$rate}. Montant valide le plus proche : {$nearestLower}.", 'status' => 400];
                }
                if ($user->loyalty_points < $pointsToRedeem) {
                    return ['error' => 'Points insuffisants', 'status' => 400];
                }

                \Illuminate\Support\Facades\DB::table('users')
                    ->where('id', $user->id)
                    ->decrement('loyalty_points', $pointsToRedeem);

                // [AUDIT-P47-BUG1] Write redemption to loyalty_transactions ledger for audit trail.
                // Corrected to use actual column names from migration (not 'note' which doesn't exist).
                $balanceAfter = $user->loyalty_points - $pointsToRedeem;
                \App\Models\LoyaltyTransaction::create([
                    'user_id'        => $user->id,
                    'loyalty_code'   => $user->loyalty_code,
                    'order_id'       => null, // redemption is pre-order, no order_id yet
                    'type'           => 'redeem',
                    'points'         => -$pointsToRedeem,
                    'balance_after'  => $balanceAfter,
                    'source_surface' => $isKiosk ? 'kiosk' : 'pos',
                    'description'    => 'Réduction fidélité appliquée',
                ]);

                Log::info("Loyalty redeem: {$pointsToRedeem} pts redeemed from user #{$user->id}");

                return ['points' => $user->loyalty_points - $pointsToRedeem, 'user_id' => $user->id];
            });

            if (isset($result['error'])) {
                return response()->json(['status' => false, 'message' => $result['error']], $result['status']);
            }

            $pointsToRedeem = (int) $request->input('points', 0);
            return response()->json([
                'status' => true,
                'message' => "{$pointsToRedeem} points utilisés",
                'data' => ['points' => $result['points']]
            ]);
        } catch (Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['status' => false, 'message' => 'Erreur serveur'], 500);
        }
    }

    public function balance(Request $request)
    {
        return $this->check($request);
    }

    /**
     * [SPLASH] Return loyalty program configuration for the kiosk UI.
     * Kiosk needs to know the conversion rate to display "Vous économisez X€".
     */
    public function config(Request $request)
    {
        try {
            $pointsPerEuro  = (int)   Settings::group('loyalty_setup')->get('loyalty_points_per_euro', 10);
            $pointsFor1Euro = (int)   Settings::group('loyalty_setup')->get('loyalty_points_for_1_euro_discount', 100);
            $minRedeem      = (int)   Settings::group('loyalty_setup')->get('loyalty_min_redeem_points', 50);

            return response()->json([
                'status' => true,
                'data'   => [
                    'points_per_euro'             => $pointsPerEuro,
                    'points_for_1_euro_discount'  => $pointsFor1Euro,
                    'min_redeem_points'           => $minRedeem,
                    'label'                       => "Dépensez {$pointsFor1Euro} points = 1€ de remise",
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function history(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Paramètres invalides',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['status' => false, 'message' => 'Non authentifié'], 401);
            }

            $perPage = (int) $request->input('per_page', 20);

            // Read from loyalty_transactions ledger if it exists, otherwise fall back to orders
            $hasLedger = \Illuminate\Support\Facades\Schema::hasTable('loyalty_transactions');

            if ($hasLedger) {
                $transactions = \Illuminate\Support\Facades\DB::table('loyalty_transactions')
                    ->where('user_id', $user->id)
                    ->orderByDesc('created_at')
                    ->paginate($perPage);

                $items = collect($transactions->items())->map(function ($row) {
                    return [
                        'type'           => $row->type,
                        'points'         => $row->points,
                        'balance_after'  => $row->balance_after,
                        'source_surface' => $row->source_surface,
                        'description'    => $row->description,
                        'date'           => $row->created_at,
                    ];
                });

                return response()->json([
                    'status' => true,
                    'data'   => $items,
                    'meta'   => [
                        'current_page' => $transactions->currentPage(),
                        'last_page'    => $transactions->lastPage(),
                        'total'        => $transactions->total(),
                        'per_page'     => $transactions->perPage(),
                    ],
                ]);
            }

            // Fallback: read from orders table directly (before ledger migration runs)
            $loyaltyCode = $user->loyalty_code;
            $query = \Illuminate\Support\Facades\DB::table('orders')
                ->where(function ($q) use ($user, $loyaltyCode) {
                    $q->where('user_id', $user->id);
                    if ($loyaltyCode) {
                        $q->orWhere('loyalty_customer_code', $loyaltyCode);
                    }
                })
                ->whereNotNull('loyalty_points_awarded')
                ->where('loyalty_points_awarded', '>', 0)
                ->orderByDesc('created_at');

            $orders = $query->paginate($perPage);

            $items = collect($orders->items())->map(function ($row) {
                return [
                    'type'           => 'earn',
                    'points'         => $row->loyalty_points_awarded,
                    'balance_after'  => null,
                    'source_surface' => $row->source_surface ?? null,
                    'description'    => 'Commande #' . ($row->order_serial_no ?? $row->id),
                    'date'           => $row->created_at,
                ];
            });

            return response()->json([
                'status' => true,
                'data'   => $items,
                'meta'   => [
                    'current_page' => $orders->currentPage(),
                    'last_page'    => $orders->lastPage(),
                    'total'        => $orders->total(),
                    'per_page'     => $orders->perPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Loyalty] history: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erreur serveur'], 500);
        }
    }

    /**
     * [SPLASH] Convert N loyalty points to a discount amount in €.
     * Rate from settings: loyalty_points_for_1_euro_discount (default 100).
     */
    private function pointsToDiscount(int $points): float
    {
        try {
            $rate = (int) Settings::group('loyalty_setup')->get('loyalty_points_for_1_euro_discount', 100);
            if ($rate <= 0) return 0.0;
            return round($points / $rate, 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }
}
