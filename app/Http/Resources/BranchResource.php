<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
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
            "id"        => $this->id,
            "name"      => $this->name,
            "email"     => $this->email === null ? '' : $this->email,
            "phone"     => $this->phone === null ? '' : $this->phone,
            "latitude"  => $this->latitude === null ? '' : $this->latitude,
            "longitude" => $this->longitude === null ? '' : $this->longitude,
            "city"      => $this->city,
            "state"     => $this->state,
            "zip_code"  => $this->zip_code,
            "address"   => $this->address,
            "status"    => $this->status,
            "zone"      => $this->zone === null ? '' : $this->zone,

            /*
             * [ONB-01 2026-08-28 · P0 · CONFORMITE NF525] Ces quatre cles etaient
             * ABSENTES de la ressource.
             *
             * L'ecran des filiales porte pourtant une garde explicite
             * (`BranchListComponent.vue:198-203`) dont le commentaire annonce :
             * « sans ces trois lignes, ouvrir une filiale existante puis enregistrer
             * EFFACERAIT son identite fiscale ». Cette garde etait INERTE : elle fait
             * `branch.siret ?? ""` sur un objet qui ne porte pas la cle, donc toujours
             * `undefined` -> `""`. Le middleware `ConvertEmptyStringsToNull`
             * (`Kernel.php:29`) transforme ensuite `""` en `null`, `nullable` le laisse
             * passer, et `update()` l'ECRIT.
             *
             * Le commercant saisissait son SIRET, l'enregistrement reussissait, le
             * ticket sortait correctement. Il rouvrait la fiche pour corriger un
             * telephone, enregistrait — et son SIRET partait a NULL. Les tickets
             * suivants sortaient sans SIRET : un ticket de caisse francais sans SIRET
             * n'est pas conforme, et rien ne l'en avertissait.
             *
             * Quatrieme commentaire de cette session qui affirmait un comportement que
             * le code n'avait pas — et le seul qui se croyait protecteur.
             */
            "siret"        => $this->siret,
            "vat_intra"    => $this->vat_intra,
            "legal_footer" => $this->legal_footer,
            "register_id"  => $this->register_id,
        ];
    }
}