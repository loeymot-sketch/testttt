<?php

namespace App\Services\Wheel;

use Smartisan\Settings\Facades\Settings;

/**
 * LES RÉGLAGES DE LA ROUE, SAISISSABLES PAR L'EXPLOITANT — et c'est le point de tout ce fichier.
 *
 * ── LE BLOCAGE QU'IL RÉSOUT ──────────────────────────────────────────────────────────────────
 * Les étapes du parcours ont besoin de trois adresses : le lien d'avis Google, le compte Instagram,
 * le compte Snapchat. Ce sont les COMPTES DU PROPRIÉTAIRE — je ne peux ni les inventer (un lien qui
 * mène ailleurs est pire que pas de lien) ni les deviner.
 *
 * Tant qu'elles vivaient dans des variables d'environnement, le jeu restait bloqué à attendre que
 * QUELQU'UN les pose sur le serveur. C'est exactement le genre de dépendance qui laisse une
 * fonctionnalité finie dormir des semaines. Elles vivent donc en BASE, saisissables depuis un écran
 * d'administration : le propriétaire colle ses trois liens en dix secondes, seul, depuis sa
 * tablette, et le parcours s'active immédiatement — sans redéploiement, sans accès serveur, sans moi.
 *
 * ── L'ORDRE DE PRIORITÉ ──────────────────────────────────────────────────────────────────────
 * Ce que l'exploitant a saisi PRIME toujours sur la configuration. La configuration ne sert que de
 * valeur de départ. Une valeur saisie vide est traitée comme absente — sinon un champ effacé par
 * mégarde ferait disparaître un réglage sans qu'on comprenne pourquoi.
 */
class WheelSettingsService
{
    public const GROUP = 'wheel';

    /** Clés saisissables, avec leur valeur de départ prise dans la configuration. */
    public function defaults(): array
    {
        return [
            'review_url' => (string) config('wheel.steps.review.url', ''),
            'instagram_url' => (string) config('wheel.steps.follow.instagram', ''),
            'snapchat_url' => (string) config('wheel.steps.follow.snapchat', ''),
            // Facebook : troisième réseau, dont l'adresse est DÉJÀ dans le site du restaurant. Ce
            // n'est donc pas une supposition — c'est une donnée vérifiée, et elle rend l'étape
            // « abonnement » utilisable dès aujourd'hui, sans attendre les deux autres comptes.
            'facebook_url' => (string) config('wheel.steps.follow.facebook', ''),
            'review_required' => (bool) config('wheel.steps.review.required', true) ? '1' : '0',
            'follow_required' => (bool) config('wheel.steps.follow.required', true) ? '1' : '0',
            'review_dwell' => (string) config('wheel.steps.review.dwell_seconds', 20),
            'follow_dwell' => (string) config('wheel.steps.follow.dwell_seconds', 8),
            'min_order' => (string) config('wheel.min_order_amount', 10),
        ];
    }

    /** Réglages effectifs = défauts écrasés par ce que l'exploitant a saisi. */
    public function all(): array
    {
        $stored = [];
        try {
            $brut = Settings::group(self::GROUP)->all();
            // Une valeur vide vaut « non renseignée » : un champ effacé par mégarde ne doit pas
            // faire disparaître un réglage en silence.
            $stored = is_array($brut)
                ? array_filter($brut, static fn ($v) => $v !== null && $v !== '')
                : [];
        } catch (\Throwable $e) {
            // Réglages illisibles : on retombe sur la configuration. Un jeu qui tourne avec ses
            // valeurs de départ vaut mieux qu'un jeu en panne.
            $stored = [];
        }

        return array_merge($this->defaults(), $stored);
    }

    public function get(string $key, $default = null)
    {
        $all = $this->all();

        return array_key_exists($key, $all) && $all[$key] !== '' ? $all[$key] : $default;
    }

    /**
     * Enregistre ce que l'exploitant a saisi. Seules les clés connues sont acceptées : un formulaire
     * n'a pas à pouvoir écrire n'importe quelle clé de réglage de l'application.
     */
    public function save(array $data): void
    {
        $connues = array_keys($this->defaults());
        $aEcrire = [];
        foreach ($data as $k => $v) {
            if (in_array($k, $connues, true)) {
                $aEcrire[$k] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
            }
        }

        if ($aEcrire !== []) {
            Settings::group(self::GROUP)->set($aEcrire);
        }
    }

    // ── Lectures typées, utilisées par le reste du jeu ────────────────────────────────────────

    /**
     * Lien pour laisser un avis.
     *
     * Si l'exploitant n'a pas encore collé le lien COURT de sa fiche Google (celui en `g.page/r/…`,
     * qui ouvre directement le formulaire), on en DÉRIVE un depuis l'identité du restaurant : une
     * recherche Google Maps sur le nom et l'adresse. Ce n'est pas aussi direct — le client aura un
     * appui de plus à faire — mais ça FONCTIONNE tout de suite, sans rien attendre de personne.
     *
     * On ne devine rien : le nom et l'adresse viennent de la fiche du restaurant en base, pas d'une
     * supposition. Et le lien collé par l'exploitant prime toujours.
     */
    public function reviewUrl(): string
    {
        $saisi = trim((string) $this->get('review_url', ''));
        if ($saisi !== '') {
            return $saisi;
        }

        return $this->derivedReviewUrl();
    }

    /** Le lien d'avis a-t-il été COLLÉ, ou est-il seulement dérivé ? Utile pour le dire à l'écran. */
    public function reviewUrlIsDerived(): bool
    {
        return trim((string) $this->get('review_url', '')) === '' && $this->derivedReviewUrl() !== '';
    }

    private function derivedReviewUrl(): string
    {
        // Un repli qu'on ne peut pas éteindre est un repli sur lequel on ne peut pas raisonner :
        // impossible de vérifier le comportement « aucun lien », ni de le désactiver si
        // l'exploitant ne veut PAS de lien d'avis du tout.
        if (! (bool) config('wheel.steps.review.derive_fallback', true)) {
            return '';
        }

        try {
            $b = \App\Models\Branch::query()
                ->withoutGlobalScopes()
                ->orderBy('id')
                ->first(['name', 'address', 'zip_code', 'city']);
        } catch (\Throwable $e) {
            return '';
        }

        if (! $b || trim((string) $b->name) === '') {
            return '';
        }

        // Le nom de la branche porte souvent un suffixe technique — « Le Cayenne (principal) » —
        // qui n'existe pas sur la fiche Google et fait échouer la recherche. On le retire, ainsi que
        // tout ce qui suit un tiret cadratin ou un pipe : ces marqueurs servent à l'exploitant, pas
        // à Google.
        $nom = trim(preg_replace('/\s*[\(\[|—-]{1}.*$/u', '', (string) $b->name) ?? '');
        if ($nom === '') {
            $nom = trim((string) $b->name);
        }

        $morceaux = array_filter([
            $nom,
            trim((string) $b->address),
            trim((string) $b->zip_code),
            trim((string) $b->city),
        ], static fn ($x) => $x !== '');

        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(implode(' ', $morceaux));
    }

    public function facebookUrl(): string
    {
        return trim((string) $this->get('facebook_url', ''));
    }

    public function instagramUrl(): string
    {
        return trim((string) $this->get('instagram_url', ''));
    }

    public function snapchatUrl(): string
    {
        return trim((string) $this->get('snapchat_url', ''));
    }

    public function reviewRequired(): bool
    {
        return (string) $this->get('review_required', '0') === '1';
    }

    public function followRequired(): bool
    {
        return (string) $this->get('follow_required', '0') === '1';
    }

    public function reviewDwell(): int
    {
        return max(0, (int) $this->get('review_dwell', 20));
    }

    public function followDwell(): int
    {
        return max(0, (int) $this->get('follow_dwell', 8));
    }

    public function minOrder(): float
    {
        return max(0, (float) $this->get('min_order', 10));
    }

    /**
     * Le parcours est-il RÉELLEMENT actif ? C'est la question que le propriétaire pose : « jamais ça
     * tourne ». Il tourne dès qu'au moins un lien est renseigné — donc dès qu'il y a quelque chose à
     * ouvrir et à chronométrer.
     */
    public function journeyReady(): bool
    {
        return $this->reviewUrl() !== '' || $this->instagramUrl() !== ''
            || $this->snapchatUrl() !== '' || $this->facebookUrl() !== '';
    }
}
