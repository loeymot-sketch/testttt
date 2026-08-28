<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $printerId = $this->route('printer')?->id;
        $branchId = $this->resolvedBranchId();

        return [
            // [ONB-10 2026-08-28] Obligatoire pour l'admin qui CRÉE.
            //
            // `printers.branch_id` porte une clé étrangère vers `branches.id`, et
            // `resolveBranchId()` renvoie `validated('branch_id')` dès que l'acteur a
            // `branch_id = 0` — le cas de l'administrateur. Sans valeur, l'insertion
            // partait avec 0, aucune filiale ne porte cet identifiant, et le patron
            // recevait « SQLSTATE[23000]: Integrity constraint violation » au lieu
            // d'un message lui disant de choisir son établissement.
            //
            // Jumeau exact du défaut `phone` (obligatoire en base, facultatif dans la
            // règle). En modification la valeur existante sert de repli, d'où le
            // `isMethod('POST')`.
            'branch_id' => [
                Rule::requiredIf(fn () => $this->isMethod('POST')
                    && (int) ($this->user()?->branch_id ?? 0) === 0),
                'nullable',
                'integer',
                'exists:branches,id',
            ],
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('printers', 'name')
                    ->where(fn ($query) => $query->where('branch_id', $branchId))
                    ->ignore($printerId),
            ],
            'type' => ['required', 'string', Rule::in(['escpos_tcp', 'escpos_usb', 'browser_html'])],
            // [GOAL-L2-HEAL-03 2026-05-24] L7.2 L7-2-F-01 P1 — SafeRemoteHost
            // defends SSRF / internal-VPC port-scan via fsockopen() in
            // app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php:24.
            // Admin (and POS-Operator with permission:pos via testPrint) can
            // no longer point printer host at 127.0.0.1, 169.254.169.254
            // (cloud metadata), or arbitrary RFC1918 internal IPs unless
            // SAFE_REMOTE_HOST_ALLOWLIST explicitly allowlists the subnet.
            // Hostnames pass through (DNS-rebind residual risk documented
            // in SafeRemoteHost docblock — V1.0.2 follow-up).
            //
            // [OWNER DECISION 2026-08-13 — option (b) "allowlist fermée"] The
            // rule runs in PORT-AWARE mode here: the allowlist entry that
            // unlocks an internal host must also cover the port, so
            // allowlisting the local print bridge (127.0.0.1:9100-9101) does
            // NOT re-open the fsockopen() port-scan oracle on the other 65533
            // ports of the box. defaultPort mirrors TcpPrinterTransport::send()
            // which dials 9100 when `port` is left blank.
            'host' => [
                Rule::requiredIf(fn () => $this->input('type', 'escpos_tcp') === 'escpos_tcp'),
                'nullable',
                'string',
                'max:64',
                new \App\Rules\SafeRemoteHost(portField: 'port', defaultPort: 9100),
            ],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'station' => ['nullable', 'string', Rule::in(['receipt', 'kitchen_hot', 'kitchen_cold', 'bar'])],
            // [ONB-10 2026-08-28] 42 MANQUAIT, alors que l'ecran le propose sous le
            // libelle « 42 (80 mm SAGA) » — c'est-a-dire en NOMMANT le modele. Choisir
            // la largeur de sa propre imprimante renvoyait un 422, et le champ n'avait
            // aucun affichage d'erreur : le refus etait invisible.
            //
            // La largeur n'est qu'un nombre de caracteres par ligne pour le rendu
            // ESC/POS : 42 est mecaniquement valide. On rend vrai ce que l'ecran
            // promet, plutot que de retirer l'option et priver ces imprimantes du bon
            // reglage.
            'width_chars' => ['nullable', 'integer', Rule::in([32, 42, 48])],
            // [ONB-10 2026-08-27] Était `Rule::in([0, 1])` — une convention booléenne
            // que RIEN d'autre ne partageait. Les trois chemins d'impression du produit
            // (KitchenTicketAutoPrinter, PosReceiptPrintController, et le listener
            // d'encaissement comptoir) cherchent `status = App\Enums\Status::ACTIVE`,
            // qui vaut 5 — et les imprimantes réelles du Cayenne sont bien à 5.
            //
            // Trois lectures incompatibles de la même colonne cohabitaient : le serveur
            // n'acceptait que 0 ou 1, l'écran écrivait 5 pour « archivé » (donc 422 sur
            // le bouton Archiver), et le contrôleur créait à 1 — une valeur qu'aucun
            // chemin d'impression ne reconnaît. Voir ImprimanteCreeeDepuisEcranImprimeTest.
            'status' => ['nullable', 'integer', Rule::in([\App\Enums\Status::ACTIVE, \App\Enums\Status::INACTIVE])],
            'options' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required' => "Choisissez l'établissement auquel cette imprimante appartient.",
            'branch_id.exists' => "Cet établissement n'existe pas.",
        ];
    }

    private function resolvedBranchId(): int
    {
        $userBranchId = (int) ($this->user()?->branch_id ?? 0);

        if ($userBranchId > 0) {
            return $userBranchId;
        }

        return (int) ($this->input('branch_id') ?: $this->route('printer')?->branch_id ?: 0);
    }
}
