<?php

namespace App\Contracts;

/**
 * Common contract for Order and FrontendOrder so broadcast events
 * can accept either model without a type mismatch.
 *
 * Both models expose: id, branch_id, queue_number, status, order_type, total, token, created_at
 */
interface BroadcastableOrder
{
    // Intentionally empty — used purely for type-safe polymorphism.
    // PHP structural typing via interface allows both Order and FrontendOrder
    // to be accepted by broadcast event constructors.
}
