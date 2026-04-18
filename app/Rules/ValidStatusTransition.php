<?php

namespace App\Rules;

use App\Domain\Order\OrderStateMachine;
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
        $user = auth()->check() ? auth()->user() : null;

        return OrderStateMachine::allows($this->currentStatus, $newStatus, $user);
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
