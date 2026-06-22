<?php

namespace App\Events\Concerns;

use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\DB;

/**
 * Replacement for {@see \Illuminate\Foundation\Events\Dispatchable} that defers
 * dispatch until the active DB transaction commits (and discards the dispatch
 * entirely on rollback).
 *
 * Rationale (gate C9 — KI-001) : domain events that ride the outbox / broadcast
 * pipeline MUST NOT fire while a write transaction is still pending, because
 * downstream consumers (KDS broadcast, Kiosk presence, POS availability sync)
 * could observe state that gets rolled back.
 *
 * Behavior matrix :
 *  - No active transaction              → dispatch immediately (Laravel default)
 *  - Inside DB::transaction()           → dispatch fires AFTER commit succeeds
 *  - Inside DB::transaction() rollback  → dispatch is dropped silently
 *
 * Compatible with `Event::fake()` because the closure registered on
 * `DB::afterCommit()` only runs after the outermost transaction commits, at
 * which point the EventFake captures it normally.
 */
trait DispatchableAfterCommit
{
    public static function dispatch(...$arguments)
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit(function () use ($arguments): void {
                event(new static(...$arguments));
            });

            return null;
        }

        return event(new static(...$arguments));
    }

    public static function dispatchIf($boolean, ...$arguments)
    {
        if ($boolean) {
            return static::dispatch(...$arguments);
        }

        return null;
    }

    public static function dispatchUnless($boolean, ...$arguments)
    {
        if (! $boolean) {
            return static::dispatch(...$arguments);
        }

        return null;
    }

    /**
     * Bypass the after-commit guard (rare — for tests or explicit imperative cases).
     */
    public static function dispatchNow(...$arguments)
    {
        return event(new static(...$arguments));
    }

    public static function broadcast(...$arguments)
    {
        return new PendingDispatch(new static(...$arguments));
    }
}
