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

    public function reviewUrl(): string
    {
        return trim((string) $this->get('review_url', ''));
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
        return $this->reviewUrl() !== '' || $this->instagramUrl() !== '' || $this->snapchatUrl() !== '';
    }
}
