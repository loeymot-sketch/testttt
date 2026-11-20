<?php

namespace App\Imports\Concerns;

use Illuminate\Support\Str;

/**
 * [ONB 2026-08-28] Accepter le fichier que l'application vient d'exporter.
 *
 * Les exports ecrivent des en-tetes TRADUITS (`trans('all.label.name')` donne
 * « Nom »), et `WithHeadingRow` les slugge (`nom`). Les imports, eux, attendent
 * les noms canoniques (`name`). Sans pont entre les deux, l'aller-retour
 * « j'exporte ma carte, je corrige, je redepose » ne revient JAMAIS : toutes les
 * lignes echouent sur `name required` et `SkipsOnFailure` les avale.
 *
 * Les alias sont DERIVES des memes cles de traduction que les exports utilisent.
 * Une table ecrite a la main aurait derive des la premiere reformulation d'un
 * libelle — et ce depot a deja paye ce motif : la correction avait ete appliquee
 * a l'import des produits, et pas a son jumeau des categories.
 */
trait AccepteLesEnTetesExportes
{
    /**
     * Cle de traduction utilisee par l'export -> nom de colonne attendu ici.
     *
     * @return array<string, string>
     */
    abstract protected static function correspondanceDesEnTetes(): array;

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function prepareForValidation($row, $index)
    {
        foreach (static::aliasDesColonnes() as $alias => $canonique) {
            if (! array_key_exists($canonique, $row) && array_key_exists($alias, $row)) {
                $row[$canonique] = $row[$alias];
            }
        }

        return $row;
    }

    /**
     * Alias d'en-tete -> colonne attendue, pour toutes les langues installees.
     *
     * @return array<string, string>
     */
    protected static function aliasDesColonnes(): array
    {
        $alias = [];

        foreach (['fr', 'en', 'ar', 'bn', 'de'] as $langue) {
            foreach (static::correspondanceDesEnTetes() as $cle => $colonne) {
                $libelle = trans($cle, [], $langue);

                if (! is_string($libelle) || $libelle === '' || $libelle === $cle) {
                    continue;
                }

                // Meme sluggage que celui applique par WithHeadingRow.
                $alias[Str::slug($libelle, '_')] = $colonne;
            }
        }

        return $alias;
    }
}
