<?php

namespace App\Services\Loyalty;

use App\Models\User;
use App\Services\Identity\CustomerAccount;
use App\Services\Identity\PhoneIdentity;
use Illuminate\Support\Facades\Log;

/**
 * RETROUVER LE CLIENT QUI EST DEVANT LE COMPTOIR.
 *
 * ── LA DEMANDE DU PROPRIÉTAIRE ───────────────────────────────────────────────────────────────
 * « Pouvoir utiliser les points accumulés ou bien lui ajouter des points pour sa commande […]
 *   l'accumulation avec le numéro de téléphone ou bien avec l'e-mail, il est préférable avec le
 *   numéro de téléphone […] on scanne le QR code directement avec la tablette. »
 *
 * ── CE QUI EXISTAIT, ET CE QUI MANQUAIT ──────────────────────────────────────────────────────
 * La mécanique de fidélité est complète : le crédit est automatique (`AwardLoyaltyPointsOnDelivery`
 * lit `orders.loyalty_customer_code`), le débit existe (`PosRedemptionService`), la commande de
 * caisse accepte déjà `loyalty_customer_code` (`PosOrderRequest:215`, persisté `OrderService:1181`).
 * Ce qui manquait n'était PAS la mécanique : c'était le moyen de dire QUI est le client. D'où
 * 2 lignes de gain « surface caisse » dans toute la base.
 *
 * ── TROIS ENTRÉES, UN SEUL RÉSULTAT ──────────────────────────────────────────────────────────
 * Téléphone (le moyen préféré), code fidélité, ou QR scanné à la tablette. Le QR signé est
 * à usage unique et vaut 5 minutes ; un QR en clair (`FK:<code>`) ne vaut pas mieux qu'un code
 * tapé, et on le traite exactement comme tel — le prétendre plus sûr serait mentir.
 *
 * ── LE DANGER PRINCIPAL : LE MAUVAIS HUMAIN ──────────────────────────────────────────────────
 * 5 numéros de la base sont portés par PLUSIEURS comptes (l'un par 5). Choisir pour le caissier,
 * c'est créditer ou débiter le solde de quelqu'un d'autre. Ce service ne choisit JAMAIS : il rend
 * la liste et laisse l'humain trancher, avec de quoi trancher (prénom, numéro masqué, solde).
 *
 * ── CE QUE LE COMPTOIR NE DOIT PAS VOIR ──────────────────────────────────────────────────────
 * Ni un compte d'ÉQUIPE (un caissier ne se crédite pas lui-même), ni un compte SUPPRIMÉ, ni un
 * numéro ou un e-mail en clair devant la file. Les soldes vus sont ceux de clients, et rien d'autre.
 *
 * Note sur la portée : `BranchScope` sort immédiatement sur le modèle `User` (Sanctum résout
 * l'utilisateur par ce modèle — filtrer provoquerait une récursion de garde). Vérifié en base :
 * 150 des 151 comptes fidélité portent `branch_id = 0`. Un caissier de la caisse 1 les voit donc
 * tous, ce qui est le comportement voulu — un client appartient à la maison, pas à un poste.
 *
 * Sentinelle : tests/Feature/Pos/PosCustomerLookupTest.php
 */
final class PosCustomerLookupService
{
    public const TROUVE     = 'found';
    public const AMBIGU     = 'ambiguous';
    public const INTROUVABLE = 'not_found';
    public const INVALIDE   = 'invalid';

    /** Au-delà, on ne rend pas une liste : on demande un critère plus précis. */
    private const MAX_CANDIDATS = 8;

    public function __construct(
        private PhoneIdentity $tel,
        private CustomerAccount $comptes,
        private LoyaltyRules $regles,
        private LoyaltyQrSigner $qr,
    ) {
    }

    /**
     * Recherche par numéro de téléphone — le moyen que l'exploitant préfère.
     *
     * @return array{status:string, ...}
     */
    public function byPhone(string $phone): array
    {
        if (! $this->tel->looksComplete($phone)) {
            return $this->invalide('PHONE_TOO_SHORT', 'Numéro incomplet.');
        }

        $candidats = User::query()
            ->whereIn('phone', $this->tel->variants($phone))
            ->orderByDesc('loyalty_points')
            ->limit(self::MAX_CANDIDATS + 1)
            ->get()
            ->filter(fn (User $u) => $this->eligible($u))
            ->values();

        return $this->depuisCandidats($candidats, 'phone');
    }

    /** Recherche par code fidélité — tapé, ou lu dans un QR en clair. */
    public function byCode(string $code): array
    {
        $code = strtoupper(trim($code));

        if (strlen($code) < 4) {
            return $this->invalide('CODE_TOO_SHORT', 'Code trop court.');
        }

        $user = User::query()->where('loyalty_code', $code)->first();

        if (! $user || ! $this->eligible($user)) {
            return $this->introuvable();
        }

        return ['status' => self::TROUVE, 'customer' => $this->presenter($user), 'via' => 'code'];
    }

    /**
     * Recherche par QR scanné à la tablette.
     *
     * Trois formes possibles, et on dit LAQUELLE a servi — parce qu'elles n'offrent pas la même
     * garantie et que le comptoir a le droit de le savoir :
     *   - `lqr.<charge>.<signature>` : signé, à usage unique, 5 minutes. La preuve la plus forte.
     *   - `FK:<code>` / `<code>`     : du texte. Ne vaut pas mieux qu'un code dicté à voix haute.
     */
    public function byQr(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '' || strlen($raw) > 512) {
            return $this->invalide('QR_UNREADABLE', 'QR illisible.');
        }

        if ($this->qr->isSignedToken($raw)) {
            try {
                $charge = $this->qr->verifyAndConsume($raw, 'pos');
            } catch (LoyaltyQrInvalidException $e) {
                return $this->invalide(
                    'QR_' . strtoupper($e->errorCode ?? 'INVALID'),
                    'QR expiré ou déjà utilisé — demandez au client de le réafficher.'
                );
            }

            $code = (string) ($charge['loyalty_code'] ?? $charge['code'] ?? '');

            if ($code === '') {
                return $this->invalide('QR_NO_CODE', 'QR sans code fidélité.');
            }

            $resultat = $this->byCode($code);
            $resultat['via'] = 'qr_signed';

            return $resultat;
        }

        // Texte en clair : on retire le préfixe s'il est là, et on traite comme un code.
        $code = stripos($raw, 'FK:') === 0 ? substr($raw, 3) : $raw;

        $resultat = $this->byCode($code);
        $resultat['via'] = 'qr_plaintext';

        return $resultat;
    }

    /**
     * Ce que le comptoir affiche d'un client. Rien de plus que le nécessaire pour décider.
     *
     * @return array<string, mixed>
     */
    public function presenter(User $user): array
    {
        $solde = max(0, (int) $user->loyalty_points);

        return [
            'id'              => (int) $user->id,
            'name'            => $this->nomAffichable($user),
            'phone_masked'    => $user->phone ? $this->tel->masked((string) $user->phone) : null,
            'email_masked'    => $user->email ? $this->emailMasque((string) $user->email) : null,
            'loyalty_code'    => (string) $user->loyalty_code,
            'balance'         => $solde,
            'balance_eur'     => $this->regles->euroValue($solde),
            // Ce qui est RÉELLEMENT utilisable maintenant : plancher effectif + multiple du taux.
            'usable_points'   => $this->regles->usablePoints($solde),
            'usable_eur'      => $this->regles->euroValue($this->regles->usablePoints($solde)),
            'can_use'         => $this->regles->usablePoints($solde) > 0,
            // Pour pouvoir DIRE ce qui manque au lieu d'opposer un refus sec.
            'missing_points'  => $this->regles->pointsMissingBeforeUse($solde),
            'effective_floor' => $this->regles->effectiveFloor(),
        ];
    }

    // ── privé ────────────────────────────────────────────────────────────────────────────────

    /**
     * Un compte utilisable au comptoir : un CLIENT, jamais l'équipe.
     *
     * Les comptes supprimés sont déjà exclus par le soft-delete d'Eloquent — à condition de ne
     * jamais écrire `withoutGlobalScopes()` sans argument, qui retire AUSSI `SoftDeletingScope` et
     * ressuscite 4 comptes effacés. Piège rencontré deux fois le 10 août.
     */
    private function eligible(User $user): bool
    {
        if ($this->comptes->isStaff($user)) {
            return false;
        }

        return $this->comptes->isCustomer($user) && ! empty($user->loyalty_code);
    }

    /** @param \Illuminate\Support\Collection<int, User> $candidats */
    private function depuisCandidats($candidats, string $via): array
    {
        if ($candidats->isEmpty()) {
            return $this->introuvable();
        }

        if ($candidats->count() === 1) {
            return ['status' => self::TROUVE, 'customer' => $this->presenter($candidats->first()), 'via' => $via];
        }

        if ($candidats->count() > self::MAX_CANDIDATS) {
            // Autant de comptes sur un même numéro n'est pas une ambiguïté ordinaire, c'est un
            // signal. On le trace pour qu'un ménage soit possible, et on demande mieux au comptoir.
            Log::warning('pos.loyalty.lookup.trop_de_candidats', ['via' => $via, 'n' => $candidats->count()]);

            return $this->invalide('TOO_MANY_MATCHES', 'Trop de comptes à ce critère — utilisez le code fidélité.');
        }

        return [
            'status'     => self::AMBIGU,
            'via'        => $via,
            'candidates' => $candidats->map(fn (User $u) => $this->presenter($u))->all(),
        ];
    }

    private function introuvable(): array
    {
        return ['status' => self::INTROUVABLE, 'error_code' => 'NO_ACCOUNT'];
    }

    private function invalide(string $code, string $message): array
    {
        return ['status' => self::INVALIDE, 'error_code' => $code, 'message' => $message];
    }

    private function nomAffichable(User $user): string
    {
        // La table `users` ne porte QU'UNE colonne de nom (`name`) — pas de `first_name`/`last_name`,
        // vérifié sur le schéma réel. Lire des colonnes fantômes rendait un nom vide sur chaque
        // ligne, donc un écran de comptoir où tous les candidats se ressemblent : impossible de
        // trancher entre deux comptes sur le même numéro, exactement ce que cet écran doit permettre.
        $nom = trim((string) ($user->name ?? ''));

        if ($nom !== '') {
            return $nom;
        }

        // Un compte créé au comptoir peut n'avoir aucun nom : on ne rend pas une chaîne vide, qui
        // s'afficherait comme une ligne fantôme sur la tablette.
        return 'Client ' . substr((string) $user->loyalty_code, 0, 6);
    }

    private function emailMasque(string $email): string
    {
        [$avant, $apres] = array_pad(explode('@', $email, 2), 2, '');

        if ($apres === '') {
            return '•••';
        }

        return substr($avant, 0, 1) . '•••@' . $apres;
    }
}
