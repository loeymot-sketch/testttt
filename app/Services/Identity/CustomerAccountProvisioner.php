<?php

namespace App\Services\Identity;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Mail\CustomerWelcomeMail;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * CRÉER — OU RETROUVER — LE COMPTE D'UN CLIENT À PARTIR DE SON TÉLÉPHONE.
 *
 * ── POURQUOI CE SERVICE A CHANGÉ DE MAISON ───────────────────────────────────────────────────
 * Il s'appelait `WheelAccountService` et vivait sous `App\Services\Wheel`, parce que la roue a été
 * la première à en avoir besoin. Le comptoir en a maintenant besoin pour la même chose, mot pour
 * mot : « je veux ajouter la section pour pouvoir créer un compte pour un client ». En écrire une
 * seconde version aurait produit deux façons de créer un client — donc, tôt ou tard, deux comptes
 * pour un même humain, deux soldes de points, et une plainte au comptoir.
 *
 * C'est le troisième déménagement de ce type ce jour-là (« ce numéro », « ce client », et
 * maintenant « créer ce client ») : la roue avait servi de laboratoire, les définitions qu'elle a
 * fait naître appartiennent au logiciel entier.
 *
 * ── CE QUE CE SERVICE GARANTIT ───────────────────────────────────────────────────────────────
 * - Il ne jette JAMAIS : un compte impossible à créer ne doit pas faire tomber une vente.
 * - Il ne crée PAS de doublon : il cherche les quatre écritures du numéro avant d'insérer.
 * - Il ne touche PAS aux comptes de l'ÉQUIPE (très probablement l'exploitant qui teste).
 * - Il ne RESSUSCITE PAS un compte supprimé — la suppression était une décision.
 * - Il ne vole PAS l'e-mail d'un autre compte.
 * - Il ne marque PAS l'e-mail vérifié : le client l'a tapé, personne n'a prouvé qu'il est à lui.
 *   Ce sera prouvé à sa première connexion par code. L'affirmer ici serait affirmer une preuve
 *   qu'on n'a pas.
 *
 * `$origine` ne change AUCUNE règle — seulement le nom de repli (« Client Comptoir » plutôt que
 * « Client Roue ») et l'étiquette de la trace, pour qu'on sache d'où vient un compte sans nom.
 *
 * Sentinelles : tests/Feature/Wheel/WheelPointsDeliveryTest.php (parcours roue)
 *               tests/Feature/Pos/PosCustomerCreateTest.php     (création au comptoir)
 */
class CustomerAccountProvisioner
{
    /**
     * Crée — ou complète — le compte du gagnant. Ne jette jamais.
     *
     * @return array{user_id: int|null, created: bool, reason: string}
     */
    public function ensure(string $phone, ?string $email, ?string $name, string $origine = 'roue'): array
    {
        try {
            return $this->run($phone, $email, $name, $origine);
        } catch (\Throwable $e) {
            // « Ne jette jamais » veut aussi dire « ne dit jamais qu'il est cassé ». Le 10 août, un
            // simple changement de namespace a fait pointer un `app(WheelService::class)` sur une
            // classe inexistante : ce filet a transformé une erreur FATALE en « reason: error », et
            // plus aucun compte n'était créé — sans une ligne visible. Les tests l'ont attrapé ; la
            // production ne l'aurait pas fait. On écrit donc AUSSI dans le canal par défaut, au
            // niveau `error`, là où les incidents sont réellement lus.
            $contexte = ['phone' => substr($phone, 0, 4) . '…', 'error' => $e->getMessage(), 'via' => $origine];

            Log::channel('daily')->warning($origine . '.account_failed', $contexte);
            Log::error('customer_account.provisioning_failed', $contexte);

            return ['user_id' => null, 'created' => false, 'reason' => 'error'];
        }
    }

    /** @return array{user_id: int|null, created: bool, reason: string} */
    private function run(string $phone, ?string $email, ?string $name, string $origine = 'roue'): array
    {
        $tel = app(PhoneIdentity::class)->normalize($phone);
        if (strlen($tel) < 9) {
            return ['user_id' => null, 'created' => false, 'reason' => 'phone_invalid'];
        }

        $mail = is_string($email) && trim($email) !== '' ? mb_strtolower(trim($email)) : null;
        $nom = is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 100) : '';

        // Le site n'impose aucune forme au téléphone : la base contient « 0612345678 », « 600099482 »
        // et « +33612345678 ». Chercher la seule forme normalisée créerait un DOUBLON pour un client
        // qui a déjà commandé — deux comptes, deux soldes de points, un seul humain.
        $existant = User::withoutGlobalScope(BranchScope::class)
            ->withTrashed()
            ->whereIn('phone', app(PhoneIdentity::class)->variants($tel))
            ->orderBy('id')
            ->first();

        // Même règle que le crédit des points : « pas l'équipe », et non « invité ». Un client
        // réellement inscrit (`is_guest = NO` + rôle client) est un client, et son lot doit lui être
        // rattaché comme aux autres.
        if ($existant && ! app(CustomerAccount::class)->isCustomer($existant)) {
            // Compte de l'équipe. On n'y touche pas — et le lot passe quand même : c'est très
            // probablement le propriétaire qui teste avec son propre numéro.
            return ['user_id' => null, 'created' => false, 'reason' => 'staff_phone'];
        }

        if ($existant && $existant->trashed()) {
            return ['user_id' => null, 'created' => false, 'reason' => 'deleted_account'];
        }

        if ($existant) {
            $emailVientDetreAjoute = $this->completer($existant, $mail, $nom);
            if ($emailVientDetreAjoute) {
                $this->envoyerBienvenue($existant);
            }

            return ['user_id' => (int) $existant->id, 'created' => false, 'reason' => 'existing'];
        }

        $nomCompte = $nom !== '' ? $nom : ($origine === 'comptoir' ? 'Client Comptoir' : 'Client Roue');
        $user = User::create([
            'name' => $nomCompte,
            'username' => Str::slug($nomCompte) . Str::random(5),
            'phone' => $tel,
            'country_code' => '+33',
            // Branche 0 = client du site, comme tout compte invité. Un client n'appartient pas à une
            // caisse : il commande où il veut.
            'branch_id' => 0,
            'is_guest' => Ask::YES,
            'status' => Status::ACTIVE,
            'password' => Hash::make(Str::random(32)),
        ]);
        $user->assignRole(EnumRole::CUSTOMER);

        $emailPose = $this->completer($user, $mail, $nom);

        Log::channel('daily')->info($origine . '.account_created', ['user_id' => $user->id]);

        if ($emailPose) {
            $this->envoyerBienvenue($user);
        }

        return ['user_id' => (int) $user->id, 'created' => true, 'reason' => 'created'];
    }

    /**
     * Rattache l'adresse et le nom, et garantit le code de fidélité.
     *
     * L'adresse n'est PAS marquée vérifiée : le client l'a tapée, personne n'a prouvé qu'elle est à
     * lui. Elle le deviendra à sa première connexion par code — là, la possession sera prouvée. Poser
     * `email_verified_at` ici serait affirmer une preuve qu'on n'a pas.
     *
     * @return bool VRAI si une adresse a été posée pour la première fois sur ce compte (déclenche
     *              le mail de bienvenue chez l'appelant — pas ici, la responsabilité d'envoyer un
     *              e-mail ne doit pas être invisible dans une méthode nommée « compléter »).
     */
    private function completer(User $user, ?string $mail, string $nom): bool
    {
        $modifie = false;
        $emailPose = false;

        if ($mail !== null && blank($user->email)) {
            $prise = User::withoutGlobalScope(BranchScope::class)
                ->withTrashed()
                ->where('email', $mail)
                ->where('id', '!=', $user->id)
                ->exists();

            if (! $prise) {
                $user->email = $mail;
                $modifie = true;
                $emailPose = true;
            }
        }

        // Un vrai nom déjà porté n'est jamais écrasé — seuls les substituts le sont.
        if ($nom !== '' && in_array(trim((string) $user->name), ['', 'Guest User', 'Client Roue'], true)) {
            $user->name = $nom;
            $modifie = true;
        }

        // Sans code de fidélité, le client cumule zéro point malgré la promesse affichée au panier.
        // C'est le même filet que sur l'inscription invitée.
        if (blank($user->loyalty_code)) {
            $user->loyalty_code = strtoupper(substr(md5(uniqid('', true)), 0, 8));
            $modifie = true;
        }

        if ($modifie) {
            $user->save();
        }

        return $emailPose;
    }

    /**
     * « Le client va recevoir le mail et enregistrer ses données » [propriétaire 2026-08-14].
     *
     * Ne fait JAMAIS tomber la création du compte : un e-mail qui échoue (SMTP indisponible,
     * adresse invalide) est un incident à tracer, pas une vente à casser. Même discipline que
     * `ensure()` — le filet écrit dans le canal `daily` ET le canal par défaut, la leçon du
     * `app(WheelService::class)` invalide du 10 août ne se repaie pas deux fois.
     */
    private function envoyerBienvenue(User $user): void
    {
        if (blank($user->email)) {
            return;
        }

        try {
            Mail::to($user->email)->send(new CustomerWelcomeMail(
                $user->name ?: 'client',
                (string) $user->loyalty_code
            ));
        } catch (\Throwable $e) {
            $contexte = ['user_id' => $user->id, 'error' => $e->getMessage()];
            Log::channel('daily')->warning('customer_account.welcome_mail_failed', $contexte);
            Log::error('customer_account.welcome_mail_failed', $contexte);
        }
    }
}
