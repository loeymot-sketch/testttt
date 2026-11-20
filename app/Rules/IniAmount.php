<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * [ONB-02 2026-08-28] Validation d'un montant saisi.
 *
 * Les quatre messages etaient ECRITS EN ANGLAIS, en dur. Laravel n'appelle pas
 * `trans()` sur le message d'une regle-objet — seul `:attribute` etait remplace,
 * par sa traduction. Le commercant francais lisait donc des phrases hybrides du
 * genre « This prix must be a number. » sur le champ le plus utilise du
 * formulaire produit.
 *
 * Ils passent desormais par les fichiers de langue. Le comportement de la regle
 * est INCHANGE : seul ce qu'elle dit change — sauf le cas du zero, ou les deux
 * branches disaient la meme chose alors qu'elles refusent deux choses
 * differentes (voir le commentaire dans `passes()`).
 */
class IniAmount implements Rule
{
    public $message = '';
    public $zero;
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct($zero = false)
    {
        $this->zero = $zero;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value): bool
    {
        if (!is_numeric($value)) {
            $this->message = trans('validation.ini_amount.pas_un_nombre');
            return false;
        }

        // [ONB-02 2026-08-28] Les deux branches disaient la MEME phrase — « negative
        // amount not allow » — alors qu'elles refusent deux choses differentes.
        // Un commercant qui met 0 sur un accompagnement lisait qu'un montant NEGATIF
        // n'est pas permis, ce qui est faux : 0 n'est pas negatif. Il n'avait aucun
        // moyen de deviner que la vraie regle etait « strictement superieur a 0 ».
        //
        // La distinction elle-meme est DELIBEREE et conservee : un article doit avoir
        // un prix (`new IniAmount()`), une variation ou un supplement peuvent etre
        // offerts (`new IniAmount(true)`, cf. ItemVariationRequest:43 et
        // ItemExtraRequest:44). On ne change pas la regle, on la dit.
        if ($this->zero) {
            if ($value < 0) {
                $this->message = trans('validation.ini_amount.negatif_interdit');
                return false;
            }
        } else {
            if ($value <= 0) {
                $this->message = trans('validation.ini_amount.doit_etre_positif');
                return false;
            }
        }

        $replaceValue = str_replace('.', '', $value);
        if (strlen($replaceValue) > 12) {
            $this->message = trans('validation.ini_amount.trop_long');
            return false;
        }

        if (!preg_match("/^\d{1,10}(\.\d{1,6})?$/", $value)) {
            $this->message = trans('validation.ini_amount.format_invalide');
            return false;
        }
        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return $this->message;
    }
}
