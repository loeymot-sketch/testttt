<?php

namespace App\Services\Menu\Vision;

/**
 * [ONB-04 2026-08-27] Le bouchon : une carte lue, sans qu'aucune requête ne sorte.
 *
 * C'est l'implémentation PAR DÉFAUT. Tant que le gate G-IA n'est pas tranché,
 * c'est la seule qui existe — et tout le reste de la chaîne (écran de
 * validation, correction, application au catalogue) peut être construit et
 * testé de bout en bout contre elle.
 *
 * Déterministe volontairement : la même photo rend toujours la même proposition.
 * Un bouchon qui varierait rendrait les tests instables et masquerait les vrais
 * défauts derrière du bruit.
 *
 * La fixture contient exprès des cas qui font mal, parce qu'un bouchon trop
 * propre ne prouve rien :
 *  - un prix illisible (null) ;
 *  - une confiance basse, sous le seuil ;
 *  - un accent et une apostrophe ;
 *  - deux articles de même nom dans des catégories différentes ;
 *  - une catégorie qui n'existe pas encore dans le catalogue.
 */
class MockMenuExtractionService implements MenuExtractionContract
{
    public function lireCarte(string $cheminPhoto): array
    {
        $plafond = (int) config('assistant.menu_extraction.max_items_par_lecture', 60);

        $articles = [
            [
                'nom'         => 'Tacos poulet',
                'categorie'   => 'Tacos',
                'prix'        => 8.50,
                'description' => null,
                'confiance'   => 0.96,
            ],
            [
                'nom'         => 'Tacos mixte',
                'categorie'   => 'Tacos',
                'prix'        => 9.00,
                'description' => 'Deux viandes au choix',
                'confiance'   => 0.94,
            ],
            [
                // Le prix n'a pas pu être lu : la ligne remonte quand même, marquée.
                // L'écran de validation devra la faire saisir, pas l'inventer.
                'nom'         => 'Assiette du chef',
                'categorie'   => 'Assiettes',
                'prix'        => null,
                'description' => null,
                'confiance'   => 0.41,
            ],
            [
                // Accent et apostrophe : le chemin complet doit les préserver.
                'nom'         => 'Salade César à l’ancienne',
                'categorie'   => 'Salades',
                'prix'        => 7.90,
                'description' => 'Poulet grillé, parmesan',
                'confiance'   => 0.88,
            ],
            [
                // Même nom que plus haut, catégorie différente : le catalogue impose
                // l'unicité du nom d'article. La chaîne doit le détecter à la
                // validation, pas échouer à l'écriture avec une erreur SQL.
                'nom'         => 'Tacos poulet',
                'categorie'   => 'Menus midi',
                'prix'        => 11.50,
                'description' => 'Avec frites et boisson',
                'confiance'   => 0.72,
            ],
        ];

        $tronquee = count($articles) > $plafond;
        if ($tronquee) {
            $articles = array_slice($articles, 0, $plafond);
        }

        $categories = [];
        foreach ($articles as $a) {
            $categories[$a['categorie']] = max(
                $categories[$a['categorie']] ?? 0.0,
                $a['confiance']
            );
        }

        return [
            'categories' => array_map(
                static fn (string $nom, float $c): array => ['nom' => $nom, 'confiance' => $c],
                array_keys($categories),
                array_values($categories)
            ),
            'articles' => $articles,
            'source'   => 'bouchon',
            'tronquee' => $tronquee,
        ];
    }
}
