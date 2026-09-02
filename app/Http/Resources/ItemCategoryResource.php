<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Database\Eloquent\Model;

class ItemCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request) : array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            // [2026-09-02] La hiérarchie existait en base et dans la borne, mais AUCUN écran admin ne
            // l'exposait : impossible de créer une sous-catégorie depuis le Dashboard.
            'parent_id'       => isset($this->resource->parent_id) ? (int) $this->resource->parent_id : null,
            'parent_name'     => $this->resource instanceof Model
                ? $this->whenLoaded('parent', fn () => $this->parent?->name)
                : null,
            'slug'            => $this->slug,
            'description'     => $this->description === null ? '' : $this->description,
            'status'          => $this->status,
            'sort'            => (int) ($this->sort ?? 0),
            'thumb'           => $this->thumb,
            'cover'           => $this->cover,
            // Kiosk catalogue legacy fields expected by older Vue components.
            'image'           => $this->thumb ?: $this->cover,
            'image_full_path' => $this->cover ?: $this->thumb,
            'wizard_template' => $this->wizard_template ?? 'simple',
            'has_menu'        => (bool)($this->has_menu ?? false),
            'kiosk_upsell_include'         => (bool)($this->kiosk_upsell_include ?? true),
            'kiosk_upsell_skip_after_cart' => (bool)($this->kiosk_upsell_skip_after_cart ?? false),

            /*
             * [ONB-02 2026-08-28] Ces deux cles etaient ABSENTES, et l'ecran les
             * reposait a 0 a chaque enregistrement.
             *
             * Le formulaire s'hydrate depuis cette ressource : `yn(undefined)` donne
             * `0` (`ItemCateogryListComponent.vue:268-269`), puis `save()` les envoie
             * INCONDITIONNELLEMENT (`ItemCategoryCreateComponent.vue:245-246`).
             *
             * Consequence : corriger une faute dans le NOM d'une categorie eteignait
             * la pre-selection de la formule a la borne (`default_menu_kiosk`,
             * `KioskStepMenuComponent.vue:456`) ET ajoutait une etape « sauce frites »
             * au parcours client (`sauce_included_menu`, `KioskWizardComponent.vue:1072`).
             * Sans un mot, sans trace, et sans aucun ecran ou relire la valeur perdue.
             *
             * Meme motif que l'identite fiscale effacee au second enregistrement d'une
             * filiale, corrige le meme jour : une ressource qui omet un champ que le
             * formulaire renvoie.
             */
            'default_menu_kiosk'  => (bool)($this->default_menu_kiosk ?? false),
            'sauce_included_menu' => (bool)($this->sauce_included_menu ?? false),
        ];
    }
}
