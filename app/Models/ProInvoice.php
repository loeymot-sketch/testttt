<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * [FACTURE PRO 2026-11-14 · propriétaire] Facture à montant libre pour un client professionnel.
 *
 * HORS CHAÎNE NF525, par décision du propriétaire : ce n'est pas une commande, elle n'a pas de
 * numéro fiscal, n'entre ni dans le Z ni dans le tiroir-caisse. Le document imprimé le dit
 * (« Facture établie hors ticket de caisse »).
 *
 * Ajout seul : on ne supprime pas une facture et on n'en modifie pas le contenu. La seule
 * écriture permise après la création est l'ANNULATION, une fois ; elle garde le numéro et le
 * document imprime « ANNULÉE ». Les déclencheurs de la migration couvrent la requête brute.
 */
class ProInvoice extends Model
{
    public const PAYMENT_METHODS = ['especes', 'carte', 'titre_restaurant'];

    /** Désignation quand la caisse n'en saisit aucune (la désignation est facultative). */
    public const DEFAULT_LABEL = 'Repas';

    public const PAYMENT_LABELS = [
        'especes'          => 'Espèces',
        'carte'            => 'Carte bancaire',
        'titre_restaurant' => 'Titre-restaurant',
    ];

    /** Taux de TVA proposés (restauration : 10 % sur place / consommation immédiate). */
    public const VAT_RATES = ['5.5', '10', '20'];

    /** Seules colonnes qu'une annulation a le droit d'écrire. */
    private const CANCEL_COLUMNS = ['cancelled_at', 'cancelled_by', 'cancel_reason', 'updated_at'];

    protected $table = 'pro_invoices';

    protected $fillable = [
        'branch_id', 'number',
        'client_name', 'client_address', 'client_siret', 'client_vat_number',
        'service_date', 'label', 'quantity',
        'amount_ttc', 'vat_rate', 'amount_ht', 'vat_amount',
        'payment_method', 'notes', 'created_by',
    ];

    protected $casts = [
        'branch_id'    => 'integer',
        'service_date' => 'date:Y-m-d',
        'quantity'     => 'integer',
        'amount_ttc'   => 'decimal:2',
        'vat_rate'     => 'decimal:2',
        'amount_ht'    => 'decimal:2',
        'vat_amount'   => 'decimal:2',
        'created_by'   => 'integer',
        'cancelled_at' => 'datetime',
        'cancelled_by' => 'integer',
    ];

    /**
     * Isolation de branche (CLAUDE.md §9) : une facture appartient au restaurant qui l'a émise.
     * Ajout seul côté modèle : pas de suppression, pas de modification hors annulation unique.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);

        static::deleting(function () {
            throw new LogicException('Une facture pro ne se supprime pas : annulez-la.');
        });

        static::updating(function (ProInvoice $invoice) {
            if ($invoice->getOriginal('cancelled_at') !== null) {
                throw new LogicException('Cette facture est déjà annulée.');
            }
            $interdits = array_diff(array_keys($invoice->getDirty()), self::CANCEL_COLUMNS);
            if ($interdits !== []) {
                throw new LogicException('Une facture pro ne se modifie pas après sa création.');
            }
        });
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function paymentLabel(): string
    {
        return self::PAYMENT_LABELS[$this->payment_method] ?? (string) $this->payment_method;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
