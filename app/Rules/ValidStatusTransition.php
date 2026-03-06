<?php

namespace App\Rules;

use App\Enums\OrderStatus;
use Illuminate\Contracts\Validation\Rule;

class ValidStatusTransition implements Rule
{
    protected $currentStatus;

    /**
     * Create a new rule instance.
     *
     * @param int $currentStatus
     * @return void
     */
    public function __construct($currentStatus)
    {
        $this->currentStatus = (int) $currentStatus;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $newStatus = (int) $value;
        $current = $this->currentStatus;

        // Si le statut ne change pas, c'est valide
        if ($current === $newStatus) {
            return true;
        }

        // Règles de transition
        switch ($current) {
            case OrderStatus::PENDING:
                // Depuis PENDING, on peut accepter, annuler ou rejeter
                return in_array($newStatus, [OrderStatus::ACCEPT, OrderStatus::CANCELED, OrderStatus::REJECTED]);

            case OrderStatus::ACCEPT:
                // Depuis ACCEPT, on part en préparation ou on annule
                return in_array($newStatus, [OrderStatus::PREPARING, OrderStatus::CANCELED]);

            case OrderStatus::PREPARING:
                // Depuis PREPARING, la commande est prête ou annulée
                return in_array($newStatus, [OrderStatus::PREPARED, OrderStatus::CANCELED]);

            case OrderStatus::PREPARED:
                // Depuis PREPARED, elle part en livraison ou est livrée (sur place)
                return in_array($newStatus, [OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED]);

            case OrderStatus::OUT_FOR_DELIVERY:
                // En livraison -> livrée
                return $newStatus === OrderStatus::DELIVERED;

            case OrderStatus::DELIVERED:
                // Livrée -> peut être retournée
                return $newStatus === OrderStatus::RETURNED;

            case OrderStatus::CANCELED:
            case OrderStatus::REJECTED:
            case OrderStatus::RETURNED:
                // Si l'utilisateur est un Admin, on autorise la récupération depuis un état terminal
                if (auth()->check() && auth()->user()->hasRole(\App\Enums\Role::ADMIN)) {
                    return true;
                }
                return false;

            default:
                return false;
        }
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return trans('all.message.invalid_status_transition');
    }
}
