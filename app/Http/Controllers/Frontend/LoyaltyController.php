<?php

namespace App\Http\Controllers\Frontend;

use Exception;
use App\Http\Requests\Kiosk\LoyaltyOptInRequest;
use App\Models\LoyaltyConsent;
use App\Models\User;
use App\Services\Loyalty\LoyaltyQrInvalidException;
use App\Services\Loyalty\LoyaltyQrSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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

            if ($user && $this->isCustomerActive($user)) { // [FIX P3 audit] accepte status 1 OU ACTIVE(5) (cohérent avec le reste)
                // [ULTRA-AUDIT V3 2026-07-02 — P2 IDOR JUMEAU] /check fuyait name+loyalty_code+points
                // de N'IMPORTE QUEL code/téléphone à tout token Sanctum (reverse phone→nom + énumération
                // PII). Même classe de fuite que /register et /scan colmatés en V2 — mais /check oublié.
                // Parité avec /redeem : autorisé pour une VRAIE borne (KioskMachine, pas un token guest),
                // le staff, OU le propriétaire du code. Un guest ne peut consulter QUE son propre compte.
                $caller = $request->user();
                $isKiosk = $caller
                    && $caller->tokenCan('kiosk:order')
                    && \App\Models\KioskMachine::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                        ->where('user_id', $caller->id)->exists();
                $isStaff = $caller && $caller->hasAnyRole(['Admin', 'Branch Manager', 'POS Operator', 'Stuff']);
                if (! $isKiosk && ! $isStaff && (! $caller || (int) $caller->id !== (int) $user->id)) {
                    // Non-borne, non-staff, non-propriétaire → ne rien divulguer (indistinguable de « non trouvé »).
                    return response()->json(['status' => false, 'message' => 'Non trouvé'], 404);
                }

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
                    // Email belongs to a different account.
                    // [SEC-LOYALTY-LEAK 2026-07-02] Endpoint PUBLIC (api/frontend/loyalty/register) :
                    // NE JAMAIS renvoyer le loyalty_code + phone du titulaire de cet email (fuite PII
                    // par simple énumération d'emails). On confirme seulement l'existence + un conseil
                    // neutre ; le titulaire légitime récupère son compte via le lookup par SON code /
                    // téléphone, jamais en connaissant juste un email tiers.
                    return response()->json([
                        'status' => false,
                        'code' => 'EMAIL_EXISTS',
                        'message' => 'Cet email est déjà associé à un compte fidélité.',
                        'data' => [
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
                // [ULTRA-AUDIT V2 2026-07-02 — P2 account-hijack] NE PLUS attacher un email à un
                // compte EXISTANT via /register (public, non-auth). L'ancien code (AUDIT-P50-BUG8)
                // permettait : attaquant POST {phone: victime-sans-email, email: attaquant} →
                // email attaché au compte victime → forgot-password → prise de contrôle. L'email
                // n'est désormais posé qu'à la CRÉATION d'un NOUVEAU compte (branche if ci-dessus).
                // Un client existant ajoute/modifie son email via un chemin AUTHENTIFIÉ + vérifié.
                // (no-op ici : compte préexistant → email inchangé)
            }

            // Génération d'un code de fidélité s'il n'en a pas
            if (!$user->loyalty_code) {
                $user->loyalty_code = strtoupper(substr(md5(uniqid()), 0, 8)); // ex: A1B2C3D4
                $user->loyalty_points = 0;
            }
            $user->save();

            // [SEC-LOYALTY-LEAK 2026-07-02] Vecteur PRINCIPAL de la fuite PII (raté au 1er fix) :
            // `phone` est REQUIS ; un compte trouvé par téléphone tombait ici et renvoyait
            // name+loyalty_code+points D'AUTRUI sur un endpoint PUBLIC (routes/api.php:1434, sans
            // auth) → énumération de téléphones = récolte PII. `/register` est destiné à CRÉER un
            // NOUVEAU compte (cf. commentaire routes/api.php:1429) : on ne renvoie les données QUE
            // pour un compte réellement créé par CETTE requête (wasRecentlyCreated). Un compte
            // préexistant → réponse neutre ; le titulaire récupère son compte via /loyalty/check
            // (auth:sanctum) ou l'OTP.
            if (! $user->wasRecentlyCreated) {
                return response()->json([
                    'status' => true,
                    'code' => 'PHONE_EXISTS',
                    'message' => 'Un compte fidélité existe déjà pour ce numéro.',
                ]);
            }

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
        if (!$caller || !$caller->hasAnyRole(['Admin', 'Branch Manager', 'POS Operator', 'Stuff'])) {
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
        // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-31 Q3] Pre-redeem debits loyalty
        // points immediately in its own transaction. While discretionary discounts
        // are disabled in V1 (pos.manual_discount_enabled !== true) the downstream
        // discounted order is refused by the order-creation gate, which would strand
        // the already-debited points (separate committed txn, not rolled back).
        // Refuse the redeem at the source so points are NEVER debited while discounts
        // are off. Re-enabled automatically when the flag flips on (post F1-fix).
        if (config('pos.manual_discount_enabled') !== true) {
            return response()->json([
                'status' => false,
                'message' => "La fidélité (remise) est désactivée en V1 (correction fiscale TVA/HT en attente).",
            ], 422);
        }

        $caller = $request->user();
        // [GOAL-2026-05-29 SEC-P1 LOYALTY-IDOR] The kiosk discriminator must NOT
        // rely on the `kiosk:order` ABILITY alone: GuestSignupController mints
        // ordinary guest tokens (`auth_token`, branch_id=0) WITH the
        // `['kiosk:order']` ability, so a plain guest satisfied tokenCan(...) and
        // — because the owner-check at L283 is skipped when $isKiosk — could
        // redeem (debit) ANOTHER user's loyalty points by supplying their code
        // (IDOR / points theft). Same root as the channels.php fix. Require a
        // REAL KioskMachine row for the caller: a true kiosk machine user has one
        // (KioskMachineTableSeeder), a guest never does. Preserves legit kiosk
        // redemption (kiosk machine redeems on behalf of the customer's code)
        // while a guest falls through to the owner-only check.
        $isKiosk = $caller
            && $caller->tokenCan('kiosk:order')
            && \App\Models\KioskMachine::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('user_id', $caller->id)
                ->exists();
        $isStaff = $caller && $caller->hasAnyRole(['Admin', 'Branch Manager', 'POS Operator', 'Stuff']);

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
     * Kiosk Design V1 — Phase 1.8
     *
     * `POST /api/frontend/loyalty/opt-in` — adhésion avec consentement RGPD
     * explicite obligatoire (`consent_accepted: required|accepted`).
     *
     * Différences vs `register()` :
     *  - Exige `privacy_notice_version` (audit trail).
     *  - Écrit un log `loyalty_consents` avec IP et UA hashés (sha256+salt).
     *  - Délègue ensuite la création/mise à jour à `register()` (réutilise
     *    la logique existante — pas de duplication de code loyalty_code
     *    generation).
     *
     * Rate-limit à définir côté route (ex. `throttle:5,1`).
     */
    public function optIn(LoyaltyOptInRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            $registerResponse = $this->register($request);

            // Si la création a échoué (409 email conflict, 422 validation, 500), on
            // ne logge PAS le consentement — évite de compter un opt-in avorté.
            $status = $registerResponse->getStatusCode();
            if ($status >= 400) {
                return $registerResponse;
            }

            // Récupère l'utilisateur créé / mis à jour via le phone ou email
            // pour lier le consentement.
            $user = null;
            if (!empty($data['phone'])) {
                $user = User::where('phone', $data['phone'])->first();
            }
            if (!$user && !empty($data['email'])) {
                $user = User::where('email', $data['email'])->first();
            }

            if ($user) {
                LoyaltyConsent::create([
                    'user_id'                => $user->id,
                    'consent_accepted'       => true,
                    'privacy_notice_version' => (string) $data['privacy_notice_version'],
                    'ip_hash'                => LoyaltyConsent::hashIdentifier($request->ip()),
                    'user_agent_hash'        => LoyaltyConsent::hashIdentifier((string) $request->userAgent()),
                    'occurred_at'            => now(),
                ]);
            }

            return $registerResponse;
        } catch (Exception $e) {
            Log::error('[LoyaltyOptIn] '.$e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Erreur serveur.',
            ], 500);
        }
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
            $rawTiers       = Settings::group('loyalty_setup')->get('loyalty_tiers', '100,250,500,1000,2000');

            if (is_string($rawTiers)) {
                $tiers = collect(explode(',', $rawTiers))
                    ->map(fn ($tier) => (int) trim($tier))
                    ->filter(fn ($tier) => $tier > 0)
                    ->values()
                    ->all();
            } elseif (is_array($rawTiers)) {
                $tiers = collect($rawTiers)
                    ->map(fn ($tier) => (int) $tier)
                    ->filter(fn ($tier) => $tier > 0)
                    ->values()
                    ->all();
            } else {
                $tiers = [];
            }

            if (empty($tiers)) {
                $tiers = [100, 250, 500, 1000, 2000];
            }

            return response()->json([
                'status' => true,
                'data'   => [
                    'points_per_euro'             => $pointsPerEuro,
                    'points_for_1_euro_discount'  => $pointsFor1Euro,
                    'min_redeem_points'           => $minRedeem,
                    'tiers'                       => $tiers,
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
     * Kiosk Design V1 — Phase 8.3
     *
     * `POST /api/frontend/loyalty/scan` — résout un scan QR/NFC en profil
     * fidélité anonyme (conformément au DATA_CONTRACT §7).
     *
     * Invariants :
     *  - Auth : Sanctum + ability `kiosk:order` (idem /menu, /pricing/preview).
     *  - branch_id : lu via KioskMachine, jamais payload.
     *  - Pas de PII en clair dans la réponse au-delà du prénom (`display_name`).
     *  - Pas de `customer_id` ni d'email/téléphone en réponse.
     *  - En cas d'échec (QR inconnu, NFC non provisionné), renvoie HTTP 200
     *    avec `ok=false` + `error_code` pour ne PAS bloquer le parcours client
     *    (le scan est optionnel).
     *
     * Payload accepté :
     *   { "method": "qr"|"nfc", "raw_data": "<opaque string>" }
     *
     * Formats QR acceptés V1 :
     *   - "FK:<loyalty_code>"      — préfixé (préconisé)
     *   - "<loyalty_code>"         — brut (8 chars alphanum)
     *   - "<E164_phone>"           — fallback téléphone si MSISDN détecté
     *
     * Format NFC V1 : non provisionné → renvoie `ok=false` immédiatement.
     */
    public function scan(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user || !$user->tokenCan('kiosk:order')) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Accès kiosk requis.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'method'   => ['required', 'string', 'in:qr,nfc'],
                'raw_data' => ['required', 'string', 'min:1', 'max:512'],
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Paramètres invalides',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $method  = (string) $request->input('method');
            $raw     = trim((string) $request->input('raw_data'));

            // V1 : NFC non provisionné. Parcours continue en mode anonyme.
            if ($method === 'nfc') {
                return response()->json([
                    'status' => true,
                    'data'   => $this->emptyLoyaltyScanResponse('nfc_not_provisioned'),
                ], 200);
            }

            // -- QR : parsing robuste -----------------------------------
            // Priority 1 : signed token "lqr.<payload>.<hmac>" (LCS-S-001 heal).
            //   - HMAC verified server-side, exp + nonce checked.
            //   - On any failure → HTTP 200 + error_code (invariant §12).
            // Priority 2 : legacy plaintext "FK:<code>" or bare "<code>".
            //   - Accepted while LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT=true so
            //     pre-heal mobile clients keep working. Each call is logged
            //     (channel : loyalty.qr.legacy_plaintext) so V1.0.X retirement
            //     can be evidence-driven. X-Loyalty-QR-Status: legacy header
            //     surfaces the deprecation without changing the JSON contract.
            // ---------------------------------------------------------------
            $signer = app(LoyaltyQrSigner::class);
            $target = null;
            $deprecationHeader = null;

            if ($signer->isSignedToken($raw)) {
                try {
                    $payload = $signer->verifyAndConsume($raw, 'kiosk');
                    $code = strtoupper(trim((string) ($payload['code'] ?? '')));
                    if ($code !== '') {
                        $target = User::where('loyalty_code', $code)->first();
                    }
                    if (! $target || ! $this->isCustomerActive($target)) {
                        // Signed payload was valid but the customer row vanished
                        // (rotation / deletion). Still HTTP 200, parcours continues.
                        return response()->json([
                            'status' => true,
                            'data'   => $this->emptyLoyaltyScanResponse('customer_not_found'),
                        ], 200);
                    }
                } catch (LoyaltyQrInvalidException $e) {
                    // Stable machine-readable error_code surfaces to kiosk JS so
                    // it can decide UX (show "QR expired, please regenerate"
                    // for `qr_expired` etc). HTTP stays 200 per §12.
                    Log::info('[loyalty.qr.signed_reject] ' . $e->errorCode, [
                        'surface'    => 'kiosk',
                        'error_code' => $e->errorCode,
                    ]);
                    return response()->json([
                        'status' => true,
                        'data'   => $this->emptyLoyaltyScanResponse($e->errorCode),
                    ], 200);
                }
            } else {
                // Legacy plaintext path (deprecated, gated by config flag).
                $acceptLegacy = (bool) Config::get('loyalty.qr.accept_legacy_plaintext', true);
                if (! $acceptLegacy) {
                    Log::warning('[loyalty.qr.legacy_plaintext_rejected]', [
                        'surface' => 'kiosk',
                    ]);
                    return response()->json([
                        'status' => true,
                        'data'   => $this->emptyLoyaltyScanResponse('qr_legacy_rejected'),
                    ], 200);
                }

                $deprecationHeader = 'legacy-plaintext';

                $code = $raw;
                if (stripos($raw, 'FK:') === 0) {
                    $code = substr($raw, 3);
                }
                $code = trim($code);

                Log::info('[loyalty.qr.legacy_plaintext]', [
                    'surface'  => 'kiosk',
                    'code_len' => strlen($code),
                    'has_fk_prefix' => stripos($raw, 'FK:') === 0,
                ]);

                // Recherche loyalty_code d'abord, puis phone E.164 si non trouvé.
                if ($code !== '') {
                    $target = User::where('loyalty_code', strtoupper($code))->first();
                    if (! $target) {
                        $phone = preg_replace('/[\s\-]/', '', $code);
                        if ($phone && preg_match('/^\+?\d{6,15}$/', $phone)) {
                            $target = User::where('phone', $phone)->first();
                        }
                    }
                }

                if (! $target || ! $this->isCustomerActive($target)) {
                    // Ne jamais renvoyer 404 → parcours doit continuer (invariant §12).
                    return response()->json([
                        'status' => true,
                        'data'   => $this->emptyLoyaltyScanResponse('customer_not_found'),
                    ], 200)->header('X-Loyalty-QR-Status', $deprecationHeader);
                }
            }

            // -- Token opaque éphémère (pas d'id exposé) ------------------
            // Préfixé 'lt_' + HMAC-SHA256(user_id | unix_ts | random_bytes(16)).
            // Server-only usage (loyalty/balance ou pricing/preview le ré-acceptent).
            //
            // [GOAL-J2-HEAL-03 2026-05-24] Phase J-ADV-7 HC-003 P0:
            // Customer token previously used SHA256(user_id|unix_timestamp|APP_KEY)
            // truncated to 128 bits — NO HMAC despite LOYALTY_QR_SECRET existing
            // for QR signing elsewhere. Second-resolution timestamp = predictable
            // window enabling enumeration.
            //
            // Fix: HMAC-SHA256 using LOYALTY_QR_SECRET (matches QR signing
            // pattern, mirrors LoyaltyQrSigner). random_bytes(16) defeats
            // timestamp-prediction attacks. Output full 256-bit (64 hex chars)
            // for stronger entropy. `lt_` prefix preserved (contract).
            //
            // Use `?:` fallback (not config() default arg) because
            // LOYALTY_QR_SECRET defaults to '' (config/loyalty.php:32) — the
            // key is present-but-empty in dev/test, so config()'s default arg
            // would never trigger. Production boot guard refuses empty.
            $secret = config('loyalty.qr.secret') ?: (string) config('app.key');
            $payload = $target->id.'|'.now()->timestamp.'|'.bin2hex(random_bytes(16));
            $customerToken = 'lt_'.hash_hmac('sha256', $payload, $secret);

            $displayName = (string) ($target->name ?: '');
            $firstName = trim(explode(' ', $displayName)[0] ?? '');
            // Cap conservateur : max 40 chars (évite PII longue).
            if (mb_strlen($firstName) > 40) {
                $firstName = mb_substr($firstName, 0, 40);
            }

            $points = (int) ($target->loyalty_points ?? 0);
            $declaredAllergens = $this->readDeclaredAllergens($target);

            $response = response()->json([
                'status' => true,
                'data'   => [
                    'ok'                     => true,
                    'customer_token'         => $customerToken,
                    'display_name'           => $firstName !== '' ? $firstName : null,
                    'declared_allergens'     => $declaredAllergens,
                    'loyalty_balance_points' => $points,
                    'last_order'             => null,   // V1 — pas de Turbo côté serveur
                    'error_code'             => null,
                ],
            ], 200);

            // [LCS-S-001] Surface the legacy-plaintext deprecation via response
            // header so observability + kiosk JS can track migration progress
            // without changing the JSON shape (frontend remains compatible).
            if ($deprecationHeader !== null) {
                $response->header('X-Loyalty-QR-Status', $deprecationHeader);
            } else {
                $response->header('X-Loyalty-QR-Status', 'signed');
            }

            return $response;
        } catch (\Throwable $e) {
            Log::error('[LoyaltyScan] '.$e->getMessage());
            return response()->json([
                'status' => true,
                'data'   => $this->emptyLoyaltyScanResponse('scan_error'),
            ], 200);
        }
    }

    /**
     * [LCS-S-001 / 2026-05-19] Mint a fresh signed QR token for the
     * authenticated customer.
     *
     * POST /api/frontend/loyalty/qr (auth:sanctum, NOT kiosk:order).
     *
     * The mobile / web client calls this on a 5-min interval (matches the
     * UI rotation pace already in place — see mobile/components/LoyaltyQR.jsx
     * comments). The returned token is opaque to the client and MUST be
     * presented as `raw_data` to /loyalty/scan.
     *
     * No mobile-side change is required for this endpoint to ship — the
     * deferred mobile cycle (V1.0.X) will wire it. Until then, the legacy
     * plaintext `FK:<code>` path stays accepted (gated by
     * LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT).
     */
    public function generateQr(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Authentification requise.',
                ], 401);
            }

            $loyaltyCode = (string) ($user->loyalty_code ?? '');
            if ($loyaltyCode === '') {
                // Mint one on-demand so the response is never empty for an
                // authenticated customer. Same upper-8 hex pattern as
                // LoyaltyController::check (line 82).
                $loyaltyCode = strtoupper(substr(md5(uniqid()), 0, 8));
                $user->loyalty_code = $loyaltyCode;
                $user->save();
            }

            $signed = app(LoyaltyQrSigner::class)->sign(
                (int) $user->id,
                $loyaltyCode,
            );

            return response()->json([
                'status' => true,
                'data'   => [
                    'token'        => $signed['token'],
                    'expires_at'   => $signed['expires_at'],
                    'ttl_seconds'  => (int) Config::get('loyalty.qr.ttl_seconds', 300),
                    'loyalty_code' => $loyaltyCode, // for UI display only
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[LoyaltyQrGenerate] ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Impossible de générer le QR. Réessayez.',
            ], 500);
        }
    }

    /**
     * Renvoie une réponse scan négative (sans PII). On conserve HTTP 200
     * pour ne pas bloquer le parcours kiosk (l'invariant 5 du DATA_CONTRACT
     * §12 stipule qu'un scan raté ne doit jamais renvoyer 401/403).
     */
    private function emptyLoyaltyScanResponse(string $errorCode): array
    {
        return [
            'ok'                     => false,
            'customer_token'         => null,
            'display_name'           => null,
            'declared_allergens'     => [],
            'loyalty_balance_points' => 0,
            'last_order'             => null,
            'error_code'             => $errorCode,
        ];
    }

    /**
     * Customer-active predicate used by /loyalty/scan.
     *
     * Historical code compared `status == 1` because the User model used 1
     * as the active flag before the Status enum was introduced (ACTIVE = 5).
     * Both values are still found in production rows (legacy seeders / data
     * migrations). Accepting either keeps the legacy plaintext path working
     * for existing accounts while aligning with the H1 Z6-06
     * EnsureUserStatusActive middleware which uses Status::ACTIVE.
     */
    private function isCustomerActive(\App\Models\User $target): bool
    {
        $status = (int) ($target->status ?? 0);
        return $status === 1 || $status === (int) \App\Enums\Status::ACTIVE;
    }

    /**
     * Lit les allergènes déclarés par le client si la colonne
     * `users.declared_allergens` existe (migration future). Fallback: [].
     *
     * @return array<int, string>
     */
    private function readDeclaredAllergens(User $user): array
    {
        try {
            $val = $user->declared_allergens ?? null;
            if (is_array($val)) {
                return array_values(array_filter(array_map('strval', $val)));
            }
            if (is_string($val) && $val !== '') {
                $decoded = json_decode($val, true);
                if (is_array($decoded)) {
                    return array_values(array_filter(array_map('strval', $decoded)));
                }
            }
        } catch (\Throwable $e) {
            // Silencieux — parcours doit continuer.
        }
        return [];
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
