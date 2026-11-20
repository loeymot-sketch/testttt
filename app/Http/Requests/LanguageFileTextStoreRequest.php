<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * [ONB-13 2026-08-28] L'écriture des fichiers de langue n'avait AUCUNE validation.
 *
 * `LanguageController::fileTextStore` recevait une `Illuminate\Http\Request` brute et
 * la passait telle quelle au service, qui réinjectait chaque valeur **entre guillemets
 * doubles** dans un fichier PHP (`lang/fr/all.php` est un `<?php return [...]` que le
 * traducteur de Laravel inclut à chaque requête traduite), puis réécrivait le fichier.
 *
 * Deux défenses existaient déjà, et elles sont réelles :
 *   · le CHEMIN est confiné depuis le 2026-05-24 (`validateLangFilePath` : realpath,
 *     répertoires `lang/` ou `resources/js/languages/`, extensions php/json) ;
 *   · l'ACCÈS est gardé par `permission:settings`, posé explicitement en réponse à
 *     « [P0 SEC-RCE] … file_put_contents = arbitrary file write »
 *     (`LanguageController.php:22-27`).
 *
 * La troisième manquait : le CONTENU écrit n'était ni validé ni échappé. C'est la
 * seule des trois qui protège quand les deux autres tombent — et le confinement du
 * chemin ne sert à rien si ce qu'on écrit DANS le fichier autorisé est arbitraire.
 *
 * Cette requête borne les entrées ; l'échappement, lui, est fait à l'écriture par
 * `LanguageService::litteralPourFichier()`. Les deux sont nécessaires : la validation
 * seule laisserait passer un guillemet légitime dans une traduction, et l'échappement
 * seul laisserait passer des sauts de ligne et des charges de plusieurs mégaoctets.
 */
class LanguageFileTextStoreRequest extends FormRequest
{
    /** Le contrôleur porte déjà `permission:settings` ; on double la garde. */
    public function authorize(): bool
    {
        return (bool) ($this->user()?->can('settings'));
    }

    public function rules(): array
    {
        return [
            'x_language_file_path' => ['required', 'string', 'max:4096'],
            'x_language_file_name' => ['nullable', 'string', 'max:255'],

            // Toutes les autres clés sont des traductions. On refuse les retours à la
            // ligne et les caractères de contrôle : dans un fichier PHP, un saut de
            // ligne permet d'écrire une instruction indépendante, exactement comme le
            // garde-fou anti-injection du `.env` ailleurs dans ce dépôt.
            '*' => ['nullable', 'string', 'max:2000', 'regex:/^[^\r\n\x00-\x08\x0B\x0C\x0E-\x1F]*$/u'],
        ];
    }

    public function messages(): array
    {
        return [
            '*.regex' => 'Une traduction ne peut pas contenir de retour à la ligne ni de caractère de contrôle.',
            '*.max'   => 'Une traduction est limitée à 2000 caractères.',
        ];
    }
}
