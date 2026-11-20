<?php

namespace App\Services\Assistant\MissionLocale;

/**
 * [ONB-04 2026-08-28] Une mission locale comprise, sous forme exploitable.
 *
 * « Mission locale » au sens du mandat : une phrase du commerçant qui décrit un
 * geste répétitif sur SON catalogue — « ajoute une sauce à tous les tacos ». La
 * valeur n'est pas de deviner : elle est d'éviter cinquante allers-retours dans
 * un formulaire, sans jamais écrire quoi que ce soit qu'il n'ait pas vu d'abord.
 *
 * Objet de valeur, immuable, sans dépendance : il traverse `InterpreteDeMission`
 * (qui le fabrique), `PlanificateurDeMission` (qui le projette en diff sans rien
 * écrire) et `ExecuteurDeMission` (qui l'applique). Aucun de ces trois ne connaît
 * la phrase d'origine autrement que par cet objet.
 */
final class Mission
{
    /** Ajouter une option (sauce, supplément…) à tous les articles d'une catégorie. */
    public const AJOUTER_UNE_OPTION = 'ajouter_une_option';

    /** Fixer le prix de tous les articles d'une catégorie. */
    public const CHANGER_LE_PRIX = 'changer_le_prix';

    /** Activer ou désactiver tous les articles d'une catégorie. */
    public const CHANGER_LA_DISPONIBILITE = 'changer_la_disponibilite';

    private function __construct(
        public readonly string $type,
        public readonly string $categorie,
        public readonly ?string $nomOption = null,
        public readonly ?string $groupe = null,
        public readonly ?float $prix = null,
        public readonly ?bool $actif = null,
    ) {
    }

    public static function ajouterUneOption(
        string $categorie,
        string $nomOption,
        string $groupe,
        float $prix
    ): self {
        return new self(
            type: self::AJOUTER_UNE_OPTION,
            categorie: $categorie,
            nomOption: $nomOption,
            groupe: $groupe,
            prix: $prix,
        );
    }

    public static function changerLePrix(string $categorie, float $prix): self
    {
        return new self(type: self::CHANGER_LE_PRIX, categorie: $categorie, prix: $prix);
    }

    public static function changerLaDisponibilite(string $categorie, bool $actif): self
    {
        return new self(type: self::CHANGER_LA_DISPONIBILITE, categorie: $categorie, actif: $actif);
    }

    /**
     * Ce que la mission fera, dit au commerçant avant qu'il ne confirme.
     *
     * Écrit à la première personne du système, pas à l'impératif : il doit lire une
     * PROMESSE qu'il peut refuser, pas un ordre déjà parti.
     */
    public function resume(): string
    {
        return match ($this->type) {
            self::AJOUTER_UNE_OPTION => sprintf(
                'Ajouter « %s » (%s, %s) à tous les produits de « %s ».',
                $this->nomOption,
                mb_strtolower((string) $this->groupe),
                $this->prix > 0
                    ? 'supplément de ' . number_format($this->prix, 2, ',', ' ') . ' €'
                    : 'gratuit',
                $this->categorie
            ),
            self::CHANGER_LE_PRIX => sprintf(
                'Mettre tous les produits de « %s » à %s €.',
                $this->categorie,
                number_format((float) $this->prix, 2, ',', ' ')
            ),
            self::CHANGER_LA_DISPONIBILITE => sprintf(
                '%s tous les produits de « %s ».',
                $this->actif ? 'Activer' : 'Désactiver',
                $this->categorie
            ),
        };
    }
}
