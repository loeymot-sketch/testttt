<?php

namespace App\Http\Resources;


use App\Libraries\AppLibrary;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
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
            'id'              => $this->id,
            'order_id'        => $this->order_id,
            'order_serial_no' => $this->order?->order_serial_no,
            'transaction_no'  => $this->transaction_no,
            // amount / payment_method kept in their ORIGINAL shape (flat 'M.DD' string +
            // raw upper enum) — this resource is nested in OrderResource/OrderDetailsResource
            // consumed by external (mobile/customer) clients that may parse them numerically
            // or compare the enum; changing them risks a silent cross-surface regression.
            'amount'          => AppLibrary::flatAmountFormat($this->amount),
            'payment_method'  => strtoupper($this->payment_method),
            // [P1-13] ADDITIVE FR display fields for the gérant transactions table only
            // (sales-report-style '19,00 €' + FR payment label instead of 'COUNTER_CASH').
            'amount_display'        => AppLibrary::currencyAmountFormat($this->amount),
            'payment_method_label'  => self::paymentMethodLabel($this->payment_method),
            'type'            => $this->type,
            'sign'            => $this->sign,
            'date'            => AppLibrary::datetime($this->created_at)
        ];
    }

    /**
     * Map a stored payment-method slug to its canonical FR label (mirrors the
     * fr.json labels used on sales-report). Counter/caisse slugs dominate V1
     * (Le Cayenne single restaurant); unknown/online slugs degrade gracefully
     * to a humanized form rather than the SHOUTED raw enum.
     */
    private static function paymentMethodLabel($method): string
    {
        $slug = strtolower((string) $method);
        return match ($slug) {
            'counter_cash', 'cash'                 => 'Espèces',
            'counter_card', 'card', 'credit'       => 'Carte bancaire',
            'counter_mobile_banking', 'mobile_banking' => 'Paiement mobile',
            'counter_ticket_restaurant', 'ticket_restaurant' => 'Titre-restaurant',
            'counter_other', 'other'               => 'Autre',
            ''                                      => '—',
            default => ucwords(str_replace(['_', '-'], ' ', $slug)),
        };
    }
}
