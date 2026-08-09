<?php

namespace App\Services\Pilotage;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Smartisan\Settings\Facades\Settings;

/**
 * [PILOTAGE 2026-08-09] Les interrupteurs que le propriétaire peut actionner
 * lui-même, sans déploiement.
 *
 * Constat de l'audit (reports/audit/PILOTAGE_2026-08-09.md, point 4) : les
 * bascules qui gouvernent des comportements sensibles vivaient dans des fichiers
 * de configuration. Les changer exigeait un déploiement — or ce sont précisément
 * les leviers qu'on veut actionner en quelques minutes : couper le paiement
 * fractionné un soir où le terminal fait des siennes, retirer la roue d'une
 * opération qui dérape.
 *
 * ── Comment ça marche, et pourquoi ainsi ────────────────────────────────────
 * La valeur enregistrée en base ÉCRASE celle du fichier au démarrage, via
 * Config::set. Les appels `config('split_payment.enabled')` déjà présents dans
 * le code — huit, dont cinq dans le chemin de paiement — continuent donc de
 * fonctionner sans être touchés. Modifier huit sites d'appel dans du code
 * d'encaissement pour gagner un réglage aurait été un mauvais échange.
 *
 * ── Ce qui n'est VOLONTAIREMENT pas ici ─────────────────────────────────────
 * `idempotency.enabled` n'est PAS un interrupteur : c'est une protection.
 * AppServiceProvider REFUSE DE DÉMARRER en production si elle est désactivée
 * (CLAUDE.md §8), parce qu'elle empêche l'encaissement en double. La rendre
 * basculable depuis un écran reviendrait à mettre un bouton « désactiver le
 * garde-fou fiscal » à portée de clic. Elle reste dans le fichier, et sous le
 * garde de démarrage.
 */
class InterrupteurService
{
    /**
     * Le catalogue des bascules autorisées. Une clé absente d'ici n'est PAS
     * modifiable par l'API : c'est une liste blanche, pas un filtre.
     *
     * @var array<string,array{cle:string,libelle:string,description:string,consequence:string}>
     */
    public const CATALOGUE = [
        'split_payment' => [
            'cle'         => 'split_payment.enabled',
            'libelle'     => 'Paiement en plusieurs fois',
            'description' => "Permet d'encaisser une commande en plusieurs règlements (espèces + carte, ou deux cartes).",
            'consequence' => 'Désactivé, la caisse n’accepte plus qu’un seul moyen de paiement par commande.',
        ],
        'wheel' => [
            'cle'         => 'wheel.enabled',
            'libelle'     => 'Roue promotionnelle',
            'description' => 'Le jeu de la roue proposé au client après sa commande.',
            'consequence' => 'Désactivée, la roue disparaît ; les lots déjà gagnés restent valables.',
        ],
    ];

    private const GROUPE = 'pilotage';

    /** Valeur courante d'une bascule : la base si elle a été réglée, sinon le fichier. */
    public function valeur(string $nom): bool
    {
        $def = self::CATALOGUE[$nom] ?? null;
        if ($def === null) {
            return false;
        }
        $defaut = (bool) Config::get($def['cle'], false);

        try {
            $stocke = Settings::group(self::GROUPE)->get($def['cle'], null);
        } catch (\Throwable $e) {
            // Base indisponible (migrations, console) : le fichier fait foi.
            return $defaut;
        }

        return $stocke === null ? $defaut : (bool) $stocke;
    }

    /** Régler une bascule. Retourne l'état complet, tel qu'il sera lu ensuite. */
    public function regler(string $nom, bool $actif): array
    {
        if (! isset(self::CATALOGUE[$nom])) {
            throw new \InvalidArgumentException("Interrupteur inconnu : {$nom}");
        }
        $cle = self::CATALOGUE[$nom]['cle'];
        Settings::group(self::GROUPE)->set($cle, $actif);
        // Prise en compte immédiate dans la requête en cours, sans attendre le
        // prochain démarrage : sinon l'écran afficherait l'ancien état.
        Config::set($cle, $actif);

        return $this->etat();
    }

    /** L'état de toutes les bascules, prêt pour l'écran. */
    public function etat(): array
    {
        $out = [];
        foreach (self::CATALOGUE as $nom => $def) {
            $out[] = [
                'nom'         => $nom,
                'libelle'     => $def['libelle'],
                'description' => $def['description'],
                'consequence' => $def['consequence'],
                'actif'       => $this->valeur($nom),
                'defaut'      => (bool) Config::get($def['cle'], false),
            ];
        }

        return $out;
    }

    /**
     * Applique les valeurs enregistrées à la configuration, au démarrage.
     *
     * Volontairement silencieux en cas d'échec : une base injoignable ne doit
     * pas empêcher l'application de démarrer, elle doit simplement laisser les
     * valeurs du fichier en place. C'est le comportement d'avant.
     */
    public function appliquerAuDemarrage(): void
    {
        try {
            foreach (self::CATALOGUE as $def) {
                $v = Settings::group(self::GROUPE)->get($def['cle'], null);
                if ($v !== null) {
                    Config::set($def['cle'], (bool) $v);
                }
            }
        } catch (\Throwable $e) {
            Log::debug('[pilotage] interrupteurs non appliqués : '.$e->getMessage());
        }
    }
}
