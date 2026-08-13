<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {


        return [
            "id"           => $this->id,
            "name"         => $this->name,
            "username"     => $this->username,
            "email"        => $this->email,
            "branch_id"    => $this->branch_id,
            "phone"        => $this->phone === null ? '' : $this->phone,
            "status"       => $this->status,
            "image"        => $this->image,
            "country_code" => $this->country_code,

            /*
             * [FIDÉLITÉ 2026-08-13] LE SOLDE MANQUAIT ICI, ET SEULEMENT ICI.
             *
             * Mesuré en production : 25 adhérents à la fidélité, et pas un seul endroit dans
             * l'administration pour voir ce qu'ils ont. Deux gestes de patron en étaient rendus
             * impossibles — répondre à « pourquoi j'ai ce solde ? » quand un client conteste au
             * comptoir, et décider quoi que ce soit sur la fidélité : on ne pilote pas ce qu'on ne
             * voit pas.
             *
             * Le solde était pourtant DÉJÀ servi par `UserResource:38`. Il ne manquait que dans
             * cette ressource-ci, qui est celle de l'écran « Clients ». Deux ressources pour la
             * même personne, une seule à jour : le motif du « jumeau oublié » rencontré trois fois
             * dans ce projet — un correctif posé sur une copie, et personne ne voit la seconde.
             *
             * `(int)` avec repli à 0 : un solde absent vaut ZÉRO, jamais `null`. Zéro est une
             * information qu'un caissier peut lire ; `null` est une case vide qu'il interprète.
             */
            "loyalty_code"   => $this->loyalty_code,
            "loyalty_points" => (int) ($this->loyalty_points ?? 0),
            // [TERRAIN-HEAL 2026-07-16 · CUSTRES-MSGCOUNT] idem : ne pas hydrater toute la relation
            // messages par client juste pour la compter (préférer withCount, sinon COUNT léger).
            "messages"     => $this->messages_count
                ?? ($this->relationLoaded('messages') ? $this->messages->count() : $this->messages()->count()),
        ];
    }
}
