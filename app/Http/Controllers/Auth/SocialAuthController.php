<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Rules\ValidPhone;
use App\Services\Auth\DeviceTokenService;
use App\Services\Auth\SocialIdentityVerifier;
use App\Support\PhoneDisplay;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Smartisan\Settings\Facades\Settings;
use Throwable;

/**
 * [APPS 2026-08-19] Connexion « Se connecter avec Apple » / « Se connecter avec Google »
 * pour les applications iOS et Android.
 *
 * POURQUOI CE CONTRÔLEUR EXISTE À CÔTÉ DE GuestSignupController
 * -------------------------------------------------------------
 * Le parcours historique du site identifie le client par son TÉLÉPHONE : on envoie un
 * code, il le renvoie, le compte est celui qui porte ce numéro. Une connexion Apple ou
 * Google, elle, n'apporte PAS de téléphone — elle apporte une identité déjà prouvée par
 * le fournisseur. Les deux parcours aboutissent au même type de compte et au même type de
 * jeton, mais ils partent de preuves différentes ; les mêler dans un seul contrôleur
 * aurait mélangé deux raisonnements de sécurité distincts.
 *
 * LE TÉLÉPHONE RESTE OBLIGATOIRE (exigence explicite de l'exploitant)
 * -------------------------------------------------------------------
 * Une commande sans numéro joignable est une commande qu'on ne peut pas sauver : rupture
 * d'un produit, question sur une cuisson, client qui ne vient pas la chercher — le
 * restaurant doit pouvoir APPELER. La connexion sociale ouvre donc la session, mais
 * `phone_required` reste vrai tant que le compte n'a pas de numéro, et le serveur refuse
 * la commande dans cet état (voir RequireCustomerPhone). Le blocage vit côté SERVEUR :
 * un écran qu'on peut contourner en fermant l'application ne protège rien.
 *
 * Ici le téléphone n'est PAS un second facteur, et c'est délibéré : l'identité est déjà
 * établie par Apple ou Google, et ce projet n'envoie pas de SMS (choix de l'exploitant).
 * Redemander un code par e-mail à quelqu'un dont l'e-mail vient d'être attesté par son
 * fournisseur ajouterait de la friction sans rien prouver de plus. Le numéro est collecté
 * comme MOYEN DE CONTACT ; sa possession n'est pas revendiquée par le système.
 */
class SocialAuthController extends Controller
{
    public function __construct(
        private readonly SocialIdentityVerifier $verifier,
        private readonly DeviceTokenService $tokens,
    ) {
    }

    /** Fournisseurs acceptés → colonne qui porte leur identifiant stable. */
    private const COLONNES = [
        'apple'  => 'apple_sub',
        'google' => 'google_sub',
    ];

    /**
     * POST /api/auth/social/{provider}
     * Corps : { id_token: "<JWT du fournisseur>" }
     */
    public function login(Request $request, string $provider): JsonResponse
    {
        if (! isset(self::COLONNES[$provider])) {
            return $this->echec(trans('all.message.credentials_invalid'), 404);
        }

        if (Settings::group('site')->get('site_guest_login') == \App\Enums\Activity::DISABLE) {
            return $this->echec(trans('all.message.guest_login_is_not_allowed'), 422);
        }

        $request->validate([
            'id_token' => ['required', 'string', 'min:20', 'max:8192'],
        ]);

        try {
            $identite = $this->verifier->verifier($provider, (string) $request->post('id_token'));
        } catch (Throwable $e) {
            // Le motif exact (signature invalide, destinataire inattendu, jeton expiré…)
            // est journalisé pour l'exploitation mais JAMAIS renvoyé au client : détailler
            // pourquoi un jeton est refusé aide surtout celui qui en fabrique.
            Log::warning('[social-auth] jeton refusé', ['provider' => $provider, 'raison' => $e->getMessage()]);

            return $this->echec(trans('all.message.credentials_invalid'), 422);
        }

        $colonne = self::COLONNES[$provider];

        // --- Résolution du compte -------------------------------------------------
        // 1) Par l'identifiant stable du fournisseur : c'est le seul rapprochement
        //    parfaitement sûr, et le seul qui survit à un changement d'e-mail.
        $user = User::withoutGlobalScope(BranchScope::class)->withTrashed()
            ->where($colonne, $identite['sub'])->first();

        // 2) Sinon, par e-mail — mais UNIQUEMENT si le fournisseur atteste l'avoir vérifié.
        //    Sans cette condition, quiconque peut déclarer une adresse chez un fournisseur
        //    laxiste prendrait le compte du client qui l'utilise réellement chez nous.
        if (! $user && $identite['email'] !== null && $identite['email_verified']) {
            $user = User::withoutGlobalScope(BranchScope::class)->withTrashed()
                ->whereRaw('LOWER(email) = ?', [$identite['email']])->first();
        }

        // Un compte NON-INVITÉ (personnel, gérant, administrateur) ne s'ouvre jamais par
        // cette porte, supprimé ou non. Même raisonnement que la garde du parcours
        // téléphone : une preuve d'identité grand public ne doit pas donner la caisse.
        if ($user && $user->is_guest != Ask::YES) {
            Log::warning('[social-auth] tentative sur un compte non-invité', [
                'provider' => $provider, 'user_id' => $user->id,
            ]);

            return $this->echec(trans('all.message.credentials_invalid'), 422);
        }

        if ($user && $user->trashed()) {
            $user->restore();
            $user->status = Status::ACTIVE;
            $user->save();
        }

        // 3) Aucun compte trouvé → création, SANS téléphone (il sera exigé juste après).
        if (! $user) {
            $nom = trim(($identite['prenom'] ?? '') . ' ' . ($identite['nom'] ?? ''));
            $nom = $nom !== '' ? mb_substr($nom, 0, 100) : 'Client';

            $user = User::create([
                'name'         => $nom,
                'username'     => Str::slug($nom) . Str::random(5),
                'branch_id'    => 0,
                'is_guest'     => Ask::YES,
                // Renseigné à la création, comme le fait le parcours téléphone
                // (GuestSignupController::register). Ce n'est pas cosmétique : la route de
                // SUPPRESSION DE COMPTE est gardée par `verify.api`. Sans cette ligne, un
                // client connecté par Apple ou Google dont le fournisseur n'atteste pas
                // l'adresse serait dans l'impossibilité de supprimer son compte — soit
                // exactement le manquement qu'Apple et Google sanctionnent. L'identité est
                // ici attestée par le fournisseur, ce qui vaut au moins la vérification
                // d'adresse que ce champ représente.
                'email_verified_at' => Carbon::now()->getTimestamp(),
                'password'     => Hash::make(Str::random(32)),
            ]);
            $user->assignRole(EnumRole::CUSTOMER);
        }

        // --- Rattachement de l'identité sociale -----------------------------------
        if (blank($user->{$colonne})) {
            $user->{$colonne} = $identite['sub'];
        }

        // L'e-mail n'est repris que s'il est attesté ET libre. Ne JAMAIS écraser une
        // adresse différente déjà portée par ce compte, ni s'approprier celle d'un autre :
        // même garde que le parcours téléphone, pour la même raison (vol de compte par
        // collision d'adresse). L'échec est silencieux — la connexion réussit quand même.
        if ($identite['email'] !== null && $identite['email_verified']) {
            $prisParUnAutre = User::withoutGlobalScope(BranchScope::class)->withTrashed()
                ->whereRaw('LOWER(email) = ?', [$identite['email']])
                ->where('id', '!=', $user->id)
                ->exists();

            if (! $prisParUnAutre && (blank($user->email) || strcasecmp((string) $user->email, $identite['email']) === 0)) {
                $user->email = $identite['email'];
                $user->email_verified_at = Carbon::now()->getTimestamp();
            }
        }

        // Un compte encore au nom générique récupère l'identité donnée par le fournisseur.
        // Un vrai nom déjà porté n'est jamais écrasé.
        $nomFournisseur = trim(($identite['prenom'] ?? '') . ' ' . ($identite['nom'] ?? ''));
        if ($nomFournisseur !== '' && in_array(trim((string) $user->name), ['', 'Client', 'Guest User'], true)) {
            $user->name = mb_substr($nomFournisseur, 0, 100);
        }

        // Code fidélité garanti AVANT l'émission du jeton — sinon le client cumule zéro
        // point malgré la promesse affichée au panier. Motif repris du parcours téléphone.
        if (blank($user->loyalty_code)) {
            $user->loyalty_code = strtoupper(substr(md5(uniqid('', true)), 0, 8));
        }

        $user->save();

        return $this->reponseSession($user, trans('all.message.login_success'));
    }

    /**
     * POST /api/auth/social/phone — enregistre le numéro d'un compte qui n'en a pas.
     *
     * Réservé aux comptes CLIENT (jeton `auth_token`). Une borne libre-service porte un
     * jeton `kiosk-token` et n'a aucun téléphone à déclarer : c'est la distinction déjà
     * retenue par BlockKioskMachineToken, on la réutilise plutôt que d'en inventer une.
     */
    public function attacherTelephone(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->echec(trans('all.message.credentials_invalid'), 401);
        }

        $jeton = $user->currentAccessToken();
        if ($jeton && method_exists($jeton, 'getAttribute') && $jeton->name === 'kiosk-token') {
            return $this->echec(trans('all.message.credentials_invalid'), 403);
        }

        $request->validate([
            'phone' => ['required', 'string', 'max:190', new ValidPhone()],
            'code'  => ['nullable', 'string', 'max:8'],
        ]);

        $telephone = trim((string) $request->post('phone'));
        $indicatif = trim((string) ($request->post('code') ?: '+33'));

        // Changer un numéro déjà enregistré est une opération de PROFIL, avec ses propres
        // garanties (le numéro sert de clé de connexion au parcours historique). Cet
        // endpoint ne fait que COMPLÉTER un compte qui n'en a pas encore.
        //
        // Attention : « pas encore » ne veut PAS dire « vide ». La colonne est NOT NULL
        // depuis 2026_05_16_140100, et User::creating y injecte une sentinelle
        // `PENDING_CREATE_<hex>` quand aucun numéro n'est fourni. Un simple `filled()`
        // aurait donc considéré tout compte social comme déjà pourvu, et cet endpoint
        // n'aurait JAMAIS rien enregistré tout en répondant « c'est bon ».
        if (PhoneDisplay::safe((string) $user->phone) !== null) {
            return response()->json([
                'status'         => true,
                'message'        => trans('all.message.login_success'),
                'phone_required' => false,
            ]);
        }

        // Unicité. On compare sur les 9 derniers chiffres, car le même numéro peut avoir
        // été enregistré « 0612345678 » par le site et « +33612345678 » ailleurs : une
        // comparaison littérale laisserait passer un doublon, et deux comptes pour une
        // seule personne, c'est un historique coupé en deux et une fidélité perdue.
        $significatifs = $this->chiffresSignificatifs($telephone);
        if ($significatifs !== '') {
            // Le filtrage se fait en base plutôt qu'en chargeant tous les comptes en
            // mémoire. Le `LIKE '%…'` attrape les deux écritures réellement produites par
            // le système (« 0612345678 » côté site, « +33612345678 » ailleurs) ; la
            // comparaison exacte est refaite en PHP pour ne rien conclure sur un `LIKE`.
            $collision = User::withoutGlobalScope(BranchScope::class)->withTrashed()
                ->whereNotNull('phone')
                ->where('id', '!=', $user->id)
                ->where('phone', 'like', '%' . $significatifs)
                // Les sentinelles `PENDING_…` contiennent des chiffres hexadécimaux : sans
                // cette exclusion, l'une d'elles pourrait faire croire à une collision et
                // refuser un numéro parfaitement libre, sans explication compréhensible.
                ->where('phone', 'not like', 'PENDING\_%')
                ->limit(50)
                ->get(['id', 'phone'])
                ->first(fn ($autre) => $this->chiffresSignificatifs((string) $autre->phone) === $significatifs);

            if ($collision) {
                return response()->json([
                    'status'  => false,
                    'code'    => 'PHONE_EXISTS',
                    'message' => 'Ce numéro est déjà rattaché à un compte. Connectez-vous avec ce numéro pour retrouver vos commandes et vos points.',
                ], 422);
            }
        }

        $user->phone = $telephone;
        $user->country_code = $indicatif;
        $user->save();

        return response()->json([
            'status'         => true,
            'message'        => trans('all.message.login_success'),
            'phone_required' => false,
            'user'           => new UserResource($user),
        ]);
    }

    /** Les 9 derniers chiffres — le numéro national, quelle que soit sa mise en forme. */
    private function chiffresSignificatifs(string $telephone): string
    {
        $chiffres = preg_replace('/\D+/', '', $telephone) ?? '';

        return strlen($chiffres) >= 9 ? substr($chiffres, -9) : $chiffres;
    }

    /** Réponse commune : jeton d'accès + état du compte. */
    private function reponseSession(User $user, string $message): JsonResponse
    {
        Auth::guard('web')->loginUsingId($user->id);

        // Jeton volontairement identique à celui du parcours téléphone : même nom
        // (`auth_token`, ce qui le distingue d'une borne), même habilitation étroite
        // (`kiosk:order` seulement — un jeton client qui fuite ne doit rien pouvoir faire
        // sur l'administration), même durée de 30 jours, même purge par appareil.
        $token = $this->tokens
            ->issueForDevice($user, 'auth_token', ['kiosk:order'], request(), 60 * 24 * 30)
            ->plainTextToken;

        return response()->json([
            'status'         => true,
            'message'        => $message,
            'token'          => $token,
            'branch_id'      => (int) $user->branch_id,
            'user'           => new UserResource($user),
            // Le drapeau que l'application lit pour afficher l'écran bloquant. Jugé par le
            // helper canonique : un compte social porte une sentinelle `PENDING_…`, pas
            // une chaîne vide (voir attacherTelephone pour le détail).
            'phone_required' => PhoneDisplay::safe((string) $user->phone) === null,
        ], 201);
    }

    private function echec(string $message, int $statut): JsonResponse
    {
        return response()->json(['status' => false, 'message' => $message], $statut);
    }
}
