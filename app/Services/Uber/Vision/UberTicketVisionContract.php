<?php

namespace App\Services\Uber\Vision;

/**
 * [UBER-PHOTO 2026-08-10 · owner] Lecteur de TICKET Uber photographié.
 *
 * Un seul contrat, deux implémentations : la vraie (modèle de vision) et une doublure locale.
 * Tout le reste du flux — la mise en correspondance avec le catalogue, la symbolisation cuisine,
 * la création de la commande, l'impression — est IDENTIQUE quelle que soit l'implémentation.
 * C'est ce qui permet de tout tester de bout en bout sans clé d'API et sans un octet de réseau.
 *
 * POURQUOI PLUSIEURS PHOTOS EN UN SEUL APPEL
 * ------------------------------------------
 * L'owner l'a dit : « la tablette n'affiche pas tous les produits en même temps, je prendrai
 * plusieurs photos ». Un ticket coupé en deux clichés reste UNE commande. Si on lisait chaque
 * photo séparément on obtiendrait deux commandes à moitié vides, et la cuisine préparerait deux
 * fois la même chose. Les images arrivent donc ENSEMBLE, dans l'ordre où elles ont été prises,
 * et le lecteur rend UNE lecture.
 */
interface UberTicketVisionContract
{
    /**
     * Lit un ticket Uber à partir d'une ou plusieurs photos (ordre = ordre de prise de vue).
     *
     * Contrat de sortie — tout champ inconnu est ABSENT ou vide, JAMAIS deviné :
     *
     * @param  array<int, string>  $photoPaths  chemins absolus, lisibles
     * @return array{
     *     customer_name: string,
     *     display_id: string,
     *     order_type: string,
     *     items: array<int, array{title: string, quantity: int, options: array<int,string>, note: string}>,
     *     total: float|null
     * }
     */
    public function readTicket(array $photoPaths): array;

    /** Nom court du pilote, journalisé sur la capture (« openai », « mock ») — traçabilité. */
    public function driverName(): string;
}
