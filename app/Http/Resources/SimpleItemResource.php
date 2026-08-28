<?php

namespace App\Http\Resources;


use App\Enums\Status;
use App\Libraries\AppLibrary;
use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class SimpleItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        $price = $this->price;
        $effectiveAvailability = $this->effective_is_available;
        $isAvailable = $effectiveAvailability === null
            ? ($this->is_available === null ? true : (bool) $this->is_available)
            : (bool) $effectiveAvailability;

        return [
            "id"             => $this->id,
            "name"           => $this->name,
            "slug"           => $this->slug,
            "item_category_id" => $this->item_category_id,
            "tax_id"           => $this->tax_id,
            "is_featured"      => $this->is_featured,
            // [ONB-02 2026-08-28] Le rang d'affichage MANQUAIT.
            //
            // Sans lui, le formulaire ne pouvait pas renvoyer la valeur reelle et
            // postait une constante : corriger une faute de frappe dans le nom d'un
            // produit remettait son rang a 1, defaisant l'ordre de la carte que le
            // commercant avait etabli. La borne trie la-dessus.
            //
            // Il manquait aussi a `CatalogStudioComponent::nextItemOrder`, qui lit
            // `it.order` pour placer un nouveau produit A LA FIN : la clef etant
            // absente, `max` restait 0 et la methode rendait toujours 1.
            "order"            => $this->order,
            "is_upsell"        => $this->is_upsell ?? 10,
            "flat_price"     => AppLibrary::flatAmountFormat($this->price),
            "convert_price"  => AppLibrary::convertAmountFormat($this->price),
            "currency_price" => AppLibrary::currencyAmountFormat($this->price),
            "price"          => $this->price,
            "item_type"      => $this->item_type,
            "status"         => $this->status,
            "is_available"   => $isAvailable,
            "availability_reason" => $this->availability_reason,
            "description"    => $this->description === null ? '' : $this->description,
            "caution"        => $this->caution === null ? '' : $this->caution,
            "thumb"          => $this->thumb,
            "cover"          => $this->cover,
            "preview"        => $this->preview,
            "category_name"  => optional($this->category)->name,
            "offer"          => SimpleOfferResource::collection(
                $this->offer->filter(function ($offer) use ($price) {
                    if (Carbon::now()->between(
                            $offer->start_date,
                            $offer->end_date
                        ) && $offer->status === Status::ACTIVE) {
                        $offer->flat_price     = AppLibrary::flatAmountFormat($price - ($price / 100 * $offer->amount));
                        $offer->convert_price  = AppLibrary::convertAmountFormat(
                            $price - ($price / 100 * $offer->amount)
                        );
                        $offer->currency_price = AppLibrary::currencyAmountFormat(
                            $price - ($price / 100 * $offer->amount)
                        );
                        return $offer;
                    }
                })
            ),

            /*
             * [ONB-02 2026-08-28] `channels` etait ABSENT de cette ressource, qui est
             * la SEULE source du tiroir d'edition (`ItemController.php:112` ;
             * `store/modules/item.js:131-133` ne refait aucun appel).
             *
             * `item.channels` valait donc toujours `undefined` -> `[]`, et les trois
             * cases « Caisse / Borne / Web » revenaient SYSTEMATIQUEMENT decochees.
             *
             * Ce que ca produisait : un article restreint a `["pos"]`. Le commercant
             * rouvre sa fiche, voit trois cases vides, coche « Borne » en croyant
             * AJOUTER une surface, enregistre -> `channels = ["kiosk"]` ->
             * **l'article disparait de la caisse**. La seule interaction possible avec
             * ce champ produisait l'inverse de l'intention.
             */
            'channels' => $this->channels,

            /*
             * [ONB 2026-08-28] Exposes EN MEME TEMPS que le formulaire qui les
             * envoie, jamais apres.
             *
             * Le tiroir d'edition s'hydrate depuis cette ressource. Ajouter les
             * cases « allergenes » a l'ecran sans rendre la valeur ici aurait
             * reproduit a l'identique le defaut d'effacement corrige le meme jour
             * sur `siret`, sur les reglages de borne et sur `channels` : le
             * formulaire aurait affiche des cases vides, et les aurait renvoyees
             * telles quelles. Corriger une faute dans le NOM d'un produit aurait
             * efface ses allergenes — c'est-a-dire une information legalement due
             * au client (Reglement INCO 1169/2011).
             */
            'allergen_flags' => $this->allergen_flags ?? [],
            'kds_station'    => $this->kds_station,
        ];
    }
}
