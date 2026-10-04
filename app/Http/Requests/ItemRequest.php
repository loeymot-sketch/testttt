<?php

namespace App\Http\Requests;

use App\Enums\Status;
use App\Models\Allergen;
use App\Models\Item;
use App\Rules\IniAmount;
use App\Rules\NoDangerousFileExtension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * V1.0.2 BUILD-6 heal: defense-in-depth — ItemController middleware enforces
     * `permission:items_create` on store/import/duplicate and `permission:items_edit`
     * on update/changeImage; FormRequest accepts either since the same class is injected
     * on both verbs. Any future route bypass still authz-checks against the items family.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        return $user->can('items_create') || $user->can('items_edit');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    /**
     * [ONB 2026-08-28] Distinguer « aucun allergene » de « champ non envoye ».
     *
     * `$item->update($request->validated())` n'ecrit que les cles PRESENTES. Un
     * formulaire qui n'envoie rien laisse donc la valeur en place — ce qui est le
     * bon comportement pour un ecran qui ignore le champ, mais rend IMPOSSIBLE le
     * retrait du dernier allergene : decocher la derniere case n'envoie aucune
     * entree `allergen_flags[]`, donc rien ne change.
     *
     * L'ecran envoie donc un temoin `allergen_flags_defini` : il affirme « j'ai
     * affiche ce champ et voici son etat complet ». Sa presence sans aucune entree
     * signifie « aucun allergene », et non « je n'en sais rien ».
     *
     * Le temoin n'a pas de regle : il est absent de `validated()`, donc jamais
     * ecrit sur le modele.
     */
    protected function prepareForValidation(): void
    {
        if ($this->boolean('allergen_flags_defini') && ! $this->has('allergen_flags')) {
            $this->merge(['allergen_flags' => []]);
        }
    }

    public function rules(): array
    {
        $allergenCodes = Allergen::query()->pluck('code')->all();

        return [
            'name'            => [
                'required',
                'string',
                'max:190',
                Rule::unique("items", "name")->whereNull('deleted_at')->ignore($this->route('item.id'))
            ],
            // [F-ITEM-CATEGORY-EXISTS 2026-07-15 / P2] `exists` + non soft-deleted : sans ça
            // l'update/création acceptait une catégorie INEXISTANTE (422 « erreur base » générique)
            // ou SOFT-DELETED (produit rattaché à une catégorie invisible → orphelin).
            'item_category_id' => ['required', 'numeric', 'not_in:0', \Illuminate\Validation\Rule::exists('item_categories', 'id')->whereNull('deleted_at')],
            // [ONB-02 T-2.1.3 2026-08-27] La taxe devient OBLIGATOIRE.
            //
            // Avant : 'nullable' + 'not_in:0'. La règle interdisait explicitement la
            // valeur 0 mais laissait passer `null` — et `PricingService.php:240-243`
            // fait `(int) ($dbItem->tax_id ?? 0)` puis `$taxes[0] ?? null`, donc
            // `$taxRate = 0.0` SANS alerte ni journal. Un article créé sans taxe
            // était facturé hors taxe, en silence, à la borne comme à la caisse.
            // Le garde-fou existait d'un côté, l'autre porte était ouverte.
            //
            // On ferme ici, à la source, ce qui ne demande aucune modification de
            // PricingService (zone gelée §7). La défense en profondeur côté moteur
            // de prix — refuser plutôt que facturer à 0 — reste à arbitrer par le
            // propriétaire (gate G-PRIX) : tant qu'elle n'existe pas, tout chemin
            // d'écriture qui contourne cette FormRequest rouvre le trou.
            'tax_id'           => ['required', 'numeric', 'not_in:0', 'exists:taxes,id'],
            'item_type'        => ['required', 'numeric', 'not_in:0'],
            'price'            => ['required', new IniAmount()],
            'is_featured'      => ['required', 'numeric', 'not_in:0'],
            // [GAP-27-1] is_upsell — optional flag for Splash-style upsell suggestions on kiosk
            'is_upsell'        => ['nullable', 'numeric'],
            'is_chef_pick'     => ['nullable', 'boolean'],
            'is_new'           => ['nullable', 'boolean'],
            'is_available'     => ['nullable', 'boolean'],
            'is_spicy'         => ['nullable', 'boolean'],
            'is_vegetarian'    => ['nullable', 'boolean'],
            'is_pork_free'     => ['nullable', 'boolean'],
            'is_halal'         => ['nullable', 'boolean'],
            'is_gluten_free'   => ['nullable', 'boolean'],
            'chef_pick_order'  => ['nullable', 'integer', 'min:0', 'max:9999'],
            'channels'         => ['nullable', 'array'],
            'channels.*'       => ['string', 'in:kiosk,pos,web'],
            'allergen_flags'   => ['nullable', 'array'],
            'allergen_flags.*' => array_values(array_filter([
                'string',
                $allergenCodes !== [] ? Rule::in($allergenCodes) : null,
            ])),
            'kiosk_emoji'      => ['nullable', 'string', 'max:10'],
            // [v1-0-1-h5 Z5-P1-02 2026-05-17] barcode + kds_station — fields fillable on Item model but were
            // silently dropped when posted via admin form (FormRequest gatekeeping). POS scanners + KDS routing rely on them.
            'barcode'          => ['nullable', 'string', 'max:64', 'unique:items,barcode' . ($this->item ? ',' . $this->item->id : '')],
            // [ONB-02 T-2.1.4 2026-08-27] La colonne est un ENUM MySQL STRICT
            // (migration add_kds_station_to_items : 'bar', 'cuisine_chaude',
            // 'cuisine_froide', 'none'). La règle acceptait n'importe quelle chaîne
            // de 32 caractères : toute autre valeur partait en base et se faisait
            // tronquer ou rejeter par MySQL, avec une erreur brute à l'écran.
            // On aligne la règle sur la colonne. Les quatre valeurs sont écrites en
            // clair ici plutôt que dans un App\Enums\KdsStation : cet enum est
            // revendiqué par ONB-10 (collision déclarée au protocole §5), on lui
            // laisse la main et on enverra une fiche pour brancher la constante.
            // `max:32` est conservé : ItemRequestBarcodeKdsStationTest vérifie que
            // la règle existe toujours, et une borne de longueur ne coûte rien.
            'kds_station'      => ['nullable', 'string', 'max:32', 'in:bar,cuisine_chaude,cuisine_froide,none'],
            'description'      => ['nullable', 'string', 'max:5000'],
            'caution'          => ['nullable', 'string', 'max:5000'],
            'status'           => ['required', 'numeric', 'max:24'],
            'order'            => ['required', 'numeric'],
            'variations'       => ['nullable', 'json'],
            'extras'           => ['nullable', 'json'],
            // [GOAL-L2-HEAL-02 2026-05-24] Phase L7.1-V1: NoDangerousFileExtension
            // blocks .pht / double-extension polyglot attacks.
            'image'            => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048', new NoDangerousFileExtension()],
        ];
    }

    /**
     * [F-ITEM-SAVE-MUET 2026-09-03] « La valeur du champ nom est deja utilisee » ne dit pas
     * QUI occupe le nom. Or le produit en conflit peut etre DESACTIVE : il n'apparait alors
     * dans aucune liste que le commercant consulte, et le refus devient incomprehensible —
     * il ne voit qu'un enregistrement qui « ne marche pas ».
     *
     * Cas reel, reproduit en production le 2026-09-03 : deux fiches « Sandwich Classique »,
     * l'une de mai DESACTIVEE, l'autre d'aout ACTIVE. Modifier la fiche active repondait 422
     * a cause d'une fiche invisible. On nomme donc le produit qui occupe le nom, et son etat.
     */
    public function messages(): array
    {
        return [
            'name.unique' => $this->messageNomDejaUtilise(),
        ];
    }

    private function messageNomDejaUtilise(): string
    {
        $nom = (string) $this->input('name');

        $enCours = $this->route('item');
        $idEnCours = $enCours instanceof Item ? $enCours->getKey() : $enCours;

        // [SENTINELLE WGS 2026-09-03] `withoutGlobalScopes()` retiré : le modèle Item ne
        // déclare AUCUN scope global (vérifié), l'appel était donc un no-op posé par
        // réflexe — précisément ce que WithoutGlobalScopesAuditSentinelTest traque. Le
        // précédent du 2026-08-19 (5 appels retirés plutôt qu'inscrits sur la liste
        // d'exceptions) fait règle. Comportement inchangé.
        $conflit = Item::query()
            ->whereNull('deleted_at')
            ->where('name', $nom)
            ->when($idEnCours !== null, fn ($requete) => $requete->whereKeyNot($idEnCours))
            ->first();

        if ($conflit === null) {
            return 'Ce nom est deja utilise par un autre produit.';
        }

        $etat = (int) $conflit->status === Status::ACTIVE
            ? 'actif'
            : 'DESACTIVE — il n\'apparait pas dans la liste des produits actifs';

        return sprintf(
            'Le nom « %s » est deja porte par le produit #%d (%s). Renommez l\'un des deux.',
            $nom,
            $conflit->getKey(),
            $etat
        );
    }

    public function attributes()
    {
        return [
            'item_category_id' => strtolower(trans('all.label.item_category_id')),
            'tax_id'           => strtolower(trans('all.label.tax_id')),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateNestedModifierSurfaces($validator, 'variations');
            $this->validateNestedModifierSurfaces($validator, 'extras');
            $this->validateNestedModifierPrices($validator, 'variations');
            $this->validateNestedModifierPrices($validator, 'extras');
        });
    }

    /**
     * [P3 heal 2026-07-07] Prix des variations/extras non validés à l'édition item.
     *
     * `variations` / `extras` sont postés comme blobs JSON et n'étaient validés que
     * par la règle `json` (structure) — jamais le prix de chaque ligne. Un prix
     * négatif ou non-numérique traversait la validation et était persisté tel quel
     * par ItemService (createMany / update), corrompant le pricing catalogue (SSOT).
     * On refuse ici toute ligne dont `price` est présent mais non-numérique ou < 0.
     * 0 reste légitime (supplément/variation gratuit — cohérent avec IniAmount(true)
     * appliqué aux variations unitaires).
     */
    private function validateNestedModifierPrices(Validator $validator, string $field): void
    {
        $raw = $this->input($field);
        if ($raw === null || $raw === '') {
            return;
        }

        $rows = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row) || ! array_key_exists('price', $row)) {
                continue;
            }

            $price = $row['price'];
            if ($price === null || ! is_numeric($price)) {
                $validator->errors()->add("{$field}.{$index}.price", 'The price must be a number.');
                continue;
            }

            if ((float) $price < 0) {
                $validator->errors()->add("{$field}.{$index}.price", 'The price must be at least 0.');
            }
        }
    }

    private function validateNestedModifierSurfaces(Validator $validator, string $field): void
    {
        $raw = $this->input($field);
        if ($raw === null || $raw === '') {
            return;
        }

        $rows = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row) || ! array_key_exists('visible_on', $row) || $row['visible_on'] === null) {
                continue;
            }

            if (! is_array($row['visible_on'])) {
                $validator->errors()->add("{$field}.{$index}.visible_on", 'The visible_on field must be an array.');
                continue;
            }

            foreach ($row['visible_on'] as $surfaceIndex => $surface) {
                if (! in_array((string) $surface, ['kiosk', 'pos', 'web'], true)) {
                    $validator->errors()->add("{$field}.{$index}.visible_on.{$surfaceIndex}", 'The selected visible_on surface is invalid.');
                }
            }
        }
    }
}
