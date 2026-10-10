<?php

namespace App\Exceptions;

use DomainException;

/** Thrown when a business gate, not the state machine, stops an Action (for example a missing agreement). */
class ActionBlocked extends DomainException
{
    public static function organizationNotVerified(): self
    {
        return new self('Your organization must be verified before you can submit a brief. Please contact Groibe.');
    }

    public static function clientMsaNotAccepted(): self
    {
        return new self('The organization owner must accept the current Client MSA first.');
    }

    public static function guardFailed(string $message): self
    {
        return new self($message);
    }

    public static function immutable(string $subject): self
    {
        return new self("{$subject} can no longer be changed.");
    }

    public static function missingTemplate(string $what): self
    {
        return new self("No active {$what} is published. A super admin must publish one first.");
    }
}
