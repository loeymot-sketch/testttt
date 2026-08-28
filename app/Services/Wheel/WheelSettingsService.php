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
 * Ce que l'exploitant a saisi PRIME toujours sur la configuration — y compris quand il a saisi
 * « rien ». La configuration ne sert que de valeur de départ, pour les clés auxquelles il n'a jamais
 * touché. Voir `stored()` : c'est là que se joue la différence entre « jamais réglé » et « retiré
 * exprès », et c'est ce qui rend le retrait d'un compte enfin possible.
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
        return array_merge($this->defaults(), $this->stored());
    }

    /**
     * LES LOTS TELS QUE L'EXPLOITANT LES A RÉGLÉS — probabilité et quantité.
     *
     * [2026-08-12 · propriétaire : « je veux permettre de faire la probabilité et le nombre de
     * cadeaux que je veux faire gagner aux gens — 50 tiramisu, 50 boissons, 10 sandwiches, 10
     * burgers pour le mois ; plus de probabilité sur les boissons aujourd'hui. »]
     *
     * Deux réglages par lot, et deux seulement, parce que ce sont les deux seules décisions qui lui
     * appartiennent vraiment :
     *   · `prize_<clé>_weight`   — la probabilité relative. 0 = affiché, jamais tiré.
     *   · `prize_<clé>_quantity` — le nombre de cadeaux pour la campagne. 0 = illimité.
     *
     * Ce qu'on ne lui donne PAS à régler : le produit de référence de coût, le type, le libellé. Un
     * libellé modifiable, c'est une roue qui promet « Big Cayenne » et sort une boisson ; un produit
     * de coût modifiable, c'est l'inventaire d'un autre article qui dérive. Ces choix restent dans le
     * fichier, où ils sont relus par quelqu'un.
     *
     * Une clé absente veut dire « je n'ai pas décidé » → la valeur du fichier tient. Une clé écrite à
     * 0 est une DÉCISION (« ne le fais plus gagner ») et prime, comme pour les liens de réseaux.
     *
     * @return array<string, array{weight?: int, quantity?: int}>
     */
    public function prizeOverrides(): array
    {
        $stored = $this->stored();
        $out = [];

        foreach ($stored as $cle => $valeur) {
            if (! preg_match('/^prize_(.+)_(weight|quantity)$/', (string) $cle, $m)) {
                continue;
            }

            if ($valeur === '' || $valeur === null) {
                continue;
            }

            // Jamais de valeur négative : un poids négatif fausserait le total du tirage, une
            // quantité négative rendrait un lot épuisé dès le premier tour.
            $out[$m[1]][$m[2]] = max(0, (int) $valeur);
        }

        return $out;
    }

    /** Les clés de réglage d'un lot, pour composer le formulaire et la validation. */
    public static function prizeKeys(string $prizeKey): array
    {
        return ["prize_{$prizeKey}_weight", "prize_{$prizeKey}_quantity"];
    }

    /**
     * CE QUE L'EXPLOITANT A RÉELLEMENT ENREGISTRÉ — sans les valeurs de départ de la configuration.
     *
     * ── POURQUOI UNE CHAÎNE VIDE EST CONSERVÉE ICI ────────────────────────────────────────────
     * [P1 2026-08-10 — audit E2E vague C] La version précédente jetait TOUTE valeur vide, au motif
     * qu'« un champ effacé par mégarde ne doit pas faire disparaître un réglage ». Conséquence
     * mesurée : retirer un compte était IMPOSSIBLE. Le champ vidé était bien écrit en base, la
     * lecture le remplaçait par la valeur par défaut de la configuration, et l'écran affichait quand
     * même « Réglages enregistrés ». Le patron retirait son lien, on lui répondait oui, et le lien
     * revenait tout seul au rechargement.
     *
     * Une chaîne vide ne peut arriver ici que d'une seule façon : l'exploitant a vidé CE champ et
     * envoyé le formulaire — `save()` n'écrit que les clés qui lui sont soumises, jamais les autres.
     * C'est donc une DÉCISION, et une décision prime sur une valeur livrée par défaut.
     *
     * La protection contre l'effacement involontaire n'est pas supprimée, elle change de place :
     * l'écran dit maintenant champ par champ ce qui est saisi, absent, de secours ou par défaut (voir
     * `linkStatuses()`). Un lien retiré par erreur se VOIT immédiatement, au lieu de réapparaître en
     * silence. `null` reste traité comme absent : c'est la marque d'une clé jamais écrite.
     */
    private function stored(): array
    {
        try {
            $brut = Settings::group(self::GROUP)->all();
        } catch (\Throwable $e) {
            // Réglages illisibles : on retombe sur la configuration. Un jeu qui tourne avec ses
            // valeurs de départ vaut mieux qu'un jeu en panne.
            return [];
        }

        return is_array($brut) ? array_filter($brut, static fn ($v) => $v !== null) : [];
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
            // [ONB-09 2026-08-28] La liste blanche ne contenait QUE les 9 cles de
            // `defaults()` — aucune `prize_*`. Or le formulaire poste
            // `prize_<lot>_weight` et `prize_<lot>_quantity` pour chaque lot
            // (`reglages.blade.php:289-290`), et le controleur construit pour chacune
            // une regle de validation et six messages d'erreur en francais
            // (`WheelSettingsController.php:98-110`).
            //
            // Toutes etaient donc ecartees EN SILENCE, et l'ecran affichait quand meme
            // « Reglages enregistres. ». `prizeOverrides()` ne trouvait jamais rien et
            // `WheelService::segments()` servait `config/wheel.php` intact : le
            // commercant plafonnait son budget cadeaux — « 10 burgers ce mois-ci »,
            // « Terminator a zero » — lisait un succes, et rien n'etait garde. Seul un
            // developpeur editant le fichier de configuration pouvait borner la depense.
            //
            // L'INTENTION DU FILTRE EST CONSERVEE : un formulaire ne doit pas pouvoir
            // ecrire n'importe quelle cle de reglage. On accepte donc les cles `prize_*`
            // par leur FORME EXACTE — la meme expression que celle que `prizeOverrides()`
            // relit — et rien d'autre.
            $estCleDeLot = (bool) preg_match('/^prize_(.+)_(weight|quantity)$/', (string) $k);

            if (in_array($k, $connues, true) || $estCleDeLot) {
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
            // `Branch` est déjà exempt de BranchScope (auto-référence) : le pluriel ne retirait donc que
            // le filtre de suppression douce, et pouvait rendre une branche SUPPRIMÉE comme adresse
            // du restaurant. Une requête simple est à la fois plus juste et plus courte.
            $b = \App\Models\Branch::query()
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

        // Le champ « adresse » contient souvent DÉJÀ le code postal et la ville : les ajouter
        // produisait « 437 Rue Élie Gruyelle, 62110 Hénin-Beaumont 62110 Hénin-Beaumont ». Une
        // adresse qui se répète brouille la recherche au lieu de la préciser.
        $adresse = trim((string) $b->address);
        $cp = trim((string) $b->zip_code);
        $ville = trim((string) $b->city);

        $morceaux = [$nom, $adresse];
        if ($cp !== '' && ! str_contains($adresse, $cp)) {
            $morceaux[] = $cp;
        }
        if ($ville !== '' && mb_stripos($adresse, $ville) === false) {
            $morceaux[] = $ville;
        }

        $morceaux = array_filter($morceaux, static fn ($x) => $x !== '');

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

    /**
     * [ONB-05 2026-08-28] LE MINIMUM DE COMMANDE, UNE SEULE PORTE D'ENTRÉE.
     *
     * Cette méthode n'avait qu'UN appelant (`WheelPrizeController:242`), pendant que
     * cinq autres endroits lisaient `config('wheel.min_order_amount')` en direct —
     * dont `WheelController:332`, qui APPLIQUE la contrainte au client.
     *
     * L'exploitant réglait « minimum 15 € », l'écran de contrôle affichait 15 €, et
     * la roue continuait d'appliquer la valeur du fichier. Un réglage qui ment coûte
     * plus cher qu'un réglage absent : il donne la certitude fausse d'avoir agi.
     *
     * Les replis divergeaient en prime : 10 ici, 0 chez les lecteurs directs.
     *
     * C'est exactement ce que le docblock de `WheelService::segments()` interdit
     * depuis août — « lire la config en direct ailleurs, ce serait ignorer les
     * réglages du propriétaire sur une surface et pas sur l'autre ». Le principe
     * était écrit et appliqué aux segments ; il ne l'était pas ici.
     */
    public function minOrder(): float
    {
        return max(0, (float) $this->get('min_order', 10));
    }

    /**
     * Le parcours PEUT-IL tourner ? C'est la question du moteur : y a-t-il quelque chose à ouvrir et
     * à chronométrer ? Un lien de secours ou une adresse livrée par défaut comptent ici — un jeu qui
     * tourne vaut mieux qu'un jeu qui attend, et c'est un choix assumé (correctif du 2026-08-09).
     *
     * ⚠️ Ce n'est PAS la question du patron. Lui demande « est-ce que MON jeu est réglé ? » — et à
     * cette question-là répondent `configuredByOperator()` et `linkStatuses()`. Confondre les deux
     * est exactement ce qui affichait une bannière verte au-dessus de champs tous vides.
     */
    public function journeyReady(): bool
    {
        return $this->reviewUrl() !== '' || $this->instagramUrl() !== ''
            || $this->snapchatUrl() !== '' || $this->facebookUrl() !== '';
    }

    /** Les quatre liens du parcours, dans l'ordre où l'écran les présente. */
    public const LINK_KEYS = ['review_url', 'instagram_url', 'snapchat_url', 'facebook_url'];

    /**
     * Au moins un lien vient-il RÉELLEMENT du patron ? C'est ce que la bannière doit dire.
     *
     * [P1 2026-08-10 — audit E2E vague C] La bannière verte « Le parcours tourne » s'affichait
     * au-dessus de champs tous vides, parce qu'elle interrogeait le moteur (`journeyReady()`) qui
     * compte le lien d'avis dérivé et la page Facebook livrée par défaut. Sur l'écran qui existe
     * précisément pour débloquer le jeu, le patron ne pouvait donc pas savoir s'il avait réglé quoi
     * que ce soit.
     */
    public function configuredByOperator(): bool
    {
        foreach ($this->linkStatuses() as $lien) {
            if ($lien['etat'] === 'saisi') {
                return true;
            }
        }

        return false;
    }

    /**
     * L'ÉTAT VRAI DE CHAQUE LIEN, champ par champ, pour que l'écran arrête de résumer.
     *
     * Cinq états, et l'écran les nomme un par un :
     *   · 'saisi'   — c'est SON lien, il l'a collé lui-même ;
     *   · 'retire'  — il avait quelque chose et l'a vidé exprès : on CONFIRME le retrait, au lieu de
     *                 laisser croire à une valeur par défaut revenue en douce ;
     *   · 'secours' — rien de collé : on ouvre une recherche Google Maps fabriquée depuis l'adresse
     *                 du restaurant. Ça marche, mais ce n'est pas sa vraie fiche ;
     *   · 'defaut'  — la valeur vient de la configuration livrée, pas de lui : à vérifier ;
     *   · 'absent'  — rien du tout, ce réseau ne sera pas proposé au client.
     *
     * @return array<int, array{cle: string, nom: string, etat: string, dit: string}>
     */
    public function linkStatuses(): array
    {
        $stored = $this->stored();
        $defauts = $this->defaults();

        $noms = [
            'review_url' => 'Avis Google',
            'instagram_url' => 'Instagram',
            'snapchat_url' => 'Snapchat',
            'facebook_url' => 'Facebook',
        ];

        $etats = [];
        foreach ($noms as $cle => $nom) {
            // L'ordre compte : un champ VIDÉ par le patron est un choix, il passe donc avant la valeur
            // par défaut. C'est tout le sens du correctif — sinon l'écran annoncerait « valeur par
            // défaut » pour un compte que le patron vient de retirer, et le retrait aurait l'air raté.
            $aTouche = array_key_exists($cle, $stored);
            $saisi = trim((string) ($stored[$cle] ?? ''));

            if ($aTouche && $saisi !== '') {
                $etat = 'saisi';
                $dit = 'ton lien';
            } elseif ($aTouche) {
                $etat = 'retire';
                $dit = 'retiré par toi — plus proposé au client';
            } elseif (trim((string) ($defauts[$cle] ?? '')) !== '') {
                $etat = 'defaut';
                $dit = 'valeur livrée par défaut — vérifie que c\'est bien ton compte';
            } elseif ($cle === 'review_url' && $this->reviewUrlIsDerived()) {
                $etat = 'secours';
                $dit = 'lien de secours, pas encore ta vraie fiche';
            } else {
                $etat = 'absent';
                $dit = 'absent — ne sera pas proposé au client';
            }

            $etats[] = ['cle' => $cle, 'nom' => $nom, 'etat' => $etat, 'dit' => $dit];
        }

        return $etats;
    }
}
