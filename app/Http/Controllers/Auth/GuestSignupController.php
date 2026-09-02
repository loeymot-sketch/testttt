<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Http\Requests\GuestEmailLoginRequest;
use App\Http\Requests\GuestSignupEmailOtpRequest;
use App\Http\Requests\GuestSignupPhoneRequest;
use Illuminate\Http\Request;
use App\Mail\SignupOtpMail;
use App\Http\Resources\MenuResource;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\UserResource;
use App\Libraries\AppLibrary;
use App\Services\DefaultAccessService;
use App\Services\MenuService;
use App\Services\PermissionService;
use Carbon\Carbon;
use Exception;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Enums\Role as EnumRole;
use App\Services\OtpManagerService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\VerifyPhoneRequest;
use Smartisan\Settings\Facades\Settings;

class GuestSignupController extends Controller
{
    private OtpManagerService $otpManagerService;
    public string $token;
    public DefaultAccessService $defaultAccessService;
    public PermissionService $permissionService;
    public MenuService $menuService;

    public function __construct(
        OtpManagerService $otpManagerService,
        MenuService $menuService,
        PermissionService $permissionService,
        DefaultAccessService $defaultAccessService
    ) {
        $this->otpManagerService    = $otpManagerService;
        $this->menuService          = $menuService;
        $this->permissionService    = $permissionService;
        $this->defaultAccessService = $defaultAccessService;
    }


    public function otp(GuestSignupPhoneRequest $request
    ) : \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $this->otpManagerService->otp($request);

            $payload = ['status' => true, 'message' => trans("all.message.check_your_phone_for_code")];

            // [W16 DEV-OTP 2026-07-20 · DURCI 2026-07-30 SEC-DEVCODE] Aide dev : renvoyer le
            // code OTP fraîchement généré dans `dev_code`. RESTREINT à l'environnement `local`
            // (machine développeur) UNIQUEMENT — JAMAIS sur un box DÉPLOYÉ public (staging OU
            // production). Raison du durcissement : l'audit adversaire 2026-07-30 a montré que
            // sur le VPS `staging` (public, données réelles, clé Mollie live) l'ancienne garde
            // `!production` laissait fuiter le VRAI OTP de n'importe quel numéro → bypass d'auth
            // invité (l'x-api-key est public). La testabilité staging passe désormais par le
            // canal EMAIL (emailOtp/Brevo délivre le code) ou la lecture directe de otps.token
            // en base pour l'e2e — plus besoin de fuiter le code sur un box public. Le preflight
            // go-live (PreflightProductionCommand) reste le second verrou.
            if (app()->environment('local')) {
                try {
                    $smsEnforced     = (int) Settings::group('site')->get('site_phone_verification') === Activity::ENABLE;
                    $smsGatewayWired = ! blank(Settings::group('site')->get('site_default_sms_gateway'));
                    if (! $smsEnforced || ! $smsGatewayWired) {
                        $devCode = DB::table('otps')
                            ->where('phone', $request->post('phone'))
                            ->latest('created_at')
                            ->value('token');
                        if (! blank($devCode)) {
                            $payload['dev_code'] = (string) $devCode;
                        }
                    }
                } catch (\Throwable $e) {
                    // Best-effort dev helper — ne doit JAMAIS casser l'envoi OTP.
                }
            }

            return response($payload);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [WAVE C EMAIL-OTP 2026-07-28 — GOAL_WEB_COMMANDE_CLIENT §5] Canal EMAIL
     * pour le code signup web : le flux SMS (SendSmsCode) n'a AUCUN provider
     * câblé (mandat owner : pas de SMS, coût) → le client ne recevait jamais
     * le code. Ici : même génération OTP (OtpManagerService::otp — ligne otps,
     * phone=clé, token=code, anti-flood GAP-20 inchangé), mais le code part
     * par EMAIL (SignupOtpMail, envoi SYNCHRONE — pas de dépendance queue).
     * L'email est lié au téléphone en cache (email_otp_email:<phone>, TTL =
     * expiry OTP) et sera persisté sur le User par register() AU succès du
     * verify (contrat /verify inchangé). Réponse identique que l'email existe
     * déjà ou non (anti-énumération).
     */
    public function emailOtp(GuestSignupEmailOtpRequest $request
    ) : \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            // Indicatif pays optionnel côté web — défaut FR (+33), colonne otps.code.
            if (blank($request->post('code'))) {
                $request->merge(['code' => '+33']);
            }

            $this->envoyerCodeParEmail($request);

            $payload = ['status' => true, 'message' => trans('all.message.check_your_email_for_code')];
            $this->ajouterCodeDev($payload, (string) $request->post('phone'));

            return response($payload);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [APP MOBILE 2026-09-02 — GOAL_APP_MOBILE_APPSTORE §A1] Connexion « e-mail d'abord ».
     *
     * Demande propriétaire : « s'il a un compte, juste un mail, il reçoit le code ; s'il n'a
     * pas de compte, sans changer de page, on lui demande le prénom et le téléphone ». UN seul
     * écran côté client, deux formes ici :
     *   - {email}                     → compte invité connu : code posé sur le téléphone du compte
     *                                    (clé fidélité) — ou sur `email:<adresse>` si le compte n'a
     *                                    pas de numéro (connexion Apple/Google) —, envoyé à l'e-mail
     *                                    DU COMPTE, jamais à une adresse fournie. Réponse known:true.
     *                                    Inconnu : known:false, rien n'est envoyé, rien n'est créé.
     *   - {email, first_name, phone}  → inscription : MÊME moteur que email-otp (garde
     *                                    anti-usurpation de canal conservée, cf. envoyerCodeParEmail).
     *
     * Choix ASSUMÉ (propriétaire) : `known` révèle qu'un e-mail a un compte — c'est ce qui permet
     * de ne rien demander d'autre à celui qui en a un. Mitigation : débit `otp-send` (5/min par
     * e-mail + 20/min global) ; un compte non-invité (staff) est TOUJOURS « inconnu » ici.
     *
     * PAS de code fixe pour la revue App Store. J'en avais posé un (APP_REVIEW_EMAIL /
     * APP_REVIEW_OTP) : `app/PUBLICATION.md §8` du dépôt tranche l'inverse, et a raison —
     * « un code fixe pour une adresse connue est une porte qu'on oublie de refermer ». Le
     * garde-fou habituel du projet (interdire en production) ne s'applique pas ici, puisque
     * Apple examine l'application CONTRE la production. L'examinateur reçoit donc son code
     * comme tout le monde, dans une boîte de démonstration dédiée dont les identifiants sont
     * donnés en notes de revue (procédure : PUBLICATION.md §8).
     */
    public function emailLogin(GuestEmailLoginRequest $request
    ) : \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $email  = mb_strtolower(trim((string) $request->post('email')));
            $compte = $this->compteInviteParEmail($email);

            if ($compte) {
                $cle = self::cleOtpDuCompte($compte, $email);
                $this->poser($request, ['phone' => $cle, 'code' => filled($compte->country_code) ? $compte->country_code : '+33']);
                $this->otpManagerService->otp($request, dispatchSms: false);

                $ttlMinutes = max(1, (int) Settings::group('otp')->get('otp_expire_time') ?: 5);
                $token = DB::table('otps')->where('phone', $cle)->latest('created_at')->value('token');
                if (! blank($token) && filled($compte->email)) {
                    Mail::to($compte->email)->send(new SignupOtpMail((string) $token, $ttlMinutes));
                }

                $payload = ['status' => true, 'known' => true, 'message' => trans('all.message.check_your_email_for_code')];
                $this->ajouterCodeDev($payload, $cle);

                return response($payload);
            }

            // Inconnu, sans identité : l'écran client déplie prénom + téléphone. Rien n'est envoyé.
            if (blank($request->post('phone')) || blank($request->post('first_name'))) {
                return response([
                    'status'  => true,
                    'known'   => false,
                    'sent'    => false,
                    'message' => 'Aucun compte avec cet e-mail. Indique ton prénom et ton numéro pour en créer un.',
                ]);
            }

            // Inscription : moteur email-otp (le téléphone reste la clé fidélité, l'e-mail le canal).
            if (blank($request->post('code'))) {
                $this->poser($request, ['code' => '+33']);
            }
            $this->envoyerCodeParEmail($request);

            $payload = ['status' => true, 'known' => false, 'sent' => true, 'message' => trans('all.message.check_your_email_for_code')];
            $this->ajouterCodeDev($payload, (string) $request->post('phone'));

            return response($payload);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Pose des valeurs SUR LES DEUX sources d'entrée d'une FormRequest JSON.
     *
     * Piège Laravel vérifié en test : pour une requête JSON, `FormRequest::createFrom()`
     * COPIE le corps dans le sac `request` (lu par `->post()`) et garde le sac `json`
     * (lu par `->input()` / `$request->phone`) à part. `merge()` n'écrit QUE dans le sac
     * `json` : `OtpManagerService` lit `->post('phone')` et ne voyait donc jamais la clé
     * posée ici — le code était créé sur une clé et vérifié sur une autre.
     */
    private function poser(Request $request, array $valeurs): void
    {
        $request->merge($valeurs);
        foreach ($valeurs as $cle => $valeur) {
            $request->request->set($cle, $valeur);
        }
    }

    /** Compte INVITÉ (jamais staff) portant cet e-mail, y compris soft-deleted (restauré au verify). */
    private function compteInviteParEmail(string $email): ?User
    {
        if ($email === '') {
            return null;
        }

        return User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->withTrashed()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_guest', Ask::YES)
            ->orderBy('deleted_at') // un compte vivant prime sur un compte supprimé portant le même e-mail
            ->first();
    }

    /**
     * Clé otps d'un compte SANS numéro (connexion Apple/Google, téléphone pas encore donné).
     * Ne passe jamais ValidPhone ni ne devient un téléphone de compte : register() reçoit le
     * compte déjà résolu et ne crée rien à partir de cette clé.
     */
    public static function cleSansNumero(string $email): string
    {
        return 'email:'.mb_strtolower(trim($email));
    }

    /**
     * Clé otps d'un compte connu : son numéro s'il est JOIGNABLE, sinon la clé synthétique.
     * Vérifié en test : un compte créé sans numéro (connexion Apple/Google) ne porte pas `null`
     * mais un jalon `PENDING_CREATE_…` (colonne non nulle) — `filled()` le prendrait pour un
     * numéro. `PhoneDisplay::safe()` est le seul juge de « joignable », comme pour la commande.
     */
    private static function cleOtpDuCompte(User $compte, string $email): string
    {
        return \App\Support\PhoneDisplay::safe($compte->phone) !== null
            ? (string) $compte->phone
            : self::cleSansNumero($email);
    }

    /**
     * [W16 DEV-OTP · même garde que otp()] Renvoie le code dans `dev_code` en environnement
     * `local` UNIQUEMENT (machine développeur, banc E2E) — jamais sur un box déployé.
     */
    private function ajouterCodeDev(array &$payload, string $cle): void
    {
        if (! app()->environment('local')) {
            return;
        }
        try {
            $devCode = DB::table('otps')->where('phone', $cle)->latest('created_at')->value('token');
            if (! blank($devCode)) {
                $payload['dev_code'] = (string) $devCode;
            }
        } catch (\Throwable $e) {
            // Best-effort — ne doit JAMAIS casser l'envoi.
        }
    }

    /**
     * Moteur commun email-otp / email-login (inscription) : génère le code (ligne otps,
     * phone = clé), décide OÙ il part (garde anti-usurpation de canal), l'envoie par e-mail
     * et mémorise l'identité saisie pour le verify.
     *
     * @throws Exception
     */
    private function envoyerCodeParEmail(Request $request): void
    {
            $this->otpManagerService->otp($request, dispatchSms: false);

            // otp() ne retourne pas le code — relecture de la ligne fraîche (pattern dev_code W16).
            $token = DB::table('otps')
                ->where('phone', $request->post('phone'))
                ->latest('created_at')
                ->value('token');

            if (! blank($token)) {
                // [SEC HEAL 2026-07-30 · CHANNEL-CONFUSION] Anti prise-de-contrôle par canal.
                // Si le téléphone est DÉJÀ rattaché à un compte invité PORTANT un email, le code
                // ne part QUE vers l'email LIÉ AU COMPTE — jamais vers l'email fourni par l'appelant.
                // Sinon un attaquant connaissant un numéro se fait livrer le code sur SON email puis
                // verify()→loginUsingId(victime) = token sur le compte victime. Pour un NOUVEAU
                // numéro (aucun compte) l'email fourni EST le canal légitime d'inscription. Réponse
                // identique dans les deux cas (anti-énumération : l'appelant ne sait pas où part le code).
                // [P0-2 SÉCU 2026-08-04] withTrashed() : un compte invité SOFT-DELETED À VALEUR
                // (points fidélité = argent, PII) était invisible ici → le code partait vers
                // l'email de l'APPELANT (attaquant) → verify le restaure → takeover. register()
                // le retrouve déjà avec withTrashed() (GAP-32-3) — on aligne la garde de livraison.
                $existing = User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->withTrashed()
                    ->where('phone', $request->post('phone'))
                    ->where('is_guest', Ask::YES)
                    ->first();
                // [SEC MISSION-1 2026-07-31] Résidu channel-confusion fermé (compte SANS email).
                // - compte AVEC email → uniquement l'email LIÉ (correctif 07-30, inchangé).
                // - compte téléphone-seul AYANT DE LA VALEUR (points fidélité OU commandes) → on NE
                //   délivre PAS vers l'email APPELANT : l'email-OTP prouve la possession de l'EMAIL,
                //   pas du TÉLÉPHONE ; sinon un attaquant connaissant le numéro réclame le compte de
                //   la victime (points = argent, PII). Aucun code livré pour lui — réponse générique
                //   inchangée (anti-énumération). Le canal SMS otp() (preuve téléphone) n'est PAS affecté.
                // - nouveau numéro OU compte vide (rien à voler) → l'email appelant est le canal légitime.
                if ($existing && filled($existing->email)) {
                    $deliverTo = $existing->email;
                } elseif ($existing && (((int) ($existing->loyalty_points ?? 0) > 0)
                    || \App\Models\Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                        ->where('user_id', $existing->id)->exists())) {
                    $deliverTo = null;
                } else {
                    $deliverTo = $request->post('email');
                }

                if (filled($deliverTo)) {
                    $ttlMinutes = max(1, (int) Settings::group('otp')->get('otp_expire_time') ?: 5);
                    Cache::put(
                        'email_otp_email:'.$request->post('phone'),
                        (string) $deliverTo,
                        now()->addMinutes($ttlMinutes)
                    );
                    // [OWNER 2026-08-01] L'identité saisie AVANT le code voyage avec le canal :
                    // le verify seule le compte en « Prénom Nom » même si le client ne re-poste
                    // pas ces champs (le front n'a plus à les redemander à l'étape code).
                    Cache::put(
                        'email_otp_name:'.$request->post('phone'),
                        trim($request->post('first_name').' '.$request->post('last_name')),
                        now()->addMinutes($ttlMinutes)
                    );
                    Mail::to($deliverTo)->send(new SignupOtpMail((string) $token, $ttlMinutes));
                }
            }
    }

    public function verify(VerifyPhoneRequest $request)
    {
        try {
            // [APP MOBILE 2026-09-02 · e-mail d'abord] Vérification PAR E-MAIL : le client ne
            // connaît pas le téléphone du compte (il ne sort jamais vers l'appelant). Le serveur
            // résout le compte invité, pose la clé otps (téléphone du compte, ou `email:<adresse>`
            // pour un compte sans numéro) puis vérifie le code exactement comme par téléphone.
            // Compte introuvable (ou non-invité) ⇒ 422 générique, même texte qu'un code faux.
            $compteConnu = null;
            if (blank($request->post('phone')) && filled($request->post('email'))) {
                $email       = mb_strtolower(trim((string) $request->post('email')));
                $compteConnu = $this->compteInviteParEmail($email);
                if (! $compteConnu) {
                    return response(['status' => false, 'message' => trans('all.message.code_is_invalid')], 422);
                }
                $this->poser($request, [
                    'phone' => self::cleOtpDuCompte($compteConnu, $email),
                    'code'  => filled($compteConnu->country_code) ? $compteConnu->country_code : '+33',
                ]);
            }

            // [P0 OTP-BYPASS 2026-07-20] `site_phone_verification` ne pilote QUE l'envoi SMS
            // (dans OtpManagerService::otp()) — JAMAIS le fait de VÉRIFIER le code. L'ancienne
            // branche `if (site_phone_verification == DISABLE)` supprimait les otps du téléphone
            // puis appelait register() SANS vérifier le code : n'importe quel code (voire un
            // numéro jamais-demandé) mintait un token Sanctum kiosk:order = bypass d'auth.
            // Désormais on passe TOUJOURS par otpManagerService->verify() : il lit otps.token,
            // gère l'expiry + l'anti-brute-force (GAP-20) + la consommation one-time. Un code
            // faux ou un numéro jamais-OTP → Exception → 422. Reste compatible « OTP lu en
            // table » (staging/e2e sans SMS : on insère/lit otps.token puis on le soumet).
            // verify() renvoie true en succès et jette en échec (jamais false).
            if ($this->otpManagerService->verify($request)) {
                return $this->register(
                    [
                        'code' => $request->code,
                        'phone' => $request->phone,
                        'token' => $request->token,
                        // [HEAL SIGNUP 2026-07-30] email + prénom transmis → persistance
                        // déterministe dans register() (fallback cache + prénom réel).
                        'email' => $request->post('email'),
                        'first_name' => $request->post('first_name'),
                        'last_name' => $request->post('last_name'),
                    ],
                    $compteConnu
                );
            }
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'status'  => false,
            'message' => trans('all.message.code_is_invalid'),
        ], 422);
    }

    private function register($array, ?User $compteConnu = null) : JsonResponse
    {

        if (Settings::group('site')->get('site_guest_login') == Activity::DISABLE) {
            throw new Exception(trans('all.message.guest_login_is_not_allowed'), 422);
         }

        // [GAP-32-3] Pre-auth lookup: find ALL accounts with this phone, including
        // soft-deleted users and users from other branches (BranchScope).
        // Without this, a deleted or out-of-scope account causes a duplicate to be created.
        // [APP MOBILE 2026-09-02] Vérification par e-mail : le compte est DÉJÀ résolu par verify()
        // — on ne repasse pas par le téléphone (qui peut être la clé synthétique `email:<adresse>`,
        // laquelle ne doit JAMAIS créer un compte).
        $user = $compteConnu ?: User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->withTrashed()->where('phone', $array['phone'])->first();

        // [SECURITY] If the phone matches a non-guest account (staff, admin, manager),
        // refuse to issue a guest token. OTP alone must not grant access to privileged accounts.
        // [P0-1 SÉCU 2026-08-04] La clause `&& !$user->trashed()` DÉSARMAIT cette garde pour un
        // compte staff SOFT-DELETED (offboardé) → le bloc restore ci-dessous le ressuscitait +
        // mintait un token dessus (takeover total depuis un simple numéro de téléphone). Un
        // compte NON-INVITÉ est refusé qu'il soit supprimé ou non — jamais de résurrection staff.
        if ($user && $user->is_guest != Ask::YES) {
            // The phone belongs to a staff/admin account — do not allow guest login
            throw new \Exception(trans('all.message.credentials_invalid'), 422);
        }

        // Restore soft-deleted GUEST account instead of creating a duplicate (la garde ci-dessus
        // garantit is_guest=YES ici — jamais un staff). Defense-in-depth : on re-teste is_guest.
        if ($user && $user->trashed() && $user->is_guest == Ask::YES) {
            $user->restore();
            $user->status = \App\Enums\Status::ACTIVE;
            $user->save();
        }

        // [HEAL SIGNUP 2026-07-30 · OWNER 2026-08-01] Identité réelle « Prénom Nom ».
        // Priorité : ce qui est posté au verify > ce qui a été saisi à la demande de code
        // (mémorisé avec le canal email-otp) > « Guest User » (legacy SMS/borne seulement).
        // [LIVE 2026-08-03] Résolue AVANT le branchement create/existing : un compte legacy
        // au placeholder doit aussi en profiter (prouvé en réel : commande 030826318 arrivée
        // « Guest User » en caisse alors que le client avait saisi son identité).
        $first = is_string($array['first_name'] ?? null) ? trim($array['first_name']) : '';
        $last  = is_string($array['last_name'] ?? null) ? trim($array['last_name']) : '';
        $name  = trim($first.' '.$last);
        if ($name === '') {
            $cachedName = Cache::pull('email_otp_name:'.$array['phone']);
            $name = is_string($cachedName) ? trim($cachedName) : '';
        }
        $name = $name !== '' ? mb_substr($name, 0, 100) : '';

        // Compte EXISTANT encore au placeholder → le renommer avec l'identité PROUVÉE
        // (possession du code). Un VRAI nom déjà porté n'est JAMAIS écrasé.
        if ($user && $name !== '' && in_array(trim((string) $user->name), ['', 'Guest User'], true)) {
            $user->name = $name;
            $user->save();
        }

        if (!$user) {
            $name = $name !== '' ? $name : 'Guest User';
            $user = User::create([
                'name'              => $name,
                'username'          => Str::slug($name) . \Illuminate\Support\Str::random(5),
                'phone'             => $array['phone'],
                'country_code'      => $array['code'],
                'branch_id'         => 0,
                'email_verified_at' => Carbon::now()->getTimestamp(),
                'is_guest'          => Ask::YES,
                'password'          => Hash::make(\Illuminate\Support\Str::random(10))
            ]);
            $user->assignRole(EnumRole::CUSTOMER);
        }

        // [LOY-WEB-01 2026-07-15] Garantir un loyalty_code sur tout compte invité (web/borne
        // OTP) AVANT d'émettre le token — sinon le client cumule 0 point malgré la promesse
        // « +N pts » du panier (AwardLoyaltyPointsOnDelivery + QR fidélité ont besoin du code).
        // Assigné après create (loyalty_code non mass-assignable) ; backfille aussi les
        // invités historiques sans code. Pattern canonique = LoyaltyController::check.
        if (empty($user->loyalty_code)) {
            $user->loyalty_code = strtoupper(substr(md5(uniqid('', true)), 0, 8));
            $user->save();
        }

        // [WAVE C EMAIL-OTP 2026-07-28] Si le code a été demandé via le canal EMAIL
        // (emailOtp), l'adresse liée au téléphone est persistée ICI — c.-à-d.
        // seulement APRÈS preuve de possession du code (verify OK). Pull one-time.
        // Garde-fous : jamais d'écrasement d'un email différent déjà porté par ce
        // compte, jamais d'attache d'un email appartenant à un AUTRE compte
        // (anti-vol/anti-énumération : échec silencieux, le login réussit quand même).
        // [HEAL SIGNUP 2026-07-30] Fallback DÉTERMINISTE : si le cache email a expiré (client
        // qui cherche le code dans ses spams > TTL, ou renvoi) on prend l'email transmis au
        // verify (contrat élargi, VerifyPhoneRequest.email nullable) → plus de « email non
        // renseigné ». Le cache reste prioritaire (posé au send, non-falsifiable).
        $pendingEmail = Cache::pull('email_otp_email:'.$array['phone']);
        if (! (is_string($pendingEmail) && $pendingEmail !== '')) {
            $pendingEmail = is_string($array['email'] ?? null) ? trim($array['email']) : null;
        }
        if (is_string($pendingEmail) && $pendingEmail !== '') {
            $emailTakenByOther = User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->withTrashed()
                ->where('email', $pendingEmail)
                ->where('id', '!=', $user->id)
                ->exists();
            if (! $emailTakenByOther && (blank($user->email) || strcasecmp((string) $user->email, $pendingEmail) === 0)) {
                $user->email = $pendingEmail;
                $user->email_verified_at = Carbon::now()->getTimestamp();
                $user->save();
            }
        }

        if ($user) {
            Auth::guard('web')->loginUsingId($user->id);
            $branchId = Auth::user()->branch_id;
            if (Auth::user()->branch_id == 0) {
                $branchId = Settings::group('site')->get('site_default_branch');
            }

            $this->defaultAccessService->storeOrUpdate(['branch_id' => $branchId]);
            // [GAP-20-5] Guest tokens expire after 30 days. Without expiry, a token issued
            // to a kiosk customer or anonymous web visitor remains valid indefinitely,
            // creating a growing attack surface. 30 days matches typical session expectations.
            // [Sprint H1 Z6-02 2026-05-17] Scope ability to `kiosk:order` only — not
            // wildcard. CLAUDE.md §9 + Wave Z RED-team: a leaked guest token must not
            // be replayable against admin endpoints. Guest UI consumes only
            // `/api/frontend/order/*` which requires `tokenCan('kiosk:order')`
            // (see OrderRequest.php:46-47: both `['*']` and `['kiosk:order']`
            // satisfy that check — the narrow scope is strictly sufficient).
            // [PROCUREUR cycle 7 — 2026-08-05 · P2 F-I] Ce chemin ne purgeait PAS les jetons
            // précédents, contrairement à sa jumelle `LoginController` — alors que CLAUDE.md §9
            // pose « Old tokens revoked à chaque relogin (prevent token sprawl) ». Constaté en
            // base : 13 jetons VIVANTS pour un seul utilisateur, chacun valable 30 jours. Un
            // client qui se reconnecte depuis un nouvel appareil laissait donc derrière lui
            // autant de sessions actives que de connexions, et sa déconnexion n'en tuait qu'une.
            // On purge par NOM, comme la jumelle, pour ne jamais toucher un jeton d'une autre
            // nature.
            // [MULTI-DEVICE 2026-08-07] Même correctif que sur la jumelle
            // `LoginController` : la purge portait sur TOUS les jetons du
            // compte, donc un client connecté sur son téléphone était éjecté
            // dès qu'il ouvrait le site sur un autre appareil. On garde la
            // purge anti-prolifération, scopée à l'appareil, plus le plafond.
            $this->token = app(\App\Services\Auth\DeviceTokenService::class)
                ->issueForDevice($user, 'auth_token', ['kiosk:order'], request(), 60 * 24 * 30)
                ->plainTextToken;

            // [FIX] Guard against user with no roles (should not happen, but defensive)
            $firstRole = $user->roles->first();
            if (!$firstRole) {
                $user->assignRole(EnumRole::CUSTOMER);
                $user->refresh();
                $firstRole = $user->roles->first();
            }

            $permission        = PermissionResource::collection($this->permissionService->permission($firstRole));
            $defaultPermission = AppLibrary::defaultPermission($permission);

            return new JsonResponse([
                'message'           => trans('all.message.login_success'),
                'token'             => $this->token,
                'branch_id'         => (int)$user->branch_id,
                // [APP MOBILE 2026-09-02] Parité avec la connexion Apple/Google : un compte sans
                // numéro joignable doit être invité à en donner un (le serveur refuse la commande
                // sinon — RequireCustomerPhone). Le client affiche l'écran « Ton numéro ».
                'phone_required'    => $user->numeroJoignable() === null,
                'user'              => new UserResource($user),
                'menu'              => MenuResource::collection(collect($this->menuService->menu($firstRole))),
                'permission'        => $permission,
                'defaultPermission' => $defaultPermission,
            ], 201);
        }
        return new JsonResponse([
            'status'  => false,
            'message' => trans('all.message.credentials_invalid'),
        ], 422);
    }
}
