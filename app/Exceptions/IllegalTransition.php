<?php

namespace App\Exceptions;

use DomainException;

/** Thrown when a state machine Action is asked for a transition the current state does not allow. */
class IllegalTransition extends DomainException
{
    public static function for(string $subject, string $from, string $to): self
    {
        return new self("Illegal transition for {$subject}: {$from} -> {$to}.");
    }
}
