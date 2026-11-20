<?php

namespace App\Support\Order;

/**
 * [LIBELLÉ-FORMULE 2026-11-02] Ce que le client a RÉELLEMENT pris dans une formule.
 *
 * Sur la borne et la caisse, une formule est UN seul supplément scellé « Menu (Frites + Boisson) »
 * (item id 1), au nom IDENTIQUE pour les trois choix. Le choix du client n'est porté que par le
 * `role` du supplément dans `composition_snapshot` :
 *   menu_full    → frites ET boisson   (prix plein)
 *   menu_frites  → frites seules       (prix proratisé)
 *   menu_boisson → boisson seule       (prix proratisé)
 *
 * Défaut constaté par le propriétaire : l'écran de suivi, le détail de commande et le ticket à
 * l'écran affichaient « Menu » + un prix, quel que soit le choix — impossible de savoir si le
 * client avait pris les frites seules, la boisson seule ou le menu complet.
 *
 * Ce libellé ne change QUE le texte. Le prix affiché reste le `line_total` scellé (NF525).
 * Miroir JS : resources/js/helpers/menuFormulaLabel.js — toute évolution se fait dans les deux.
 */
final class MenuFormulaLabel
{
    public const FULL = 'Menu Frites + Boisson';

    public const FRITES = 'Frites seules';

    public const BOISSON = 'Boisson seule';

    /**
     * Libellé client d'un supplément de formule, ou '' si le rôle n'est pas une formule
     * (l'appelant garde alors le nom brut du supplément : boisson/dessert/upsell hors formule).
     */
    public static function forRole(?string $role): string
    {
        $role = strtolower(trim((string) $role));
        if ($role === '' || ! str_starts_with($role, 'menu_')) {
            return '';
        }

        return match ($role) {
            'menu_frites' => self::FRITES,
            'menu_boisson' => self::BOISSON,
            // menu_full / menu_formule / futur menu_* = formule complète.
            default => self::FULL,
        };
    }

    /**
     * Libellé à AFFICHER pour un supplément : le choix réel si le nom scellé est le nom GÉNÉRIQUE
     * de la formule (« Menu (Frites + Boisson) »), sinon le nom tel quel. Un supplément qui porte
     * déjà le nom de la boisson (« Coca-Cola », rôle menu_boisson) garde ce nom : l'écraser par
     * « Boisson seule » ferait perdre au caissier la boisson choisie.
     */
    public static function forAddon(?string $role, string $name): string
    {
        $label = self::forRole($role);
        if ($label === '') {
            return $name;
        }
        $clean = trim($name);

        return ($clean === '' || preg_match('/^menu\b/iu', $clean) === 1) ? $label : $name;
    }
}
