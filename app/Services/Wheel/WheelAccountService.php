<?php

namespace App\Services\Wheel;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * LE COMPTE CRÉÉ AU MOMENT OÙ LE CLIENT RÉCLAME SON LOT.
 *
 * ── LA DEMANDE ───────────────────────────────────────────────────────────────────────────────
 * « On va lui créer un compte en même temps, ça va être inscrit avec son numéro et e-mail pour
 * recevoir le code, et en même temps on va créer son compte. Chaque fois qu'il veut se connecter,
 * il va mettre le code qu'il va recevoir. »
 *
 * Le client a déjà tapé son numéro et son adresse pour débloquer son lot. Lui redemander les mêmes
 * deux champs plus tard, pour « créer un compte », c'est le même effort une seconde fois — et c'est
 * là qu'on perd les gens.
 *
 * ── AUCUN NOUVEAU MÉCANISME DE CONNEXION ─────────────────────────────────────────────────────
 * Le compte créé ici est EXACTEMENT celui du site : compte invité (`is_guest`), clé = téléphone,
 * adresse rattachée, `loyalty_code` présent, rôle client. La connexion « avec le code reçu » que
 * décrit le propriétaire EXISTE DÉJÀ (`/guest-signup/email-otp` puis `/verify`) : le client entre son
 * numéro, reçoit un code par e-mail, entre le code. On ne construit donc pas une seconde porte
 * d'entrée — deux portes, c'est deux fois les gardes à maintenir, et un jour l'une des deux oublie
 * quelque chose.
 *
 * ── CE QU'ON NE FAIT JAMAIS ICI ──────────────────────────────────────────────────────────────
 * Cet appel arrive d'un point d'entrée PUBLIC, où le seul « secret » est un numéro de téléphone.
 * Un numéro n'est pas une preuve d'identité. Donc :
 *
 *   · AUCUN jeton, AUCUNE session émise. Sinon n'importe qui réclamerait un lot avec le numéro d'un
 *     autre et repartirait avec sa session : contournement d'authentification complet.
 *   · Un numéro portant un compte NON-INVITÉ (équipe, gérant, administrateur) n'est pas touché. On
 *     ne crée rien, on ne modifie rien. C'est la même garde que sur l'inscription invitée, et pour
 *     la même raison : un compte privilégié ne se réclame pas avec un numéro.
 *   · Un compte invité SUPPRIMÉ n'est pas ressuscité. La restauration sans preuve de possession est
 *     précisément le défaut fermé le 4 août sur le chemin d'inscription.
 *   · Une adresse DÉJÀ portée par un autre compte n'est jamais rattachée. Le lot est quand même
 *     accordé — le client honnête ne doit pas payer une collision — mais on ne déplace pas l'adresse
 *     de quelqu'un d'autre.
 *
 * ── ET SI LA CRÉATION ÉCHOUE ? ───────────────────────────────────────────────────────────────
 * Le lot reste dû. Un client qui a laissé un avis, s'est abonné et a tourné n'a pas à perdre son
 * cadeau parce qu'une écriture annexe a échoué. L'échec est journalisé, pas propagé.
 */
class WheelAccountService
{
    /**
     * Crée — ou complète — le compte du gagnant. Ne jette jamais.
     *
     * @return array{user_id: int|null, created: bool, reason: string}
     */
    public function ensure(string $phone, ?string $email, ?string $name): array
    {
        try {
            return $this->run($phone, $email, $name);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('wheel.account_failed', [
                'phone' => substr($phone, 0, 4) . '…', 'error' => $e->getMessage(),
            ]);

            return ['user_id' => null, 'created' => false, 'reason' => 'error'];
        }
    }

    /** @return array{user_id: int|null, created: bool, reason: string} */
    private function run(string $phone, ?string $email, ?string $name): array
    {
        $tel = app(WheelService::class)->normalizePhone($phone);
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
            ->whereIn('phone', $this->variantes($tel))
            ->orderBy('id')
            ->first();

        if ($existant && (int) $existant->is_guest !== (int) Ask::YES) {
            // Compte de l'équipe. On n'y touche pas — et le lot passe quand même : c'est très
            // probablement le propriétaire qui teste avec son propre numéro.
            return ['user_id' => null, 'created' => false, 'reason' => 'staff_phone'];
        }

        if ($existant && $existant->trashed()) {
            return ['user_id' => null, 'created' => false, 'reason' => 'deleted_account'];
        }

        if ($existant) {
            $this->completer($existant, $mail, $nom);

            return ['user_id' => (int) $existant->id, 'created' => false, 'reason' => 'existing'];
        }

        $nomCompte = $nom !== '' ? $nom : 'Client Roue';
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

        $this->completer($user, $mail, $nom);

        Log::channel('daily')->info('wheel.account_created', ['user_id' => $user->id]);

        return ['user_id' => (int) $user->id, 'created' => true, 'reason' => 'created'];
    }

    /**
     * Rattache l'adresse et le nom, et garantit le code de fidélité.
     *
     * L'adresse n'est PAS marquée vérifiée : le client l'a tapée, personne n'a prouvé qu'elle est à
     * lui. Elle le deviendra à sa première connexion par code — là, la possession sera prouvée. Poser
     * `email_verified_at` ici serait affirmer une preuve qu'on n'a pas.
     */
    private function completer(User $user, ?string $mail, string $nom): void
    {
        $modifie = false;

        if ($mail !== null && blank($user->email)) {
            $prise = User::withoutGlobalScope(BranchScope::class)
                ->withTrashed()
                ->where('email', $mail)
                ->where('id', '!=', $user->id)
                ->exists();

            if (! $prise) {
                $user->email = $mail;
                $modifie = true;
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
    }

    /**
     * Les écritures possibles d'un même numéro français. On ne devine pas un indicatif étranger :
     * mieux vaut un compte de plus qu'un compte rattaché au mauvais humain.
     *
     * @return array<int, string>
     */
    private function variantes(string $tel): array
    {
        $v = [$tel];

        if (str_starts_with($tel, '0') && strlen($tel) === 10) {
            $sansZero = substr($tel, 1);
            $v[] = $sansZero;
            $v[] = '33' . $sansZero;
            $v[] = '+33' . $sansZero;
        }

        return array_values(array_unique($v));
    }
}
