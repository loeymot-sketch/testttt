<?php

namespace App\Services\Wheel;

use App\Models\WheelStepProgress;
use Illuminate\Support\Facades\Log;

/**
 * LES ÉTAPES DU PARCOURS — et la seule façon honnête de les contrôler.
 *
 * ── CE QU'ON PEUT VÉRIFIER, ET CE QU'ON NE PEUT PAS ──────────────────────────────────────────
 * Aucune API publique ne dit qu'une personne PRÉCISE a écrit un avis Google ou s'est abonnée à un
 * compte. Ce n'est pas une lacune de notre code, c'est une limite de ces plateformes. Prétendre le
 * contraire serait mentir au propriétaire.
 *
 * Ce qui EST vérifiable, et qu'on vérifie donc :
 *   · le lien a bien été OUVERT — le serveur l'horodate lui-même ;
 *   · le TEMPS PASSÉ entre l'ouverture et la suite. Personne n'écrit un avis en deux secondes.
 *
 * ── POURQUOI L'HORODATAGE EST CÔTÉ SERVEUR ───────────────────────────────────────────────────
 * Le compteur de 20 secondes vit dans le navigateur : il se contourne dans les outils de
 * développement, ou en rejouant la requête. Un client qui déclare « j'ai attendu 20 s » ne déclare
 * rien. Le serveur pose donc l'heure lui-même et recalcule le délai au moment du tour — le client
 * ne peut pas mentir sur l'horloge du serveur.
 *
 * ── CE QUE LE CLIENT NE SAIT PAS ─────────────────────────────────────────────────────────────
 * Le relevé du nombre d'abonnés avant/après est invisible pour lui — décision explicite du
 * propriétaire : « je vais pas lui rentrer dans quelque chose de compliqué, il va pas le faire ».
 * C'est un contrôle de gestion, pas une porte : il ne bloque personne, il révèle les dérives sur
 * une journée.
 */
class WheelStepService
{
    public const REVIEW = 'review';
    public const FOLLOW = 'follow';

    /**
     * Les adresses et les délais viennent des RÉGLAGES SAISIS par l'exploitant, pas de variables
     * d'environnement. C'est ce qui débloque le jeu : il colle ses trois liens depuis un écran, en
     * dix secondes, et le parcours s'active — sans redéploiement ni accès serveur.
     */
    public function __construct(private readonly WheelSettingsService $reglages)
    {
    }

    /**
     * Le client vient d'ouvrir un lien. Le SERVEUR pose l'heure.
     *
     * @return array{ok: bool, wait_seconds: int}
     */
    public function open(string $tokenHash, int $branchId, string $step): array
    {
        $step = $step === self::REVIEW ? self::REVIEW : self::FOLLOW;

        $p = WheelStepProgress::firstOrCreate(
            ['unlock_token_hash' => $tokenHash],
            ['branch_id' => $branchId, 'followers_before' => $this->followersNow()]
        );

        $champ = $step === self::REVIEW ? 'review_opened_at' : 'follow_opened_at';

        // On n'ÉCRASE PAS un horodatage déjà posé : sinon il suffirait de rouvrir le lien juste
        // avant de tourner pour remettre le compteur à zéro… dans le bon sens pour le fraudeur.
        // La première ouverture est celle qui compte.
        if ($p->{$champ} === null) {
            $p->{$champ} = now();
            $p->save();
        }

        return ['ok' => true, 'wait_seconds' => $this->remaining($p, $step)];
    }

    /** Secondes restantes avant que l'étape se débloque. 0 = c'est bon. */
    public function remaining(WheelStepProgress $p, string $step): int
    {
        $champ = $step === self::REVIEW ? 'review_opened_at' : 'follow_opened_at';
        $ouvert = $p->{$champ};
        if ($ouvert === null) {
            return $this->dwell($step);
        }

        $ecoule = now()->diffInSeconds($ouvert);

        return max(0, $this->dwell($step) - $ecoule);
    }

    /**
     * Toutes les étapes REQUISES sont-elles faites, et assez lentement ?
     *
     * @throws WheelException
     */
    public function assertDone(string $tokenHash): array
    {
        $p = WheelStepProgress::where('unlock_token_hash', $tokenHash)->first();

        foreach ([self::REVIEW, self::FOLLOW] as $step) {
            if (! $this->required($step)) {
                continue;
            }

            if (! $p) {
                throw new WheelException($this->messageManquant($step), 428);
            }

            $reste = $this->remaining($p, $step);
            $champ = $step === self::REVIEW ? 'review_opened_at' : 'follow_opened_at';

            if ($p->{$champ} === null) {
                throw new WheelException($this->messageManquant($step), 428);
            }

            if ($reste > 0) {
                // Message qui ne culpabilise pas et qui donne le chiffre : un refus sans durée est
                // vécu comme une panne.
                throw new WheelException(
                    'Encore ' . $reste . ' seconde' . ($reste > 1 ? 's' : '') . ' et c\'est à toi !',
                    428
                );
            }
        }

        return [
            'review_opened_at' => $p?->review_opened_at,
            'follow_opened_at' => $p?->follow_opened_at,
            'followers_before' => $p?->followers_before,
            'steps_seconds' => $p && $p->review_opened_at
                ? now()->diffInSeconds($p->review_opened_at)
                : null,
        ];
    }

    /**
     * Une étape est requise SEULEMENT si elle est à la fois demandée ET FOURNIE.
     *
     * [P0 2026-08-09] Sans cette condition, une étape marquée « requise » mais sans adresse
     * configurée rendait le jeu INJOUABLE : le client n'avait aucun lien à ouvrir, le serveur
     * exigeait pourtant l'horodatage, et tout tour était refusé en 428 — indéfiniment, sans que
     * personne comprenne pourquoi. On ne peut pas exiger ce qu'on ne fournit pas.
     *
     * Conséquence voulue : tant que le propriétaire n'a pas donné son lien d'avis et ses comptes,
     * le jeu TOURNE (étapes sautées) au lieu d'être bloqué. `missingLinks()` le signale.
     */
    public function required(string $step): bool
    {
        $demandee = $step === self::REVIEW
            ? $this->reglages->reviewRequired()
            : $this->reglages->followRequired();

        if (! $demandee) {
            return false;
        }

        return $this->hasLink($step);
    }

    /** L'étape a-t-elle au moins une adresse utilisable ? */
    public function hasLink(string $step): bool
    {
        if ($step === self::REVIEW) {
            return $this->reglages->reviewUrl() !== '';
        }

        return $this->reglages->instagramUrl() !== ''
            || $this->reglages->snapchatUrl() !== ''
            || $this->reglages->facebookUrl() !== '';
    }

    /**
     * Étapes DEMANDÉES mais non fournies. Un trou nommé se corrige ; un trou silencieux fait croire
     * que le jeu vérifie quelque chose qu'il ne vérifie pas.
     *
     * @return array<int, string>
     */
    public function missingLinks(): array
    {
        $out = [];
        foreach ([self::REVIEW, self::FOLLOW] as $step) {
            $demandee = $step === self::REVIEW
                ? $this->reglages->reviewRequired()
                : $this->reglages->followRequired();
            if ($demandee && ! $this->hasLink($step)) {
                $out[] = $step;
            }
        }

        return $out;
    }

    public function dwell(string $step): int
    {
        return $step === self::REVIEW
            ? $this->reglages->reviewDwell()
            : $this->reglages->followDwell();
    }

    /** Ce que le client a besoin de savoir pour agir : les liens, et rien de plus. */
    public function publicSteps(): array
    {
        $out = [];
        if ($this->hasLink(self::REVIEW)) {
            $out[] = [
                'key' => self::REVIEW,
                'required' => $this->required(self::REVIEW),
                'url' => $this->reglages->reviewUrl(),
                'dwell' => $this->dwell(self::REVIEW),
            ];
        }
        $ig = $this->reglages->instagramUrl();
        $sc = $this->reglages->snapchatUrl();
        $fb = $this->reglages->facebookUrl();
        if ($ig !== '' || $sc !== '' || $fb !== '') {
            $out[] = [
                'key' => self::FOLLOW,
                'required' => $this->required(self::FOLLOW),
                'instagram' => $ig,
                'snapchat' => $sc,
                'facebook' => $fb,
                'dwell' => $this->dwell(self::FOLLOW),
            ];
        }

        return $out;
    }

    /**
     * Nombre d'abonnés Instagram MAINTENANT, pour le contrôle de cohérence.
     *
     * Rend NUL sans jeton configuré — et c'est volontaire : pas de valeur inventée, pas d'appel
     * réseau bloquant le parcours d'un client debout au comptoir. Snapchat n'a AUCUNE API publique
     * équivalente : on ne prétend pas le mesurer.
     */
    public function followersNow(): ?int
    {
        $id = (string) config('wheel.followers.instagram_account_id', '');
        $token = (string) config('wheel.followers.instagram_token', '');
        if ($id === '' || $token === '') {
            return null;
        }

        try {
            $r = \Illuminate\Support\Facades\Http::timeout(3)->get(
                'https://graph.facebook.com/v19.0/' . $id,
                ['fields' => 'followers_count', 'access_token' => $token]
            );

            return $r->successful() ? (int) ($r->json('followers_count') ?? 0) : null;
        } catch (\Throwable $e) {
            // Un contrôle de gestion ne doit JAMAIS faire échouer un parcours client.
            Log::channel('daily')->warning('wheel.followers_read_failed', ['message' => $e->getMessage()]);

            return null;
        }
    }

    private function messageManquant(string $step): string
    {
        return $step === self::REVIEW
            ? 'Laisse d\'abord ton avis — le bouton se débloque juste après.'
            : 'Dernière petite étape : abonne-toi, et c\'est à toi !';
    }
}
