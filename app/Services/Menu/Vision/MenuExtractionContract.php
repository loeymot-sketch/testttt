<?php

namespace App\Services\Menu\Vision;

/**
 * [ONB-04 2026-08-27] Contrat de LECTURE d'une carte photographiée.
 *
 * Même motif que les deux lectures d'image déjà en service :
 *  - {@see MockMenuExtractionService}  — DÉFAUT, fixture déterministe, aucune requête sortante.
 *  - une implémentation réelle viendra derrière le gate propriétaire G-IA.
 *
 * Le fournisseur ({@see \App\Providers\AssistantServiceProvider}) choisit selon
 * `config('assistant.enabled')` ET la présence d'une clé — deux verrous, jamais un.
 *
 * CE QUE CE CONTRAT NE FAIT PAS, et ne doit jamais faire :
 *  - il n'écrit rien en base ;
 *  - il ne calcule aucun prix ;
 *  - il ne choisit aucune taxe.
 *
 * Il rend une PROPOSITION. Un écran la montre, le commerçant corrige et valide,
 * et ce sont les services existants du catalogue qui écrivent — avec leurs règles,
 * dont la taxe obligatoire posée par ONB-02. C'est exactement le motif déjà
 * éprouvé sur la facture d'achat (brouillon puis validation humaine) et sur le
 * ticket Uber (capture puis confirmation explicite).
 *
 * Domaine NEUF, ADDITIF, HORS NF525 — aucune écriture fiscale.
 */
interface MenuExtractionContract
{
    /**
     * Lit une carte photographiée et rend une proposition structurée.
     *
     * @param  string  $cheminPhoto  Chemin de la photo, sur un disque non servi par le web.
     * @return array{
     *     categories: array<int, array{nom:string, confiance:float}>,
     *     articles: array<int, array{
     *         nom:string,
     *         categorie:string,
     *         prix:float|null,
     *         description:string|null,
     *         confiance:float
     *     }>,
     *     source: string,
     *     tronquee: bool
     * }
     *         `source` vaut 'bouchon' ou le nom du fournisseur réel — pour qu'un
     *         écran puisse toujours dire d'où vient ce qu'il affiche.
     *         `tronquee` signale que la carte dépassait le plafond de relecture
     *         humaine : mieux vaut le dire que rendre 200 lignes que personne ne lira.
     *         Peut rendre des listes vides, jamais null.
     */
    public function lireCarte(string $cheminPhoto): array;
}
